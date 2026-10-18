<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Integrations\Contracts\CredentialStore;
use App\Jobs\ScanCategoryJob;
use App\Models\Ai\AiCategory;
use App\Models\Ai\AiFinding;
use App\Models\Ai\AiSearchRun;
use App\Services\Ai\AiClock;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\CategorySearchService;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\DTO\AiResult;
use App\Services\Ai\Gemini\GeminiClient;
use App\Services\Ai\Scanning\AiCategoryService;
use App\Services\Ai\Scanning\CategoryScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\FakeAiClock;
use Tests\Support\Ai\GeminiResponses;
use Tests\TestCase;

class GeminiCategorySearchTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeAiClock;
        $this->app->instance(AiClock::class, $this->clock);
        Http::preventStrayRequests();
        config([
            'ai_providers.default' => 'cursor',
            'ai_providers.providers.gemini.api_key' => 'gemini-controlled-test-key',
            'ai_providers.providers.gemini.credential_ref' => null,
            'ai_providers.providers.gemini.model_credentials' => [],
            'ai_providers.providers.gemini.model' => 'gemini-3.8-flash',
            'ai_providers.providers.gemini.google_search_grounding' => false,
            'ai_providers.providers.gemini.timeout' => 10,
        ]);
    }

    private function search(array $options = []): AiResult
    {
        return app(CategorySearchService::class)->search('jobs', 'Kontrolowany prompt', options: $options, provider: 'gemini');
    }

    private function failure(string $code, array $options = []): AiProviderException
    {
        try {
            $this->search($options);
        } catch (AiProviderException $error) {
            $this->assertSame($code, $error->errorCode);

            return $error;
        }
        $this->fail('Expected provider failure.');
    }

    public function test_end_to_end_search_has_same_input_and_result_and_does_not_write_to_database(): void
    {
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed('{"items":[{"title":"Księgowy","price":0,"confidence":0}]}'))]);
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });
        $filters = ['location' => 'Warszawa', 'budget' => ['min' => 0, 'max' => 9000, 'currency' => 'PLN'], 'keywords' => ['księgowość']];
        $result = app(CategorySearchService::class)->search('job_offers', 'Znajdź oferty.', $filters, ['max_items' => 5], 'gemini');
        $this->assertSame('gemini', $result->provider);
        $this->assertSame('job_offers', $result->category);
        $this->assertSame('gemini-3.8-flash', $result->model);
        $this->assertSame(0.0, $result->items[0]->price);
        $this->assertSame(0.0, $result->items[0]->confidence);
        $this->assertSame([], $mutations);
        foreach (['offers', 'applications', 'sync_outbox', 'ai_runs', 'ai_search_runs', 'ai_findings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($filters): bool {
            $body = $request->data();
            $input = json_decode($body['input'], true);

            return $request->url() === GeminiClient::ENDPOINT && $request->method() === 'POST'
                && $request->hasHeader('x-goog-api-key', 'gemini-controlled-test-key')
                && ! str_contains($request->body(), 'gemini-controlled-test-key')
                && $body['model'] === 'gemini-3.8-flash' && ! isset($body['tools'])
                && $body['store'] === false && $body['stream'] === false && $body['background'] === false
                && $body['response_format']['mime_type'] === 'application/json'
                && $input['filters'] === $filters && $input['max_items'] === 5;
        });
    }

    public function test_capability_flag_and_empty_result_do_not_claim_a_search_was_performed(): void
    {
        $provider = app(AiProviderManager::class)->resolve('gemini');
        $this->assertInstanceOf(AiProviderInterface::class, $provider);
        $this->assertSame('gemini', $provider->key());
        $this->assertSame('Google Gemini', $provider->name());
        $this->assertTrue($provider->supports()->supports('analyze'));
        $this->assertTrue($provider->supports()->structuredResults);
        $this->assertFalse($provider->supports()->webSearch);
        config(['ai_providers.providers.gemini.google_search_grounding' => true]);
        $this->assertTrue(app(AiProviderManager::class)->resolve('gemini')->supports()->webSearch);
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed())]);
        $result = $this->search();
        $this->assertSame([], $result->items);
        $this->assertTrue($result->metadata['gemini']['grounding_enabled']);
        $this->assertFalse($result->metadata['gemini']['search_performed']);
        Http::assertSent(fn ($request) => $request['tools'] === [['type' => 'google_search']]);
    }

    public function test_analyze_with_category_id_uses_model_override_and_its_own_credentials(): void
    {
        config(['ai_providers.providers.gemini.api_key' => null,
            'ai_providers.providers.gemini.model_credentials' => ['gemini-3.6-flash' => ['api_key' => 'model-specific-test-key']],
        ]);
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed(overrides: ['model' => 'models/gemini-3.6-flash']))]);
        $result = app(CategorySearchService::class)->analyze(42, 'Przeanalizuj dane.', options: ['model' => 'gemini-3.6-flash'], provider: 'gemini');
        $this->assertSame('42', $result->category);
        $this->assertSame('models/gemini-3.6-flash', $result->model);
        $this->assertSame('gemini-3.6-flash', $result->metadata['gemini']['requested_model']);
        Http::assertSent(fn ($request) => $request['model'] === 'gemini-3.6-flash'
            && json_decode($request['input'], true)['operation'] === 'analyze'
            && $request->hasHeader('x-goog-api-key', 'model-specific-test-key'));
    }

    public function test_keychain_reference_is_used_without_putting_credentials_in_results(): void
    {
        $store = $this->mock(CredentialStore::class);
        $store->shouldReceive('read')->twice()->with('gemini-keychain-test')->andReturn(['api_key' => 'keychain-controlled-test-key']);
        config(['ai_providers.providers.gemini.credential_ref' => 'gemini-keychain-test']);
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed())]);
        $result = $this->search();
        $this->assertStringNotContainsString('keychain-controlled-test-key', json_encode($result));
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'keychain-controlled-test-key'));
    }

    public function test_database_selection_can_choose_gemini_without_touching_cursor(): void
    {
        app(AiProviderManager::class)->configure('gemini');
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed())]);
        $this->assertSame('gemini', app(CategorySearchService::class)->search('jobs', 'Prompt')->provider);
        Http::assertSentCount(1);
    }

    public function test_missing_credentials_fail_before_http_with_no_fake_fallback(): void
    {
        config(['ai_providers.providers.gemini.api_key' => null]);
        $provider = app(AiProviderManager::class)->resolve('gemini');
        $this->assertFalse($provider->supports()->available);
        $this->assertSame('missing_credentials', $provider->supports()->unavailableReason);
        $this->failure('missing_credentials');
        Http::assertNothingSent();
    }

    #[DataProvider('permanentErrors')]
    public function test_permanent_http_errors_are_not_retried_and_diagnostics_are_sanitized(int $status): void
    {
        Http::fake([GeminiClient::ENDPOINT => Http::response(['error' => 'private-error-body-with-key'], $status)]);
        $error = $this->failure(in_array($status, [401, 403], true) ? 'gemini_auth_failed' : 'gemini_request_failed');
        $this->assertSame($status, $error->httpStatus);
        $this->assertSame('request', $error->phase);
        $this->assertFalse($error->retryable);
        $this->assertStringNotContainsString('private-error-body-with-key', $error->getMessage());
        $this->assertNull($error->getPrevious());
        Http::assertSentCount(1);
    }

    public static function permanentErrors(): array
    {
        return [[400], [401], [403], [404], [422], [501], [302]];
    }

    public function test_transient_http_errors_retry_with_backoff_and_retry_after(): void
    {
        Http::fake([GeminiClient::ENDPOINT => Http::sequence()->push([], 429, ['Retry-After' => '1'])->push([], 503)->push(GeminiResponses::completed())]);
        $this->assertSame([], $this->search()->items);
        $this->assertSame([1000, 500], $this->clock->delays);
        Http::assertSentCount(3);
    }

    public function test_network_failure_retries_and_preserves_the_same_payload(): void
    {
        $calls = 0;
        Http::fake([GeminiClient::ENDPOINT => function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('private-transport-diagnostic');
            }

            return Http::response(GeminiResponses::completed());
        }]);
        $this->search();
        $this->assertSame(2, $calls);
        $this->assertSame([250], $this->clock->delays);
    }

    public function test_exhausted_retries_and_total_deadline_are_bounded(): void
    {
        Http::fake([GeminiClient::ENDPOINT => Http::response([], 503)]);
        $this->assertTrue($this->failure('gemini_request_failed')->retryable);
        $this->assertSame([250, 500], $this->clock->delays);
        Http::assertSentCount(3);
    }

    public function test_long_retry_after_does_not_sleep_beyond_the_deadline(): void
    {
        Http::fake([GeminiClient::ENDPOINT => Http::response([], 429, ['Retry-After' => '999999999999999999999'])]);
        $this->assertTrue($this->failure('gemini_request_timeout')->retryable);
        $this->assertSame([], $this->clock->delays);
        Http::assertSentCount(1);
    }

    public function test_a_late_success_after_the_deadline_is_not_accepted(): void
    {
        Http::fake([GeminiClient::ENDPOINT => function () {
            $this->clock->time += 11;

            return Http::response(GeminiResponses::completed());
        }]);
        $this->failure('gemini_request_timeout');
        Http::assertSentCount(1);
    }

    public function test_malformed_http_json_and_oversized_body_are_not_retried(): void
    {
        foreach (['not JSON', str_repeat('a', 2_000_001), '[]'] as $body) {
            Http::fake([GeminiClient::ENDPOINT => Http::response($body)]);
            $this->failure('invalid_response');
        }
        $this->assertSame([], $this->clock->delays);
    }

    public function test_unsupported_options_and_incompatible_grounding_fail_before_http(): void
    {
        $this->failure('unsupported_options', ['temperature' => 0.2]);
        config(['ai_providers.providers.gemini.google_search_grounding' => true]);
        $this->failure('unsupported_options', ['model' => 'gemini-2.5-flash']);
        Http::assertNothingSent();
    }

    public function test_invalid_configuration_never_exposes_a_key_or_calls_http(): void
    {
        config(['ai_providers.providers.gemini.model' => 'https://untrusted-host']);
        $this->failure('invalid_configuration');
        config(['ai_providers.providers.gemini.model' => 'gemini-3.8-flash', 'ai_providers.providers.gemini.timeout' => 0]);
        $this->failure('invalid_configuration');
        Http::assertNothingSent();
    }

    public function test_more_than_requested_items_is_an_error_not_silent_truncation(): void
    {
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed('{"items":[{"title":"A"},{"title":"B"}]}'))]);
        $this->failure('invalid_response', ['max_items' => 1]);
        Http::assertSentCount(1);
    }

    public function test_existing_job_can_select_gemini_and_rescanning_deduplicates(): void
    {
        $category = AiCategory::factory()->active()->create(['default_provider' => 'cursor']);
        app(AiCategoryService::class)->addPrompt($category, 'Prompt', 'Kontrolowany prompt kategorii.');
        Http::fake([GeminiClient::ENDPOINT => Http::response(GeminiResponses::completed('{"items":[{"title":"Księgowy","url":"https://example.org/job/1","confidence":0}]}'))]);
        $job = new ScanCategoryJob($category->id, 'gemini');
        $job->handle(app(CategoryScanService::class));
        $run = AiSearchRun::query()->findOrFail($job->runId);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('gemini', $run->provider);
        $this->assertSame('gemini-3.8-flash', $run->model);
        $this->assertSame(1, $run->meta['new_count']);
        $this->assertSame(0.0, AiFinding::query()->sole()->confidence);
        $this->artisan('ai:scan', ['category' => $category->slug, '--provider' => 'gemini'])->assertExitCode(0);
        $this->assertDatabaseCount('ai_findings', 1);
        $this->assertDatabaseCount('ai_search_runs', 2);
        Http::assertSentCount(2);
    }

    public function test_category_provider_override_and_failure_keep_existing_cache_intact(): void
    {
        $category = AiCategory::factory()->active()->create(['default_provider' => 'gemini']);
        app(AiCategoryService::class)->addPrompt($category, 'Prompt', 'Prompt kategorii');
        Http::fake([GeminiClient::ENDPOINT => Http::sequence()
            ->push(GeminiResponses::completed('{"items":[{"title":"Kierowca","url":"https://example.org/job/1"}]}'))
            ->push(GeminiResponses::completed('invalid JSON'))]);
        $service = app(CategoryScanService::class);
        $this->assertSame('succeeded', $service->scan($category->id)->status);
        $failure = $service->scan($category->id);
        $this->assertSame('failed', $failure->status);
        $this->assertSame('invalid_response', $failure->meta['error_code']);
        $this->assertDatabaseCount('ai_findings', 1);
        $this->assertSame('Kierowca', AiFinding::query()->sole()->title);
    }
}

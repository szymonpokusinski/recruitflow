<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Integrations\Contracts\CredentialStore;
use App\Services\Ai\AiClock;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\CategorySearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\FakeAiClock;
use Tests\TestCase;

class CursorCategorySearchTest extends TestCase
{
    use RefreshDatabase;

    private const RUN_ID = 'run-00000000-0000-0000-0000-000000000001';

    private FakeAiClock $clock;

    private string $agentId = '';

    private int $starts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeAiClock;
        $this->app->instance(AiClock::class, $this->clock);
        Http::preventStrayRequests();
        config([
            'ai_providers.default' => 'cursor',
            'ai_providers.providers.cursor.api_key' => 'cursor-test-key',
            'ai_providers.providers.cursor.credential_ref' => null,
            'ai_providers.providers.cursor.model' => 'test-model',
            'ai_providers.providers.cursor.model_credentials' => [],
            'ai_providers.providers.cursor.timeout' => 10,
        ]);
    }

    private function fakeCursor(array $runs = [], ?callable $start = null, int $archiveStatus = 200): void
    {
        Http::fake(['https://api.cursor.com/v1/*' => function (Request $request) use (&$runs, $start, $archiveStatus) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/v1/agents') {
                $this->agentId = $request['agentId'];
                $this->starts++;

                return $start ? $start($request, $this->starts) : Http::response($this->initial(), 201);
            }
            if ($request->method() === 'GET' && $path === '/v1/agents/'.$this->agentId) {
                return Http::response(['id' => $this->agentId, 'latestRunId' => self::RUN_ID, 'status' => 'ACTIVE']);
            }
            if ($request->method() === 'GET' && $path === '/v1/agents/'.$this->agentId.'/runs/'.self::RUN_ID) {
                $next = $runs === [] ? ['status' => 'FINISHED', 'result' => '{"items":[{"title":"Kontrolowana oferta","confidence":0}]}'] : array_shift($runs);
                if (isset($next['http_status'])) {
                    return Http::response(['error' => 'private-http-error'], $next['http_status'], $next['headers'] ?? []);
                }

                return Http::response($next + ['id' => self::RUN_ID, 'agentId' => $this->agentId]);
            }
            if ($request->method() === 'POST' && $path === '/v1/agents/'.$this->agentId.'/archive') {
                return Http::response(['id' => $this->agentId], $archiveStatus);
            }
            if ($request->method() === 'POST' && $path === '/v1/agents/'.$this->agentId.'/runs/'.self::RUN_ID.'/cancel') {
                return Http::response(['id' => self::RUN_ID]);
            }

            throw new \LogicException('Unexpected fake endpoint.');
        }]);
    }

    private function initial(): array
    {
        return ['agent' => ['id' => $this->agentId], 'run' => ['id' => self::RUN_ID, 'agentId' => $this->agentId, 'status' => 'CREATING']];
    }

    public function test_end_to_end_search_returns_dto_with_filters_and_no_database_writes(): void
    {
        $this->fakeCursor([['status' => 'RUNNING'], ['status' => 'FINISHED', 'result' => '{"items":[{"title":"Księgowy","price":0,"currency":"PLN","confidence":0,"description":"Pierwszy\\nDrugi"}]}']]);
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });
        $filters = ['location' => 'Warszawa', 'budget' => ['min' => 0, 'max' => 10000, 'currency' => 'PLN'], 'keywords' => ['księgowość']];
        $result = app(CategorySearchService::class)->search('job_offers', 'Znajdź oferty.', $filters, ['max_items' => 5]);
        $this->assertSame('cursor', $result->provider);
        $this->assertSame('test-model', $result->model);
        $this->assertSame('job_offers', $result->category);
        $this->assertSame(self::RUN_ID, $result->requestId);
        $this->assertSame(0.0, $result->items[0]->price);
        $this->assertSame(0.0, $result->items[0]->confidence);
        $this->assertSame("Pierwszy\nDrugi", $result->items[0]->description);
        $this->assertTrue($result->items[0]->metadata['cursor']['cleanup']['archived']);
        $this->assertSame([], $mutations);
        foreach (['offers', 'applications', 'offer_evaluations', 'sync_outbox', 'ai_runs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertSentCount(4);
        Http::assertSent(function (Request $request) use ($filters) {
            if ($request->url() !== 'https://api.cursor.com/v1/agents') {
                return false;
            }
            $body = $request->data();
            $input = json_decode(explode("\nUSER_INPUT_JSON:\n", $body['prompt']['text'])[1], true);

            return array_intersect(array_keys($body), ['repos', 'env', 'envVars', 'mcpServers']) === []
                && $body['autoCreatePR'] === false && $body['model'] === ['id' => 'test-model']
                && $input['filters'] === $filters && $input['max_items'] === 5
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('cursor-test-key:'))
                && ! str_contains($request->body(), 'cursor-test-key');
        });
    }

    public function test_analyze_accepts_numeric_category_id_and_server_default_model_is_unknown(): void
    {
        config(['ai_providers.providers.cursor.model' => null]);
        $this->fakeCursor();
        $result = app(CategorySearchService::class)->analyze(42, 'Przeanalizuj dostarczone dane.');
        $this->assertSame('42', $result->category);
        $this->assertNull($result->model);
        $this->assertSame('unknown_server_default', $result->items[0]->metadata['cursor']['model_source']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.cursor.com/v1/agents'
            && ! isset($request->data()['model'])
            && str_contains($request['prompt']['text'], '"operation":"analyze"'));
    }

    public function test_database_selection_can_choose_fake_without_calling_cursor(): void
    {
        config(['ai_providers.providers.fake.enabled' => true]);
        app(AiProviderManager::class)->configure('fake');
        $this->assertSame('fake', app(CategorySearchService::class)->search('jobs', 'Prompt')->provider);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_missing_credentials_reject_before_http_without_a_fake_fallback(): void
    {
        config(['ai_providers.providers.cursor.api_key' => null]);
        $this->failure('missing_credentials');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_requested_model_uses_its_own_api_key_without_default_credentials(): void
    {
        config([
            'ai_providers.providers.cursor.api_key' => null,
            'ai_providers.providers.cursor.model_credentials' => ['special-model' => ['api_key' => 'special-test-key']],
        ]);
        $this->fakeCursor();
        $result = app(CategorySearchService::class)->search('jobs', 'Prompt', options: ['model' => 'special-model']);
        $this->assertSame('special-model', $result->model);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.cursor.com/v1/agents'
            && $request['model']['id'] === 'special-model'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('special-test-key:')));
    }

    public function test_model_keychain_reference_isolated_from_the_default_account(): void
    {
        $store = $this->mock(CredentialStore::class);
        $store->shouldReceive('read')->twice()->with('model-keychain')->andReturn(['api_key' => 'model-secure-test-key']);
        config([
            'ai_providers.providers.cursor.credential_ref' => 'default-must-not-be-read',
            'ai_providers.providers.cursor.model_credentials' => ['model-two' => ['credential_ref' => 'model-keychain']],
        ]);
        $this->fakeCursor();
        $result = app(CategorySearchService::class)->search('jobs', 'Prompt', options: ['model' => 'model-two']);
        $this->assertStringNotContainsString('model-secure-test-key', json_encode($result));
    }

    #[DataProvider('permanentStartErrors')]
    public function test_permanent_start_errors_are_not_retried_and_do_not_leak_response(int $status): void
    {
        $this->fakeCursor(start: fn () => Http::response(['error' => 'Authorization: never-log-http-body'], $status));
        $exception = $this->failure(in_array($status, [401, 403], true) ? 'cursor_auth_failed' : 'cursor_start_failed');
        $this->assertSame('start', $exception->phase);
        $this->assertSame($status, $exception->httpStatus);
        $this->assertFalse($exception->retryable);
        $this->assertSame(1, $this->starts);
        $this->assertStringNotContainsString('never-log-http-body', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public static function permanentStartErrors(): array
    {
        return [[400], [401], [403], [404], [422], [501]];
    }

    public function test_start_retries_retryable_http_errors_with_same_agent_id_and_backoff(): void
    {
        $this->fakeCursor(start: fn ($request, $attempt) => match ($attempt) {
            1 => Http::response([], 429, ['Retry-After' => '1']),
            2 => Http::response([], 503),
            default => Http::response($this->initial(), 201),
        });
        app(CategorySearchService::class)->search('jobs', 'Prompt');
        $this->assertSame([1000, 500], $this->clock->delays);
        $posts = Http::recorded(fn ($request) => $request->url() === 'https://api.cursor.com/v1/agents');
        $this->assertCount(3, $posts);
        $this->assertCount(1, $posts->map(fn ($pair) => $pair[0]['agentId'])->unique());
    }

    public function test_ambiguous_start_then_conflict_recovers_only_the_same_agent(): void
    {
        $this->fakeCursor(start: fn ($request, $attempt) => Http::response(['code' => 'agent_id_conflict'], $attempt === 1 ? 503 : 409));
        $result = app(CategorySearchService::class)->search('jobs', 'Prompt');
        $this->assertSame(self::RUN_ID, $result->requestId);
        $this->assertSame(2, $this->starts);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.cursor.com/v1/agents/'.$this->agentId);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/runs'));
    }

    public function test_connection_exception_is_retryable_without_leaking_message(): void
    {
        $this->fakeCursor(start: function ($request, $attempt) {
            if ($attempt === 1) {
                throw new ConnectionException('private-token-in-connection-error');
            }

            return Http::response($this->initial(), 201);
        });
        $result = app(CategorySearchService::class)->search('jobs', 'Prompt');
        $this->assertSame(2, $this->starts);
        $this->assertStringNotContainsString('private-token-in-connection-error', json_encode($result));
    }

    public function test_retrying_poll_does_not_launch_another_agent(): void
    {
        $this->fakeCursor([['http_status' => 503], ['http_status' => 429], ['status' => 'FINISHED', 'result' => '{"items":[]}']]);
        $this->assertSame([], app(CategorySearchService::class)->search('jobs', 'Prompt')->items);
        $this->assertSame(1, $this->starts);
        $this->assertSame([250, 500], $this->clock->delays);
    }

    #[DataProvider('terminalErrors')]
    public function test_terminal_run_errors_are_never_accepted_as_partial_success(string $status, string $code): void
    {
        $this->fakeCursor([['status' => $status, 'result' => '{"items":[{"title":"partial"}]}']]);
        $exception = $this->failure($code);
        $this->assertSame('run', $exception->phase);
        $this->assertSame(self::RUN_ID, $exception->requestId);
        $this->assertFalse($exception->retryable);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/cancel'));
    }

    public static function terminalErrors(): array
    {
        return [['ERROR', 'cursor_run_failed'], ['CANCELLED', 'cursor_run_cancelled'], ['EXPIRED', 'cursor_run_expired']];
    }

    public function test_timeout_cancels_and_archives_known_run_with_no_results_saved(): void
    {
        config(['ai_providers.providers.cursor.timeout' => 2]);
        $this->fakeCursor(array_fill(0, 5, ['status' => 'RUNNING']));
        $exception = $this->failure('cursor_run_timeout');
        $this->assertSame('run', $exception->phase);
        $this->assertTrue($exception->context['cleanup']['cancelled']);
        $this->assertTrue($exception->context['cleanup']['archived']);
        $this->assertLessThanOrEqual(2, $this->clock->time);
        $this->assertSame(1, $this->starts);
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_start_timeout_is_distinct_from_run_timeout(): void
    {
        config(['ai_providers.providers.cursor.timeout' => 1]);
        $this->fakeCursor(start: function () {
            $this->clock->time += 2;

            return Http::response($this->initial(), 201);
        });
        $exception = $this->failure('cursor_start_timeout');
        $this->assertSame('start', $exception->phase);
        $this->assertSame(1, $this->starts);
    }

    public function test_bad_final_json_fails_without_starting_a_replacement_run(): void
    {
        $this->fakeCursor([['status' => 'FINISHED', 'result' => 'not JSON']]);
        $exception = $this->failure('invalid_response');
        $this->assertSame('run', $exception->phase);
        $this->assertSame(1, $this->starts);
        Http::assertSentCount(3);
    }

    public function test_cleanup_failure_is_visible_without_losing_valid_results(): void
    {
        $this->fakeCursor(archiveStatus: 503);
        $result = app(CategorySearchService::class)->search('jobs', 'Prompt');
        $this->assertFalse($result->items[0]->metadata['cursor']['cleanup']['archived']);
        $this->assertTrue($result->items[0]->metadata['cursor']['cleanup']['attempted']);
        Http::assertSentCount(3);
    }

    public function test_cleanup_state_is_visible_even_for_empty_results(): void
    {
        $this->fakeCursor([['status' => 'FINISHED', 'result' => '{"items":[]}']], archiveStatus: 503);
        $result = app(CategorySearchService::class)->search('jobs', 'Prompt');
        $this->assertSame([], $result->items);
        $this->assertFalse($result->metadata['cursor']['cleanup']['archived']);
    }

    public function test_cleanup_state_is_preserved_when_final_json_is_invalid(): void
    {
        $this->fakeCursor([['status' => 'FINISHED', 'result' => 'bad-json']], archiveStatus: 503);
        $error = $this->failure('invalid_response');
        $this->assertFalse($error->context['cleanup']['archived']);
    }

    public function test_run_auth_error_is_not_retried_and_identifies_run_phase(): void
    {
        $this->fakeCursor([['http_status' => 403]]);
        $exception = $this->failure('cursor_auth_failed');
        $this->assertSame('run', $exception->phase);
        $this->assertSame(403, $exception->httpStatus);
        $this->assertFalse($exception->retryable);
        $this->assertSame(self::RUN_ID, $exception->requestId);
        Http::assertSentCount(4); // start, one poll, cancel, archive
    }

    public function test_exhausted_retryable_start_stops_at_attempt_limit(): void
    {
        $this->fakeCursor(start: fn () => Http::response([], 503));
        $exception = $this->failure('cursor_start_failed');
        $this->assertSame('start', $exception->phase);
        $this->assertTrue($exception->retryable);
        $this->assertSame(3, $this->starts);
        $this->assertSame([250, 500], $this->clock->delays);
    }

    public function test_missing_explicit_model_key_never_falls_back_to_default_account(): void
    {
        config(['ai_providers.providers.cursor.model_credentials' => ['no-key-model' => ['api_key' => null]]]);
        try {
            app(CategorySearchService::class)->search('jobs', 'Prompt', options: ['model' => 'no-key-model']);
            $this->fail('Default key used despite explicit missing model credentials.');
        } catch (AiProviderException $exception) {
            $this->assertSame('missing_credentials', $exception->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_invalid_timeout_rejects_before_http(): void
    {
        config(['ai_providers.providers.cursor.timeout' => 0]);
        $this->failure('invalid_configuration');
        Http::assertNothingSent();
    }

    public function test_invalid_filters_are_rejected_before_starting_billable_work(): void
    {
        foreach ([['budget' => ['min' => 100, 'max' => 0]], ['budget' => ['min' => -1]], ['keywords' => ['']], ['location' => ['bad']], ['unknown' => true]] as $filters) {
            try {
                app(CategorySearchService::class)->search('jobs', 'Prompt', $filters);
                $this->fail('Invalid filters accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_artisan_search_uses_the_complete_pipeline_without_database_persistence(): void
    {
        $this->fakeCursor();
        $this->artisan('ai:search', [
            'category' => 'jobs', '--prompt' => 'Kontrolowany test.', '--location' => 'Warszawa',
            '--budget-min' => '0', '--currency' => 'PLN', '--keyword' => ['marketing'], '--max-items' => '5',
        ])->assertExitCode(0);
        $this->assertSame(1, $this->starts);
        $this->assertDatabaseCount('ai_runs', 0);
        $this->assertDatabaseCount('offers', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.cursor.com/v1/agents'
            && str_contains($request['prompt']['text'], '"max_items":5'));
    }

    public function test_artisan_missing_key_returns_failure_without_network(): void
    {
        config(['ai_providers.providers.cursor.api_key' => null]);
        $this->artisan('ai:search', ['category' => 'jobs', '--prompt' => 'Prompt'])
            ->expectsOutputToContain('missing_credentials')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_unknown_or_unsupported_options_do_not_start_an_agent(): void
    {
        try {
            app(CategorySearchService::class)->search('jobs', 'Prompt', options: ['temperature' => 0]);
            $this->fail('Unsupported Cursor option accepted.');
        } catch (AiProviderException $exception) {
            $this->assertSame('unsupported_options', $exception->errorCode);
        }
        Http::assertNothingSent();
    }

    private function failure(string $code): AiProviderException
    {
        try {
            app(CategorySearchService::class)->search('jobs', 'Prompt');
            $this->fail('Expected failure '.$code);
        } catch (AiProviderException $exception) {
            $this->assertSame($code, $exception->errorCode);

            return $exception;
        }
    }
}

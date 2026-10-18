<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Integrations\Contracts\CredentialStore;
use App\Models\Ai\AiProviderSetting;
use App\Models\Ai\AiRun;
use App\Models\Recruitment\Offer;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiService;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\DTO\AiCapabilities;
use App\Services\Ai\DTO\AiItem;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai_providers.default' => 'fake',
            'ai_providers.providers.fake.enabled' => true,
            'ai_providers.providers.fake.items' => [['title' => 'Kontrolowana oferta', 'confidence' => null]],
            'ai_providers.drivers.scripted' => ScriptedProvider::class,
            'ai_providers.providers.scripted' => ['driver' => 'scripted', 'enabled' => true, 'model' => 'scripted-model'],
        ]);
    }

    public function test_both_operations_are_logged_without_saving_business_records_or_prompts(): void
    {
        foreach (['search', 'analyze'] as $operation) {
            $result = app(AiService::class)->{$operation}('job_offers', 'private-prompt-not-to-store');
            $run = AiRun::query()->where('request_id', $result->requestId)->firstOrFail();
            $this->assertSame('succeeded', $run->status);
            $this->assertSame('fake', $run->provider);
            $this->assertSame('fake-v1', $run->model);
            $this->assertSame('job_offers', $run->category);
            $this->assertSame($operation, $run->operation);
            $this->assertSame(1, $run->items_count);
            $this->assertGreaterThanOrEqual(0, $run->duration_ms);
            $this->assertNotNull($run->finished_at);
            $this->assertEquals($run->requested_at->getTimestamp(), $result->requestedAt->getTimestamp());
            $this->assertNull($run->error);
            $this->assertStringNotContainsString('private-prompt-not-to-store', json_encode($run));
            $this->assertTrue($result->items[0]->metadata['synthetic']);
        }
        $this->assertDatabaseCount('ai_runs', 2);
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('offer_evaluations', 0);
        $this->assertDatabaseCount('sync_outbox', 0);
        Http::assertNothingSent();
    }

    public function test_database_provider_selection_and_model_override_are_used_immediately(): void
    {
        $manager = app(AiProviderManager::class);
        $service = app(AiService::class);
        config(['ai_providers.providers.another' => ['driver' => 'fake', 'enabled' => true, 'model' => 'from-config', 'items' => []]]);
        $manager->configure('another', 'from-db');
        $this->assertSame('another', $manager->resolve()->key());
        $first = $service->analyze('general', 'Prompt');
        $this->assertSame('another', $first->provider);
        $this->assertSame('from-db', $first->model);
        $this->assertSame('from-db', AiRun::query()->where('request_id', $first->requestId)->firstOrFail()->model);
        $second = $service->search('general', 'Prompt', ['model' => 'per-request']);
        $this->assertSame('per-request', $second->model);
        $manager->configure('fake');
        $this->assertSame('fake', $service->search('general', 'Prompt')->provider);
        $this->assertDatabaseCount('ai_provider_settings', 1);
    }

    public function test_missing_cursor_key_records_a_failure_with_no_fake_fallback(): void
    {
        config(['ai_providers.default' => 'cursor', 'ai_providers.providers.cursor.api_key' => null, 'ai_providers.providers.cursor.credential_ref' => null]);
        $exception = $this->assertFailure('missing_credentials');
        $run = AiRun::query()->sole();
        $this->assertSame('cursor', $run->provider);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->request_id);
        $this->assertSame($run->request_id, $exception->requestId);
        $this->assertNull($run->items_count);
        Http::assertNothingSent();
    }

    public function test_credentials_are_only_references_in_database_and_never_logged(): void
    {
        $store = $this->mock(CredentialStore::class);
        $store->shouldReceive('read')->twice()->with('ai-cursor-test')->andReturn(['api_key' => 'secure-token-never-log']);
        Http::fake(['https://api.cursor.com/v1/agents' => Http::response(['error' => 'secret-in-http-error'], 403)]);
        app(AiProviderManager::class)->configure('cursor', 'selected-model', 'ai-cursor-test');
        $this->assertFailure('cursor_auth_failed');
        $this->assertSame('ai-cursor-test', AiProviderSetting::query()->sole()->credential_ref);
        $this->assertStringNotContainsString('secure-token-never-log', AiRun::query()->get()->toJson());
        $this->assertStringNotContainsString('secure-token-never-log', AiProviderSetting::query()->get()->toJson());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('secure-token-never-log:'))
            && ! str_contains($request->body(), 'secure-token-never-log'));
    }

    public function test_invalid_results_from_a_custom_driver_cannot_be_marked_successful(): void
    {
        config(['ai_providers.default' => 'scripted', 'ai_providers.providers.scripted.mode' => 'invalid-score']);
        $this->assertFailure('invalid_response');
        $this->assertDatabaseHas('ai_runs', ['status' => 'failed', 'error_code' => 'invalid_response']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_result_cannot_spoof_provider_or_category(): void
    {
        config(['ai_providers.default' => 'scripted']);
        foreach (['wrong-provider', 'wrong-category'] as $mode) {
            config(['ai_providers.providers.scripted.mode' => $mode]);
            $this->assertFailure('invalid_response');
        }
        $this->assertDatabaseCount('ai_runs', 2);
        $this->assertSame(0, AiRun::query()->where('status', 'succeeded')->count());
    }

    public function test_model_alias_can_resolve_to_actual_model_without_losing_requested_model(): void
    {
        config(['ai_providers.default' => 'scripted', 'ai_providers.providers.scripted.mode' => 'resolved-model']);
        $result = app(AiService::class)->analyze('job_offers', 'Prompt', ['model' => 'latest-alias']);
        $run = AiRun::query()->where('request_id', $result->requestId)->firstOrFail();
        $this->assertSame('actual-model-version', $result->model);
        $this->assertSame('actual-model-version', $run->model);
        $this->assertSame('latest-alias', $run->requested_model);
    }

    public function test_capabilities_are_checked_before_calling_driver(): void
    {
        config(['ai_providers.default' => 'scripted', 'ai_providers.providers.scripted.mode' => 'analyze-only']);
        $this->assertFailure('unsupported_operation');
        $result = app(AiService::class)->analyze('job_offers', 'Prompt');
        $this->assertSame('scripted', $result->provider);
        $this->assertDatabaseHas('ai_runs', ['operation' => 'analyze', 'status' => 'succeeded']);
    }

    public function test_untrusted_exception_messages_are_not_stored_or_exposed(): void
    {
        config(['ai_providers.default' => 'scripted', 'ai_providers.providers.scripted.mode' => 'error']);
        $exception = $this->assertFailure('provider_failed');
        $this->assertStringNotContainsString('secret-token', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString('secret-token', AiRun::query()->sole()->toJson());
    }

    public function test_unknown_and_disabled_providers_fail_instead_of_falling_back(): void
    {
        config(['ai_providers.default' => 'missing']);
        $this->assertFailure('unknown_provider');
        config(['ai_providers.default' => 'fake', 'ai_providers.providers.fake.enabled' => false]);
        $this->assertFailure('provider_disabled');
        $this->assertDatabaseCount('ai_runs', 2);
    }

    public function test_driver_must_implement_interface_and_database_cannot_select_php_classes(): void
    {
        config(['ai_providers.drivers.fake' => \stdClass::class]);
        $this->assertFailure('invalid_driver');
        $this->expectException(ValidationException::class);
        app(AiProviderManager::class)->configure(\stdClass::class);
    }

    public function test_invalid_requests_are_rejected_before_any_database_write(): void
    {
        foreach ([['category' => 'job_offers', 'prompt' => '   ', 'options' => []],
            ['category' => '../bad', 'prompt' => 'Prompt', 'options' => []],
            ['category' => 'job_offers', 'prompt' => 'Prompt', 'options' => ['api_key' => 'secret']]] as $input) {
            try {
                app(AiService::class)->search($input['category'], $input['prompt'], $input['options']);
                $this->fail('Invalid request accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('ai_runs', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_max_items_limits_fake_and_rejects_oversized_custom_responses(): void
    {
        config(['ai_providers.providers.fake.items' => [['title' => 'A'], ['title' => 'B']]]);
        $this->assertCount(1, app(AiService::class)->search('job_offers', 'Prompt', ['max_items' => 1])->items);
        config(['ai_providers.default' => 'scripted', 'ai_providers.providers.scripted.mode' => 'too-many']);
        try {
            app(AiService::class)->search('job_offers', 'Prompt', ['max_items' => 1]);
            $this->fail('Limit ignored.');
        } catch (AiProviderException $exception) {
            $this->assertSame('invalid_response', $exception->errorCode);
        }
    }

    public function test_ai_execution_preserves_existing_real_scores_and_user_preferences(): void
    {
        $data = ['title' => 'Prawdziwa oferta', 'aiMatchScore' => 87, 'cvSent' => true, 'myNotes' => 'Moja notatka'];
        $offer = Offer::query()->create(['id' => 'LOCAL_existing', 'external_key' => 'LOCAL_existing', 'data' => $data]);
        app(AiService::class)->analyze('job_offers', 'Nie zapisuj wyniku');
        $this->assertSame($data, $offer->fresh()->data);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('sync_outbox', 0);
    }

    private function assertFailure(string $code): AiProviderException
    {
        try {
            app(AiService::class)->search('job_offers', 'Prompt');
            $this->fail('Expected failure '.$code);
        } catch (AiProviderException $exception) {
            $this->assertSame($code, $exception->errorCode);

            return $exception;
        }
    }
}

/** Test-only custom driver proving the service does not depend on Cursor/Fake. */
class ScriptedProvider implements AiProviderInterface
{
    public function __construct(private readonly string $providerKey, private readonly array $configuration) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function name(): string
    {
        return 'Scripted test driver';
    }

    public function supports(): AiCapabilities
    {
        return new AiCapabilities(($this->configuration['mode'] ?? '') === 'analyze-only' ? ['analyze'] : ['search', 'analyze'], true);
    }

    public function search(string $category, string $prompt, array $options = []): AiResult
    {
        if (($this->configuration['mode'] ?? '') === 'analyze-only') {
            throw new \LogicException('Must not call unsupported operation.');
        }

        return $this->analyze($category, $prompt, $options);
    }

    public function analyze(string $category, string $prompt, array $options = []): AiResult
    {
        $mode = $this->configuration['mode'] ?? '';
        if ($mode === 'error') {
            throw new \RuntimeException('Authorization: secret-token; '.$prompt);
        }
        $items = [new AiItem('Test', confidence: $mode === 'invalid-score' ? 20 : null)];
        if ($mode === 'too-many') {
            $items[] = new AiItem('Test 2');
        }

        return new AiResult(
            $mode === 'wrong-provider' ? 'spoofed' : $this->key(),
            $mode === 'resolved-model' ? 'actual-model-version' : $options['model'],
            $mode === 'wrong-category' ? 'spoofed' : $category,
            CarbonImmutable::now('UTC'), $items,
        );
    }
}

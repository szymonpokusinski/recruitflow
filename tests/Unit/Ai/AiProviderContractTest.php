<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Integrations\Contracts\CredentialStore;
use App\Services\Ai\AiCredentials;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiResultMapper;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\DTO\AiResult;
use App\Services\Ai\Providers\CursorProvider;
use App\Services\Ai\Providers\FakeProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderContractTest extends TestCase
{
    public function test_fake_implements_both_operations_with_the_same_dto(): void
    {
        Http::preventStrayRequests();
        $provider = new FakeProvider('fixture_provider', [
            'model' => 'fixture-model',
            'items' => [['title' => 'Kontrolowany test', 'confidence' => 0, 'metadata' => ['synthetic' => false]]],
        ], app(AiResultMapper::class));
        $this->assertInstanceOf(AiProviderInterface::class, $provider);
        $this->assertSame('fixture_provider', $provider->key());
        $this->assertNotEmpty($provider->name());
        $this->assertTrue($provider->supports()->supports('search'));
        $this->assertFalse($provider->supports()->webSearch);
        foreach (['search', 'analyze'] as $operation) {
            $result = $provider->{$operation}('job_offers', 'fixture only');
            $this->assertInstanceOf(AiResult::class, $result);
            $this->assertSame('fixture-model', $result->model);
            $this->assertSame(0.0, $result->items[0]->confidence);
            $this->assertTrue($result->items[0]->metadata['synthetic']);
        }
        Http::assertNothingSent();
    }

    public function test_fake_without_fixtures_returns_no_generated_offers(): void
    {
        $provider = new FakeProvider('fake', [], app(AiResultMapper::class));
        $this->assertSame([], $provider->search('job_offers', 'Znajdź pracę')->items);
    }

    public function test_cursor_without_credentials_is_unavailable_and_makes_no_http_requests(): void
    {
        Http::preventStrayRequests();
        $store = $this->mock(CredentialStore::class);
        $store->shouldNotReceive('read');
        $provider = $this->app->makeWith(CursorProvider::class, ['providerKey' => 'cursor', 'configuration' => [], 'credentials' => new AiCredentials($store)]);
        $this->assertInstanceOf(AiProviderInterface::class, $provider);
        $this->assertFalse($provider->supports()->available);
        $this->assertFalse($provider->supports()->supports('search'));
        foreach (['search', 'analyze'] as $operation) {
            try {
                $provider->{$operation}('job_offers', 'Prompt');
                $this->fail('Missing credentials must not fabricate success.');
            } catch (AiProviderException $exception) {
                $this->assertSame('missing_credentials', $exception->errorCode);
            }
        }
        Http::assertNothingSent();
    }

    public function test_cursor_with_credentials_declares_capabilities_without_starting_a_run(): void
    {
        Http::preventStrayRequests();
        $provider = $this->app->makeWith(CursorProvider::class, ['providerKey' => 'cursor', 'configuration' => ['api_key' => 'test-not-a-real-key']]);
        $this->assertNull($provider->supports()->unavailableReason);
        $this->assertTrue($provider->supports()->supports('search'));
        $this->assertTrue($provider->supports()->supports('analyze'));
        $this->assertTrue($provider->supports()->webSearch);
        Http::assertNothingSent();
    }

    public function test_keychain_reference_takes_precedence_over_environment_key(): void
    {
        $store = $this->mock(CredentialStore::class);
        $store->shouldReceive('read')->once()->with('ai-cursor-test')->andReturn(['api_key' => 'secure-test-key']);
        $this->assertSame('secure-test-key', (new AiCredentials($store))->apiKey([
            'credential_ref' => 'ai-cursor-test', 'api_key' => 'environment-test-key',
        ]));
    }

    public function test_vault_failure_does_not_fall_back_or_leak_error_details(): void
    {
        $store = $this->mock(CredentialStore::class);
        $store->shouldReceive('read')->once()->andThrow(new \RuntimeException('secret-in-error'));
        try {
            (new AiCredentials($store))->apiKey(['credential_ref' => 'ai-cursor-test', 'api_key' => 'fallback-key']);
            $this->fail('Vault failure ignored.');
        } catch (AiProviderException $exception) {
            $this->assertSame('credential_store_error', $exception->errorCode);
            $this->assertStringNotContainsString('secret-in-error', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_driver_registry_supports_another_provider_key_without_cursor_logic(): void
    {
        config(['ai_providers.default' => 'future_provider', 'ai_providers.providers.future_provider' => [
            'driver' => 'fake', 'enabled' => true, 'model' => 'future-model', 'items' => [],
        ]]);
        $provider = app(AiProviderManager::class)->resolve();
        $this->assertSame('future_provider', $provider->key());
        $this->assertSame('future-model', $provider->analyze('general', 'Prompt')->model);
    }
}

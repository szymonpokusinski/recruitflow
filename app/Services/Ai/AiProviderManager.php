<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Ai\AiProviderSetting;
use App\Services\Ai\Contracts\AiProviderInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class AiProviderManager
{
    public function __construct(private readonly Container $container) {}

    /** One immutable selection per run. No provider instances cached across runs. */
    public function selection(): array
    {
        $setting = Schema::hasTable('ai_provider_settings')
            ? AiProviderSetting::query()->find('default') : null;

        $selection = [
            'provider' => $setting?->provider ?? config('ai_providers.default'),
            'model' => $setting?->model,
            'credential_ref' => $setting?->credential_ref,
        ];
        $this->validateSelection($selection);

        return $selection;
    }

    public function resolve(?string $key = null): AiProviderInterface
    {
        return $this->resolveSelection($this->selectionFor($key));
    }

    public function selectionFor(?string $key = null): array
    {
        $selection = $this->selection();
        if ($key !== null && $key !== $selection['provider']) {
            $selection = ['provider' => $key, 'model' => null, 'credential_ref' => null];
        }

        $this->validateSelection($selection);

        return $selection;
    }

    public function resolveSelection(array $selection): AiProviderInterface
    {
        $this->validateSelection($selection);
        $key = $selection['provider'];
        $configuration = $this->providerConfiguration($key);
        foreach (['model', 'credential_ref'] as $field) {
            if (($selection[$field] ?? null) !== null) {
                $configuration[$field] = $selection[$field];
            }
        }
        if (! is_string($configuration['driver'] ?? null)) {
            throw new AiProviderException('invalid_driver');
        }
        $driver = config('ai_providers.drivers.'.$configuration['driver']);
        if (! is_string($driver) || ! is_a($driver, AiProviderInterface::class, true)) {
            throw new AiProviderException('invalid_driver');
        }

        return $this->container->makeWith($driver, ['providerKey' => $key, 'configuration' => $configuration]);
    }

    public function configure(string $provider, ?string $model = null, ?string $credentialRef = null): void
    {
        $selection = ['provider' => $provider, 'model' => $model, 'credential_ref' => $credentialRef];
        $this->validateSelection($selection);
        $this->providerConfiguration($provider);
        AiProviderSetting::query()->updateOrCreate(['id' => 'default'], $selection);
    }

    private function providerConfiguration(string $key): array
    {
        $configuration = config('ai_providers.providers.'.$key);
        if (! is_array($configuration)) {
            throw new AiProviderException('unknown_provider');
        }
        if (! ($configuration['enabled'] ?? false)) {
            throw new AiProviderException('provider_disabled');
        }

        return $configuration;
    }

    private function validateSelection(array $selection): void
    {
        Validator::make($selection, [
            'provider' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'model' => ['nullable', ...(($selection['model'] ?? null) !== null ? ['required'] : []), 'string', 'max:100', 'regex:/\S/u'],
            'credential_ref' => ['nullable', ...(($selection['credential_ref'] ?? null) !== null ? ['required'] : []), 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/'],
        ])->validate();
    }
}

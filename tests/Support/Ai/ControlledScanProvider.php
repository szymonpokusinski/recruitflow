<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiResultMapper;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\DTO\AiCapabilities;
use App\Services\Ai\DTO\AiItem;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;

class ControlledScanProvider implements AiProviderInterface
{
    public static array $calls = [];

    public function __construct(private readonly string $providerKey, private readonly array $configuration, private readonly AiResultMapper $mapper) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function name(): string
    {
        return 'Controlled scan test';
    }

    public function supports(): AiCapabilities
    {
        return new AiCapabilities(['search', 'analyze'], true);
    }

    public function search(string $category, string $prompt, array $options = []): AiResult
    {
        self::$calls[] = ['category' => $category, 'prompt' => $prompt, 'options' => $options, 'provider' => $this->providerKey];
        if (isset($this->configuration['callback'])) {
            ($this->configuration['callback'])();
        }
        if (($this->configuration['mode'] ?? '') === 'error') {
            throw new \RuntimeException('private-error-token');
        }
        if (($this->configuration['mode'] ?? '') === 'provider-error') {
            throw new AiProviderException('provider_failed');
        }
        if (($this->configuration['mode'] ?? '') === 'invalid') {
            return new AiResult($this->key(), 'test-model', $category, CarbonImmutable::now('UTC'), [new AiItem('A', confidence: 99)]);
        }

        return $this->mapper->map([
            'provider' => $this->key(), 'model' => $options['model'] ?? 'test-model', 'category' => $category,
            'requested_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'items' => $this->configuration['items'] ?? [],
        ]);
    }

    public function analyze(string $category, string $prompt, array $options = []): AiResult
    {
        return $this->search($category, $prompt, $options);
    }
}

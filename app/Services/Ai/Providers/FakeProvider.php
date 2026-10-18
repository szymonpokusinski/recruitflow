<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiResultMapper;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\DTO\AiCapabilities;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;

final class FakeProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $providerKey,
        private readonly array $configuration,
        private readonly AiResultMapper $mapper,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function name(): string
    {
        return 'Fake (test fixtures only)';
    }

    public function supports(): AiCapabilities
    {
        return new AiCapabilities(['search', 'analyze'], available: true);
    }

    public function search(string $category, string $prompt, array $options = []): AiResult
    {
        return $this->result($category, $options);
    }

    public function analyze(string $category, string $prompt, array $options = []): AiResult
    {
        return $this->result($category, $options);
    }

    private function result(string $category, array $options): AiResult
    {
        $result = $this->mapper->map([
            'provider' => $this->key(),
            'model' => $options['model'] ?? $this->configuration['model'] ?? 'fake-v1',
            'category' => $category,
            'requested_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'items' => $this->configuration['items'] ?? [],
        ]);
        // Synthetic provenance cannot be removed by a fixture supplied to the fake.
        $data = $result->toArray();
        $data['items'] = array_slice($data['items'], 0, $options['max_items'] ?? 100);
        foreach ($data['items'] as &$item) {
            $item['metadata']['synthetic'] = true;
        }
        unset($item);

        return $this->mapper->map($data);
    }
}

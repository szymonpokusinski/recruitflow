<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCredentials;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Cursor\CursorCloudClient;
use App\Services\Ai\Cursor\CursorPromptBuilder;
use App\Services\Ai\Cursor\CursorResultMapper;
use App\Services\Ai\DTO\AiCapabilities;
use App\Services\Ai\DTO\AiRequest;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CursorProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $providerKey,
        private readonly array $configuration,
        private readonly AiCredentials $credentials,
        private readonly CursorCloudClient $client,
        private readonly CursorPromptBuilder $prompts,
        private readonly CursorResultMapper $mapper,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function name(): string
    {
        return 'Cursor Cloud Agents';
    }

    public function supports(): AiCapabilities
    {
        $available = $this->credentials->apiKey($this->configuration, $this->configuration['model'] ?? null) !== null;

        return new AiCapabilities(
            operations: $available ? ['search', 'analyze'] : [], available: $available,
            webSearch: $available, structuredResults: true,
            unavailableReason: $available ? null : 'missing_credentials',
        );
    }

    public function search(string $category, string $prompt, array $options = []): AiResult
    {
        return $this->execute(AiRequest::make('search', $category, $prompt, $options));
    }

    public function analyze(string $category, string $prompt, array $options = []): AiResult
    {
        return $this->execute(AiRequest::make('analyze', $category, $prompt, $options));
    }

    private function execute(AiRequest $request): AiResult
    {
        try {
            Validator::make(['options' => $request->options], ['options' => ['array:filters,model,max_items']])->validate();
        } catch (ValidationException) {
            throw new AiProviderException('unsupported_options', phase: 'input');
        }
        $model = $request->options['model'] ?? $this->configuration['model'] ?? null;
        $key = $this->credentials->apiKey($this->configuration, $model);
        if ($key === null) {
            throw new AiProviderException('missing_credentials');
        }
        $requestedAt = CarbonImmutable::now('UTC');
        $run = $this->client->run($key, $this->prompts->build($request), $model, $this->configuration);
        $result = $this->mapper->map($run['result'], $this->key(), $run['model'], $request->category, $requestedAt, $run['context']);
        if (count($result->items) > ($request->options['max_items'] ?? 100)) {
            throw new AiProviderException('invalid_response', $result->requestId, phase: 'run', context: $run['context']);
        }

        return $result;
    }
}

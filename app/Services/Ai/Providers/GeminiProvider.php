<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCredentials;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\DTO\AiCapabilities;
use App\Services\Ai\DTO\AiRequest;
use App\Services\Ai\DTO\AiResult;
use App\Services\Ai\Gemini\GeminiClient;
use App\Services\Ai\Gemini\GeminiPromptBuilder;
use App\Services\Ai\Gemini\GeminiResultMapper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class GeminiProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $providerKey,
        #[SensitiveParameter] private readonly array $configuration,
        private readonly AiCredentials $credentials,
        private readonly GeminiClient $client,
        private readonly GeminiPromptBuilder $prompts,
        private readonly GeminiResultMapper $mapper,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function name(): string
    {
        return 'Google Gemini';
    }

    public function supports(): AiCapabilities
    {
        $model = $this->model();
        $available = $this->credentials->apiKey($this->configuration, $model) !== null;

        return new AiCapabilities(
            operations: $available ? ['search', 'analyze'] : [], available: $available,
            webSearch: $available && $this->grounding($model), structuredResults: true,
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
        $model = $this->model($request->options['model'] ?? null);
        $grounding = $this->grounding($model);
        $key = $this->credentials->apiKey($this->configuration, $model);
        if ($key === null) {
            throw new AiProviderException('missing_credentials');
        }
        $requestedAt = CarbonImmutable::now('UTC');
        $response = $this->client->run($key, $this->prompts->build($request, $model, $grounding), $this->configuration);
        $result = $this->mapper->map($response, $this->key(), $request->category, $requestedAt, $model, $grounding);
        if (count($result->items) > ($request->options['max_items'] ?? 100)) {
            throw new AiProviderException('invalid_response', $result->requestId, 'run');
        }

        return $result;
    }

    private function model(?string $override = null): string
    {
        $model = $override ?? $this->configuration['model'] ?? 'gemini-3.8-flash';
        if (! is_string($model) || strlen($model) > 100 || ! preg_match('/^gemini-[a-zA-Z0-9_.-]+$/', $model)) {
            throw new AiProviderException('invalid_configuration');
        }

        return $model;
    }

    private function grounding(string $model): bool
    {
        $enabled = $this->configuration['google_search_grounding'] ?? false;
        if (! is_bool($enabled)) {
            throw new AiProviderException('invalid_configuration');
        }
        // Structured output + built-in tools require Gemini 3 or newer.
        if ($enabled && preg_match('/^gemini-(\d+)/', $model, $match) && (int) $match[1] < 3) {
            throw new AiProviderException('unsupported_options', phase: 'input');
        }

        return $enabled;
    }
}

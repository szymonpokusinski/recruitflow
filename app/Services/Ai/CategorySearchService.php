<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\DTO\AiRequest;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Retrieval only. Does not use AiService's optional database run-history path. */
final class CategorySearchService
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiResultMapper $mapper,
    ) {}

    public function search(string|int $category, string $prompt, array $filters = [], array $options = [], ?string $provider = null): AiResult
    {
        return $this->execute('search', (string) $category, $prompt, $filters, $options, $provider);
    }

    public function analyze(string|int $category, string $prompt, array $filters = [], array $options = [], ?string $provider = null): AiResult
    {
        return $this->execute('analyze', (string) $category, $prompt, $filters, $options, $provider);
    }

    private function execute(string $operation, string $category, string $prompt, array $filters, array $options, ?string $providerOverride): AiResult
    {
        $request = AiRequest::make($operation, $category, $prompt, array_replace($options, ['filters' => $filters]));
        $selection = $this->providers->selectionFor($providerOverride);
        $selection['model'] = $request->options['model'] ?? $selection['model'];
        $requestedAt = CarbonImmutable::now('UTC');

        try {
            $provider = $this->providers->resolveSelection($selection);
            $capabilities = $provider->supports();
            if (! $capabilities->available) {
                throw new AiProviderException($capabilities->unavailableReason ?? 'unsupported_operation');
            }
            if (! $capabilities->supports($operation)) {
                throw new AiProviderException('unsupported_operation');
            }
            $result = $provider->{$operation}($category, $prompt, $request->options);
            $result = $this->mapper->map($result->toArray());
            if ($result->provider !== $selection['provider'] || $result->category !== $category
                || count($result->items) > ($options['max_items'] ?? 100)) {
                throw new AiProviderException('invalid_response', $result->requestId);
            }

            return new AiResult($result->provider, $result->model, $category, $requestedAt, $result->items, $result->requestId, $result->metadata);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiProviderException($exception instanceof ValidationException ? 'invalid_response' : 'provider_failed');
        }
    }
}

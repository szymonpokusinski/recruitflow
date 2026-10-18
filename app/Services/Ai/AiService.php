<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Ai\AiRun;
use App\Services\Ai\DTO\AiRequest;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AiService
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiResultMapper $mapper,
    ) {}

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
        $selection = $this->providers->selection();
        $selection['model'] = $request->options['model'] ?? $selection['model'];
        $started = hrtime(true);
        $requestedAt = CarbonImmutable::now('UTC');
        $requestedModel = $request->options['model'] ?? $selection['model'] ?? config('ai_providers.providers.'.$selection['provider'].'.model');
        $run = AiRun::query()->create([
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'provider' => $selection['provider'],
            'model' => $requestedModel, 'requested_model' => $requestedModel,
            'category' => $request->category, 'operation' => $request->operation,
            'status' => 'running', 'requested_at' => $requestedAt,
        ]);

        try {
            $provider = $this->providers->resolveSelection($selection);
            $capabilities = $provider->supports();
            if (! $capabilities->available) {
                throw new AiProviderException($capabilities->unavailableReason ?? 'unsupported_operation');
            }
            if (! $capabilities->supports($request->operation)) {
                throw new AiProviderException('unsupported_operation');
            }
            $options = $request->options;
            if ($run->requested_model !== null) {
                $options['model'] = $run->requested_model;
            }
            $result = $provider->{$request->operation}($request->category, $request->prompt, $options);
            // A typed DTO alone does not guarantee valid ranges or trusted identity.
            $result = $this->mapper->map($result->toArray());
            if ($result->provider !== $selection['provider'] || $result->category !== $request->category
                || count($result->items) > ($options['max_items'] ?? 100)) {
                throw new AiProviderException('invalid_response');
            }
            $result = new AiResult($result->provider, $result->model, $result->category, $requestedAt, $result->items, $run->request_id, $result->metadata);
            // Run history intentionally stores neither prompts nor provider payloads.
            $run->update([
                'status' => 'succeeded', 'model' => $result->model,
                'items_count' => count($result->items), 'finished_at' => CarbonImmutable::now('UTC'),
                'duration_ms' => $this->duration($started),
            ]);

            return $result;
        } catch (Throwable $exception) {
            $code = $exception instanceof AiProviderException ? $exception->errorCode
                : ($exception instanceof ValidationException ? 'invalid_response' : 'provider_failed');
            $safe = new AiProviderException(
                $code, $run->request_id,
                phase: $exception instanceof AiProviderException ? $exception->phase : null,
                httpStatus: $exception instanceof AiProviderException ? $exception->httpStatus : null,
                retryable: $exception instanceof AiProviderException && $exception->retryable,
            );
            $run->update([
                'status' => 'failed', 'finished_at' => CarbonImmutable::now('UTC'),
                'duration_ms' => $this->duration($started),
                'error_code' => $safe->errorCode, 'error' => $safe->getMessage(),
            ]);
            throw $safe;
        }
    }

    private function duration(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}

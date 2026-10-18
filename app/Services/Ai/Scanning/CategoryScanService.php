<?php

declare(strict_types=1);

namespace App\Services\Ai\Scanning;

use App\Models\Ai\AiCategory;
use App\Models\Ai\AiSearchRun;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiResultMapper;
use App\Services\Ai\CategorySearchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CategoryScanService
{
    public function __construct(
        private readonly CategorySearchService $search,
        private readonly AiProviderManager $providers,
        private readonly AiCategoryService $categories,
        private readonly AiResultMapper $mapper,
        private readonly FindingRepository $findings,
    ) {}

    public function scan(int $categoryId, ?string $provider = null, ?string $operationId = null): AiSearchRun
    {
        if (config('widgets.read_only_preview')) {
            throw new AiScanException('invalid_configuration');
        }
        [$run, $claimed] = $this->prepare($categoryId, $provider, $operationId);
        if (! $claimed) {
            return $run;
        }

        try {
            $category = AiCategory::query()->findOrFail($categoryId);
            if (! $category->is_active) {
                throw new AiScanException('category_inactive');
            }
            $this->categories->validateSettings($category->settings, $category->slug);
            $prompt = $category->activePrompt()->first();
            if ($prompt === null) {
                throw new AiScanException('prompt_missing');
            }
            $providerOverride = $run->meta['provider_override'] ?? $provider;
            $selection = $this->providers->selectionFor($providerOverride ?? $category->default_provider);
            $options = $category->settings['options'] ?? [];
            $requestedModel = $options['model'] ?? $selection['model'] ?? config('ai_providers.providers.'.$selection['provider'].'.model');
            $run->update([
                'prompt_id' => $prompt->id, 'provider' => $selection['provider'], 'model' => $requestedModel,
                'meta' => $run->meta + [
                    'prompt_id' => $prompt->id, 'prompt_name' => $prompt->name,
                    'prompt_version' => $prompt->version, 'prompt_hash' => hash('sha256', $prompt->prompt_text),
                    'requested_model' => $requestedModel,
                ],
            ]);
            // No transaction is held across a network request.
            $result = $this->search->search($category->slug, $prompt->prompt_text, $category->settings['filters'] ?? [], $options, $selection['provider']);
            $result = $this->mapper->map($result->toArray());
            if ($result->provider !== $selection['provider'] || $result->category !== $category->slug) {
                throw new AiProviderException('invalid_response', $result->requestId);
            }
            DB::transaction(function () use ($run, $result): void {
                $current = AiSearchRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'running' || $current->started_at->addSeconds(config('ai_scanning.running_lease_seconds'))->isPast()) {
                    throw new AiScanException('stale_run');
                }
                $counts = $this->findings->upsert($current, $result);
                $current->update([
                    'status' => 'succeeded', 'model' => $result->model, 'finished_at' => CarbonImmutable::now('UTC'),
                    'meta' => array_replace($current->meta, $counts, ['request_id' => $result->requestId, 'result_metadata' => $result->metadata]),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            $this->fail($run, $exception);
        }

        return $run->fresh();
    }

    /** Returns [run, newly created]. Queue dispatch uses the same durable claim. */
    public function enqueueRun(int $categoryId, ?string $provider = null, ?CarbonImmutable $scheduledFor = null): array
    {
        return $this->prepare($categoryId, $provider, queued: true, scheduledFor: $scheduledFor);
    }

    private function prepare(int $categoryId, ?string $provider, ?string $operationId = null, bool $queued = false, ?CarbonImmutable $scheduledFor = null): array
    {
        $id = $operationId ?? (string) Str::uuid();
        try {
            return DB::transaction(function () use ($categoryId, $provider, $id, $queued, $scheduledFor): array {
                $category = AiCategory::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
                $this->expireStale($categoryId);
                $existing = AiSearchRun::query()->whereKey($id)->first();
                if ($existing !== null) {
                    if ($existing->category_id !== $categoryId) {
                        throw new AiScanException('stale_run');
                    }
                    if (! $queued && $existing->status === 'queued') {
                        $claimed = AiSearchRun::query()->whereKey($id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => CarbonImmutable::now('UTC')]);

                        return [$existing->fresh(), $claimed === 1];
                    }

                    return [$existing, false];
                }
                if ($scheduledFor !== null) {
                    $slot = AiSearchRun::query()->where('category_id', $categoryId)->where('scheduled_for', $scheduledFor)->first();
                    if ($slot !== null) {
                        return [$slot, false];
                    }
                }
                $open = AiSearchRun::query()->where('category_id', $categoryId)->whereIn('status', ['queued', 'running'])->first();
                if ($queued && $open !== null) {
                    return [$open, false];
                }
                $skip = ! $category->is_active ? 'category_inactive' : ($open !== null ? 'category_busy' : null);
                $status = $skip !== null ? 'skipped' : ($queued ? 'queued' : 'running');
                $run = AiSearchRun::query()->create([
                    'id' => $id, 'category_id' => $categoryId, 'provider' => null, 'model' => null,
                    'status' => $status, 'scheduled_for' => $scheduledFor,
                    'started_at' => $status === 'running' ? CarbonImmutable::now('UTC') : null,
                    'finished_at' => $skip !== null ? CarbonImmutable::now('UTC') : null,
                    'error' => $skip !== null ? (new AiScanException($skip))->getMessage() : null,
                    'meta' => $skip !== null ? ['error_code' => $skip, 'provider_override' => $provider] : ['provider_override' => $provider],
                ]);

                return [$run, $skip === null];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $existing = AiSearchRun::query()->where('category_id', $categoryId)
                ->where(function ($query) use ($id, $scheduledFor) {
                    $query->where('id', $id)->orWhereIn('status', ['queued', 'running']);
                    if ($scheduledFor !== null) {
                        $query->orWhere('scheduled_for', $scheduledFor);
                    }
                })->firstOrFail();

            return [$existing, false];
        }
    }

    private function expireStale(int $categoryId): void
    {
        $now = CarbonImmutable::now('UTC');
        $stale = AiSearchRun::query()->where('category_id', $categoryId)->where(function ($query) use ($now) {
            $query->where(fn ($running) => $running->where('status', 'running')->where('started_at', '<=', $now->subSeconds(config('ai_scanning.running_lease_seconds'))))
                ->orWhere(fn ($queued) => $queued->where('status', 'queued')->where('created_at', '<=', $now->subSeconds(config('ai_scanning.queued_lease_seconds'))));
        })->get();
        foreach ($stale as $run) {
            $run->update(['status' => 'failed', 'finished_at' => $now, 'error' => (new AiScanException('interrupted'))->getMessage(), 'meta' => array_replace($run->meta, ['error_code' => 'interrupted'])]);
        }
    }

    private function fail(AiSearchRun $run, Throwable $exception): void
    {
        $current = $run->fresh();
        if ($current->status !== 'running') {
            return;
        }
        $code = match (true) {
            $exception instanceof AiProviderException => $exception->errorCode,
            $exception instanceof AiScanException => $exception->reason,
            $exception instanceof ValidationException => 'invalid_configuration',
            default => 'scan_failed',
        };
        $safe = $exception instanceof AiProviderException ? new AiProviderException($code) : new AiScanException($code);
        AiSearchRun::query()->whereKey($run->id)->where('status', 'running')->update([
            'status' => 'failed', 'finished_at' => CarbonImmutable::now('UTC'), 'error' => $safe->getMessage(),
            'meta' => json_encode(array_replace($current->meta, [
                'error_code' => $code,
                'phase' => $exception instanceof AiProviderException ? $exception->phase : null,
                'request_id' => $exception instanceof AiProviderException ? $exception->requestId : null,
                'http_status' => $exception instanceof AiProviderException ? $exception->httpStatus : null,
                'retryable' => $exception instanceof AiProviderException && $exception->retryable,
            ]), JSON_THROW_ON_ERROR),
        ]);
    }
}

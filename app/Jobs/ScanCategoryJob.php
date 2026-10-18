<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ai\AiSearchRun;
use App\Services\Ai\Scanning\CategoryScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScanCategoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 930;

    public string $runId;

    public function __construct(public int $categoryId, public ?string $provider = null, ?string $runId = null)
    {
        // Serialized stable ID makes replay of this job a no-op after completion.
        $this->runId = $runId ?? (string) Str::uuid();
        $this->onQueue(config('ai_scanning.queue', 'ai-scans'));
    }

    public function handle(CategoryScanService $scans): void
    {
        try {
            $run = $scans->scan($this->categoryId, $this->provider, $this->runId);
            if ($run->status === 'failed') {
                Log::warning('AI category scan failed', ['category_id' => $this->categoryId, 'run_id' => $run->id, 'reason' => $run->meta['error_code'] ?? 'scan_failed']);
            }
        } catch (Throwable) {
            // A deleted category / unavailable local DB does not stop other jobs.
            Log::warning('AI category job could not execute', ['category_id' => $this->categoryId, 'run_id' => $this->runId, 'reason' => 'local_error']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AiSearchRun::query()->find($this->runId);
        if ($run !== null && in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Worker skanowania został przerwany.', 'meta' => array_replace($run->meta, ['error_code' => 'interrupted'])]);
        }
    }
}

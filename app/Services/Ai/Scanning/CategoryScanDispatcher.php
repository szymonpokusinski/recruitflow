<?php

declare(strict_types=1);

namespace App\Services\Ai\Scanning;

use App\Jobs\ScanCategoryJob;
use App\Models\Ai\AiSearchRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class CategoryScanDispatcher
{
    public function __construct(private readonly CategoryScanService $scans) {}

    public function dispatch(int $categoryId, ?string $provider = null, ?CarbonImmutable $scheduledFor = null): AiSearchRun
    {
        // In NativePHP, the run and database queue share the same local SQLite.
        // Commit both together: a crash cannot leave a claim without its job.
        $queueConnection = config('queue.connections.database.connection');
        if (Queue::getDefaultDriver() === 'database' && ($queueConnection === null || $queueConnection === config('database.default'))) {
            return DB::transaction(fn () => $this->enqueueAndDispatch($categoryId, $provider, $scheduledFor), 3);
        }

        return $this->enqueueAndDispatch($categoryId, $provider, $scheduledFor);
    }

    private function enqueueAndDispatch(int $categoryId, ?string $provider, ?CarbonImmutable $scheduledFor): AiSearchRun
    {
        [$run, $created] = $this->scans->enqueueRun($categoryId, $provider, $scheduledFor);
        if ($created && $run->status === 'queued') {
            try {
                // Database job is inserted before commit; sync mode holds no outer tx.
                Bus::dispatch((new ScanCategoryJob($categoryId, $provider, $run->id))->beforeCommit());
            } catch (Throwable) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Nie udało się dodać skanu do kolejki.', 'meta' => array_replace($run->meta, ['error_code' => 'queue_error'])]);
            }
        }

        return $run->fresh();
    }
}

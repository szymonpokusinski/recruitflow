<?php

namespace App\Jobs;

use App\Integrations\IntegrationException;
use App\Integrations\OutboxSynchronizer;
use App\Integrations\SyncService;
use App\Models\Integrations\DataSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SynchronizeRecruitment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 360;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public bool $read = false)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'recruitment-sync';
    }

    public function handle(OutboxSynchronizer $outbox, SyncService $sync): void
    {
        $outbox->push();
        foreach (DataSource::where('enabled', true)->get() as $source) {
            $last = $source->runs()->latest('started_at')->first()?->started_at;
            if (! $this->read && $last && $last->copy()->addMinutes($source->sync_interval_minutes)->isFuture()) {
                continue;
            }
            try {
                $sync->sync($source, 'background');
            } catch (\Throwable $error) {
                Log::warning('Background recruitment read failed', ['source_id' => $source->id, 'reason' => $error instanceof IntegrationException ? $error->reason : 'local_error']);
            }
        }
    }
}

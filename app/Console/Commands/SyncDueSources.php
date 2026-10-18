<?php

namespace App\Console\Commands;

use App\Integrations\SyncService;
use App\Models\Integrations\DataSource;
use Illuminate\Console\Command;

class SyncDueSources extends Command
{
    protected $signature = 'integration:sync-due';

    protected $description = 'Synchronize enabled sources according to their local intervals';

    public function handle(SyncService $service): int
    {
        foreach (DataSource::where('enabled', true)->get() as $source) {
            $last = $source->runs()->latest('started_at')->first()?->started_at;
            if ($last && $last->addMinutes($source->sync_interval_minutes)->isFuture()) {
                continue;
            }
            try {
                $service->sync($source, 'scheduled');
            } catch (\Throwable) { /* SyncService records sanitized errors; the previous snapshot survives. */
            }
        }

        return self::SUCCESS;
    }
}

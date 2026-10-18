<?php

namespace App\Jobs;

use App\Integrations\IntegrationException;
use App\Integrations\SyncService;
use App\Models\Integrations\DataSource;
use App\Models\Recruitment\RecruitmentSetting;
use App\Widgets\WidgetSnapshotPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** A widget refresh reads connected sources; it never pushes the write outbox. */
class RefreshWidgetData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 360;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'widget-refresh';
    }

    public function handle(SyncService $sync, WidgetSnapshotPublisher $publisher): void
    {
        if (config('widgets.read_only_preview')) {
            return;
        }
        $this->status('running');
        $publisher->publish();
        $errors = 0;
        foreach (DataSource::where('enabled', true)->get() as $source) {
            try {
                $sync->sync($source, 'widget');
            } catch (\Throwable $error) {
                $errors++;
                Log::warning('Widget refresh failed', ['source_id' => $source->id,
                    'reason' => $error instanceof IntegrationException ? $error->reason : 'local_error']);
            }
        }
        $this->status($errors ? 'failed' : 'succeeded');
        $publisher->publish();
    }

    private function status(string $status): void
    {
        RecruitmentSetting::updateOrCreate(['key' => 'widget_refresh'], ['value' => [
            'status' => $status, 'at' => now()->toIso8601String(),
        ]]);
    }
}

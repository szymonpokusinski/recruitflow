<?php

namespace App\Widgets;

use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Shell;

final class WidgetSnapshotPublisher
{
    private bool $dirty = false;

    public function directory(): string
    {
        return config('widgets.snapshot_directory') ?: storage_path('app/private/widgets/'.(config('nativephp-internal.running') ? 'native' : 'herd'));
    }

    public function queue(): void
    {
        if (config('widgets.enabled')) {
            $this->dirty = true;
        }
    }

    public function flush(): void
    {
        if ($this->dirty) {
            $this->dirty = false;
            $this->publish();
        }
    }

    public function publish(): ?array
    {
        if (! config('widgets.enabled')) {
            return null;
        }
        $directory = $this->directory();
        $temporary = null;
        $lock = null;
        try {
            if (is_link($directory)) {
                throw new \RuntimeException('Unsafe snapshot directory');
            }
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Snapshot directory unavailable');
            }
            chmod($directory, 0700);
            $lock = fopen($directory.'/.publish.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Snapshot lock unavailable');
            }
            chmod($directory.'/.publish.lock', 0600);
            $snapshot = app(WidgetDashboardService::class)->snapshot();
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > 2_000_000) {
                throw new \RuntimeException('Snapshot exceeds size limit');
            }
            $temporary = tempnam($directory, '.snapshot-');
            if (! $temporary || file_put_contents($temporary, $json) !== strlen($json)) {
                throw new \RuntimeException('Snapshot write failed');
            }
            chmod($temporary, 0600);
            if (! rename($temporary, $directory.'/'.config('widgets.snapshot_name'))) {
                throw new \RuntimeException('Snapshot replace failed');
            }
            $temporary = null;
            if (config('widgets.companion_enabled') && config('nativephp-internal.running')) {
                try {
                    Shell::openExternal('recruitflow-widgets://refresh');
                } catch (\Throwable) {
                    Log::notice('Widget companion notification unavailable');
                }
            }

            return $snapshot;
        } catch (\Throwable $exception) {
            // A widget export failure never rolls back recruitment data or deletes the old snapshot.
            Log::warning('Widget snapshot publication failed', ['error_type' => $exception::class]);

            return null;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}

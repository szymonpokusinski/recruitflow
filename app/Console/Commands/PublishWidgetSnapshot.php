<?php

namespace App\Console\Commands;

use App\Widgets\WidgetSnapshotPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class PublishWidgetSnapshot extends Command
{
    protected $signature = 'widgets:publish {--native-cache : Read the existing development NativePHP SQLite in read-only mode}';

    protected $description = 'Prepare the private versioned WidgetKit snapshot from local SQLite';

    public function handle(WidgetSnapshotPublisher $publisher): int
    {
        if ($this->option('native-cache')) {
            $path = database_path('nativephp.sqlite');
            if (! is_file($path)) {
                $this->error('Lokalna baza NativePHP nie istnieje. Nie utworzono nowej bazy.');

                return self::FAILURE;
            }
            config(['database.connections.widget_readonly' => ['driver' => 'sqlite', 'database' => $path,
                'prefix' => '', 'options' => [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]],
                'database.default' => 'widget_readonly', 'widgets.snapshot_directory' => storage_path('app/private/widgets/native')]);
            DB::purge('widget_readonly');
        }
        $snapshot = $publisher->publish();
        if ($snapshot === null) {
            $this->error('Nie opublikowano snapshotu. Sprawdź konfigurację i lokalny log.');

            return self::FAILURE;
        }
        $this->info('Snapshot v'.$snapshot['schema_version'].'; ofert: '.$snapshot['totalOffers']);
        $this->line($publisher->directory().'/'.config('widgets.snapshot_name'));

        return self::SUCCESS;
    }
}

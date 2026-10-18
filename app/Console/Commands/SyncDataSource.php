<?php

namespace App\Console\Commands;

use App\Integrations\AutoConnectService;
use App\Integrations\DataSourceManager;
use App\Integrations\IntegrationException;
use App\Models\Integrations\DataSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDataSource extends Command
{
    protected $signature = 'integration:sync-source {source?} {--native : Use the local NativePHP development SQLite} {--inspect : Read metadata and headers only}';

    protected $description = 'Inspect or automatically connect a configured data source without changing Google Sheets';

    public function handle(DataSourceManager $manager): int
    {
        if ($this->option('native')) {
            config(['database.connections.sqlite.database' => database_path('nativephp.sqlite')]);
            DB::purge('sqlite');
        }
        $source = $this->argument('source') ? DataSource::findOrFail($this->argument('source')) : DataSource::firstOrFail();
        try {
            if ($this->option('inspect')) {
                $adapter = $manager->adapter($source);
                $metadata = $adapter->metadata($source);
                $this->line(json_encode(['title' => $metadata['title'], 'timezone' => $metadata['timezone'], 'tabs' => array_column($metadata['sheets'], 'title')], JSON_UNESCAPED_UNICODE));
                foreach ($metadata['sheets'] as $sheet) {
                    if (! in_array($sheet['title'], ['OFERTY', 'APLIKACJE', 'ZDARZENIA_HR', 'PROFILE_SZUKANIA'], true)) {
                        continue;
                    }
                    $this->line(json_encode(['sheet' => $sheet['title'], 'headers' => $adapter->headers($source, $sheet['id'], 1)['columns']], JSON_UNESCAPED_UNICODE));
                }

                return self::SUCCESS;
            }
            $result = app(AutoConnectService::class)->connect($source);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (IntegrationException $error) {
            $this->error($error->reason.': '.$error->getMessage());

            return self::FAILURE;
        }
    }
}

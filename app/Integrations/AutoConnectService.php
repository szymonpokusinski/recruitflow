<?php

namespace App\Integrations;

use App\Integrations\Mapping\RecruitFlowSchemaDetector;
use App\Models\Integrations\DataSource;
use Illuminate\Support\Facades\Log;

class AutoConnectService
{
    public function __construct(private DataSourceManager $manager, private RecruitFlowSchemaDetector $detector, private SyncService $sync) {}

    public function connect(DataSource $source): array
    {
        try {
            $adapter = $this->manager->adapter($source);
            $metadata = $adapter->metadata($source);
            $plans = $this->detector->detect($source, $adapter, $metadata);
            $run = $this->sync->sync($source, 'automatic', true, false, $plans);
            if ($source->name === 'Google Sheets') {
                $source->update(['name' => $metadata['title']]);
            }
            $counts = $source->records()->selectRaw('collection, count(*) as total')->groupBy('collection')->pluck('total', 'collection')->all();

            return ['source_id' => $source->id, 'title' => $metadata['title'], 'status' => $run->status, 'records_count' => $run->records_count, 'counts' => $counts, 'schema' => collect($plans)->pluck('schema_profile')->unique()->values()->all()];
        } catch (IntegrationException $error) {
            $source->update(['last_error' => $error->getMessage()]);
            Log::warning('Automatic source recognition failed', ['source_id' => $source->id, 'reason' => $error->reason]);
            throw $error;
        }
    }
}

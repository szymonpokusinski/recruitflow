<?php

namespace App\Integrations;

use App\Integrations\Mapping\RecruitmentMapper;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;
use App\Models\Integrations\DataSourceFieldMapping;
use App\Models\Integrations\DataSourceRecord;
use App\Models\Integrations\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncService
{
    public function __construct(private DataSourceManager $manager, private RecruitmentMapper $mapper, private CanonicalCache $cache) {}

    private function prepare(DataSource $source, ?array $plans = null): array
    {
        $records = [];
        $configurations = $plans === null ? $source->configurations()->with('mappings')->where('role', '!=', 'other')->get() : collect($plans)->map(function ($plan) {
            $configuration = new DataSourceConfiguration(collect($plan)->except('mapping')->all());
            $configuration->setRelation('mappings', collect($plan['mapping'])->map(fn ($column, $field) => new DataSourceFieldMapping(['field' => $field, 'column_name' => $column]))->values());

            return $configuration;
        });
        if ($configurations->isEmpty()) {
            throw new IntegrationException('Przypisz przynajmniej jedną zakładkę do kolekcji danych.', 'mapping_missing');
        }
        foreach ($configurations as $configuration) {
            $records = [...$records, ...$this->mapper->map($configuration, $this->manager->adapter($source)->rows($source, $configuration))];
        }

        return $records;
    }

    public function test(DataSource $source): array
    {
        try {
            $metadata = $this->manager->adapter($source)->metadata($source);
            $count = $source->configurations()->exists() ? count($this->prepare($source)) : 0;
            $source->update(['last_tested_at' => now(), 'last_error' => null]);

            return ['title' => $metadata['title'], 'sheets_count' => count($metadata['sheets']), 'records_count' => $count, 'mapping_valid' => $source->configurations()->exists()];
        } catch (IntegrationException $e) {
            $source->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function sync(DataSource $source, string $trigger = 'manual', bool $activate = false, bool $primary = false, ?array $plans = null): SyncRun
    {
        $lock = Cache::lock('sync-source-'.$source->id, 1800);
        if (! $lock->get()) {
            throw new IntegrationException('To źródło jest już synchronizowane.', 'sync_running');
        }
        $run = null;
        try {
            $source->refresh();
            $run = SyncRun::create(['data_source_id' => $source->id, 'status' => 'running', 'trigger' => $trigger, 'started_at' => now()]);
            if (! $source->enabled && ! $activate) {
                throw new IntegrationException('Źródło jest wyłączone.', 'source_disabled');
            }
            $records = $this->prepare($source, $plans);
            // All network reads/validation happen before the transaction. Errors roll back the whole snapshot.
            DB::transaction(function () use ($source, $records, $activate, $run, $primary, $plans) {
                if ($plans !== null) {
                    $previousPrimary = $source->configurations()->where('is_primary', true)->pluck('role')->all();
                    $source->configurations()->delete();
                    foreach ($plans as $plan) {
                        $configuration = $source->configurations()->create([...collect($plan)->except('mapping')->all(), 'is_primary' => in_array($plan['role'], $previousPrimary, true)]);
                        foreach ($plan['mapping'] as $field => $column) {
                            $configuration->mappings()->create(['field' => $field, 'column_name' => $column]);
                        }
                    }
                }
                DataSourceRecord::where('data_source_id', $source->id)->delete();
                foreach (array_chunk($records, 100) as $chunk) {
                    DataSourceRecord::insert(array_map(fn ($record) => [
                        'data_source_id' => $source->id, 'external_key' => $record['external_key'],
                        'collection' => $record['collection'], 'data' => json_encode($record['data'], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ], $chunk));
                }
                if ($activate) {
                    $source->update(['enabled' => true]);
                }
                if ($primary) {
                    foreach ($source->configurations()->whereIn('role', ['offers', 'applications', 'events'])->get() as $configuration) {
                        DataSourceConfiguration::where('role', $configuration->role)->update(['is_primary' => false]);
                        $configuration->update(['is_primary' => true]);
                    }
                }
                $this->cache->rebuild();
                $source->update(['last_synced_at' => now(), 'last_error' => null]);
                $run->update(['status' => 'succeeded', 'records_count' => count($records), 'finished_at' => now()]);
            });

            return $run->fresh();
        } catch (\Throwable $error) {
            $exception = $error instanceof IntegrationException ? $error : new IntegrationException('Błąd lokalnej synchronizacji. Poprzedni cache zachowano.', 'local_error');
            $run?->update(['status' => 'failed', 'error_code' => $exception->reason, 'error' => $exception->getMessage(), 'finished_at' => now()]);
            $source->refresh()->update(['last_error' => $exception->getMessage()]);
            Log::warning('Integration sync failed', ['source_id' => $source->id, 'run_id' => $run?->id, 'reason' => $exception->reason]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}

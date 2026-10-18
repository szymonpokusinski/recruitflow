<?php

namespace App\Integrations;

use App\Integrations\Contracts\DataSourceAdapterInterface;
use App\Integrations\Google\GoogleSheetsAdapter;
use App\Integrations\Google\SpreadsheetIdentifier;
use App\Integrations\Mapping\MappingSchema;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;
use App\Models\Integrations\DataSourceFieldMapping;
use App\Models\Integrations\DataSourceRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DataSourceManager
{
    public function __construct(private GoogleSheetsAdapter $sheets, private CanonicalCache $cache) {}

    private function guarded(DataSource $source, callable $callback): mixed
    {
        $lock = Cache::lock('sync-source-'.$source->id, 1800);
        if (! $lock->get()) {
            throw new IntegrationException('Źródło jest synchronizowane lub edytowane. Spróbuj po zakończeniu operacji.', 'sync_running');
        }
        try {
            $source->refresh();

            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function adapter(DataSource $source): DataSourceAdapterInterface
    {
        return match ($source->type) {
            'google_sheets' => $this->sheets, default => throw new IntegrationException('Ten rodzaj źródła nie ma jeszcze adaptera.', 'adapter_missing')
        };
    }

    public function save(array $values, ?DataSource $source = null): DataSource
    {
        return $source ? $this->guarded($source, fn () => $this->saveConfiguration($values, $source)) : $this->saveConfiguration($values);
    }

    private function saveConfiguration(array $values, ?DataSource $source = null): DataSource
    {
        $externalId = SpreadsheetIdentifier::parse($values['spreadsheet']);

        return DB::transaction(function () use ($values, $source, $externalId) {
            if (DataSource::where('external_id', $externalId)->where('type', 'google_sheets')->when($source, fn ($query) => $query->where('id', '!=', $source->id))->exists()) {
                throw new IntegrationException('Ten arkusz jest już skonfigurowany. Edytuj istniejące źródło.', 'source_exists');
            }
            $source ??= new DataSource;
            $identityChanged = $source->exists && ($source->external_id !== $externalId || $source->integration_account_id !== $values['integration_account_id']);
            if ($identityChanged && $source->enabled) {
                throw new IntegrationException('Przed zmianą arkusza lub konta wyłącz źródło.', 'disable_first');
            }
            $source->fill(['name' => $values['name'], 'external_id' => $externalId, 'integration_account_id' => $values['integration_account_id'], 'sync_interval_minutes' => $values['sync_interval_minutes'], 'read_only' => $identityChanged ? true : ($source->exists ? $source->read_only : true), 'type' => 'google_sheets'])->save();
            if ($identityChanged) {
                $source->configurations()->delete();
                DataSourceRecord::where('data_source_id', $source->id)->delete();
                $source->update(['last_synced_at' => null, 'last_tested_at' => null, 'last_error' => null]);
            }

            return $source->fresh();
        });
    }

    public function saveMapping(DataSource $source, array $sheets): void
    {
        $this->guarded($source, function () use ($source, $sheets) {
            if ($source->enabled) {
                throw new IntegrationException('Wyłącz źródło przed zmianą mapowania. Zmień konfigurację, a następnie aktywuj źródło ponownie.', 'disable_first');
            }
            $roles = [];
            foreach ($sheets as &$sheet) {
                if (! array_key_exists($sheet['role'], MappingSchema::REQUIRED)) {
                    throw new IntegrationException('Nieznana rola zakładki.', 'mapping_invalid');
                }
                if ($sheet['role'] !== 'other' && in_array($sheet['role'], $roles, true)) {
                    throw new IntegrationException('Każdą kolekcję można przypisać do jednej zakładki w źródle.', 'mapping_invalid');
                }
                $roles[] = $sheet['role'];
                $available = $this->adapter($source)->headers($source, $sheet['sheet_id'], $sheet['header_row']);
                $sheet['sheet_title'] = $available['title'];
                foreach ($sheet['mapping'] as $field => $column) {
                    if (! isset(MappingSchema::definitions()[$sheet['role']][$field]) || ! in_array($column, $available['columns'], true)) {
                        throw new IntegrationException('Mapowanie zawiera nieznane pole lub kolumnę.', 'mapping_invalid');
                    }
                }
                foreach (MappingSchema::required($sheet['role'], $sheet['schema_profile'] ?? 'generic') as $field) {
                    if (! isset($sheet['mapping'][$field]) || $sheet['mapping'][$field] === '') {
                        throw new IntegrationException('Brak mapowania wymaganego pola '.$field.'.', 'mapping_invalid');
                    }
                }
                if (count(array_unique($sheet['mapping'])) !== count($sheet['mapping'])) {
                    throw new IntegrationException('Kolumna nie może być przypisana do kilku pól.', 'mapping_invalid');
                }
            }
            unset($sheet);
            DB::transaction(function () use ($source, $sheets) {
                $source->configurations()->delete();
                foreach ($sheets as $sheet) {
                    $configuration = $source->configurations()->create(['sheet_id' => $sheet['sheet_id'], 'sheet_title' => $sheet['sheet_title'], 'role' => $sheet['role'], 'header_row' => $sheet['header_row'], 'is_primary' => false, 'schema_profile' => $sheet['schema_profile'] ?? 'generic']);
                    foreach ($sheet['mapping'] as $field => $column) {
                        DataSourceFieldMapping::create(['data_source_configuration_id' => $configuration->id, 'field' => $field, 'column_name' => $column]);
                    }
                }
                $source->update(['last_tested_at' => null, 'last_error' => null]);
            });

        });
    }

    public function primary(DataSource $source, bool $primary): void
    {
        $this->guarded($source, function () use ($source, $primary) {
            if (! $source->enabled) {
                throw new IntegrationException('Najpierw aktywuj i zsynchronizuj źródło.', 'source_disabled');
            }
            DB::transaction(function () use ($source, $primary) {
                foreach ($source->configurations()->whereIn('role', ['offers', 'applications', 'events'])->get() as $configuration) {
                    if ($primary) {
                        DataSourceConfiguration::where('role', $configuration->role)->update(['is_primary' => false]);
                    }
                    $configuration->update(['is_primary' => $primary]);
                }
                $this->cache->rebuild();
            });

        });
    }

    public function disable(DataSource $source): void
    {
        $this->guarded($source, function () use ($source) {
            DB::transaction(function () use ($source) {
                $source->update(['enabled' => false]);
                $source->configurations()->update(['is_primary' => false]);
                $this->cache->rebuild();
            });

        });
    }

    public function delete(DataSource $source): void
    {
        $this->guarded($source, function () use ($source) {
            DB::transaction(function () use ($source) {
                $source->delete();
                $this->cache->rebuild();
            });

        });
    }
}

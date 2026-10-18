<?php

namespace App\Recruitment;

use App\Models\Integrations\DataSourceRecord;
use App\Models\Recruitment\SyncConflict;
use App\Models\Recruitment\SyncOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class LocalStateMerger
{
    public function persist(string $collection, DataSourceRecord $record, array $incoming): array
    {
        $model = EntityRegistry::MODELS[$collection];
        $entity = $model::find($incoming['id']);
        $overrides = $entity?->local_overrides ?? [];
        $before = $entity?->remote_baseline ?? [];
        $conflictFields = [];
        $ignored = ['id', 'externalKey', 'version', 'schemaProfile', 'completionStatus', 'createdAt', 'foundBy'];
        if ($collection === 'applications') {
            $ignored = [...$ignored, 'cvSent', 'cvSentAt', 'appliedAt', 'status', 'rawStatus', 'applicationStage'];
        }
        foreach ($overrides as $field => $local) {
            if (! in_array($field, $ignored, true) && array_key_exists($field, $before) && array_key_exists($field, $incoming) && $before[$field] != $incoming[$field] && $local != $incoming[$field]) {
                $conflictFields[$field] = ['base' => $before[$field], 'local' => $local, 'remote' => $incoming[$field]];
            }
        }
        if ($conflictFields && $entity) {
            $operation = SyncOperation::where('entity_type', $collection)->where('entity_id', $entity->id)->whereIn('status', ['pending', 'retry', 'local', 'conflict'])->latest('id')->first();
            $conflict = SyncConflict::where('entity_type', $collection)->where('entity_id', $entity->id)->where('source_id', $record->data_source_id)->where('status', 'open')->first();
            ($conflict ?? new SyncConflict)->fill(['id' => $conflict?->id ?? (string) Str::uuid(), 'operation_id' => $operation?->operation_id, 'entity_type' => $collection, 'entity_id' => $entity->id, 'source_id' => $record->data_source_id, 'fields' => array_replace($conflict?->fields ?? [], $conflictFields), 'status' => 'open'])->save();
            $operation?->update(['status' => 'conflict', 'error_code' => 'field_conflict', 'last_error' => 'Lokalne i zdalne wartości wymagają porównania.']);
        }
        $data = array_replace($incoming, $overrides);
        if ($collection === 'offers') {
            $dates = [];
            foreach ([$entity?->data['firstDetectedAt'] ?? null, $entity?->data['createdAt'] ?? null,
                $incoming['createdAt'] ?? null, $incoming['foundAt'] ?? null,
                $incoming['extraData']['first_seen'] ?? null, $incoming['extraData']['first_found_at'] ?? null] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    try {
                        $date = CarbonImmutable::parse($value, config('app.timezone'));
                        if ($date->year >= 2000 && $date->year <= 2100 && $date <= now()) {
                            $dates[] = $date;
                        }
                    } catch (\Throwable) {
                        // Unknown source dates stay unknown.
                    }
                }
            }
            if ($entity && $dates !== []) {
                $dates[] = CarbonImmutable::instance($entity->created_at);
            }
            usort($dates, fn ($a, $b) => $a <=> $b);
            $data['firstDetectedAt'] = isset($dates[0]) ? $dates[0]->toIso8601String() : null;
        }
        $data['version'] = $entity?->data['version'] ?? 0;
        $data['remoteMissing'] = false;
        $model::updateOrCreate(['id' => $incoming['id']], ['external_key' => $entity?->external_key ?? $record->external_key, 'data' => $data, 'origin' => $entity?->origin ?? 'google_sheets', 'source_id' => $record->data_source_id, 'remote_baseline' => $incoming]);

        return $data;
    }
}

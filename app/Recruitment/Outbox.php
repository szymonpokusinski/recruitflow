<?php

namespace App\Recruitment;

use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;
use App\Models\Recruitment\SyncOperation;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class Outbox
{
    public function target(string $collection, ?string $sourceId = null): ?DataSource
    {
        $role = $collection === 'history' ? 'offers' : $collection;

        return DataSourceConfiguration::when($sourceId, fn ($query) => $query->where('data_source_id', $sourceId))->where('role', $role)->where('is_primary', true)->whereHas('source', fn ($query) => $query->where('enabled', true)->where('read_only', false))->first()?->source;
    }

    public function append(string $commandId, string $collection, Model $entity, array $payload, array $before, ?string $offerKey): SyncOperation
    {
        $source = $collection === 'profiles' || ($collection === 'history' && ! $offerKey) ? null : $this->target($collection, $entity->source_id);
        $base = [];
        foreach ($payload as $field => $value) {
            $base[$field] = $before[$field] ?? null;
        }

        return SyncOperation::create([
            'operation_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'recruitflow/command/'.$commandId.'/'.$collection.'/'.$entity->id)->toString(),
            'command_id' => $commandId, 'entity_type' => $collection, 'entity_id' => $entity->id,
            'offer_key' => $offerKey, 'payload' => $payload, 'base_values' => $base,
            'base_version' => hash('sha256', json_encode($base, JSON_THROW_ON_ERROR)), 'source_id' => $source?->id,
            'status' => $source && $source->enabled && ! $source->read_only ? 'pending' : 'local',
        ]);
    }
}

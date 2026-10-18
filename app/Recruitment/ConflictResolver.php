<?php

namespace App\Recruitment;

use App\Integrations\IntegrationException;
use App\Models\Recruitment\RecruitmentAudit;
use App\Models\Recruitment\SyncConflict;
use App\Models\Recruitment\SyncOperation;
use Ramsey\Uuid\Uuid;

class ConflictResolver
{
    public function __construct(private CommandRunner $commands, private Outbox $outbox, private LocalRecruitmentService $recruitment) {}

    public function resolve(SyncConflict $conflict, array $values): array
    {
        return $this->commands->run($values['operationId'], 'conflict.resolve', $values, function () use ($conflict, $values) {
            $conflict->refresh();
            if ($conflict->status !== 'open') {
                throw new IntegrationException('Ten konflikt został już rozstrzygnięty.', 'conflict_resolved');
            }
            $model = EntityRegistry::MODELS[$conflict->entity_type] ?? null;
            if (! $model) {
                throw new IntegrationException('Konflikt dziennika wymaga kontroli schematu, a nie edycji istniejącej historii.', 'history_immutable');
            }
            $entity = $model::findOrFail($conflict->entity_id);
            $overrides = $entity->local_overrides ?? [];
            $data = $entity->data;
            $local = [];
            $remoteBase = $entity->remote_baseline ?? [];
            foreach ($conflict->fields as $field => $versions) {
                $choice = $values['fields'][$field] ?? $values['choice'] ?? null;
                if (! in_array($choice, ['local', 'remote'], true)) {
                    throw new IntegrationException('Wybierz wersję dla każdego pola konfliktu.', 'resolution_incomplete');
                }
                if ($choice === 'remote') {
                    unset($overrides[$field]);
                    $data[$field] = $versions['remote'];
                } else {
                    $local[$field] = $versions['local'];
                    $data[$field] = $versions['local'];
                    $overrides[$field] = $versions['local'];
                }
                $remoteBase[$field] = $versions['remote'];
            }
            // Replace only affected fields in outstanding operations. Audit/history
            // operations remain append-only and are never erased by resolution.
            foreach (SyncOperation::where('entity_type', $conflict->entity_type)->where('entity_id', $entity->id)->whereNotIn('status', ['synced', 'superseded'])->get() as $operation) {
                $payload = array_diff_key($operation->payload, $conflict->fields);
                $operation->update(['payload' => $payload, 'status' => $payload ? ($this->outbox->target($operation->entity_type, $operation->source_id) ? 'pending' : 'local') : 'superseded', 'last_error' => null, 'error_code' => null]);
            }
            $entity->update(['data' => $data, 'local_overrides' => $overrides, 'remote_baseline' => $remoteBase]);
            if ($local) {
                $this->outbox->append($values['operationId'], $conflict->entity_type, $entity, $local, $remoteBase, $conflict->entity_type === 'offers' ? $entity->external_key : ($data['offerKey'] ?? null));
            }
            $conflict->update(['status' => 'resolved', 'resolved_at' => now()]);
            $entity->update(['data' => array_replace($entity->data, ['version' => ($entity->data['version'] ?? 0) + 1])]);
            $offerKey = $conflict->entity_type === 'offers' ? $entity->external_key : ($data['offerKey'] ?? null);
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'recruitflow/resolution/'.$values['operationId'])->toString();
            $audit = RecruitmentAudit::create(['id' => $id, 'command_id' => $values['operationId'], 'entity_type' => $conflict->entity_type, 'entity_id' => $entity->id, 'offer_key' => $offerKey, 'action' => 'Rozstrzygnięto konflikt pól', 'actor' => $values['actor'] ?? 'Użytkownik lokalny', 'changes' => $conflict->fields]);
            $audit->setAttribute('source_id', $entity->source_id);
            $this->outbox->append($values['operationId'], 'history', $audit, ['externalKey' => $id, 'offerKey' => $offerKey, 'occurredAt' => now()->toIso8601String(), 'field' => implode(', ', array_keys($conflict->fields)), 'oldValue' => json_encode($conflict->fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'newValue' => json_encode($values['fields'] ?? $values['choice'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'actor' => $audit->actor, 'source' => 'RecruitFlow Desktop', 'notes' => 'Rozstrzygnięto konflikt'], [], $offerKey);

            return ['id' => $entity->id];
        });
    }
}

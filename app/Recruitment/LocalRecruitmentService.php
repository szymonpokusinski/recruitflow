<?php

namespace App\Recruitment;

use App\Integrations\IntegrationException;
use App\Integrations\Mapping\RecruitmentMapper;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentAudit;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\SyncConflict;
use App\Models\Recruitment\SyncOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class LocalRecruitmentService
{
    public function __construct(private CommandRunner $commands, private Outbox $outbox) {}

    private function normalized(array $values): array
    {
        $data = [];
        foreach (OfferFields::DEFINITIONS as $field => $definition) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            $data[$field] = match ($definition[1]) {
                'number' => $value === null || $value === '' ? null : (float) $value,
                'boolean' => $value === null ? null : (bool) $value,
                'tags' => array_values(array_unique($value ?? [])),
                default => $value ?? (in_array($definition[1], ['date', 'datetime']) ? null : '')
            };
        }
        if (array_key_exists('portalStatus', $data)) {
            $data['status'] = RecruitmentMapper::status('offers', $data['portalStatus'] ?? '');
            $data['rawStatus'] = $data['portalStatus'];
        }
        if (array_key_exists('customAttributes', $values)) {
            $data['customAttributes'] = $values['customAttributes'];
        }
        if (array_key_exists('importDetails', $values)) {
            $data['importDetails'] = $values['importDetails'];
        }

        return $data;
    }

    private function patch(Model $entity, string $collection, array $changes, string $commandId, string $actor, string $action, ?string $offerKey): bool
    {
        $before = $entity->data ?? [];
        $changed = array_filter($changes, fn ($value, $field) => ! array_key_exists($field, $before) || $before[$field] !== $value, ARRAY_FILTER_USE_BOTH);
        if (! $changed) {
            return false;
        }
        $data = array_replace($before, $changed);
        $data['version'] = ($before['version'] ?? 0) + 1;
        $entity->update(['data' => $data, 'local_overrides' => array_replace($entity->local_overrides ?? [], $changed)]);
        $this->outbox->append($commandId, $collection, $entity, $changed, $before, $offerKey);
        foreach (SyncConflict::where('entity_type', $collection)->where('entity_id', $entity->id)->where('status', 'open')->get() as $conflict) {
            $fields = $conflict->fields;
            foreach ($changed as $field => $value) {
                if (isset($fields[$field])) {
                    $fields[$field]['local'] = $value;
                }
            }
            $conflict->update(['fields' => $fields]);
        }
        $auditId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'recruitflow/audit/'.$commandId.'/'.$collection.'/'.$entity->id)->toString();
        $differences = [];
        foreach ($changed as $field => $value) {
            $differences[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
        }
        $audit = RecruitmentAudit::create(['id' => $auditId, 'command_id' => $commandId, 'entity_type' => $collection, 'entity_id' => $entity->id, 'offer_key' => $offerKey, 'action' => $action, 'actor' => $actor, 'changes' => $differences]);
        $audit->setAttribute('source_id', $entity->source_id);
        $this->outbox->append($commandId, 'history', $audit, ['externalKey' => $auditId, 'occurredAt' => $audit->created_at->toIso8601String(), 'offerKey' => $offerKey, 'field' => implode(', ', array_keys($changed)), 'oldValue' => json_encode(array_intersect_key($before, $changed), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'newValue' => json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'actor' => $actor, 'source' => 'RecruitFlow Desktop', 'notes' => $action], [], $offerKey);

        return true;
    }

    public function createOffer(array $values): array
    {
        try {
            return $this->commands->run($values['operationId'], 'offer.create', $values, function () use ($values) {
                $data = array_replace(OfferFields::empty(), $this->normalized($values));
                $this->ensureUniqueUrl($data['offerUrl']);
                $pracujId = OfferIdentity::pracujId($data['offerUrl']);
                $key = $pracujId ? 'LOCAL_PRACUJ_'.$pracujId : 'LOCAL_'.$values['operationId'];
                $id = RecruitmentMapper::stableId('offers', $key);
                $offer = Offer::create(['id' => $id, 'external_key' => $key, 'origin' => 'local', 'data' => []]);
                $data += ['id' => $id, 'externalKey' => $key, 'schemaProfile' => 'recruitflow', 'createdAt' => now()->toIso8601String(), 'foundBy' => 'Ręcznie'];
                $data['completionStatus'] = empty($data['company']) || empty($data['title']) ? 'draft' : 'complete';
                $this->patch($offer, 'offers', $data, $values['operationId'], $values['actor'] ?? 'Użytkownik lokalny', 'Utworzono ofertę', $key);
                $offer->update(['local_overrides' => $this->normalized($values) + collect($data)->only(['id', 'externalKey', 'schemaProfile', 'createdAt', 'foundBy', 'completionStatus'])->all()]);

                return ['id' => $id, 'offer_key' => $key];
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'offers.external_key')) {
                throw ValidationException::withMessages(['offerUrl' => 'Ta oferta Pracuj.pl już istnieje. Otwórz istniejący rekord.']);
            }

            throw $exception;
        }
    }

    public function updateOffer(Offer $offer, array $values): array
    {
        return $this->commands->run($values['operationId'], 'offer.update', $values, function () use ($offer, $values) {
            $offer->refresh();
            if (isset($values['expectedVersion']) && $values['expectedVersion'] !== ($offer->data['version'] ?? 0)) {
                throw new IntegrationException('Oferta została zmieniona w innym widoku. Odśwież dane przed zapisem.', 'local_conflict');
            }
            $changes = $this->normalized($values);
            $this->ensureUniqueUrl($changes['offerUrl'] ?? $offer->data['offerUrl'] ?? '', $offer->id);
            $merged = array_replace($offer->data, $changes);
            if (($merged['salaryMin'] ?? null) !== null && ($merged['salaryMax'] ?? null) !== null && $merged['salaryMin'] > $merged['salaryMax']) {
                throw ValidationException::withMessages(['salaryMax' => 'Maksymalne wynagrodzenie nie może być mniejsze od minimalnego.']);
            }
            if (! empty($merged['publishedAt']) && ! empty($merged['applyDeadlineAt']) && strtotime($merged['applyDeadlineAt']) < strtotime($merged['publishedAt'])) {
                throw ValidationException::withMessages(['applyDeadlineAt' => 'Termin aplikowania nie może poprzedzać publikacji.']);
            }
            $changes['completionStatus'] = empty($merged['company']) || empty($merged['title']) ? 'draft' : 'complete';
            $this->patch($offer, 'offers', $changes, $values['operationId'], $values['actor'] ?? 'Użytkownik lokalny', 'Zmieniono ofertę / moje decyzje', $offer->external_key);

            return ['id' => $offer->id];
        });
    }

    private function ensureUniqueUrl(?string $url, ?string $exceptId = null): void
    {
        $identity = OfferIdentity::url($url);
        if (! $identity) {
            return;
        }
        foreach (Offer::where('id', '!=', $exceptId ?? '')->get() as $offer) {
            if (! isset($offer->data['mergedInto']) && OfferIdentity::url($offer->data['offerUrl'] ?? null) === $identity) {
                throw ValidationException::withMessages(['offerUrl' => 'Ta oferta już istnieje: '.($offer->data['title'] ?: 'Do uzupełnienia').'. Otwórz istniejący rekord.']);
            }
        }
    }

    private function application(Offer $offer, string $commandId, string $actor): Application
    {
        $existing = Application::where('data->offerId', $offer->id)->get()->first(fn ($application) => ! isset($application->data['mergedInto']));
        if ($existing) {
            return $existing;
        }
        $key = 'LOCAL_APP_'.$offer->external_key;
        $id = RecruitmentMapper::stableId('applications', $key);
        $application = Application::create(['id' => $id, 'external_key' => $key, 'origin' => 'local', 'source_id' => $offer->source_id, 'data' => []]);
        $data = ['id' => $id, 'externalKey' => $key, 'offerId' => $offer->id, 'offerKey' => $offer->external_key, 'schemaProfile' => 'recruitflow', 'candidateName' => '', 'candidateEmail' => '', 'candidateRole' => '', 'owner' => '', 'location' => '', 'source' => 'Ręcznie', 'appliedAt' => null, 'nextStep' => null, 'status' => RecruitmentMapper::status('applications', $offer->data['applicationStage'] ?? 'Nie aplikowano'), 'rawStatus' => $offer->data['applicationStage'] ?? 'Nie aplikowano'];
        // Creation and subsequent changes below are combined into one operation by persistApplication.
        $application->update(['data' => $data, 'local_overrides' => $data]);

        return $application;
    }

    private function persistApplication(Application $application, Offer $offer, array $changes, string $commandId, string $actor, string $action): void
    {
        $isNew = $application->origin === 'local' && ! SyncOperation::where('entity_type', 'applications')->where('entity_id', $application->id)->exists();
        if ($isNew) {
            $data = array_replace($application->data, $changes);
            $application->update(['data' => []]);
            $this->patch($application, 'applications', $data, $commandId, $actor, $action, $offer->external_key);
        } else {
            $this->patch($application, 'applications', $changes, $commandId, $actor, $action, $offer->external_key);
        }
    }

    public function markCv(Offer $offer, array $values): array
    {
        return $this->commands->run($values['operationId'], 'offer.cv', $values, function () use ($offer, $values) {
            $offer->refresh();
            $actor = $values['actor'] ?? 'Użytkownik lokalny';
            $sent = $values['sent'];
            $when = ! $sent ? null : (empty($values['time']) ? $values['date'] : CarbonImmutable::parse($values['date'].' '.$values['time'], $values['timezone'])->utc()->toIso8601String());
            $current = RecruitmentMapper::status('applications', $offer->data['applicationStage'] ?? 'Nie aplikowano');
            $stage = $sent ? RecruitmentStages::afterCv($current) : ($current === 'sent' ? 'not_applied' : $current);
            $label = RecruitmentStages::LABELS[$stage] ?? ($offer->data['applicationStage'] ?? 'Nie aplikowano');
            $changes = ['cvSent' => $sent, 'cvSentAt' => $when, 'cvSentPrecision' => empty($values['time']) ? 'date' : 'datetime', 'applicationStage' => $label];
            $changed = $this->patch($offer, 'offers', $changes, $values['operationId'], $actor, 'Oznaczenie / korekta wysłania CV', $offer->external_key);
            $application = $this->application($offer->fresh(), $values['operationId'], $actor);
            $appChanges = ['cvSent' => $sent, 'cvSentAt' => $when, 'appliedAt' => $when, 'status' => $stage, 'rawStatus' => $label, 'applicationStage' => $label, 'source' => $values['channel'] ?? '', 'cvVersion' => $values['cvVersion'] ?? '', 'coverLetterVersion' => $values['coverLetterVersion'] ?? '', 'reference' => $values['reference'] ?? ''];
            $this->persistApplication($application, $offer, $appChanges, $values['operationId'], $actor, 'Zapisano dane aplikacji');
            if ($changed || ! empty($values['note'])) {
                $this->event($offer, $application, ['eventType' => $sent ? 'Wysłano CV' : 'Zmiana statusu', 'eventSubtype' => 'CV_CORRECTION', 'occurredAt' => now()->toIso8601String(), 'message' => ($sent ? 'Oznaczono wysłanie CV: '.$when : 'Skorygowano oznaczenie wysłania CV.').(! empty($values['note']) ? "\n".$values['note'] : ''), 'channel' => $values['channel'] ?? 'Inny'], $values['operationId'], $actor);
            }

            return ['id' => $application->id, 'offerId' => $offer->id];
        });
    }

    public function changeStage(Offer $offer, array $values): array
    {
        return $this->commands->run($values['operationId'], 'offer.stage', $values, function () use ($offer, $values) {
            $offer->refresh();
            $actor = $values['actor'] ?? 'Użytkownik lokalny';
            $label = RecruitmentStages::LABELS[$values['stage']];
            $before = $offer->data['applicationStage'] ?? 'Nie aplikowano';
            $changed = $this->patch($offer, 'offers', ['applicationStage' => $label], $values['operationId'], $actor, 'Zmieniono etap rekrutacji', $offer->external_key);
            $application = $this->application($offer->fresh(), $values['operationId'], $actor);
            $this->persistApplication($application, $offer, ['status' => $values['stage'], 'rawStatus' => $label, 'applicationStage' => $label], $values['operationId'], $actor, 'Zmieniono etap aplikacji');
            if ($changed) {
                $this->event($offer, $application, ['eventType' => 'Zmiana statusu', 'occurredAt' => now()->toIso8601String(), 'message' => $before.' → '.$label.(! empty($values['note']) ? "\n".$values['note'] : ''), 'statusBefore' => $before, 'statusAfter' => $label], $values['operationId'], $actor);
            }

            return ['id' => $application->id, 'offerId' => $offer->id];
        });
    }

    private function event(Offer $offer, Application $application, array $values, string $commandId, string $actor): RecruitmentEvent
    {
        $key = 'LOCAL_EVENT_'.$commandId;
        $id = RecruitmentMapper::stableId('events', $key);
        $event = RecruitmentEvent::create(['id' => $id, 'external_key' => $key, 'origin' => 'local', 'source_id' => $offer->source_id, 'data' => []]);
        $data = array_replace(['id' => $id, 'externalKey' => $key, 'offerId' => $offer->id, 'offerKey' => $offer->external_key, 'applicationId' => $application->id, 'applicationKey' => $application->external_key, 'kind' => 'note', 'actor' => $actor, 'source' => 'RecruitFlow Desktop', 'channel' => 'Inny', 'direction' => 'Wewnętrzna', 'requiresAction' => false, 'actionDeadlineAt' => null, 'interviewAt' => null], $values);
        $this->patch($event, 'events', $data, $commandId, $actor, 'Dodano zdarzenie HR', $offer->external_key);

        return $event;
    }

    public function addEvent(Application $application, array $values): array
    {
        return $this->commands->run($values['operationId'], 'event.create', $values, function () use ($application, $values) {
            $application->refresh();
            $offer = Offer::findOrFail($application->data['offerId']);
            $standard = ['Potwierdzenie', 'Przejrzano CV', 'Wiadomość HR', 'Zaproszenie', 'Zadanie', 'Odmowa', 'Oferta pracy', 'Prośba o dokumenty', 'Zmiana terminu', 'Kontakt telefoniczny', 'Rozmowa HR', 'Rozmowa techniczna', 'Wysłano CV', 'Notatka', 'Zmiana statusu', 'Wycofanie', 'Inne'];
            if (! in_array($values['eventType'], $standard, true)) {
                $values['eventSubtype'] = $values['eventSubtype'] ?? $values['eventType'];
                $values['eventType'] = 'Inne';
            }
            $event = $this->event($offer, $application, collect($values)->except(['operationId', 'actor'])->all(), $values['operationId'], $values['actor'] ?? 'Użytkownik lokalny');

            return ['id' => $event->id];
        });
    }

    public function updateApplication(Application $application, array $values): array
    {
        return $this->commands->run($values['operationId'], 'application.update', $values, function () use ($application, $values) {
            $application->refresh();
            $offer = Offer::findOrFail($application->data['offerId']);
            $changes = collect($values)->except(['operationId', 'actor'])->all();
            $this->patch($application, 'applications', $changes, $values['operationId'], $values['actor'] ?? 'Użytkownik lokalny', 'Skorygowano dane aplikacji', $offer->external_key);

            return ['id' => $application->id];
        });
    }

    public function recordChange(Model $entity, string $collection, array $changes, string $commandId, string $actor, string $action, ?string $offerKey): void
    {
        $this->patch($entity, $collection, $changes, $commandId, $actor, $action, $offerKey);
    }
}

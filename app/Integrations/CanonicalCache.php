<?php

namespace App\Integrations;

use App\Integrations\Mapping\OfferAiEvaluation;
use App\Integrations\Mapping\RecruitmentMapper;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;
use App\Models\Integrations\DataSourceRecord;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\OfferEvaluation;
use App\Models\Recruitment\RecordAlias;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\RecruitmentSetting;
use App\Models\Recruitment\SearchProfile;
use App\Models\Recruitment\SyncOperation;
use App\Recruitment\LocalStateMerger;
use Illuminate\Support\Facades\DB;

class CanonicalCache
{
    public function rebuild(): void
    {
        $sources = DataSource::where('enabled', true)->pluck('id');
        $primary = DataSourceConfiguration::where('is_primary', true)->pluck('data_source_id', 'role');
        $selected = ['offers' => [], 'applications' => [], 'events' => [], 'profiles' => []];
        $aliases = [];
        foreach (RecordAlias::all() as $alias) {
            $aliases[RecruitmentMapper::stableId($alias->collection, $alias->external_key)] = $alias->entity_id;
        }
        foreach (DataSourceRecord::whereIn('data_source_id', $sources)->get()->sortBy(fn ($record) => ($primary[$record->collection] ?? null) === $record->data_source_id ? 0 : 1) as $record) {
            if (! isset($selected[$record->collection])) {
                continue;
            }
            $old = $selected[$record->collection][$record->external_key] ?? null;
            if ($old && $old->data != $record->data) {
                if (($primary[$record->collection] ?? null) === $old->data_source_id) {
                    continue;
                }
                if (($primary[$record->collection] ?? null) !== $record->data_source_id) {
                    throw new IntegrationException('Źródła zawierają różne dane dla tego samego klucza w kolekcji '.$record->collection.'. Wybierz źródło główne lub popraw klucze.', 'key_conflict');
                }
            }
            $data = $record->data;
            $data['id'] = $aliases[$data['id']] ?? $data['id'];
            foreach (['offerId', 'applicationId'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = $aliases[$data[$field]] ?? $data[$field];
                }
            }
            $record->data = $data;
            $selected[$record->collection][$record->external_key] = $record;
        }
        $offersById = [];
        $applicationsById = [];
        $remoteOffers = [];
        foreach ($selected['offers'] as $record) {
            $remoteOffers[$record->data['id']] = $record->data;
            $offersById[$record->data['id']] = app(LocalStateMerger::class)->persist('offers', $record, $record->data);
        }
        foreach (Offer::all() as $offer) {
            $offersById[$offer->id] ??= $offer->data;
        }
        foreach ($selected['applications'] as $record) {
            $offer = $remoteOffers[$record->data['offerId']] ?? $offersById[$record->data['offerId']] ?? null;
            if (! $offer) {
                throw new IntegrationException('Aplikacja wskazuje ofertę, której nie ma w aktywnych źródłach.', 'missing_reference');
            }
            $data = $record->data;
            $data['company'] = $offer['company'];
            $data['position'] = $offer['title'];
            $data['offerUrl'] = $offer['offerUrl'] ?? '';
            if (($data['schemaProfile'] ?? 'generic') === 'recruitflow') {
                $data['cvSent'] = $offer['cvSent'];
                $data['cvSentAt'] = $offer['cvSentAt'];
                $data['appliedAt'] = $offer['cvSentAt'];
                $data['applicationStage'] = $offer['applicationStage'];
                $data['rawStatus'] = $offer['applicationStage'];
                $data['status'] = RecruitmentMapper::status('applications', $offer['applicationStage']);
            }
            $record->data = $data;
            $merged = app(LocalStateMerger::class)->persist('applications', $record, $data);
            $actualOffer = $offersById[$data['offerId']];
            if (($merged['schemaProfile'] ?? 'generic') === 'recruitflow') {
                $merged = array_replace($merged, ['cvSent' => $actualOffer['cvSent'] ?? null, 'cvSentAt' => $actualOffer['cvSentAt'] ?? null, 'appliedAt' => $actualOffer['cvSentAt'] ?? null, 'applicationStage' => $actualOffer['applicationStage'] ?? '', 'rawStatus' => $actualOffer['applicationStage'] ?? '', 'status' => RecruitmentMapper::status('applications', $actualOffer['applicationStage'] ?? '')]);
                Application::whereKey($data['id'])->update(['data' => $merged]);
            }
            $applicationsById[$data['id']] = $merged;
        }
        foreach (Application::all() as $application) {
            $applicationsById[$application->id] ??= $application->data;
        }
        foreach ($selected['events'] as $record) {
            $data = $record->data;
            $application = isset($data['applicationId']) ? ($applicationsById[$data['applicationId']] ?? null) : null;
            if (isset($data['applicationId']) && ! $application) {
                throw new IntegrationException('Zdarzenie wskazuje nieistniejącą aplikację.', 'missing_reference');
            }
            if (! isset($data['offerId']) && $application) {
                $data['offerId'] = $application['offerId'];
            }
            if (! isset($data['offerId']) || ! isset($offersById[$data['offerId']])) {
                throw new IntegrationException('Zdarzenie wskazuje nieistniejącą ofertę.', 'missing_reference');
            }
            if ($application && $application['offerId'] !== $data['offerId']) {
                throw new IntegrationException('Zdarzenie wskazuje aplikację i inną ofertę.', 'missing_reference');
            }
            $record->data = $data;
            app(LocalStateMerger::class)->persist('events', $record, $data);
        }
        foreach (['offers' => Offer::class, 'applications' => Application::class, 'events' => RecruitmentEvent::class, 'profiles' => SearchProfile::class] as $collection => $model) {
            $ids = [];
            foreach ($selected[$collection] as $key => $record) {
                $ids[] = $record->data['id'];
                if ($collection === 'profiles') {
                    app(LocalStateMerger::class)->persist('profiles', $record, $record->data);
                }
            }
            // Retain user records and historical applications when a source disappears.
            foreach ($model::where('origin', 'google_sheets')->whereNotIn('id', $ids)->get() as $entity) {
                $entity->update(['data' => array_replace($entity->data, ['remoteMissing' => true])]);
            }
        }
    }

    public function snapshot(): array
    {
        return DB::transaction(function () {
            $unsupported = SyncOperation::whereNotNull('unsupported_fields')->orderBy('id')->get()->groupBy(fn ($operation) => $operation->entity_type.'/'.$operation->entity_id)->map(fn ($operations) => $operations->flatMap(fn ($operation) => $operation->unsupported_fields)->unique()->values()->all());
            $states = SyncOperation::whereNotIn('status', ['synced', 'superseded'])->get()->groupBy(fn ($operation) => $operation->entity_type.'/'.$operation->entity_id);
            $decorate = function ($entity, string $collection) use ($states, $unsupported) {
                $data = $entity->data;
                if (isset($data['mergedInto'])) {
                    return null;
                }
                $operations = $states[$collection.'/'.$entity->id] ?? collect();

                return array_replace($data, ['origin' => $entity->origin, 'sourceId' => $entity->source_id, 'syncStatus' => $operations->contains('status', 'conflict') ? 'conflict' : ($operations->contains(fn ($operation) => in_array($operation->status, ['pending', 'retry', 'processing'])) ? 'pending' : ($operations->contains('status', 'blocked') ? 'blocked' : ($entity->source_id && $operations->isEmpty() ? 'synced' : 'local'))), 'localOnlyFields' => $unsupported[$collection.'/'.$entity->id] ?? []]);
            };

            return ['profiles' => SearchProfile::orderBy('external_key')->get()->map(fn ($entity) => $decorate($entity, 'profiles'))->filter()->values()->all(), 'offers' => Offer::orderBy('external_key')->get()->map(fn ($entity) => $decorate($entity, 'offers'))->filter()->map(fn ($offer) => OfferAiEvaluation::fromCache($offer) + ['aiProfileId' => ! empty($offer['aiProfileKey'] ?? $offer['extraData']['search_profile_id'] ?? null) ? SearchProfile::where('external_key', $offer['aiProfileKey'] ?? $offer['extraData']['search_profile_id'])->value('id') : null])->values()->all(), 'applications' => Application::orderBy('external_key')->get()->map(fn ($entity) => $decorate($entity, 'applications'))->filter()->values()->all(), 'activities' => RecruitmentEvent::orderBy('external_key')->get()->map(fn ($entity) => $decorate($entity, 'events'))->filter()->values()->all(), 'evaluations' => OfferEvaluation::all()->map(fn ($evaluation) => ['offerId' => $evaluation->offer_id, 'profileId' => $evaluation->profile_id, 'data' => $evaluation->data])->all(), 'activeProfileId' => RecruitmentSetting::find('active_profile')?->value['id'] ?? null];
        });
    }
}

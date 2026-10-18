<?php

namespace App\Recruitment;

use App\Integrations\CanonicalCache;
use App\Integrations\IntegrationException;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecordAlias;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\SyncOperation;

class OfferMergeService
{
    public function __construct(private CommandRunner $commands, private CanonicalCache $cache) {}

    public function merge(Offer $local, Offer $remote, array $values): array
    {
        return $this->commands->run($values['operationId'], 'offer.merge', $values + ['local' => $local->id, 'remote' => $remote->id], function () use ($local, $remote) {
            $local->refresh();
            $remote->refresh();
            if ($local->origin !== 'local' || $remote->origin !== 'google_sheets' || isset($local->data['mergedInto']) || isset($remote->data['mergedInto'])) {
                throw new IntegrationException('Połączyć można aktywną ofertę ręczną z zaimportowaną.', 'merge_invalid');
            }
            $sameUrl = OfferIdentity::url($local->data['offerUrl'] ?? '') && OfferIdentity::url($local->data['offerUrl'] ?? '') === OfferIdentity::url($remote->data['offerUrl'] ?? '');
            $sameTitle = ! empty($local->data['company']) && ! empty($local->data['title']) && OfferIdentity::title($local->data) === OfferIdentity::title($remote->data);
            if (! $sameUrl && ! $sameTitle) {
                throw new IntegrationException('Tożsamości tych ofert nie pasują. Nie połączono rekordów.', 'merge_invalid');
            }
            $oldKey = $local->external_key;
            $this->alias('offers', $local, $remote);
            $localApplication = Application::where('data->offerId', $local->id)->get()->first(fn ($application) => ! isset($application->data['mergedInto']));
            $remoteApplication = Application::where('data->offerId', $remote->id)->get()->first(fn ($application) => ! isset($application->data['mergedInto']));
            if ($localApplication && $remoteApplication) {
                $this->alias('applications', $localApplication, $remoteApplication);
            }
            foreach (Application::all() as $application) {
                $data = $application->data;
                if (($data['offerId'] ?? null) === $remote->id || ($data['offerId'] ?? null) === $local->id) {
                    $data['offerId'] = $local->id;
                    $data['offerKey'] = $local->external_key;
                    $overrides = $application->local_overrides ?? [];
                    if (isset($overrides['offerId'])) {
                        $overrides['offerId'] = $local->id;
                        $overrides['offerKey'] = $local->external_key;
                    }
                    $application->update(['data' => $data, 'local_overrides' => $overrides]);
                }
            }
            foreach (RecruitmentEvent::all() as $event) {
                $data = $event->data;
                if (($data['offerId'] ?? null) === $remote->id || ($data['offerId'] ?? null) === $local->id) {
                    $data['offerId'] = $local->id;
                    $data['offerKey'] = $local->external_key;
                }
                if ($localApplication && $remoteApplication && ($data['applicationId'] ?? null) === $remoteApplication->id) {
                    $data['applicationId'] = $localApplication->id;
                    $data['applicationKey'] = $localApplication->external_key;
                }
                $event->update(['data' => $data, 'local_overrides' => $event->origin === 'local' ? $data : $event->local_overrides]);
            }
            foreach (SyncOperation::where('offer_key', $oldKey)->whereNotIn('status', ['synced', 'superseded'])->get() as $operation) {
                $payload = $operation->payload;
                if (isset($payload['offerKey'])) {
                    $payload['offerKey'] = $local->external_key;
                }
                if ($operation->entity_type === 'offers') {
                    $payload = array_intersect_key($payload, $local->local_overrides ?? []);
                    $payload['externalKey'] = $local->external_key;
                }
                $operation->update(['offer_key' => $local->external_key, 'payload' => $payload]);
            }
            $this->cache->rebuild();

            return ['id' => $local->id];
        });
    }

    private function alias(string $collection, $local, $remote): void
    {
        $remoteKey = $remote->external_key;
        RecordAlias::updateOrCreate(['collection' => $collection, 'external_key' => $remoteKey], ['entity_id' => $local->id]);
        RecordAlias::updateOrCreate(['collection' => $collection, 'external_key' => $local->external_key], ['entity_id' => $local->id]);
        // Keep the retired document and its history instead of deleting it.
        $remote->update(['external_key' => 'MERGED_'.$remote->id, 'data' => array_replace($remote->data, ['mergedInto' => $local->id])]);
        $overrides = $local->local_overrides ?? [];
        $overrides['id'] = $local->id;
        $overrides['externalKey'] = $remoteKey;
        $baseline = $remote->remote_baseline ?? $remote->data;
        $baseline['id'] = $local->id;
        $local->update(['external_key' => $remoteKey, 'source_id' => $remote->source_id, 'data' => array_replace($baseline, $overrides), 'local_overrides' => $overrides, 'remote_baseline' => $baseline]);
        foreach (SyncOperation::where('entity_type', $collection)->where('entity_id', $local->id)->whereNotIn('status', ['synced', 'superseded'])->get() as $operation) {
            $payload = array_intersect_key($operation->payload, $overrides);
            if (isset($payload['externalKey'])) {
                $payload['externalKey'] = $remoteKey;
            }
            $base = array_intersect_key($baseline, $payload);
            $operation->update(['payload' => $payload, 'source_id' => $remote->source_id, 'base_values' => $base, 'base_version' => hash('sha256', json_encode($base, JSON_THROW_ON_ERROR))]);
        }
    }
}

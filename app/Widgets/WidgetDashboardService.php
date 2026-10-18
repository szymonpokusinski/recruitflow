<?php

namespace App\Widgets;

use App\Integrations\CanonicalCache;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\SyncRun;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\RecruitmentSetting;
use App\Recruitment\OfferIdentity;
use Carbon\CarbonImmutable;

final class WidgetDashboardService
{
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value, config('app.timezone'));

            return $date->year >= 2000 && $date->year <= 2100 ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function score(mixed $value): ?float
    {
        return ! is_bool($value) && is_numeric($value) && is_finite((float) $value)
            && (float) $value >= 0 && (float) $value <= 100 ? (float) $value : null;
    }

    private function text(mixed $value, int $length = 300): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(is_string($value) ? $value : '')) ?? ''), 0, $length);
    }

    private function amount(mixed $value): ?float
    {
        return ! is_bool($value) && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }

    public function snapshot(): array
    {
        $now = CarbonImmutable::now();
        $cache = app(CanonicalCache::class)->snapshot();
        $reviewed = $this->date(RecruitmentSetting::find('widget_last_reviewed')?->value['at'] ?? null);
        $models = Offer::all()->keyBy('id');
        $groups = [];
        foreach ($cache['offers'] as $offer) {
            $identity = OfferIdentity::url($offer['offerUrl'] ?? null) ?? 'key:'.($offer['externalKey'] ?? $offer['id']);
            $groups[$identity][] = $offer;
        }
        $offers = [];
        $idMap = [];
        $activeProfile = $cache['activeProfileId'];
        foreach ($groups as $duplicates) {
            // Preserve canonical user decisions when a pending local/remote merge exists.
            usort($duplicates, fn ($a, $b) => ((int) ($b['origin'] === 'local')) <=> ((int) ($a['origin'] === 'local')));
            $offer = $duplicates[0];
            $dates = [];
            foreach ($duplicates as $item) {
                $idMap[$item['id']] = $offer['id'];
                foreach ([$item['firstDetectedAt'] ?? null, $item['createdAt'] ?? null, $item['foundAt'] ?? null,
                    $item['extraData']['first_seen'] ?? null, $item['extraData']['first_found_at'] ?? null] as $value) {
                    if (($date = $this->date($value)) && $date <= $now) {
                        $dates[] = $date;
                    }
                }
                if (($item['origin'] ?? '') === 'local' && isset($models[$item['id']])) {
                    $dates[] = CarbonImmutable::instance($models[$item['id']]->created_at);
                }
            }
            usort($dates, fn ($a, $b) => $a <=> $b);
            $first = $dates[0] ?? null;
            $active = ! ($offer['archived'] ?? false) && ! ($offer['hidden'] ?? false)
                && ! ($offer['remoteMissing'] ?? false) && ! in_array($offer['status'] ?? '', ['closed', 'paused'], true)
                && ! in_array(mb_strtolower($offer['portalStatus'] ?? ''), ['zamknięta', 'wygasła', 'niedostępna', 'nieaktywna'], true);
            $matches = [];
            foreach ($cache['evaluations'] as $evaluation) {
                if ($evaluation['offerId'] !== $offer['id']) {
                    continue;
                }
                $data = $evaluation['data'];
                $score = $this->score($data['aiMatchScore'] ?? null);
                if ($score !== null) {
                    $matches[] = ['profileId' => $evaluation['profileId'], 'score' => $score,
                        'source' => $this->text($data['source'] ?? $data['aiSource'] ?? 'Zapisana ocena'),
                        'evaluatedAt' => $this->date($data['aiEvaluatedAt'] ?? null)?->toIso8601String()];
                }
            }
            $legacy = $this->score($offer['aiMatchScore'] ?? null);
            if ($legacy !== null && ! collect($matches)->contains('profileId', $offer['aiProfileId'] ?? null)) {
                $matches[] = ['profileId' => $offer['aiProfileId'] ?? null, 'score' => $legacy,
                    'source' => $this->text($offer['extraData']['ai_source'] ?? 'Zapisana ocena z arkusza'),
                    'evaluatedAt' => $this->date($offer['aiEvaluatedAt'] ?? null)?->toIso8601String()];
            }
            $selectedMatch = collect($matches)->first(fn ($match) => $match['profileId'] === $activeProfile);
            if ($activeProfile === null) {
                $selectedMatch = collect($matches)->first(fn ($match) => $match['profileId'] === null);
            }
            $source = $offer['importDetails']['provider'] ?? $offer['foundBy'] ?? $offer['extraData']['found_by'] ?? $offer['extraData']['ai_source'] ?? null;
            $source = $this->text($source ?: (($offer['origin'] ?? '') === 'local' ? 'Ręcznie' : 'Google Sheets'));
            $offers[] = ['id' => $offer['id'], 'company' => $this->text($offer['company'] ?? ''),
                'title' => $this->text($offer['title'] ?? '') ?: 'Do uzupełnienia',
                'location' => $this->text($offer['location'] ?? ''), 'workMode' => $this->text($offer['workMode'] ?? ''),
                'salary' => ['min' => $this->amount($offer['salaryMin'] ?? null), 'max' => $this->amount($offer['salaryMax'] ?? null),
                    'currency' => $this->text($offer['currency'] ?? '', 10), 'period' => $this->text($offer['salaryPeriod'] ?? '', 30),
                    'basis' => $this->text($offer['salaryBasis'] ?? '', 30)],
                'match' => $selectedMatch, 'matches' => $matches, 'profileId' => $offer['aiProfileId'] ?? null,
                'priority' => $this->text($offer['priority'] ?? '', 60), 'source' => $source,
                'firstSeenAt' => $first?->toIso8601String(), 'status' => $this->text($offer['applicationStage'] ?? '', 80),
                'cvSent' => (bool) ($offer['cvSent'] ?? false), 'active' => $active,
                'isNewToday' => $first?->setTimezone(config('app.timezone'))->isSameDay($now->setTimezone(config('app.timezone'))) ?? false,
                'isNew24Hours' => $first !== null && $first >= $now->subHours(24),
                'unreviewed' => $reviewed !== null && $first !== null ? $first > $reviewed : null,
                'deepLink' => WidgetDeepLink::offer($offer['id'])];
        }
        usort($offers, fn ($a, $b) => strcmp($b['firstSeenAt'] ?? '', $a['firstSeenAt'] ?? '') ?: strcmp($a['id'], $b['id']));
        $proposals = array_values(array_filter($offers, fn ($offer) => $offer['active'] && ! $offer['cvSent']));
        $events = [];
        foreach ($cache['activities'] as $event) {
            $id = $idMap[$event['offerId'] ?? ''] ?? null;
            if (! $id) {
                continue;
            }
            $occurred = $this->date($event['occurredAt'] ?? null);
            $deadline = $this->date($event['interviewAt'] ?? $event['actionDeadlineAt'] ?? null);
            $inbound = in_array(mb_strtolower($event['direction'] ?? ''), ['przychodząca', 'incoming', 'inbound'], true);
            if (($inbound && $occurred && $occurred >= $now->subHours(24)) || ($deadline && $deadline >= $now)) {
                $events[] = ['id' => $event['id'], 'offerId' => $id,
                    'type' => $this->text($event['eventType'] ?? '', 100) ?: 'Zdarzenie HR',
                    'occurredAt' => $occurred?->toIso8601String(), 'deadlineAt' => $deadline?->toIso8601String(),
                    'isReply' => $inbound, 'isInterview' => ! empty($event['interviewAt']),
                    'deepLink' => WidgetDeepLink::offer($id)];
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['deadlineAt'] ?? '9999', $b['deadlineAt'] ?? '9999'));
        $lastSync = SyncRun::where('status', 'succeeded')->max('finished_at');
        $updated = collect([Offer::max('updated_at'), Application::max('updated_at'),
            RecruitmentEvent::max('updated_at')])->filter()->sort()->last();
        $activeProcesses = array_filter($offers, fn ($offer) => ! in_array($offer['status'], ['', 'Nie aplikowano', 'Planowana aplikacja', 'Odrzucono', 'Wycofano', 'Zatrudniono'], true));

        return ['schema_version' => 1, 'generatedAt' => $now->toIso8601String(), 'timezone' => config('app.timezone'),
            'refresh' => RecruitmentSetting::find('widget_refresh')?->value,
            'dataUpdatedAt' => $this->date($updated)?->toIso8601String(), 'hasRemoteSources' => DataSource::where('enabled', true)->exists(),
            'staleAfterMinutes' => config('widgets.stale_after_minutes'), 'lastSuccessfulSyncAt' => $this->date($lastSync)?->toIso8601String(),
            'lastReviewedAt' => $reviewed?->toIso8601String(), 'activeProfileId' => $activeProfile,
            'statistics' => ['newToday' => count(array_filter($proposals, fn ($offer) => $offer['isNewToday'])),
                'new24Hours' => count(array_filter($proposals, fn ($offer) => $offer['isNew24Hours'])),
                'unreviewed' => $reviewed ? count(array_filter($proposals, fn ($offer) => $offer['unreviewed'])) : null,
                'highMatches' => count(array_filter($proposals, fn ($offer) => ($offer['match']['score'] ?? -1) >= 85)),
                'cvSent' => count(array_filter($offers, fn ($offer) => $offer['cvSent'])), 'activeProcesses' => count($activeProcesses),
                'newHRReplies' => count(array_filter($events, fn ($event) => $event['isReply'] && ($this->date($event['occurredAt'])?->betweenIncluded($now->subHours(24), $now) ?? false))),
                'upcomingInterviews' => count(array_filter($events, fn ($event) => $event['isInterview'] && $event['deadlineAt'] !== null && $this->date($event['deadlineAt']) <= $now->addDays(7)))],
            'offers' => array_slice($offers, 0, config('widgets.max_offers')), 'totalOffers' => count($offers),
            'truncated' => count($offers) > config('widgets.max_offers'), 'deduplicatedCount' => count($cache['offers']) - count($offers),
            'profiles' => array_map(fn ($profile) => ['id' => $profile['id'], 'name' => $this->text($profile['name'] ?? $profile['externalKey'] ?? '')], $cache['profiles']),
            'sources' => array_values(array_unique(array_column($offers, 'source'))), 'events' => array_slice($events, 0, 20)];
    }
}

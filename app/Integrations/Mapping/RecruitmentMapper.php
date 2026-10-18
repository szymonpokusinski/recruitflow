<?php

namespace App\Integrations\Mapping;

use App\Integrations\Google\SyncFieldMap;
use App\Integrations\IntegrationException;
use App\Models\Integrations\DataSourceConfiguration;
use App\Recruitment\OfferFields;
use Ramsey\Uuid\Uuid;

class RecruitmentMapper
{
    public static function stableId(string $collection, string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'recruitflow/'.$collection.'/'.$key)->toString();
    }

    public function map(DataSourceConfiguration $configuration, array $table): array
    {
        $mapping = $configuration->mappings->pluck('column_name', 'field')->all();
        if ($configuration->schema_profile === 'recruitflow' && $configuration->role === 'offers') {
            // Extend existing automatic mappings with newly supported optional AI fields.
            foreach (MappingSchema::suggest('offers', $table['headers']) as $field => $column) {
                if (str_starts_with($field, 'ai_')) {
                    $mapping[$field] ??= $column;
                }
            }
        }
        foreach (MappingSchema::required($configuration->role, $configuration->schema_profile ?? 'generic') as $field) {
            if (! isset($mapping[$field]) || $mapping[$field] === '') {
                throw new IntegrationException('Brak mapowania wymaganego pola: '.$field.'.', 'mapping_invalid');
            }
        }
        foreach ($mapping as $field => $column) {
            if (! in_array($column, $table['headers'], true)) {
                if (in_array($field, MappingSchema::required($configuration->role, $configuration->schema_profile ?? 'generic'), true)) {
                    throw new IntegrationException('Wymagana kolumna zniknęła lub zmieniła nazwę.', 'schema_changed');
                }
                unset($mapping[$field]);
            }
        }
        $records = [];
        $keys = [];
        foreach ($table['rows'] as $row) {
            $values = [];
            foreach ($mapping as $field => $column) {
                $values[$field] = $row['values'][$column] ?? '';
            }
            if ($configuration->schema_profile === 'recruitflow' && trim((string) ($values['id'] ?? '')) === '' && trim((string) ($values['offer_id'] ?? $values['title'] ?? $values['name'] ?? '')) === '' && trim((string) ($values['message'] ?? '')) === '') {
                continue;
            }
            foreach (MappingSchema::required($configuration->role, $configuration->schema_profile ?? 'generic') as $field) {
                if (trim((string) ($values[$field] ?? '')) === '') {
                    throw new IntegrationException('Wiersz '.$row['row_number'].': brak wymaganego pola '.$field.'.', 'row_invalid');
                }
            }
            $key = trim((string) $values['id']);
            if (strlen($key) > 200) {
                throw new IntegrationException('Klucz rekordu przekracza 200 znaków.', 'row_invalid');
            }
            if (isset($keys[$key])) {
                throw new IntegrationException('Zakładka zawiera powtarzający się klucz w wierszu '.$row['row_number'].'.', 'duplicate_key');
            }
            $keys[$key] = true;
            $data = $this->domain($configuration->role, $key, $values, $table['timezone'] ?? 'UTC', $configuration->schema_profile ?? 'generic');
            if ($configuration->role === 'offers' && $configuration->schema_profile === 'recruitflow') {
                $data['aiProfileKey'] = trim((string) ($row['values']['search_profile_id'] ?? '')) ?: null;
                $data = OfferFields::import($data, $row['values'], $table['timezone'] ?? 'UTC');
            }
            if ($configuration->schema_profile === 'recruitflow' && in_array($configuration->role, ['applications', 'events'], true)) {
                foreach (SyncFieldMap::fields($configuration->role) as $property => $column) {
                    if (in_array($property, ['externalKey', 'cvSent', 'applicationStage', 'occurredAt', 'message', 'eventType'], true) || ! array_key_exists($column, $row['values'])) {
                        continue;
                    }
                    $value = $row['values'][$column];
                    $data[$property] = SyncFieldMap::isDate($property) ? SheetValueNormalizer::date($value, $table['timezone'] ?? 'UTC', $column) : ($property === 'requiresAction' ? SheetValueNormalizer::boolean($value, $column) : $value);
                }
            }
            $records[] = ['external_key' => $key, 'collection' => $configuration->role, 'data' => $data + ['extraData' => $row['values']]];
        }

        return $records;
    }

    private function text(array $row, string $field): string
    {
        return trim((string) ($row[$field] ?? ''));
    }

    private function date(array $row, string $field, string $timezone): ?string
    {
        return SheetValueNormalizer::date($row[$field] ?? null, $timezone, $field);
    }

    private function money(array $row, string $field): ?float
    {
        $value = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], $this->text($row, $field));
        if ($value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new IntegrationException('Pole '.$field.' wymaga nieujemnej liczby.', 'row_invalid');
        }

        return (float) $value;
    }

    public static function status(string $role, string $raw): string
    {
        $value = MappingSchema::normalize($raw);
        $groups = $role === 'offers' ? ['open' => ['open', 'active', 'aktywna', 'otwarta', 'available', 'dostepna'], 'unconfirmed' => ['niepotwierdzona', 'unconfirmed'], 'paused' => ['paused', 'wstrzymana'], 'closed' => ['closed', 'zamknieta', 'expired', 'wygasla', 'inactive', 'nieaktywna', 'unavailable']] : [
            'not_applied' => ['nieaplikowano', 'notapplied'], 'planned' => ['planowanaaplikacja', 'planned'],
            'hr_contact' => ['kontakthr', 'hrcontact'], 'hr_interview' => ['rozmowahr', 'hrinterview'],
            'technical_task' => ['zadanietechniczne', 'technicaltask'], 'technical_interview' => ['rozmowatechniczna', 'technicalinterview'],
            'final' => ['etapkoncowy', 'final'], 'withdrawn' => ['wycofano', 'withdrawn'],
            'new' => ['new', 'nowa', 'nowy'], 'screening' => ['screening', 'selekcja'], 'interview' => ['interview', 'rozmowa'],
            'sent' => ['wyslanocv', 'cvsent', 'sent'], 'confirmed' => ['potwierdzonoprzyjecie', 'confirmed', 'confirmation'], 'offer' => ['offer', 'ofertazlozona', 'ofertapracy'], 'hired' => ['hired', 'zatrudniona', 'zatrudniony', 'zatrudniono'], 'rejected' => ['rejected', 'odrzucona', 'odrzucony', 'odrzucono'],
        ];
        foreach ($groups as $status => $aliases) {
            if (in_array($value, $aliases, true)) {
                return $status;
            }
        }

        return 'unknown';
    }

    private function domain(string $role, string $key, array $row, string $timezone, string $profile): array
    {
        $data = ['schemaProfile' => $profile, 'id' => self::stableId($role, $key), 'externalKey' => $key];
        if ($role === 'offers') {
            foreach (['title', 'company', 'department', 'location', 'employment', 'owner', 'description'] as $field) {
                $data[$field] = $this->text($row, $field);
            }
            $data += ['workMode' => $this->text($row, 'work_mode'), 'salaryMin' => $this->money($row, 'salary_min'), 'salaryMax' => $this->money($row, 'salary_max'), 'currency' => strtoupper($this->text($row, 'currency')), 'status' => self::status($role, $this->text($row, $profile === 'recruitflow' ? 'effective_status' : 'status')), 'rawStatus' => $this->text($row, $profile === 'recruitflow' ? 'effective_status' : 'status'), 'createdAt' => $this->date($row, 'created_at', $timezone), 'requirements' => array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', $this->text($row, 'requirements')) ?: [])))];
            foreach (['offer_url' => 'offerUrl', 'region' => 'region', 'country' => 'country', 'seniority' => 'seniority', 'effective_status' => 'effectiveStatus', 'portal_status' => 'portalStatus', 'application_stage' => 'applicationStage', 'salary_period' => 'salaryPeriod', 'priority' => 'priority', 'my_notes' => 'myNotes'] as $field => $property) {
                $data[$property] = $this->text($row, $field);
            }
            foreach (['cv_sent' => 'cvSent', 'favorite' => 'favorite'] as $field => $property) {
                $data[$property] = SheetValueNormalizer::boolean($row[$field] ?? null, $field);
            }
            $data['myInterest'] = $row['my_interest'] ?? null;
            $data += OfferAiEvaluation::map($row, $timezone);
            $data['cvSentAt'] = $this->date($row, 'cv_sent_at', $timezone);
            $data['applyDeadlineAt'] = $this->date($row, 'apply_deadline_at', $timezone);
            if ($data['salaryMin'] !== null && $data['salaryMax'] !== null && $data['salaryMin'] > $data['salaryMax']) {
                throw new IntegrationException('Wynagrodzenie minimalne przekracza maksymalne.', 'row_invalid');
            }
        } elseif ($role === 'applications') {
            $data += ['offerId' => self::stableId('offers', $this->text($row, 'offer_id')), 'candidateName' => $this->text($row, 'candidate_name'), 'candidateEmail' => $this->text($row, 'candidate_email'), 'candidateRole' => $this->text($row, 'candidate_role'), 'location' => $this->text($row, 'location'), 'status' => self::status($role, $this->text($row, $profile === 'recruitflow' ? 'application_stage' : 'status')), 'rawStatus' => $this->text($row, $profile === 'recruitflow' ? 'application_stage' : 'status'), 'owner' => $this->text($row, 'owner'), 'source' => $this->text($row, 'source'), 'appliedAt' => $this->date($row, 'applied_at', $timezone), 'nextStep' => $this->date($row, 'next_step', $timezone)];
            $data['cvSent'] = SheetValueNormalizer::boolean($row['cv_sent'] ?? null, 'cv_sent');
            $data['applicationStage'] = $this->text($row, 'application_stage');
            foreach (['cv_sent_at' => 'cvSentAt', 'confirmation_at' => 'confirmationAt', 'first_reply_at' => 'firstReplyAt', 'last_reply_at' => 'lastReplyAt', 'rejection_at' => 'rejectionAt'] as $field => $property) {
                $data[$property] = $this->date($row, $field, $timezone);
            }
            if ($profile === 'recruitflow') {
                $data['appliedAt'] = $data['cvSentAt'];
            }
        } elseif ($role === 'events') {
            $kind = $this->text($row, 'kind');
            $data += ['applicationId' => $this->text($row, 'application_id') !== '' ? self::stableId('applications', $this->text($row, 'application_id')) : null, 'offerId' => $this->text($row, 'offer_id') !== '' ? self::stableId('offers', $this->text($row, 'offer_id')) : null, 'kind' => in_array($kind, ['applied', 'status', 'note'], true) ? $kind : 'unknown', 'actor' => $this->text($row, 'actor'), 'message' => $this->text($row, 'message'), 'occurredAt' => $this->date($row, 'occurred_at', $timezone)];
            foreach (['event_type' => 'eventType', 'event_subtype' => 'eventSubtype', 'source' => 'source', 'gmail_url' => 'gmailUrl', 'direction' => 'direction', 'channel' => 'channel'] as $field => $property) {
                $data[$property] = $this->text($row, $field);
            }
            $data['requiresAction'] = SheetValueNormalizer::boolean($row['requires_action'] ?? null, 'requires_action');
            $data['actionDeadlineAt'] = $this->date($row, 'action_deadline_at', $timezone);
            $data['interviewAt'] = $this->date($row, 'interview_at', $timezone);
        } else {
            $data += $row;
            $data['enabled'] = SheetValueNormalizer::boolean($row['enabled'] ?? null, 'enabled');
            $data['salary_min'] = $this->money($row, 'salary_min');
            $data['min_match_score'] = $this->money($row, 'min_match_score');
        }

        return $data;
    }
}

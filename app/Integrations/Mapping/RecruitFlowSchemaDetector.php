<?php

namespace App\Integrations\Mapping;

use App\Integrations\Contracts\DataSourceAdapterInterface;
use App\Integrations\IntegrationException;
use App\Models\Integrations\DataSource;

class RecruitFlowSchemaDetector
{
    public const AUXILIARY = ['Oferty_ChatGPT', 'Oferty_Gemini', 'WIDOK_WIDGET', 'KOMENDY_WIDGET', 'HISTORIA_ZMIAN', 'SNAJPER_EMAIL_LOG', 'LOG_SYNCHRONIZACJI', 'SCHEMAT_POL', 'INSTRUKCJA', 'START', 'KARTA_OFERTY', 'SLOWNIKI'];

    public const TABLES = ['OFERTY' => 'offers', 'APLIKACJE' => 'applications', 'ZDARZENIA_HR' => 'events', 'PROFILE_SZUKANIA' => 'profiles'];

    public const FIELDS = [
        'offers' => [
            'id' => 'offer_key', 'title' => 'position', 'company' => 'company', 'offer_url' => 'offer_url', 'location' => 'city', 'region' => 'region', 'country' => 'country',
            'work_mode' => 'work_model', 'seniority' => 'seniority', 'effective_status' => 'effective_status', 'portal_status' => 'portal_status', 'application_stage' => 'application_stage',
            'cv_sent' => 'cv_sent', 'cv_sent_at' => 'cv_sent_at', 'salary_min' => 'salary_min', 'salary_max' => 'salary_max', 'currency' => 'salary_currency', 'salary_period' => 'salary_period',
            'ai_match_score' => 'ai_match_score', 'ai_stack_score' => 'ai_stack_score', 'ai_seniority_score' => 'ai_seniority_score', 'ai_location_score' => 'ai_location_score', 'ai_salary_score' => 'ai_salary_score',
            'ai_fit_reasons' => 'ai_fit_reasons', 'ai_fit_concerns' => 'ai_fit_concerns', 'ai_recommendation' => 'ai_recommendation', 'ai_cv_suggestions' => 'ai_cv_suggestions', 'ai_evaluation_confidence' => 'ai_evaluation_confidence', 'ai_evaluated_at' => 'ai_evaluated_at',
            'priority' => 'priority', 'favorite' => 'favorite', 'my_interest' => 'my_interest', 'my_notes' => 'my_notes', 'apply_deadline_at' => 'apply_deadline_at',
            'created_at' => 'first_found_at', 'description' => 'job_summary', 'requirements' => 'requirements_must', 'employment' => 'contract_types',
        ],
        'applications' => ['id' => 'application_id', 'offer_id' => 'offer_key', 'cv_sent' => 'cv_sent', 'cv_sent_at' => 'cv_sent_at', 'application_stage' => 'application_stage', 'source' => 'application_source',
            'confirmation_at' => 'confirmation_at', 'first_reply_at' => 'first_reply_at', 'last_reply_at' => 'last_reply_at', 'next_step' => 'next_interview_at', 'rejection_at' => 'rejection_at'],
        'events' => ['id' => 'event_id', 'offer_id' => 'offer_key', 'application_id' => 'application_id', 'occurred_at' => 'event_at', 'event_type' => 'event_type', 'event_subtype' => 'event_subtype', 'source' => 'source',
            'gmail_url' => 'gmail_url', 'message' => 'summary', 'requires_action' => 'requires_action', 'action_deadline_at' => 'action_deadline_at', 'interview_at' => 'interview_at', 'direction' => 'direction', 'channel' => 'channel'],
        'profiles' => ['id' => 'profile_id', 'enabled' => 'enabled', 'name' => 'profile_name', 'main_language' => 'main_language', 'role_keywords' => 'role_keywords', 'seniority' => 'seniority',
            'required_stack' => 'required_stack', 'nice_stack' => 'nice_stack', 'country' => 'country', 'city_or_region' => 'city_or_region', 'remote_mode' => 'remote_mode', 'salary_min' => 'salary_min', 'min_match_score' => 'min_match_score'],
    ];

    public static function required(string $role): array
    {
        return match ($role) {
            'offers' => ['id', 'title'], 'applications' => ['id', 'offer_id'], 'events' => ['id', 'offer_id', 'occurred_at'], 'profiles' => ['id'], default => []
        };
    }

    public static function auxiliary(string $title): bool
    {
        return in_array(MappingSchema::normalize($title), array_map(MappingSchema::normalize(...), self::AUXILIARY), true);
    }

    private function profileRole(array $headers): ?string
    {
        $names = array_map(MappingSchema::normalize(...), $headers);
        foreach (['events' => 'event_id', 'profiles' => 'profile_id', 'applications' => 'application_id'] as $role => $key) {
            if (in_array(MappingSchema::normalize($key), $names, true) && ($role === 'profiles' || in_array('offerkey', $names, true))) {
                return $role;
            }
        }
        if (in_array('offerkey', $names, true) && in_array('position', $names, true)) {
            return 'offers';
        }

        return null;
    }

    public function detect(DataSource $source, DataSourceAdapterInterface $adapter, array $metadata): array
    {
        $plans = [];
        $roles = [];
        foreach ($metadata['sheets'] as $sheet) {
            if (self::auxiliary($sheet['title'])) {
                continue;
            }
            $headers = $adapter->headers($source, $sheet['id'], 1)['columns'];
            $role = $this->profileRole($headers);
            $profile = $role ? 'recruitflow' : 'generic';
            $mapping = [];
            if ($role) {
                foreach (self::FIELDS[$role] as $field => $technical) {
                    $matches = array_values(array_filter($headers, fn ($header) => MappingSchema::normalize($header) === MappingSchema::normalize($technical)));
                    if (count($matches) === 1) {
                        $mapping[$field] = $matches[0];
                    }
                }
            } else {
                $title = MappingSchema::normalize($sheet['title']);
                $role = match ($title) {
                    'oferty','offers','jobs' => 'offers', 'aplikacje','applications' => 'applications', 'zdarzeniahr','events' => 'events', 'profileszukania','profiles' => 'profiles', default => null
                };
                if (! $role) {
                    continue;
                }
                $mapping = MappingSchema::suggest($role, $headers);
            }
            foreach ($profile === 'recruitflow' ? self::required($role) : MappingSchema::REQUIRED[$role] as $field) {
                if (! isset($mapping[$field])) {
                    throw new IntegrationException('Zakładka '.$sheet['title'].': nie rozpoznano wymaganej kolumny '.$field.'. Sprawdź strukturę arkusza lub użyj ustawień zaawansowanych.', 'schema_unrecognized');
                }
            }
            if (in_array($role, $roles, true)) {
                throw new IntegrationException('Rozpoznano kilka zakładek dla kolekcji '.$role.'. Wybierz właściwą w ustawieniach zaawansowanych.', 'schema_ambiguous');
            }
            $roles[] = $role;
            $plans[] = ['sheet_id' => $sheet['id'], 'sheet_title' => $sheet['title'], 'role' => $role, 'header_row' => 1, 'schema_profile' => $profile, 'mapping' => $mapping];
        }
        if (! $plans) {
            throw new IntegrationException('Nie rozpoznano tabel rekrutacyjnych. Ręczne mapowanie jest dostępne w ustawieniach zaawansowanych.', 'schema_unrecognized');
        }

        return $plans;
    }
}

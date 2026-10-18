<?php

namespace App\Recruitment;

use App\Integrations\Mapping\SheetValueNormalizer;

class OfferFields
{
    // Each field belongs to the existing Offer.data document. Industry attributes
    // remain optional; no occupation requires IT-specific fields.
    public const DEFINITIONS = [
        'company' => ['Firma', 'text', 'Podstawowe', 'company'],
        'title' => ['Stanowisko', 'text', 'Podstawowe', 'position'],
        'industry' => ['Branża', 'text', 'Podstawowe', 'company_industry'],
        'jobCategory' => ['Kategoria zawodowa', 'text', 'Podstawowe', 'position_category'],
        'specialization' => ['Specjalizacja', 'text', 'Podstawowe', null],
        'offerUrl' => ['Link do ogłoszenia', 'url', 'Podstawowe', 'offer_url'],
        'applyUrl' => ['Link do aplikowania', 'url', 'Podstawowe', 'apply_url'],
        'companySite' => ['Strona firmy', 'url', 'Podstawowe', 'company_site'],
        'platform' => ['Portal / źródło ogłoszenia', 'text', 'Podstawowe', 'platform'],
        'foundAt' => ['Data znalezienia', 'date', 'Podstawowe', 'first_found_at'],
        'publishedAt' => ['Data publikacji', 'date', 'Podstawowe', 'published_at'],
        'applyDeadlineAt' => ['Termin aplikowania', 'date', 'Podstawowe', 'apply_deadline_at'],
        'portalStatus' => ['Dostępność ogłoszenia', 'select', 'Podstawowe', 'portal_status', ['Aktywna', 'Zamknięta', 'Wygasła', 'Niedostępna', 'Niepotwierdzona']],
        'country' => ['Kraj', 'text', 'Organizacja pracy', 'country'],
        'region' => ['Region / województwo', 'text', 'Organizacja pracy', 'region'],
        'location' => ['Miasto', 'text', 'Organizacja pracy', 'city'],
        'address' => ['Adres', 'text', 'Organizacja pracy', 'office_address'],
        'workMode' => ['Tryb pracy', 'select', 'Organizacja pracy', 'work_model', ['Zdalnie', 'Hybrydowo', 'Stacjonarnie', 'Elastycznie', 'Nie podano']],
        'remoteFromPoland' => ['Praca z Polski', 'boolean', 'Organizacja pracy', 'remote_from_poland'],
        'remoteGeography' => ['Kraje pracy zdalnej', 'text', 'Organizacja pracy', 'remote_geography'],
        'officeDays' => ['Dni w biurze w tygodniu', 'number', 'Organizacja pracy', 'office_days_week'],
        'workload' => ['Wymiar etatu', 'select', 'Organizacja pracy', 'workload', ['Pełny etat', 'Część etatu', 'Projekt', 'Nie podano']],
        'workingHours' => ['Godziny pracy', 'text', 'Organizacja pracy', 'working_hours'],
        'shiftWork' => ['Praca zmianowa / dyżury', 'text', 'Organizacja pracy', 'shift_oncall'],
        'businessTravel' => ['Wyjazdy służbowe', 'boolean', 'Organizacja pracy', 'travel_required'],
        'relocation' => ['Relokacja', 'boolean', 'Organizacja pracy', 'relocation_required'],
        'salaryMin' => ['Wynagrodzenie minimalne', 'number', 'Wynagrodzenie', 'salary_min'],
        'salaryMax' => ['Wynagrodzenie maksymalne', 'number', 'Wynagrodzenie', 'salary_max'],
        'currency' => ['Waluta', 'text', 'Wynagrodzenie', 'salary_currency'],
        'salaryBasis' => ['Netto / brutto', 'select', 'Wynagrodzenie', 'salary_net_gross', ['Netto', 'Brutto', 'Netto + VAT', 'Nie podano']],
        'salaryPeriod' => ['Okres stawki', 'select', 'Wynagrodzenie', 'salary_period', ['Godzina', 'Dzień', 'Miesiąc', 'Rok', 'Projekt', 'Nie podano']],
        'employment' => ['Rodzaj umowy', 'text', 'Wynagrodzenie', 'contract_types'],
        'bonuses' => ['Premie', 'text', 'Wynagrodzenie', 'bonus_equity'],
        'commissions' => ['Prowizje', 'text', 'Wynagrodzenie', null],
        'benefits' => ['Benefity', 'textarea', 'Wynagrodzenie', 'benefits'],
        'negotiable' => ['Negocjowalność', 'boolean', 'Wynagrodzenie', 'salary_is_negotiable'],
        'financialNotes' => ['Dodatkowe warunki finansowe', 'textarea', 'Wynagrodzenie', 'salary_evidence'],
        'seniority' => ['Poziom doświadczenia', 'text', 'Wymagania', 'seniority'],
        'minExperienceYears' => ['Minimalne doświadczenie (lata)', 'number', 'Wymagania', 'min_experience_years'],
        'preferredExperienceYears' => ['Preferowane doświadczenie (lata)', 'number', 'Wymagania', 'max_experience_years'],
        'education' => ['Wykształcenie', 'text', 'Wymagania', 'education_required'],
        'requiredSkills' => ['Wymagane umiejętności', 'tags', 'Wymagania', 'required_technologies'],
        'preferredSkills' => ['Mile widziane umiejętności', 'tags', 'Wymagania', 'preferred_technologies'],
        'certifications' => ['Uprawnienia / certyfikaty', 'tags', 'Wymagania', null],
        'languages' => ['Języki obce', 'tags', 'Wymagania', 'other_languages'],
        'tools' => ['Narzędzia / oprogramowanie', 'tags', 'Wymagania', null],
        'requiredDocuments' => ['Wymagane dokumenty', 'textarea', 'Wymagania', 'apply_documents'],
        'additionalRequirements' => ['Dodatkowe wymagania', 'textarea', 'Wymagania', 'experience_requirements'],
        'programmingLanguages' => ['Języki programowania', 'tags', 'IT — opcjonalnie', 'technology_tags'],
        'backendFrameworks' => ['Frameworki backendowe', 'tags', 'IT — opcjonalnie', 'backend_frameworks'],
        'frontendFrameworks' => ['Frameworki frontendowe', 'tags', 'IT — opcjonalnie', 'frontend_frameworks'],
        'databases' => ['Bazy danych', 'tags', 'IT — opcjonalnie', 'databases'],
        'cloud' => ['Cloud', 'tags', 'IT — opcjonalnie', 'cloud_platforms'],
        'devops' => ['DevOps', 'tags', 'IT — opcjonalnie', 'devops_stack'],
        'integrations' => ['API / integracje', 'tags', 'IT — opcjonalnie', 'api_integrations'],
        'testing' => ['Testowanie', 'tags', 'IT — opcjonalnie', 'testing_stack'],
        'architecture' => ['Architektura systemów', 'tags', 'IT — opcjonalnie', 'architecture_stack'],
        'description' => ['Podsumowanie oferty', 'textarea', 'Opis stanowiska', 'job_summary'],
        'responsibilities' => ['Zakres obowiązków', 'textarea', 'Opis stanowiska', 'responsibilities'],
        'requirements' => ['Wymagania obowiązkowe', 'tags', 'Opis stanowiska', 'requirements_must'],
        'niceRequirements' => ['Wymagania dodatkowe', 'textarea', 'Opis stanowiska', 'requirements_nice'],
        'companyDescription' => ['Opis firmy', 'textarea', 'Opis stanowiska', 'company_description'],
        'teamDescription' => ['Opis zespołu', 'textarea', 'Opis stanowiska', 'team_description'],
        'recruitmentSteps' => ['Etapy rekrutacji', 'textarea', 'Opis stanowiska', 'recruitment_steps'],
        'additionalInfo' => ['Informacje dodatkowe', 'textarea', 'Opis stanowiska', 'additional_remarks'],
        'myInterest' => ['Zainteresowanie', 'select', 'Moje decyzje', 'my_interest', ['Nowa', 'Do sprawdzenia', 'Interesuje', 'Bardzo interesuje', 'Na później', 'Nie interesuje', 'Archiwum']],
        'favorite' => ['Ulubiona', 'boolean', 'Moje decyzje', 'favorite'],
        'hidden' => ['Ukryta', 'boolean', 'Moje decyzje', 'hidden'],
        'archived' => ['Archiwalna', 'boolean', 'Moje decyzje', 'record_status'],
        'priority' => ['Priorytet', 'select', 'Moje decyzje', 'priority', ['P1 — pilne', 'P2 — ważne', 'P3 — opcjonalne', 'Brak']],
        'myRating' => ['Moja ocena (0–100)', 'number', 'Moje decyzje', 'my_rating'],
        'watchOffer' => ['Obserwuj ofertę', 'boolean', 'Moje decyzje', 'watch_offer'],
        'nextAction' => ['Następne działanie', 'text', 'Moje decyzje', 'next_action'],
        'nextActionAt' => ['Termin działania / przypomnienie', 'datetime', 'Moje decyzje', 'next_action_at'],
        'myNotes' => ['Moje notatki', 'textarea', 'Moje decyzje', 'my_notes'],
    ];

    public static function publicFields(): array
    {
        return collect(self::DEFINITIONS)->map(fn ($definition, $key) => ['key' => $key, 'label' => $definition[0], 'type' => $definition[1], 'section' => $definition[2], 'options' => $definition[4] ?? []])->values()->all();
    }

    public static function import(array $data, array $raw, string $timezone = 'UTC'): array
    {
        foreach (self::DEFINITIONS as $property => $definition) {
            $column = $definition[3];
            if ($column === null || ! array_key_exists($column, $raw)) {
                continue;
            }
            $value = $raw[$column];
            if ($value === '' || $value === null) {
                $data[$property] = $definition[1] === 'tags' ? [] : ($definition[1] === 'boolean' || $definition[1] === 'number' || in_array($definition[1], ['date', 'datetime']) ? null : '');
            } elseif ($property === 'archived') {
                $data[$property] = $value === 'Archiwalny';
            } elseif ($definition[1] === 'boolean') {
                $data[$property] = in_array($value, ['Nie wiadomo', 'Nie podano'], true) ? null : SheetValueNormalizer::boolean($value, $column);
            } elseif ($definition[1] === 'number') {
                $number = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string) $value);
                $data[$property] = is_numeric($number) ? (float) $number : null;
            } elseif ($definition[1] === 'tags') {
                $data[$property] = array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', (string) $value) ?: [])));
            } elseif (in_array($definition[1], ['date', 'datetime'])) {
                $data[$property] = SheetValueNormalizer::date($value, $timezone, $column);
            } else {
                $data[$property] = (string) $value;
            }
        }

        return $data;
    }

    public static function empty(): array
    {
        $data = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $data[$key] = match ($definition[1]) {
                'tags' => [], 'boolean','number','date','datetime' => null, default => ''
            };
        }

        return $data + ['status' => 'unknown', 'owner' => '', 'department' => '', 'cvSent' => false, 'cvSentAt' => null, 'applicationStage' => 'Nie aplikowano', 'customAttributes' => [], 'extraData' => []];
    }
}

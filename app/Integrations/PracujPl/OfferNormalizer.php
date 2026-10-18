<?php

namespace App\Integrations\PracujPl;

use Carbon\CarbonImmutable;

final class OfferNormalizer
{
    public function normalize(PracujUrl $url, ?array $embedded, ?array $jsonLd): OfferDraftDTO
    {
        $attributes = $embedded['attributes'] ?? [];
        $employment = $attributes['employment'] ?? [];
        $publication = $embedded['publicationDetails'] ?? [];
        $form = ['offerUrl' => $url->url, 'platform' => 'Pracuj.pl'];
        $sources = ['offerUrl' => 'URL', 'platform' => 'URL'];
        $warnings = [];
        $put = static function (string $field, mixed $value, string $source) use (&$form, &$sources): void {
            if ($value !== null && $value !== '' && $value !== []) {
                $form[$field] = $value;
                $sources[$field] = $source;
            }
        };

        $title = $attributes['jobTitle'] ?? $jsonLd['title'] ?? null;
        $company = $attributes['displayEmployerName'] ?? $jsonLd['hiringOrganization'] ?? null;
        if (is_array($company)) {
            $company = $company['name'] ?? null;
        }
        $put('title', $title, isset($attributes['jobTitle']) ? 'embedded_json' : 'json_ld');
        $put('company', $company, isset($attributes['displayEmployerName']) ? 'embedded_json' : 'json_ld');
        if (isset($attributes['jobTitle'], $jsonLd['title']) && $attributes['jobTitle'] !== $jsonLd['title']) {
            $warnings[] = 'Tytuł różni się między danymi strony a JSON-LD; użyto danych strony.';
        }
        if (isset($attributes['displayEmployerName'], $jsonLd['hiringOrganization']) && is_string($jsonLd['hiringOrganization']) && $attributes['displayEmployerName'] !== $jsonLd['hiringOrganization']) {
            $warnings[] = 'Nazwa firmy różni się między danymi strony a JSON-LD; użyto danych strony.';
        }
        $put('industry', $attributes['leadingCategory']['parent']['name'] ?? null, 'embedded_json');
        $put('jobCategory', $attributes['leadingCategory']['name'] ?? null, 'embedded_json');
        $put('applyUrl', $attributes['applying']['applyUrl'] ?? null, 'embedded_json');
        $put('publishedAt', $this->date($publication['dateOfInitialPublicationUtc'] ?? $jsonLd['datePosted'] ?? null), isset($publication['dateOfInitialPublicationUtc']) ? 'embedded_json' : 'json_ld');
        $put('applyDeadlineAt', $this->date($publication['expirationDateTimeUtc'] ?? $jsonLd['validThrough'] ?? null), isset($publication['expirationDateTimeUtc']) ? 'embedded_json' : 'json_ld');
        $put('portalStatus', ($publication['isActive'] ?? true) ? 'Aktywna' : 'Wygasła', 'embedded_json');

        $locations = [];
        foreach ($attributes['workplaces'] ?? [] as $workplace) {
            $city = $workplace['inlandLocation']['location']['name'] ?? null;
            $locations[] = array_filter([
                'city' => $city,
                'region' => $workplace['region']['name'] ?? null,
                'country' => $workplace['country']['name'] ?? null,
                'address' => $workplace['displayAddress'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
        }
        if ($locations === [] && isset($jsonLd['jobLocation'])) {
            foreach ($this->list($jsonLd['jobLocation']) as $place) {
                $address = $place['address'] ?? [];
                $locations[] = array_filter(['city' => $address['addressLocality'] ?? null, 'region' => $address['addressRegion'] ?? null, 'country' => $address['addressCountry'] ?? null, 'address' => $address['streetAddress'] ?? null]);
            }
        }
        if ($locations !== []) {
            $source = $attributes['workplaces'] ?? [] ? 'embedded_json' : 'json_ld';
            $put('location', $locations[0]['city'] ?? null, $source);
            $put('region', $locations[0]['region'] ?? null, $source);
            $put('country', $locations[0]['country'] ?? null, $source);
            $put('address', $locations[0]['address'] ?? null, $source);
            if (count($locations) > 1) {
                $warnings[] = 'Oferta ma wiele lokalizacji. W formularzu pokazano pierwszą; wszystkie zachowano w szczegółach importu.';
            }
        }

        $modes = array_values(array_filter(array_map(static fn ($item) => $item['code'] ?? null, $employment['workModes'] ?? [])));
        $mode = count($modes) > 1 ? 'Elastycznie' : match ($modes[0] ?? '') {
            'remote', 'full-remote', 'remote-work', 'home-office' => 'Zdalnie',
            'hybrid', 'partial-remote' => 'Hybrydowo',
            'full-office', 'office' => 'Stacjonarnie',
            default => null,
        };
        $put('workMode', $mode, 'embedded_json');
        if (($employment['entirelyRemoteWork'] ?? false) === true && ($locations[0]['country'] ?? null) === 'Polska') {
            $put('remoteFromPoland', true, 'embedded_json');
            $put('remoteGeography', 'Polska', 'embedded_json');
        }
        $put('seniority', implode('; ', array_values(array_filter(array_map(static fn ($item) => $item['name'] ?? null, $employment['positionLevels'] ?? [])))), 'embedded_json');
        $put('workload', $this->workload($employment['workSchedules'] ?? []), 'embedded_json');

        $contracts = [];
        $variants = [];
        foreach ($employment['typesOfContracts'] ?? [] as $contract) {
            $name = $contract['name'] ?? null;
            if ($name) {
                $contracts[] = $name;
            }
            $salary = $contract['salary'] ?? null;
            if (! is_array($salary)) {
                continue;
            }
            $variants[] = [
                'contract' => $name,
                'min' => is_numeric($salary['from'] ?? null) ? (float) $salary['from'] : null,
                'max' => is_numeric($salary['to'] ?? null) ? (float) $salary['to'] : null,
                'currency' => $salary['currency']['code'] ?? null,
                'basis' => match ($salary['salaryKind']['code'] ?? '') {
                    'gross' => 'Brutto', 'net' => 'Netto', 'net-vat', 'net_plus_vat', 'net-plus-vat' => 'Netto + VAT', default => null
                },
                'period' => $this->period($salary['timeUnit']['shortForm']['name'] ?? $salary['timeUnit']['longForm']['name'] ?? null),
            ];
        }
        if ($contracts === [] && isset($jsonLd['employmentType'])) {
            $contracts = array_values(array_filter(is_array($jsonLd['employmentType']) ? $jsonLd['employmentType'] : [$jsonLd['employmentType']], 'is_string'));
        }
        $put('employment', implode('; ', $contracts), $employment['typesOfContracts'] ?? [] ? 'embedded_json' : 'json_ld');
        if (count($variants) === 1) {
            $single = $variants[0];
            foreach (['salaryMin' => 'min', 'salaryMax' => 'max', 'currency' => 'currency', 'salaryBasis' => 'basis', 'salaryPeriod' => 'period'] as $field => $key) {
                $put($field, $single[$key], 'embedded_json');
            }
        } elseif (count($variants) > 1) {
            $warnings[] = 'Oferta ma kilka stawek. Wybierz właściwą umowę i stawkę przed zapisem; wszystkie zachowano w szczegółach importu.';
        } elseif (isset($jsonLd['baseSalary']) && is_array($jsonLd['baseSalary'])) {
            $salary = $jsonLd['baseSalary'];
            $put('salaryMin', $salary['minValue'] ?? $salary['value'] ?? null, 'json_ld');
            $put('salaryMax', $salary['maxValue'] ?? $salary['value'] ?? null, 'json_ld');
            $put('currency', $salary['currency'] ?? null, 'json_ld');
        } else {
            $warnings[] = 'Nie udało się odczytać wynagrodzenia. Możesz uzupełnić je ręcznie.';
        }

        $sections = $this->sections($embedded['sections'] ?? []);
        $put('requiredSkills', $sections['technologies-expected'] ?? [], 'embedded_json');
        $put('preferredSkills', $sections['technologies-optional'] ?? [], 'embedded_json');
        $put('responsibilities', implode("\n", $sections['responsibilities'] ?? []), 'embedded_json');
        $put('requirements', $sections['requirements-expected'] ?? [], 'embedded_json');
        $put('niceRequirements', implode("\n", $sections['requirements-optional'] ?? []), 'embedded_json');
        $put('benefits', implode("\n", $sections['offered'] ?? []), 'embedded_json');
        $put('companyDescription', implode("\n", $sections['about-us-description'] ?? $sections['about-us'] ?? []), 'embedded_json');
        $put('recruitmentSteps', implode("\n", $sections['recruitment-stages'] ?? []), 'embedded_json');
        $put('additionalInfo', implode("\n", $sections['additional-module'] ?? []), 'embedded_json');
        $description = isset($sections['about-project']) ? implode("\n", $sections['about-project']) : ($jsonLd['description'] ?? $attributes['description'] ?? null);
        $descriptionSource = isset($sections['about-project']) ? 'embedded_json' : (isset($jsonLd['description']) ? 'json_ld' : 'embedded_json');
        $put('description', is_string($description) ? trim(strip_tags($description)) : null, $descriptionSource);
        if ($embedded === null) {
            $warnings[] = 'Dostępne są tylko dane JSON-LD; szczegóły ogłoszenia mogą być niepełne.';
        }
        if (! isset($form['title'], $form['company'])) {
            throw new ImportException('parse_error', 'Strona nie zawiera wiarygodnej nazwy stanowiska i firmy.', 502);
        }
        $details = [
            'provider' => 'pracuj.pl',
            'providerOfferId' => $url->offerId,
            'canonicalUrl' => $url->url,
            'locations' => $locations,
            'employmentTypes' => $contracts,
            'workModes' => $modes,
            'entirelyRemoteWork' => $employment['entirelyRemoteWork'] ?? null,
            'salaryVariants' => $variants,
            'parsedAt' => now()->toIso8601String(),
            'fieldSources' => $sources,
            'warnings' => $warnings,
        ];

        return new OfferDraftDTO($url->offerId, $url->url, $warnings ? 'partial' : 'success', $form, $details, $warnings, $sources);
    }

    private function date(?string $value): ?string
    {
        try {
            return $value ? CarbonImmutable::parse($value)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function list(array $value): array
    {
        return array_is_list($value) ? $value : [$value];
    }

    private function workload(array $schedules): ?string
    {
        if (count($schedules) !== 1) {
            return null;
        }
        $name = mb_strtolower($schedules[0]['name'] ?? '');

        return match ($name) {
            'pełny etat' => 'Pełny etat', 'część etatu' => 'Część etatu', default => null
        };
    }

    private function period(?string $value): ?string
    {
        $value = mb_strtolower($value ?? '');
        if (str_contains($value, 'godz')) {
            return 'Godzina';
        }
        if (str_contains($value, 'mies')) {
            return 'Miesiąc';
        }
        if (str_contains($value, 'dzie') || str_contains($value, 'dni')) {
            return 'Dzień';
        }
        if (str_contains($value, 'rok') || str_contains($value, 'rocz')) {
            return 'Rok';
        }

        return null;
    }

    private function sections(array $sections): array
    {
        $result = [];
        foreach ($sections as $section) {
            if (isset($section['sectionType'], $section['model'])) {
                $result[$section['sectionType']] = $this->sectionText($section['model']);
            }
            foreach ($section['subSections'] ?? [] as $subsection) {
                if (isset($subsection['sectionType'], $subsection['model'])) {
                    $result[$subsection['sectionType']] = $this->sectionText($subsection['model']);
                }
            }
        }

        return $result;
    }

    private function sectionText(array $model): array
    {
        $items = $model['bullets'] ?? $model['paragraphs'] ?? [];
        if (($model['modelType'] ?? null) === 'open-dictionary') {
            $items = array_merge($model['items'] ?? [], $model['customItems'] ?? []);
        }

        return array_values(array_filter(array_map(static fn ($item) => trim(strip_tags(is_string($item) ? $item : ($item['name'] ?? ''))), $items)));
    }
}

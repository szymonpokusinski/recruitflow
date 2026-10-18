<?php

// Reduced public fields observed on 2026-10-13 in the offer page's
// __NEXT_DATA__ and job-schema-org scripts. It is not a saved HTML page.
return [
    'id' => '1005120488',
    'slug' => 'specjalista-do-spraw-obslugi-klienta-legnica-hangarowa-13d',
    'offer' => [
        'jobOfferWebId' => 1005120488,
        'publicationDetails' => ['dateOfInitialPublicationUtc' => '2026-09-28T08:13:12.843Z', 'expirationDateTimeUtc' => '2026-10-28T22:59:59Z', 'isActive' => true],
        'attributes' => [
            'jobTitle' => 'Specjalista do spraw obsługi klienta',
            'displayEmployerName' => 'KALA sp. z o.o.',
            'description' => 'Obsługa klientów i zamówień.',
            'offerAbsoluteUrl' => 'https://www.pracuj.pl/praca/specjalista-do-spraw-obslugi-klienta-legnica-hangarowa-13d,oferta,1005120488',
            'leadingCategory' => ['name' => 'Sprzedawcy', 'parent' => ['name' => 'Obsługa klienta']],
            'applying' => ['applyUrl' => 'https://www.pracuj.pl/aplikuj/specjalista-do-spraw-obslugi-klienta-legnica-hangarowa-13d,oferta,1005120488'],
            'workplaces' => [['country' => ['name' => 'Polska'], 'region' => ['name' => 'dolnośląskie'], 'displayAddress' => 'Hangarowa 13D, Legnica', 'inlandLocation' => ['location' => ['name' => 'Legnica']]]],
            'employment' => [
                'workModes' => [['code' => 'full-office', 'name' => 'praca stacjonarna']],
                'workSchedules' => [['name' => 'pełny etat']],
                'positionLevels' => [['name' => 'specjalista / specjalistka (mid / regular)']],
                'typesOfContracts' => [['name' => 'umowa o pracę', 'salary' => ['currency' => ['code' => 'PLN'], 'from' => 5600, 'to' => 8000, 'salaryKind' => ['code' => 'gross'], 'timeUnit' => ['shortForm' => ['name' => 'mies.']]]]],
            ],
        ],
        'sections' => [
            ['sectionType' => 'responsibilities', 'model' => ['modelType' => 'bullets', 'bullets' => ['przyjmowanie zamówień telefonicznych i mailowych', 'wprowadzanie zamówień do systemu']]],
            ['sectionType' => 'requirements', 'subSections' => [['sectionType' => 'requirements-expected', 'model' => ['modelType' => 'bullets', 'bullets' => ['Doświadczenie na podobnym stanowisku']]], ['sectionType' => 'requirements-optional', 'model' => ['modelType' => 'bullets', 'bullets' => ['Dostępność od października']]]]],
            ['sectionType' => 'offered', 'model' => ['modelType' => 'bullets', 'bullets' => ['Pracę w biurze od poniedziałku do piątku']]],
        ],
    ],
    'jsonld' => [
        '@type' => 'JobPosting',
        'title' => 'Specjalista do spraw obsługi klienta',
        'hiringOrganization' => 'KALA sp. z o.o.',
        'baseSalary' => ['@type' => 'MonetaryAmount', 'minValue' => 5600, 'maxValue' => 8000, 'currency' => 'PLN'],
        'datePosted' => '2026-09-28T08:13:12.843Z',
        'validThrough' => '2026-10-28T22:59:59Z',
        'employmentType' => 'umowa o pracę',
        'jobLocation' => ['address' => ['addressLocality' => 'Legnica', 'addressRegion' => 'dolnośląskie', 'addressCountry' => 'Polska', 'streetAddress' => 'Hangarowa 13D']],
    ],
];

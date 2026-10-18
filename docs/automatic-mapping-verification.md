# Automatyczne podłączenie RecruitFlow

Poprzedni panel wymagał wyboru zakładek i kolumn oraz zakładał model kandydatów.
Obecnie schemat RecruitFlow jest rozpoznawany po technicznych nagłówkach.
Panel domyślny zawiera konto Google, URL, „Połącz i synchronizuj” oraz stan/liczniki.
Ręczne mapowanie i administracja pozostają pod `/settings/integrations/advanced`.

## Faktycznie wykonana weryfikacja

Na istniejącym koncie OAuth wykonano rzeczywisty odczyt Google Sheets.
Bez ręcznych przypisań rozpoznano cztery główne tabele z 16 zakładek:

| Kolekcja | Rekordy |
|---|---:|
| Oferty | 17 |
| Aplikacje | 17 |
| Zdarzenia HR | 18 |
| Profile wyszukiwania | 4 |
| Łącznie | 56 |

Ponowny odczyt oraz synchronizacja uruchomiona przyciskiem w przeglądarce
zwróciły te same 56 rekordów. Lokalny cache nie zawiera duplikatów.
Zachowano istniejące konto, poświadczenia i źródło. Nie resetowano SQLite.
Do Google Sheets wysyłano wyłącznie żądania GET. Nie wykonywano operacji Gmail.

Przycisk UI przetestowano w tymczasowym serwerze Laravel na loopback,
z istniejącą lokalną bazą NativePHP i oryginalnymi poświadczeniami w Keychain.
NativePHP wyświetla rzeczywistą historię HR i szczegóły aplikacji.
Dashboard pokazuje 17 aplikacji w procesie. Dostępność wszystkich 17 ofert
ma w arkuszu stan „NIEPOTWIERDZONA”; nie zaliczamy ich do potwierdzonych aktywnych ofert.

## Zmienione pliki

- `app/Integrations/Mapping/RecruitFlowSchemaDetector.php`: profil technicznych pól,
  wykrywanie ról po nagłówkach, pomijanie pomocniczych zakładek, rozpoznawanie innych struktur.
- `app/Integrations/Mapping/SheetValueNormalizer.php`: polskie/ISO/numeryczne daty i wartości logiczne.
- `app/Integrations/Mapping/{MappingSchema,RecruitmentMapper}.php`: osobne statusy,
  rozszerzone pola, dodatkowe kolumny, opcjonalność pól i klucze właściwe dla profilu.
- `app/Integrations/{AutoConnectService,SyncService,CanonicalCache,DataSourceManager}.php`:
  automatyczne plany, atomowa konfiguracja i cache, relacje, źródło prawdy dla pól formuł.
- `app/Models/Integrations/{DataSource,DataSourceConfiguration}.php`,
  `app/Models/Recruitment/SearchProfile.php`.
- Migracje `2026_10_12_220000_add_schema_profile_to_sources.php`
  i `2026_10_12_230000_create_search_profiles_cache.php` — dodanie pól/tabeli bez resetu danych.
- `app/Http/Controllers/Integrations/IntegrationController.php`,
  `app/Http/Requests/Integrations/SaveMappingsRequest.php`, `routes/web.php`.
- `app/Console/Commands/SyncDataSource.php`: diagnostyka metadanych i automatyczny import CLI.
- `resources/js/Pages/{Integrations,IntegrationAdvanced,Applications,ApplicationShow,Activity,Dashboard,OfferShow}.vue`.
- `resources/js/composables/{useAutomaticSource,useIntegrations,useDetails,useActivity}.ts`.
- `resources/js/services/integrations.ts`, `resources/js/types/{integrations,recruitment}.ts`,
  `resources/js/domain/recruitment.ts`.
- `resources/js/components/{ApplicationTable,ActivityTimeline,OfferDetails}.vue`.
- `tests/Feature/AutomaticMappingTest.php`, `tests/frontend/connected.test.ts`.
- `README.md`, `docs/google-oauth.md`, ten raport i podgląd panelu.

## Komendy i wyniki

```bash
php artisan migrate --no-interaction
php artisan native:migrate --no-interaction
php artisan integration:sync-source --native --inspect
php artisan integration:sync-source --native
php artisan test --compact
npm test
npm run typecheck
npm run build
vendor/bin/pint --test
git diff --check
```

- Laravel: 45 testów, 676 asercji.
- Frontend: 8 testów.
- TypeScript strict, produkcyjny build Vite, Pint i kontrola diff: poprawne.
- Testy obejmują wykrywanie ról, aliasy nagłówków, zmienione nazwy zakładek,
  relacje także dla zdarzeń bez aplikacji, normalizację, wszystkie profile,
  dodatkowe kolumny, pomijanie pomocniczych zakładek, idempotencję i zachowanie
  mapowania/cache po błędzie.

Nie pozostał blokujący błąd dla podłączonego arkusza. Inne struktury bez
jednoznacznych kluczy wymagają profilu lub ustawień zaawansowanych; aplikacja
nie zgaduje niepewnych powiązań. Zrzut z rzeczywistą konfiguracją źródła pozostaje lokalny i jest wykluczony z Git.

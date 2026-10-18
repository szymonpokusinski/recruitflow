# Etap 3 — raport pierwszej wersji

Ten raport opisuje wcześniejszą wersję z ręcznym mapowaniem.
Aktualna implementacja i rzeczywisty test odczytu: [automatyczne mapowanie](automatic-mapping-verification.md).

## Wynik

Działa panel `/settings/integrations`, konfiguracja kont i wielu źródeł,
metadane zakładek, mapowanie nagłówków, test odczytu, aktywacja, źródło główne,
ręczna/cykliczna synchronizacja i lokalny cache. Główne widoki wybierają DEMO
wyłącznie przy braku aktywnych źródeł. Podłączone dane są tylko do odczytu.
Nie dodano zależności Composer/npm w tym etapie.

## Pliki etapu 3

- `app/Integrations/Contracts/{CredentialStore,DataSourceAdapterInterface}.php`
- `app/Integrations/Security/{KeychainCredentialStore,EncryptedFileCredentialStore}.php`
- `app/Integrations/Google/{GoogleOAuthService,GoogleSheetsAdapter,SpreadsheetIdentifier}.php`
- `app/Integrations/Mapping/{MappingSchema,RecruitmentMapper}.php`
- `app/Integrations/{IntegrationException,DataSourceManager,SyncService,CanonicalCache}.php`
- `app/Models/Integrations/{DataSource,DataSourceConfiguration,DataSourceFieldMapping,DataSourceRecord,SyncRun,IntegrationAccount,IntegrationSetting,OAuthFlow}.php`
- `app/Models/Recruitment/{Offer,Application,RecruitmentEvent}.php`
- `app/Console/Commands/{ListenForGoogleOAuth,SyncDueSources}.php`
- `app/Http/Controllers/Integrations/IntegrationController.php`
- `app/Http/Requests/Integrations/{SaveDataSourceRequest,SaveMappingsRequest}.php`
- `app/Http/Middleware/{LocalIntegrationAccess,HandleInertiaRequests}.php`
- `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`
- `config/integrations.php`, `config/database.php`, `config/nativephp.php`
- `database/migrations/2026_10_12_160000_create_integrations_tables.php`
- `routes/web.php`, `routes/console.php`
- `resources/js/Pages/Integrations.vue` oraz aktualizacje istniejących stron
  Dashboard, Offers, OfferShow, Applications, ApplicationShow, Activity i Settings
- `resources/js/composables/{useIntegrations,useRecruitment,useDashboard,usePreferences}.ts`
- `resources/js/services/{http,integrations,recruitment}.ts`
- `resources/js/types/{integrations,recruitment}.ts`, `resources/js/domain/recruitment.ts`
- `resources/js/layouts/DesktopLayout.vue`
- `resources/js/components/{OfferStatus,ApplicationStatus,OfferDetails,OfferTable,ApplicationTable,ActivityTimeline}.vue`
- `resources/css/desktop-app.css`
- `tests/Feature/IntegrationsTest.php`, `tests/frontend/connected.test.ts`
- `.gitignore`, `.env.example`, `README.md`, `docs/google-oauth.md`, ten dokument
- `docs/screenshots/recruitflow-integrations.png`

Konfiguracja działającego okna/menu NativePHP została zachowana. Jedyna zmiana
`config/nativephp.php` w etapie 3 wyklucza pliki OAuth i prywatny magazyn
poświadczeń z paczki aplikacji. SQLite otrzymał WAL i busy timeout 5 sekund,
aby wspierać scheduler, odbiornik OAuth i żądania UI w osobnych procesach.
Poprzednie etapy pozostają w tym samym niezacommitowanym drzewie roboczym.
Nie dodano remote ani nie wykonano push.

## Wykonane komendy

```bash
php artisan route:list --except-vendor
php artisan migrate:status
php artisan migrate --no-interaction
php artisan native:migrate --no-interaction
php artisan test --compact
npm test
npm run typecheck
npm run build
vendor/bin/pint --dirty
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
php artisan schedule:list
git diff --check
php artisan native:run --no-dependencies --no-interaction -v
```

Frontend sformatowano także dostępnym lokalnie CLI Prettier z runtime'u
NativePHP, bez instalowania nowego pakietu. Migracje wykonano na lokalnych
`database/database.sqlite` i `database/nativephp.sqlite`. Nie zmieniano
żadnych danych w Google Sheets.

## Wyniki

- Laravel: **38 testów, 338 asercji**, wszystkie poprawne.
- Frontend: **7 testów**, wszystkie poprawne.
- TypeScript strict, produkcyjny build Vite, Pint i `git diff --check`: poprawne.
- Composer: poprawny manifest i zgodność wymagań platformy PHP 8.3.33.
- Testy backendu: dynamiczne tytuły/ID zakładek, puste kolumny, mapowanie,
  wymagane pola, relacje, duplikaty, stabilne UUID, konflikty wielu źródeł,
  pierwszeństwo źródła, błędy sieci, 401/refresh, zmiany schematu, interwały,
  blokada zmian podczas synchronizacji i atomowość cache.
- OAuth: PKCE z wektorem RFC, state, ochrona przed ponownym użyciem,
  odmowa zgody, wygasłe logowanie, odrzucenie dodatkowych zakresów,
  zachowanie refresh tokenu oraz brak sekretów w odpowiedziach API/SQLite.
- Magazyn zastępczy: szyfrowanie, wykrywanie uszkodzenia i uprawnienia pliku 0600.
  Automatyczne testy używają magazynu poświadczeń w pamięci i atrap Google HTTP.
- Herd: panel renderuje się poprawnie, przełącznik motywu działa, konsola
  bez błędów. Dla szerokości 800 i 390 px dokument nie przepełnia viewportu.
- NativePHP: uruchomiono Electron, PHP server na loopback, główną stronę,
  endpoint lokalnego cache i scheduler. Automatyczna kontrola natywnego GUI
  była zablokowana przez ekran blokady macOS.

## Pozostała weryfikacja wymagająca użytkownika

Rzeczywista autoryzacja Google, dostęp do Keychain przez działającą aplikację
oraz odczyt wybranego arkusza muszą zostać sprawdzone po imporcie klienta,
udanej zgodzie OAuth i skonfigurowaniu źródła przez użytkownika.
Na czas sporządzenia tego dokumentu nie ma jeszcze skonfigurowanego klienta,
połączonego konta ani źródła w lokalnej bazie Herd lub NativePHP.
Nie przedstawiamy testów z atrapami jako potwierdzenia rzeczywistego odczytu.
Dokładne kroki: [google-oauth.md](google-oauth.md).

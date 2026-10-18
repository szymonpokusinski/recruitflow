# Cykliczne skanowanie kategorii

Warstwa domenowa Laravel 12 / PHP 8.3 dla NativePHP Desktop 2. Provider jest
wybierany przez klucz tekstowy; modele, job i zapis wyników nie znają konkretnych
API. Dodanie Gemini lub innego drivera nie wymaga zmian tego przepływu.

## Modele i migracja

Migracja `2026_10_14_140000_create_ai_scanning_tables.php` tworzy wyłącznie:

- `ai_categories`: slug, nazwa, aktywność, opcjonalny cron, provider i ustawienia.
- `ai_category_prompts`: nazwane, numerowane wersje promptów; jeden aktywny
  prompt na kategorię. `AiCategoryService::addPrompt()` tworzy nową wersję
  zamiast zmieniać tekst wcześniejszej.
- `ai_search_runs`: trwały UUID, kategoria, użyty prompt, provider/model,
  status, czas zaplanowania, start/koniec, bezpieczny błąd i metadane.
- `ai_findings`: wszystkie pola `AiResult.items`, ostatni run, provider/model,
  hash, first/last seen oraz flaga `is_new`.
- `ai_finding_identities`: indeksowane aliasy URL, external ID lub treści.
  Zachowuje wcześniejsze tożsamości po zmianie linku/identyfikatora.

Nie ma drugiego modelu głównych ofert. Findings są wynikami wyszukiwania do
dalszej obsługi; nie zapisujemy ich automatycznie do `offers`, aplikacji,
Google Sheets ani outboxa. W tym zadaniu nie dodawano UI.

Klucze obce chronią użyte prompty i historię przed przypadkowym usunięciem.
SQLite wymusza jeden aktywny prompt oraz jeden otwarty (`queued`/`running`)
skan na kategorię, również między procesami. Unikalność kategorii i minuty UTC
chroni harmonogram przed ponownym zleceniem tej samej pracy.

Przed użyciem w wybranym środowisku wykonaj migracje addytywne. Jeżeli wcześniejsza
migracja providerów jest już zastosowana, Laravel ją pominie:

```bash
php artisan migrate --path=database/migrations/2026_10_14_120000_create_ai_provider_tables.php --path=database/migrations/2026_10_14_140000_create_ai_scanning_tables.php
```

Nie używaj `migrate:fresh`, resetowania SQLite ani rollbacku na bazie użytkownika.
Testy wykonały migrację w SQLite `:memory:` oraz osobnym pliku tymczasowym;
nie zastosowano jej do istniejącej bazy użytkownika.

## Konfiguracja kategorii i promptu

Interfejsem domenowym jest `AiCategoryService`, rozwiązywany przez kontener.
Przykład własnej konfiguracji do wykonania lokalnie przez backend/Tinker;
nie został automatycznie uruchomiony na rzeczywistych danych:

```php
use App\Services\Ai\Scanning\AiCategoryService;

$categories = app(AiCategoryService::class);
$category = $categories->save([
    'slug' => 'accounting',
    'name' => 'Księgowość',
    'is_active' => true,
    'schedule_cron' => '0 */6 * * *',
    'default_provider' => null, // użyj globalnego wyboru z DB/config
    'settings' => [
        'timezone' => 'Europe/Warsaw',
        'filters' => ['location' => 'Warszawa', 'keywords' => ['księgowość']],
        'options' => ['max_items' => 20],
    ],
]);
$categories->addPrompt(
    $category,
    'Wersja 1',
    'Znajdź aktualne, potwierdzone oferty księgowego spełniające kryteria.',
);
```

`schedule_cron=null` oznacza kategorię uruchamianą ręcznie. `is_active=false`
wyłącza skanowanie. Ustawienia i filtry podlegają walidacji, cron jest sprawdzany
przez istniejący pakiet używany przez Laravel. API keys i tokeny są zabronione
w settings i options; konfiguruj je w magazynie właściwego providera.
Nie umieszczaj ich również w tekście promptu.

Każdy run zapamiętuje ID, wersję i hash użytego promptu oraz żądany model.
Nie zapisujemy pełnego żądania HTTP, nagłówków lub treści wyjątku providera.

## Przepływ i wybór providera

`ScanCategoryJob(categoryId)` posiada trwały `runId`. Przy harmonogramie
`CategoryScanDispatcher` najpierw tworzy claim `queued`, a potem zleca job.
Dla lokalnej kolejki `database` oba wpisy są zapisywane w tej samej transakcji
SQLite. Worker atomowo zmienia status na `running`.

1. Pobierz aktywną kategorię i jej aktywny prompt.
2. Wybierz provider: `--provider` / override joba → `category.default_provider`
   → aktywny wybór `AiProviderManager` z DB/config.
3. Wywołaj istniejący `CategorySearchService`, który używa wspólnego interfejsu.
4. Ponownie zwaliduj DTO przez `AiResultMapper`.
5. W jednej krótkiej transakcji upsertuj findings i zakończ run.

Transakcja zapisu nie obejmuje oczekiwania na sieć. Brak promptu, zła odpowiedź
JSON, błąd providera lub konflikt tożsamości kończą **ten run** jako `failed`.
Inne kategorie batcha i joby nadal są obsługiwane. Run manualnego batcha
sygnalizuje błędy kodem wyjścia 1 dopiero po obsłużeniu wszystkich kategorii.

Pusty wynik jest sukcesem z `received_count=0`, nie kasuje wcześniej znalezionych
pozycji. Wyniki błędnego lub spóźnionego runu nie trafiają do cache.
Ponowne dostarczenie tego samego zserializowanego joba nie wykonuje drugi raz AI.
Świadomy kolejny skan tworzy nową historię runu i aktualizuje istniejące findings.

## Deduplikacja i nowe pozycje

- URL używa istniejącej normalizacji `OfferIdentity`: usuwa fragment/tracking,
  porządkuje query i rozpoznaje stabilne identyfikatory Pracuj.pl.
- External ID jest zakresowany providerem i hostem źródła. Identyfikator `123`
  na dwóch różnych portalach nie oznacza automatycznie jednej oferty.
- Rekord może zyskać URL po pierwszym odczycie bez linku; zachowujemy aliasy.
- Przy braku URL i external ID stosujemy hash znormalizowanych danych
  identyfikacyjnych: tytułu, lokalizacji, firmy i opisu. Nie zgadujemy numerów
  rekordów ani nie łączymy automatycznie podobnych stanowisk różnych firm.
- Ten sam URL od różnych providerów w jednej kategorii daje jeden finding.
  Kategorie mają własne zestawy wyników.
- Gdy aliasy wskazują dwa różne istniejące rekordy, cały zapis danego runu
  jest wycofywany z `identity_conflict`; poprzednie dane zostają zachowane.

Bez stabilnego ID/URL zmiana treści może oznaczać nowy hash. Nie deklarujemy
semantycznej deduplikacji wszystkich ogłoszeń ani nie wykonujemy niebezpiecznego
fuzzy merge. `run_id` w finding wskazuje ostatnie udane zaobserwowanie.

Nowy finding otrzymuje `is_new=true`. Kolejne skany aktualizują `last_seen_at`
i dane, ale zachowują `first_seen_at` i stan przeglądnięcia. Brak opcjonalnego
pola nie kasuje wcześniej znanej wartości; zero nie jest traktowane jako brak.
Oznaczenie przeglądnięcia:

```php
app(\App\Services\Ai\Scanning\FindingRepository::class)->markSeen($finding);
```

Run ma liczniki `received_count`, `observed_count`, `new_count` i listę nowych ID.
Nie generujemy nowych ocen AI ani nie zastępujemy istniejących ocen z Google Sheets.

## Artisan

```bash
php artisan ai:scan
php artisan ai:scan accounting
php artisan ai:scan 1 --provider=provider_profile_key
php artisan ai:scan accounting --queue
php artisan ai:scan --due --queue
```

Bez kategorii skanujemy aktywne kategorie. ID i slug są obsługiwane.
`--queue` zleca pracę; status `queued` nie oznacza jeszcze udanego odczytu.
`--due` uwzględnia cron i przełącznik automatycznego skanowania oraz zawsze
zleca pracę asynchronicznie. Runy można odczytać przez `AiSearchRun` i relację
`$category->runs()`. Błąd jednej kategorii nie zatrzymuje pozostałych.

## NativePHP i Mac

[NativePHP Desktop 2](https://nativephp.com/docs/desktop/2/digging-deeper/child-processes)
uruchamia scheduler co minutę, tylko podczas działania aplikacji. Potwierdzono
to również w używanym runtime Electron (`startScheduler` → `artisan schedule:run`).
Nie instalujemy drugiego timera ani usługi systemowej.

Konfiguracja lokalnego środowiska:

```dotenv
QUEUE_CONNECTION=database
AI_SCANNING_ENABLED=true
```

Skonfiguruj właściwego providera, klucz i własne aktywne kategorie z cronem.
Nie włączono tego przełącznika automatycznie. Po zmianie `.env` usuń cache
konfiguracji (`php artisan config:clear`) i uruchom ponownie aplikację.

`routes/console.php` zleca `ai:scan --due --queue` co minutę. Worker `ai-scans`
w `config/nativephp.php` startuje razem z NativePHP i jest oddzielony od kolejki
`integrations`. Konfiguracji okien, Menu Bar, OAuth i widgetów nie zmieniono.
Podgląd tylko do odczytu nie uruchamia skanów.

Timeout workera/joba: 930 s, lease runu: 960 s, `database.retry_after`: co najmniej
990 s. Pozwala to obsłużyć istniejący deadline API do 900 s bez równoległego
ponownego dostarczenia joba. Zwiększenie lease bazy było konieczne, ponieważ
wcześniejsze 360 s było krótsze od maksymalnego czasu pracy AI. Nie zmieniono
300-sekundowego timeoutu workera synchronizacji Google.

Po zamknięciu aplikacji scheduler i worker nie pracują. Pominięte terminy nie
są odtwarzane jako nieograniczony backlog płatnych wywołań. Zapisane, nieprzetworzone
joby pozostają w SQLite. Zbyt stare runy są oznaczane `interrupted` podczas
kolejnej próby kategorii: running po 960 s, queued po 24 h. Spóźniony wynik
nie może nadpisać danych nowszego runu. Zamknięcie systemu w trakcie cloud runu
może wymagać późniejszego sprawdzenia konta providera; nie udajemy potwierdzonego
anulowania po ubiciu procesu macOS.

W Herd, bez uruchomionego NativePHP, otwórz dwa procesy w katalogu projektu:

```bash
php artisan schedule:work
php artisan queue:work database --queue=ai-scans --timeout=930 --sleep=2
```

`php artisan schedule:list` pokazuje oba harmonogramy. Nie uruchamiaj dwóch
schedulerów dla tej samej bazy bez potrzeby. Kod installed app wymaga ponownego
zbudowania/wdrożenia paczki; ta implementacja nie zastępuje istniejącej aplikacji
w `/Applications` i nie zmienia jej bazy automatycznie.

## Factory i testy

Factories w `database/factories/Ai` dotyczą czterech modeli domenowych.
Kategorie i prompty fabryczne są domyślnie nieaktywne; są to narzędzia testowe,
nie seed produkcyjnych kategorii, ofert lub ocen. Nie dodano automatycznego seeda.

Testy sprawdzają happy path, pusty wynik, invalid JSON przez faktyczny driver
z `Http::fake`, błędy providerów, priorytet wyboru, deduplikację, rollback konfliktu,
wersje promptów, ochronę historii, nowe/przeglądnięte wyniki, soft failure batcha,
powtórzenie joba, cron/strefę czasu, queue worker oraz constraints SQLite.
Osobny test plikowego SQLite zamyka połączenie i ponownie otwiera bazę: job,
wyniki i stan przeglądnięcia zostają zachowane.

```bash
php artisan test --compact tests/Feature/Ai/CategoryScanningTest.php tests/Feature/Ai/CategoryScanScheduleTest.php tests/Feature/Ai/CategoryScanPersistenceTest.php
php artisan test --compact
npm run test
npm run typecheck
npm run build
```

Nie wykonano realnych wywołań Cursor ani innych providerów, zgodnie z wcześniejszą
decyzją o testach automatycznych. Nie zmodyfikowano produkcyjnego Google Sheets,
konfiguracji OAuth ani istniejących rekordów SQLite użytkownika.

Końcowe wyniki 2026-10-14:

- **36 nowych testów domenowych: zaliczone**.
- **Pełny zestaw Laravel: 226 testów, 1867 asercji — zaliczony**.
- **JavaScript frontend/desktop: 29 testów — zaliczone**.
- **TypeScript strict, produkcyjny build Vite, Pint, `git diff --check`: zaliczone**.
- `ai:scan --help` i `schedule:list`: nowe polecenie i harmonogram są dostępne.
- Kolejka database, wykonanie joba i ponowne otwarcie plikowej SQLite: sprawdzone
  testami automatycznymi, bez realnego wywołania API.
- Live NativePHP z realnym providerem oraz ponowny build zainstalowanej aplikacji:
  nie wykonywano w tym zadaniu. Migrations nie zastosowano do bazy użytkownika.

## Pliki implementacji

```text
app/Models/Ai/{AiCategory,AiCategoryPrompt,AiSearchRun,AiFinding}.php
app/Services/Ai/Scanning/{AiCategoryService,CategoryScanService,CategoryScanDispatcher}.php
app/Services/Ai/Scanning/{FindingIdentity,FindingRepository,AiScanException}.php
app/Jobs/ScanCategoryJob.php
app/Console/Commands/ScanAiCategories.php
database/factories/Ai/{AiCategoryFactory,AiCategoryPromptFactory,AiSearchRunFactory,AiFindingFactory}.php
database/migrations/2026_10_14_140000_create_ai_scanning_tables.php
config/ai_scanning.php
tests/Feature/Ai/{CategoryScanningTest,CategoryScanScheduleTest,CategoryScanPersistenceTest}.php
tests/Support/Ai/ControlledScanProvider.php
```

Zmiany istniejących plików: `AiProviderManager`, `CategorySearchService`,
`routes/console.php`, `config/nativephp.php`, `config/queue.php`, `.env.example`
i dokumentacja. Rozszerzenie `CategorySearchService` o opcjonalny provider
zachowuje dotychczasowe wywołania i ścieżkę bez persystencji.

# RecruitFlow Desktop

Zainstalowana aplikacja macOS: **`/Applications/RecruitFlow.app`**. Otwieraj ją z Aplikacji, Spotlight lub Docka, bez terminala. [Instrukcja instalacji i widgetów](desktop/INSTALLED_MACOS.md).

RecruitFlow działa lokalnie na SQLite, z opcjonalną integracją Google Sheets i automatycznym importem ofert Pracuj.pl po adresie URL.
Interfejs aplikacji macOS na Laravel 12, NativePHP Desktop 2,
Vue 3 Composition API, TypeScript strict, Quasar 2 i Inertia 2.
Vite buduje frontend; wszystkie ikony i style są dostarczane lokalnie.

## Wymagania

- macOS 12+ dla NativePHP
- PHP 8.3+, Composer
- Node.js 22.12+ (w projekcie sprawdzono 22.22.2), npm
- Laravel Herd dla podglądu `http://recruitflow.test`

## Uruchomienie obecnego projektu

Bez serwera developerskiego Vite:

```bash
php artisan migrate
php artisan native:migrate
npm run build
php artisan native:run --no-dependencies
```

Ten sam build działa przez Herd: otwórz `http://recruitflow.test`.
Po zmianach frontendu wykonaj ponownie `npm run build` i odśwież okno.
Zamknięcie aplikacji: `Cmd+Q` lub `Ctrl+C` w terminalu.

Podczas pracy z automatycznym odświeżaniem:

```bash
composer native:dev
```

Alias uruchamia `native:run` i `npm run dev` równolegle. Dla samego podglądu
Herd wystarczy `npm run dev`. Vite akceptuje lokalne originy Herd i NativePHP.
Po zatrzymaniu Vite nie powinien pozostać plik `public/hot`; gdy proces zostanie
przerwany awaryjnie, usuń tylko ten plik i wykonaj `npm run build`.

## Pierwsza instalacja po sklonowaniu

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan native:install --no-interaction
npm run build
php artisan native:run --no-dependencies
```

Nie nadpisuj istniejącego `.env`. Podgląd Herd korzysta z lokalnej sesji
Laravel; dla świeżej instalacji utwórz `database/database.sqlite` i uruchom
`php artisan migrate` wyłącznie na lokalnej bazie. Ustaw `APP_URL=http://recruitflow.test`.
NativePHP tworzy osobną lokalną bazę `database/nativephp.sqlite` przy pierwszym
starcie. Etap 3 dodaje migrację konfiguracji integracji, historii synchronizacji oraz
cache domenowego. Uruchom `php artisan migrate` dla Herd i
`php artisan native:migrate` dla desktopu. Bazy i konfiguracje są oddzielne.

## Dostępne funkcje

- Dashboard z wyliczanymi statystykami i ostatnimi zdarzeniami.
- Oferty: ręczny zapis i edycja, import Pracuj.pl, filtry, sortowanie, szczegóły,
  preferencje użytkownika i powiązane aplikacje.
- Aplikacje: lokalna historia CV i etapów, widok Kanban oraz filtrowanie.
- Aktywność HR: trwała historia zdarzeń z filtrowaniem.
- Ustawienia: motyw, profile, integracje i stan synchronizacji.
- Responsywna nawigacja, przewijane tabele oraz stany pustych wyników.

Bez podłączonego arkusza dane ofert i aplikacji są trwale przechowywane w SQLite.
Fikcyjny zestaw DEMO jest wyświetlany wyłącznie w trybie demonstracyjnym; jest
oznaczony i nie jest używany jako dane produkcyjne. Integracja Google pozostaje
opcjonalna; zapis do rzeczywistego arkusza wymaga osobnej konfiguracji źródła
z uprawnieniem do edycji. Live testów zapisu do produkcyjnego arkusza nie wykonano.

Herd i NativePHP używają oddzielnych plików SQLite; lokalne zmiany w jednym
środowisku nie pojawiają się automatycznie w drugim. Przełączenie środowiska nie
usuwa danych z pierwszej bazy.

## Import oferty Pracuj.pl

Na ekranie **Oferty** kliknij **Dodaj ofertę**, wklej pełny adres konkretnego
ogłoszenia Pracuj.pl i kliknij **Importuj ofertę**. Backend sam pobierze publiczne
dane ogłoszenia; firma, stanowisko, lokalizacja, tryb pracy, wynagrodzenie,
umowy, wymagania i opis pojawią się w istniejącym formularzu, o ile występują
w ogłoszeniu. Możesz poprawić dane, wybrać wariant stawki i dopiero potem kliknąć
**Zapisz**. Podgląd niczego nie zapisuje. Ten sam identyfikator oferty jest
wykrywany jako duplikat również po zmianie slugu lub parametrów `utm`.

Jeśli Pracuj.pl odpowie HTTP 403, 429 lub zmieni strukturę strony, aplikacja pokaże
diagnostyczny błąd bez omijania zabezpieczeń. Brakujące dane pozostaną puste.
Metoda pobierania, ograniczenia i 10 sprawdzonych ofert są opisane w
[raporcie badania](research/pracuj-pl/RESEARCH_REPORT.md).

## Konfiguracja integracji

Dokładne kroki Google Cloud, import klienta, podłączenie konta, dodawanie własnych
arkuszy i mapowanie znajdują się w [docs/google-oauth.md](google-oauth.md).
Domyślny panel wymaga tylko URL i kliknięcia „Połącz i synchronizuj”.
Schemat RecruitFlow rozpoznaje się automatycznie; ręczne mapowanie pozostaje
w ustawieniach zaawansowanych dla innych struktur.
Żaden arkusz ani klient OAuth nie jest wpisany na stałe. Na macOS sekrety są
w Keychain, a SQLite przechowuje metadane i cache. Nie dodano nowych zależności.
W Herd dla synchronizacji cyklicznej uruchom `php artisan schedule:work`; NativePHP
uruchamia scheduler podczas działania aplikacji.

## Podział odpowiedzialności

- `resources/js/types/recruitment.ts`: typy domenowe, DTO i kontrakt `RecruitmentService`.
- `resources/js/data/demo.ts`: niezmienny, fikcyjny zestaw danych.
- `resources/js/domain/recruitment.ts`: mapowanie DTO, wyszukiwanie, relacje i statystyki.
- `resources/js/services/DemoRecruitmentService.ts`: walidacja i lokalna persystencja DEMO.
- `resources/js/services/recruitment.ts`: wybór DEMO albo backendowego cache źródeł.
- `resources/js/services/integrations.ts` i `composables/useIntegrations.ts`: API i obsługa panelu.
- `app/Integrations`: OAuth PKCE, magazyn poświadczeń, adapter Sheets, mapowanie, manager i sync.
- `app/Models/Integrations`, `app/Models/Recruitment`: konfiguracja i lokalne modele domenowe.
- `resources/js/composables`: stan, filtry, obsługa formularzy i preferencji.
- `resources/js/components`: OfferTable, OfferDetails, ApplicationTable,
  ApplicationStatus, OfferStatus, ActivityTimeline, StatsCard, EmptyState i PageHeader.
- `resources/js/Pages`: dziewięć widoków Inertia; logika biznesowa pozostaje poza nimi.
- `resources/js/layouts/DesktopLayout.vue`: trwały layout, nagłówek i nawigacja.
- `app/Http/Controllers/DesktopController.php`: strony oraz identyfikatory szczegółów.
- `app/Http/Middleware/HandleInertiaRequests.php`: metadane środowiska i protokół Inertia.

Inertia obsługuje nawigację i historię po trasach Laravel. Nie dodano drugiego
routera, store frameworka ani drugiej biblioteki komponentów. Widoki są ładowane
osobnymi chunkami. TypeScript działa z `strict` i `noUncheckedIndexedAccess`.

## NativePHP

Zachowano nazwę `RecruitFlow`, identyfikator
`pl.szymonpokusinski.recruitflow.desktop`, okno 1100 × 760 (minimum 800 × 560),
Dock, menu macOS i panel Menu Bar 360 × 420.

Uzasadnione zmiany etapu 2:

- Główna trasa `desktop.home` wyświetla Dashboard Vue.
- Menu Bar wskazuje osobną trasę `desktop.menu` z lekkim ekranem Blade;
  pełny layout desktopowy nie mieści się w panelu 360 × 420.
- `prebuild` uruchamia `npm run build`, aby pakowana aplikacja miała aktualne zasoby Vue.
- `composer native:dev` uruchamia także Vite.

Provider pozostaje wskazany w `config/nativephp.php`, bez rejestracji w
`bootstrap/providers.php`. Automatyczne aktualizacje pozostają wyłączone. Workery integracji i skanowania AI
są konfigurowane w `config/nativephp.php`. DevTools nie otwierają się automatycznie.

## Weryfikacja

```bash
php artisan test --compact
npm test
npm run typecheck
npm run build
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

- Laravel: testy integracji, lokalnego zapisu, synchronizacji i importera.
- Frontend: testy serwisów, filtrów, ocen AI i scalania danych importu z formularzem.
- TypeScript strict, build Vite i Pint: poprawne.
- Herd: HTTP 200 oraz ręczna weryfikacja nawigacji, wyszukiwania, filtrów,
  sortowania, formularzy, historii, persystencji i obu motywów.
- Responsywność: szerokości 800 i 390 px; brak przepełnienia całej strony.
- NativePHP: `php artisan native:run --no-dependencies` wystartowało Electron i
  serwer PHP na porcie 8100; ekran `/offers` odpowiedział HTTP 200. Następnie
  proces testowy został zatrzymany. Pełna wizualna kontrola okna pozostaje ręczna.
- Rzeczywisty odczyt Google i przycisk „Połącz i synchronizuj” zweryfikowano:
  17 ofert, 17 aplikacji, 18 zdarzeń HR i 4 profile. Testy automatyczne Google API
  wykorzystują atrapy. Szczegóły: [raport etapu 3](stage-3-verification.md).

Testy Laravel używają SQLite w pamięci i zastępują desktopowe HTTP przez
`Http::fake()`. Testy serwisu używają magazynu w pamięci; nie dotykają baz ani arkuszy.

Instalacja zależności frontendu etapu 2 nie zgłosiła podatności npm. Ostrzeżenia
z etapu 1 dotyczące 50 podatności w zależnościach runtime'u Electron/NativePHP
pozostają osobną kwestią. Nie wykonano wymuszonych aktualizacji pakietów w `vendor`.

## Dokumentacja technologii

Backend AI ma wspólny kontrakt providerów i wyszukiwanie kategorii bez zapisu
wyników do DB. Cursor korzysta z Cloud Agents API v1 w wariancie bez repozytorium,
więc nie wymaga lokalnego SDK ani procesu Node. Konfiguracja, klucze per model,
komenda `ai:search` i zakres weryfikacji: [Cursor AI](ai/CURSOR.md)
oraz [architektura providerów](ai/PROVIDERS.md).
Driver Gemini i opcjonalny Google Search grounding: [Gemini AI](ai/GEMINI.md).
Cykliczne skanowanie kategorii, trwała kolejka, deduplikacja findings i komenda
`ai:scan`: [warstwa domenowa skanowania](ai/SCANNING.md).

Natywne widgety macOS: projekt Xcode znajduje się w `native/widgets/RecruitFlowWidgets.xcodeproj`.
Architektura, build, App Group, snapshoty i stan testu galerii są opisane w
[dokumentacji widgetów](widgets/MACOS_WIDGETS_IMPLEMENTATION.md). Kompilacja
nie zastępuje potwierdzenia instalacji w galerii systemowej.

- [NativePHP Desktop 2](https://nativephp.com/docs/desktop/2/getting-started/installation)
- [Plugin Quasara dla Vite](https://quasar.dev/start/vite-plugin/)
- [Adapter Laravel Inertia 2](https://inertiajs.com/docs/v2/installation/server-side-setup)
- [Adapter Vue Inertia 2](https://inertiajs.com/docs/v2/installation/client-side-setup)

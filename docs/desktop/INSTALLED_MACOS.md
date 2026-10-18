# RecruitFlow zainstalowany na macOS

Stan zweryfikowany 14.10.2026.

## Korzystanie bez terminala

- Otwórz **Aplikacje → RecruitFlow** (`/Applications/RecruitFlow.app`) albo wyszukaj RecruitFlow w Spotlight.
- Aby zachować ikonę w Docku: prawy przycisk na ikonie → Opcje → Zachowaj w Docku.
- `/Applications/RecruitFlow Widgets.app` jest aplikacją pomocniczą z rozszerzeniem WidgetKit. Główna aplikacja uruchamia ją w tle podczas publikacji snapshotu.
- Po tej aktualizacji usuń starą instancję widżetu i dodaj ponownie RecruitFlow z galerii macOS. Użytkownik potwierdził, że kliknięcie nowej instancji otwiera RecruitFlow.
- Kliknięcie oferty prowadzi do szczegółów. Kliknięcie widżetu rekrutacji prowadzi do Aktywności HR.
- Przycisk **↻** otwiera RecruitFlow i zleca odczyt aktywnych źródeł. Przy działającej aplikacji publikacja snapshotu i przekazanie go do App Group są automatyczne.

Terminal, Herd, Node i zewnętrzny PHP nie są potrzebne do zwykłego używania. Pakiet zawiera Electron, PHP, Laravel, zależności produkcyjne Composer i gotowy build Vite. Jest to lokalny pakiet arm64 podpisany certyfikatem Apple Development na tym Macu; publikacja dla innych komputerów wymaga osobnego procesu dystrybucji i notarizacji.

## Dane i instalacja

Lokalna baza zainstalowanej aplikacji:

`~/Library/Application Support/RecruitFlow/database/database.sqlite`

Instalator wykorzystał API backup SQLite do skopiowania aktualnego `database/nativephp.sqlite`. Początkowy stan kopii: **23 oferty, 21 aplikacji, 22 zdarzenia, 1 konto i 1 źródło**. Oryginał pozostał zachowany. Zapasowa kopia sprzed instalacji znajduje się w ignorowanym przez Git `database/backups/native-before-install-20261014-084013.sqlite`.

Kolejna instalacja zachowuje istniejącą zainicjalizowaną bazę. Nie kopiuje na nią ponownie wersji z repozytorium. Zmiany podczas zwykłego używania zapisują się w bazie Application Support; developerska baza projektu jest osobna. OAuth pozostaje w tym samym macOS Keychain, pod dotychczasowymi referencjami.

Runtime cache Laravela znajduje się poza podpisanym `.app`, w Application Support. Pakiet nie zawiera baz użytkownika, snapshotów użytkownika ani surowych plików OAuth.

## Widżety i odświeżanie

Snapshot głównej aplikacji:

`~/Library/Application Support/RecruitFlow/storage/app/private/widgets/native/dashboard-v1.json`

Podpisany desktop automatycznie przekazuje go do App Group właściwego dla Team podpisującego. Aplikacja pomocnicza obserwuje ten kontener i wywołuje `WidgetCenter.reloadAllTimelines()`. Nie trzeba wskazywać developerskiego folderu snapshotu. Starszy bookmark zostaje zachowany, ale ustępuje źródłu zainstalowanej aplikacji.

`RefreshWidgetData` wykonuje tylko odczyt źródeł przez istniejący `SyncService`. Nie uruchamia `OutboxSynchronizer`. Zachowuje poprzedni cache przy błędzie; status i czas próby trafiają do `recruitment_settings` oraz snapshotu. Kolejka `integrations` i unikalność zadania ograniczają równoległe odświeżanie. Przy braku źródeł publikowane są aktualne dane lokalne. W czasie odczytu widżet może pokazać „Odświeżanie danych…”, a po błędzie — „Błąd odczytu · sprawdź Integracje”.

Kliknięcie przekazane przez WidgetKit do aplikacji pomocniczej jest jawnie przekazywane do `/Applications/RecruitFlow.app`. Główny pakiet rejestruje schemat `recruitflow`, zachowuje link do zakończenia startu PHP i jawnie nawiguje istniejącym oknem. Zamykanie zatrzymuje procesy NativePHP przed zakończeniem głównego procesu, aby nie zostawić instancji z nieaktywnym serwerem trzymającej blokadę uruchomienia.

macOS kontroluje moment renderowania widgetu. Zamknięcie RecruitFlow zachowuje ostatni snapshot; pobranie nowych danych wymaga uruchomienia aplikacji — przycisk ↻ ją otwiera. Proces aplikacji pomocniczej musi działać, żeby zgłaszać zmiany do WidgetCenter.

## Pliki implementacji

- `nativephp/electron/`: opublikowany oficjalny runtime NativePHP, z poprawkami obsługi linków, cache poza pakietem i zakończenia procesu; builder oczekuje zakończenia kopiowania PHP przed pakowaniem.
- `native/desktop/build-installed.py`, `install-local.py`: budowanie, podpisywanie, instalowanie i bezpieczne kopiowanie pustej bazy docelowej.
- `native/desktop/deep-links.mjs`, `widget-bridge.mjs`: walidacja, buforowanie kliknięć i przekazywanie snapshotu do App Group.
- `app/Jobs/RefreshWidgetData.php`, `app/Widgets/{OpenWidgetDeepLink,WidgetDeepLink,WidgetDashboardService}.php`: odczyt źródeł, nawigacja, metadane odświeżenia.
- `native/widgets/Sources/{Core,Host,Widgets}/`: odświeżanie w widżecie, automatyczne źródło snapshotu i przekazanie linku do głównej aplikacji.
- `config/nativephp.php`, `.gitignore`: wersja 0.2.0, wykluczenia danych i artefaktów z pakietu/Git.
- `tests/Feature/WidgetRefreshTest.php`, `tests/desktop/{deep-links.test.mjs,install_local_test.py}`, testy Swift Core: regresje nawigacji, odświeżania i ochrony bazy.

## Weryfikacja

- Laravel: **98 testów, 1289 asercji — PASS**.
- Node/frontend: **22 testy — PASS**.
- TypeScript strict: **PASS**.
- Produkcyjny Vite: **PASS**.
- Swift Core: **9 testów — PASS**.
- Python instalatora: **3 testy — PASS**.
- Xcode host + rozszerzenie, podpisany arm64 pakiet NativePHP, DMG i ZIP: **PASS**.
- `codesign --verify --deep --strict`: oba zainstalowane pakiety poprawne. Podpis głównego pakietu pozostał poprawny po uruchomieniu.
- Systemowy adresat `recruitflow://activity`: **`/Applications/RecruitFlow.app`**.
- Pierwsze uruchomienie kontrolne: dashboard z rzeczywistymi ofertami; przekazanie linku przez pomocniczą aplikację otworzyło Aktywność HR. Porównanie wszystkich tabel kopii bazowej i bazy zainstalowanej po sesji tylko do odczytu: **brak zmian**, integralność **ok**.
- Użytkownik potwierdził kliknięcie ponownie dodanego widżetu: **otwiera RecruitFlow**.
- Rzeczywisty `RefreshWidgetData`, uruchomiony przez PHP z zainstalowanego pakietu, zakończył się **succeeded**, odczytał podłączone źródło i opublikował **23 oferty**. Odczyt potwierdzony snapshotem App Group: `lastSuccessfulSyncAt = 2026-10-14T06:59:56+00:00`; stan odświeżenia `succeeded`. Nie wykonywano testowych zapisów do Google Sheets.
- Nie wykonywano kolejnego zamknięcia aktywnej sesji po końcowej poprawce, ponieważ użytkownik korzystał już z aplikacji. Kliknięcie przycisku ↻ na samym desktopie nie było osobno obserwowane; jego backend przetestowano rzeczywistym odczytem, a nawigację potwierdził użytkownik.

## Polecenia dla developera

Z katalogu projektu:

```sh
python3 native/desktop/build-installed.py --team <TEAM_ID> --identity 'Apple Development: …'
xcodebuild -project native/widgets/RecruitFlowWidgets.xcodeproj -scheme 'RecruitFlow Widgets' -configuration Debug -destination 'platform=macOS,arch=arm64' -derivedDataPath native/widgets/DerivedData build
python3 native/desktop/install-local.py
```

Instalacja wymaga prawa do `/Applications` i Application Support. Zamknij aplikację przed kolejną aktualizacją. Zmiana identyfikatorów OAuth ani App Group nie jest częścią instalacji. Nie zmieniaj Team bez przygotowania migracji współdzielonych danych.

Po przygotowaniu katalogu backendu przez `native:build`, opcja `--repackage` kompiluje i pakuje ponownie sam Electron. Po zmianach backendu wykonaj pełny build, aby nie spakować starego kodu.

Testy:

```sh
php artisan test --compact
npm test
npm run typecheck
npm run build
swift test --package-path native/widgets
python3 tests/desktop/install_local_test.py
```

`native/desktop/verify-installed-refresh.php` to jawna weryfikacja odczytu prawdziwych, aktywnych źródeł aplikacji zainstalowanej. Aktualizuje jej lokalny cache, wymaga sieci i Keychain; odmawia działania przy źródłach zapisywalnych lub trybie podglądu. Nie uruchamiaj go jako testu jednostkowego.

## Poprawka renderowania widgetów 0.1.3

Po pierwszej instalacji WidgetKit nadal korzystał z procesu rozszerzenia uruchomionego z Xcode. Odczyt 23 ofert działał, ale `chronod` odrzucał widoki z błędem `WidgetArchiver.ValidationError.bundleStubNotSupported` oraz komunikatem `Bundle version did not match; LaunchServices DB may need to be rebuilt`. Efektem był pozostający na pulpicie szkielet.

Zakończono wyłącznie stare procesy hosta i rozszerzenia RecruitFlow, podniesiono wersję hosta i rozszerzenia do **0.1.3 / build 4** i zainstalowano je ponownie w `/Applications`. Rejestracja została odświeżona tylko dla tych pakietów. Nie resetowano systemowej bazy LaunchServices ani danych użytkownika.

Instalator zatrzymuje teraz tylko procesy widgetów pasujące do pełnych ścieżek własnych pakietów, przed podmianą plików. Aktualizacja głównej aplikacji wymaga jej wcześniejszego zamknięcia. `--widgets-only` pozwala aktualizować host/rozszerzenie przy działającym RecruitFlow, bez dostępu do SQLite. Kopie poprzednich pakietów mają sufiks `.app.previous`, aby nie były kolejnymi aplikacjami wykrywanymi przez Spotlight.

Test regresyjny potwierdza, że zatrzymywanie nie obejmuje głównego RecruitFlow ani aplikacji z innych katalogów. Nie usunięto obsługi ochrony wrażliwych danych przez WidgetKit.

Po instalacji 0.1.3 użytkownik potwierdził: **widget wyświetla dane zamiast szarych pasków**.

## Skrzynka ofert do aplikowania — 0.2.1

Ekran Oferty domyślnie pokazuje zakładkę **Do aplikowania**. Oferty z wysłanym CV, datą wysłania albo potwierdzonym etapem procesu w ofercie lub powiązanej aplikacji są ukrywane z tej skrzynki. Pozostają w Aplikacjach oraz zakładkach **Aplikowano** i **Wszystkie**. Sam rekord planowanej aplikacji nie stanowi dowodu wysłania CV. Brak jednoznacznych danych pozostaje widoczny z oznaczeniem **Sprawdź aplikowanie**.

Domyślne sortowanie to najnowsze oferty, według stabilnej daty pierwszego wykrycia/dodania. Wybór sortowania jest widoczny nad tabelą: najnowsze, najlepsze AI, firma A–Z i stanowisko A–Z. Dotychczasowe sortowanie z nagłówków również działa. Dashboard ma kafelek **Do aplikowania**. Po zapisaniu wysłanego CV i odczycie nowego lokalnego snapshotu oferta automatycznie znika ze skrzynki.

Dodano `resources/js/domain/offerInbox.ts`, testy `tests/frontend/offer-inbox.test.ts`; zmieniono `offerFilters`, composable `useOffers`/`useDashboard`, strony Offers/Dashboard, OfferTable, typy dat i style. Nie dodano migracji ani nie zmieniano danych biznesowych. Aktualizacja dotyczyła tylko głównego pakietu `.app`; istniejąca SQLite została zachowana, widgety 0.1.3 pozostały uruchomione.

Weryfikacja: **98 testów Laravel (1289 asercji), 29 testów JS (7 nowych), 3 testy instalatora, TypeScript strict i Vite — PASS**. Podpisany pakiet 0.2.1 został zainstalowany i uruchomiony. W rzeczywistym UI domyślnie wybrane jest **Do aplikowania (0)**, a przełączenie **Wszystkie (23)** pokazuje pełną zachowaną listę. Wszystkie obecne 23 oferty mają dowód aplikowania; brak wyników w skrzynce nie oznacza usunięcia ofert. Nie dodawano testowych rekordów do bazy użytkownika.

Opcja instalatora `--main-only` zachowuje działające procesy i pakiet widgetów. Opcja budowania `--repackage --refresh-frontend` może aktualizować przygotowany build tylko przy zmianach frontendu; przed pakowaniem wymagany jest aktualny `npm run build`. Zmiany backendu wymagają pełnego budowania NativePHP.

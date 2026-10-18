# RecruitFlow — natywne widgety macOS

## Aktualizacja: instalacja 14.10.2026

Do zwykłego korzystania służy teraz **`/Applications/RecruitFlow.app`**, z własnym PHP i backendem, oraz `/Applications/RecruitFlow Widgets.app`. Launcher developerski opisany w historycznej części tego dokumentu został wyrejestrowany. Szczegóły aktualnej instalacji, danych, przycisku ↻ i weryfikacji: [Zainstalowana aplikacja macOS](../desktop/INSTALLED_MACOS.md).



Stan weryfikacji: 13.10.2026. Kod korzysta z rzeczywistych modeli RecruitFlow. Instalacja w galerii pozostaje oddzielnym kryterium od kompilacji; aktualny wynik i blokady opisano poniżej.

## Architektura i audyt

Wybrano aplikację pomocniczą SwiftUI z osadzonym WidgetKit Extension. Electron/NativePHP nadal uruchamia Laravel i korzysta z własnej SQLite. Nie dodano drugiej bazy ofert ani publicznego API.

Osadzenie rozszerzenia bezpośrednio w pakiecie Electron wymagałoby zmian w pakowaniu NativePHP, poprawnego zagnieżdżonego podpisu, provisioning profiles i współdzielonych uprawnień. Samo skopiowanie `.appex` nie potwierdza rejestracji przez WidgetKit. Oddzielny host Xcode pozwala utrzymać standardowy cykl budowania i osadzania rozszerzenia bez ingerencji w działający runtime NativePHP. Dystrybucja podpisanego/notaryzowanego wydania obu aplikacji jest późniejszym zadaniem.

Przepływ danych:

```text
SQLite RecruitFlow → CanonicalCache → WidgetDashboardService
  → prywatny dashboard-v1.json (atomowy zapis Laravel)
  → host SwiftUI (wybrany folder, security-scoped bookmark)
  → snapshot w App Group → WidgetKit timeline → widoki SwiftUI
```

Rozszerzenie nie otwiera SQLite, nie ma tokenów Google i nie pobiera danych z internetu. Host nie otrzymuje uprawnień do całego katalogu domowego. Użytkownik wybiera tylko katalog przygotowanego snapshotu przez systemowy dialog.

Laravel 12 i NativePHP Desktop 2 pozostają istniejącą aplikacją. Testowane środowisko to arm64, macOS 26.6.2, Xcode 27.0 (27A266a). Deployment target widgetów: macOS 14; rdzeń Swift Package: macOS 14 / iOS 17. Tryb developerski NativePHP z `app.debug=true` zachowuje repozytoryjny storage; dopiero spakowany tryb bez debug przenosi storage. Nie należy zakładać jednej ścieżki dla obu trybów.

## Źródła i tożsamość danych

Źródłem są istniejące modele `Offer`, `Application`, `RecruitmentEvent`, `SearchProfile`, `OfferEvaluation`, `RecruitmentSetting` i `SyncRun`, odczytywane przez `CanonicalCache`. Importer Pracuj.pl i ręczne dodawanie nadal zapisują te same oferty. Widgety nie generują ocen AI ani danych demonstracyjnych.

Adapter RecruitFlow synchronizuje główną kolekcję `OFERTY`. Zakładki `Oferty_ChatGPT` i `Oferty_Gemini` są pomocnicze i nie stają się automatycznie oddzielnymi źródłami widgetów. Oferta obecna wyłącznie w tych zakładkach, a nie w zsynchronizowanej `OFERTY`/SQLite, nie jest jeszcze dostępna widgetom. Należy korzystać z istniejącego procesu scalania i synchronizacji; widget nie połączy się bezpośrednio z arkuszem.

Tożsamość opiera się na UUID lokalnego modelu, stabilnym kluczu oraz istniejącej normalizacji URL (`OfferIdentity`). Ponowne znalezienie tej samej oferty Pracuj.pl z inną nazwą w URL lub parametrem UTM nie tworzy drugiej propozycji. Przy oczekującym scaleniu preferowany jest rekord lokalny, zachowujący decyzje użytkownika; zdarzenia odnoszą się do wybranego UUID. Sam eksport nie scala ani nie usuwa rekordów SQLite.

## Definicja nowości i ranking

`firstSeenAt` jest najwcześniejszą poprawną datą z `firstDetectedAt`, kanonicznego `createdAt` (mapowanego z `first_found_at`), `foundAt` i istniejących `first_seen`/`first_found_at`. Dla lokalnych ofert można użyć rzeczywistego czasu utworzenia modelu. Nieznana data importowanej oferty pozostaje `null`; czas ostatniej synchronizacji nie zastępuje pierwszego znalezienia. `LocalStateMerger` zachowuje najwcześniejsze wykrycie przy kolejnych odczytach.

„Dzisiaj” oznacza dzień kalendarzowy w strefie aplikacji, a „24 h” ruchome 24 godziny. Liczniki nowych propozycji wykluczają oferty ukryte, zarchiwizowane, zamknięte, nieaktywne, zniknięte z źródła i z wysłanym CV. Historia i liczniki procesów obejmują aplikacje również poza listą propozycji. Licznik nieprzejrzanych jest nieznany, dopóki nie istnieje `RecruitmentSetting.widget_last_reviewed.at`; eksport nie wymyśla ani nie aktualizuje tego znacznika.

Swift przelicza względny czas przy każdej nowej timeline entry. Zamknięcie RecruitFlow nie zatrzymuje daty „nowości” na zawsze. Dane o stanie rekrutacji pozostają ostatnimi znanymi danymi, z widoczną datą aktualizacji.

Ranking najnowszych używa daty pierwszego znalezienia, z UUID jako stabilnym rozstrzygnięciem remisu. Ranking dopasowania używa wyłącznie istniejącej oceny właściwego profilu, następnie daty. Wynik 0 jest oceną, brak oceny jest `null`. Wynik legacy z arkusza nie jest przypisywany do arbitralnego profilu ani prezentowany jako świeżo policzony. Snapshot zawiera pochodzenie i datę analizy. Wysokie dopasowanie to co najmniej 85/100.

## Snapshot i bezpieczeństwo

`schema_version: 1`, limit 2 MB, maksymalnie 500 ofert i 20 zdarzeń; `totalOffers` i `truncated` ujawniają ograniczenie listy. Format zawiera minimalne dane firmy, stanowiska, lokalizacji, wynagrodzenia, źródła, profilu, oceny i statusu. Zdarzenia zawierają typ i termin, bez treści wiadomości, adresów e-mail i notatek. Tokeny, CV, opisy ofert i OAuth nie trafiają do pliku.

`generatedAt` jest datą wygenerowania pliku; `dataUpdatedAt` datą ostatniej zmiany danych lokalnych; `lastSuccessfulSyncAt` pochodzi z udanego `SyncRun`. Te daty nie są utożsamiane. Próg „Starsze dane” wynosi domyślnie 60 minut. Brak synchronizacji nie jest sukcesem synchronizacji.

Publisher stosuje blokadę, plik tymczasowy i atomowe zastąpienie; katalog ma uprawnienia 0700, pliki 0600. Błąd eksportu nie wycofuje zapisu rekrutacyjnego ani nie usuwa poprzedniego snapshotu. Log zapisuje klasę błędu, bez danych snapshotu. Swift odrzuca zbyt duże pliki, niewspierane wersje, niepoprawne UUID, duplikaty, obce deep linki i oceny spoza zakresu. Host zachowuje poprzedni poprawny plik przy błędzie nowego eksportu.

Snapshoty są ignorowane przez Git. Wybrane foldery Herd i NativePHP są rozdzielone, aby pusta baza developerska nie zastąpiła danych NativePHP:

```bash
php artisan widgets:publish              # bieżąca baza Laravel/Herd
php artisan widgets:publish --native-cache # istniejąca baza development NativePHP, PDO read-only
```

Polecenie wypisuje właściwą ścieżkę. W tym repozytorium eksport NativePHP znajduje się w `storage/app/private/widgets/native/dashboard-v1.json`. W spakowanej aplikacji używane jest jej faktyczne `storage_path`, przeniesione przez NativePHP. `--native-cache` dotyczy developerskiego `database/nativephp.sqlite`, nie automatycznego szukania baz zainstalowanych aplikacji.

## Aktualizacja

Obserwatory zmian modeli kolejkują eksport po zatwierdzeniu transakcji. Zakończenie żądania/operacji kolejki publikuje snapshot; uruchomienie NativePHP również kolejkuje eksport. Nie dodano odpytywania arkusza przez widget.

Host obserwuje zmiany katalogu przez DispatchSource, wykrywa atomowe zastąpienia i porównuje SHA-256. Po zmianie zapisuje App Group i wywołuje `WidgetCenter.reloadAllTimelines()`. Timeline prosi o odświeżenie po 20 minutach; rzeczywisty termin ustala macOS. Brak gwarancji sekundowej świeżości.

Host powinien działać, aby przekazywać kolejne snapshoty. Opcjonalne `RECRUITFLOW_WIDGET_COMPANION_ENABLED=true` pozwala NativePHP obudzić hosta protokołem `recruitflow-widgets://refresh`; domyślnie jest wyłączone do czasu instalacji i konfiguracji mostu. `RECRUITFLOW_WIDGETS_ENABLED=false` wyłącza automatyczny eksport.

W obecnym developerskim uruchomieniu przez `php artisan native:run --no-dependencies` natywne modele zapisują do `database/nativephp.sqlite`, a publisher do tego samego `storage/app/private/widgets/native`, które wybrano w hoście. Zmiany w podglądzie Herd dotyczą innej bazy i folderu `herd`; celowo nie zastępują snapshotu NativePHP. Aby odbierać nowe dane Google, główna aplikacja musi działać i wykonywać istniejącą synchronizację źródła. Widget nie uruchamia synchronizacji Google samodzielnie.

Gdy RecruitFlow i host są zamknięte, WidgetKit odczytuje ostatni plik App Group i pokazuje jego wiek. Nie pobiera wtedy nowych ofert z Google Sheets. Brak internetu nie usuwa snapshotu. Nie obiecujemy synchronizacji zewnętrznej podczas wyłączenia głównej aplikacji.

## Projekt Swift i konfiguracja

- `Sources/Core`: Codable, walidacja, wybór ofert, formatowanie i `WidgetSnapshotSource`.
- `Sources/Shared`: App Group snapshot.
- `Sources/Host`: aplikacja paska menu, bookmark i most plikowy.
- `Sources/Widgets`: App Intents, provider, współdzielone widoki i WidgetBundle.
- `Tests`: osiem testów Swift Testing.
- `Verification`: dodatkowy runner kontraktu JSON Laravel → Swift.
- `POC`: minimalny host i widget, bez sztucznych danych ofert.
- `Config`: plist i sandbox entitlements obu targetów.

Otwórz `native/widgets/RecruitFlowWidgets.xcodeproj`. W obu targetach wybierz ten sam Team i automatyczne podpisywanie. Oba mają macOS App Group `$(DEVELOPMENT_TEAM).pl.szymonpokusinski.recruitflow.widgets`, rozwijany podczas buildu. Dla skonfigurowanego Team jest to `WC7US5VH6R.pl.szymonpokusinski.recruitflow.widgets`. Host dodatkowo ma User Selected File — Read Only i security-scoped bookmarks. Rozszerzenie nie ma dostępu sieciowego ani uprawnień do plików użytkownika.

Identyfikatory: host `pl.szymonpokusinski.recruitflow.widgets`, rozszerzenie `.extension`. Wspólny identyfikator grupy jest także rozwijany w Info.plist obu pakietów. `AppGroupSnapshot` odczytuje go z podpisanego pakietu i sprawdza zgodność z jego Team. Nie ma wpisanego na stałe Team ID w Swift ani awaryjnego bezpośredniego dostępu do katalogu użytkownika. Laravel nie otwiera kontenera App Group; opcjonalna konfiguracja `widgets.app_group` ma jedynie charakter informacyjny.

Na macOS format `<Team ID>.<nazwa grupy>` jest autoryzowany przez Team podpisu i nie wymaga provisioning profile grupy. Format `group.*` wymaga innej konfiguracji provisioningu; nie należy go tu podstawiać tylko dlatego, że jest standardowy dla iOS. Źródło: [Apple — Accessing app group containers](https://developer.apple.com/documentation/xcode/accessing-app-group-containers).

Generator projektu jest zabezpieczony przed nadpisaniem istniejącego projektu i konfiguracji podpisywania zmienionej w Xcode. Nie uruchamiaj go ponownie w celu zwykłego buildu.

## Warianty i ustawienia

New Offers i Best Matches obsługują Small, Medium, Large, Extra Large. Recruitment Activity obsługuje Medium, Large, Extra Large. Small pokazuje licznik i jedną propozycję, Medium do 3 ofert, Large do 5, Extra Large do 9 w dwóch kolumnach. Widoki używają systemowych fontów, tła WidgetKit i adaptacyjnych kolorów; długie teksty są ograniczone liczbą wierszy.

Każda instancja ma własne ustawienia App Intent: nowe/wszystkie, najnowsze/dopasowane, profil, źródło, minimalna ocena, uwzględnianie wysłanego CV, wynagrodzenia i HR. Profile i źródła pochodzą ze snapshotu. Globalne statystyki są oznaczone „łącznie” i nie udają statystyk filtrowanego profilu. Pusty stan nie generuje ofert ani ocen do galerii.

## Deep linki

`recruitflow://offer/<UUID>`, `recruitflow://offers/new`, `recruitflow://activity`.

`WidgetDeepLink` dopuszcza wyłącznie znane wewnętrzne cele i istniejący niescalony model. Odrzuca port, login, zapytanie, fragment i obce ścieżki. Listener `OpenedFromURL` kieruje okno NativePHP na wewnętrzną trasę. Lista `widget=new` używa tych samych reguł nowych propozycji co provider.

Systemowy start głównej aplikacji wymaga pakietu z zarejestrowanym schematem `recruitflow`. Samo developerskie `native:run` nie zarejestrowało takiego pakietu na tym Macu. Dlatego przygotowano lokalny launcher opisany poniżej; rzeczywisty zimny start do Aktywności HR oraz przejście otwartego okna do szczegółów oferty zostały zweryfikowane w macOS.

### Launcher głównej aplikacji i obsługa kliknięć

`native/desktop/.build/RecruitFlow.app` jest podpisanym **launcherem developerskim** obecnego checkoutu, nie osobną aplikacją z inną bazą ani wydaniem dystrybucyjnym. Zawiera skopiowany, wcześniej zainstalowany runtime Electron i mały entry point, który ładuje oryginalny skompilowany main NativePHP. Korzysta z istniejącego `database/nativephp.sqlite` i repozytoryjnego storage w trybie development. Nie kopiuje bazy ani OAuth do pakietu i nie modyfikuje kodu vendor.

Plik ustawień launchera zawiera wyłącznie ścieżki wygenerowane na podstawie położenia projektu. Przeniesienie checkoutu lub wymiana runtime wymaga przebudowania launchera. To nie jest samodzielny pakiet do dystrybucji na innym Macu; produkcyjne wydanie nadal wymaga standardowego procesu `native:build`, dystrybucji i świadomego przeniesienia lokalnych danych.

`CFBundleURLTypes` deklaruje `recruitflow`. Rejestracja LaunchServices kieruje linki widgetów do tego pakietu. Entry point rejestruje `open-url` przed gotowością aplikacji, waliduje trzy dozwolone cele, buforuje ostatnią nawigację i czeka na pierwszy załadowany lokalny ekran głównej aplikacji (nie okno Menu Bar). Następnie wywołuje istniejący, uwierzytelniony relay NativePHP. Sekret runtime pozostaje wewnątrz NativePHP; nie trafia do snapshotu ani Vue.

`OpenWidgetDeepLink` używa `Window::open` do utworzenia/aktywacji okna i następnie jawnie ustawia jego URL przez `Window::get('main')->url(...)`. Jest to konieczne, bo istniejący runtime przy `window/open` dla otwartego ID tylko pokazywał okno, ignorując nowy adres. Launcher zapewnia single-instance lock; kolejne kliknięcia nie uruchamiają nowych kopii tego launchera.

Budowanie i aktualizacja (podaj publiczną nazwę lub fingerprint własnego certyfikatu):

```bash
python3 native/desktop/build-launcher.py --identity 'Apple Development: …'
# Kolejne aktualizacje tylko wygenerowanego pakietu:
python3 native/desktop/build-launcher.py --update --identity 'Apple Development: …'
open native/desktop/.build/RecruitFlow.app
```

Pierwsze polecenie odmówi nadpisania istniejącego launchera; `--update` sprawdza jego bundle ID i aktualizuje wyłącznie wygenerowane zasoby. Rejestracja lokalna:

```bash
/System/Library/Frameworks/CoreServices.framework/Versions/A/Frameworks/LaunchServices.framework/Versions/A/Support/lsregister \
  -f "$PWD/native/desktop/.build/RecruitFlow.app"
```

Na tym Macu launcher jest już zarejestrowany. Do zwykłego używania otwieraj go z Findera lub widgetu; nie uruchamiaj równolegle drugiej sesji `native:run` tej samej bazy.

Weryfikacja systemowa używała `--recruitflow-read-only-preview`: queue workers są wyłączone, zadanie synchronizacji nie jest wykonywane, połączenie NativePHP ma PDO `SQLITE_OPEN_READONLY`, a produktowe operacje mutujące są blokowane przez middleware. Nie dodawaj tego argumentu podczas zwykłego używania aplikacji. Oddzielny test na tymczasowej SQLite potwierdził, że PDO odmawia zapisu. Testowana główna aplikacja została następnie zamknięta, aby zwykłe otwarcie nie dziedziczyło trybu weryfikacji.

Rzeczywiste wyniki: zimny start przez systemowy `recruitflow://activity` otworzył Aktywność HR, a link `recruitflow://offer/<istniejący UUID>` przy otwartym oknie wyświetlił właściwą ofertę Backend Developer – PHP. Są to te same systemowe URL używane przez widgety. Kliknięcie fizycznej karty każdego wariantu widgetu pozostaje osobnym sprawdzeniem UX.

Podczas testu działała również zwykła sesja `native:run`. Porównanie sum wszystkich tabel wykazało równoległe zmiany cache/synchronizacji, więc nie stanowi dowodu niezmienności całej bazy w tym przedziale. Weryfikacyjny launcher miał blokady zapisu i nie wykonywał synchronizacji Google; nie resetowano ani nie kopiowano istniejącej SQLite.

## Build i instalacja

```bash
xcodebuild -project native/widgets/RecruitFlowWidgets.xcodeproj \
  -scheme 'RecruitFlow Widgets' -configuration Debug \
  -destination 'platform=macOS,arch=arm64' \
  -derivedDataPath native/widgets/DerivedData build
swift test --package-path native/widgets
```

Najpierw uruchom hosta w Xcode (⌘R). Jeśli codesign pyta o dostęp do prywatnego klucza Apple Development, zatwierdź lokalnie w Keychain; nie zapisuj hasła w projekcie. Build bez `CODE_SIGNING_ALLOWED=NO` musi przejść. Build bez podpisywania potwierdza kompilację, nie instalację.

W menu z ikoną teczki wybierz „Wybierz lokalny folder snapshotu…”, wskaż katalog wypisany przez `widgets:publish` i sprawdź komunikat liczby ofert. Następnie otwórz galerię widgetów macOS, wyszukaj RecruitFlow i dodaj warianty. Kopię do `/Applications` i dystrybucję przygotuj świadomie po weryfikacji builda; nie instaluje ich skrypt generatora.

## Wyniki i granice weryfikacji

- Laravel: **94 testy, 1277 asercji — PASS**, w tym obserwatory po commit/rollback, nawigacja istniejącego okna i blokada PDO w trybie weryfikacji.
- Frontend i relay desktopowy: **22 testy — PASS** (18 dotychczasowych i 4 testy linków).
- TypeScript strict i build Vite — **PASS**.
- Swift Package z pełnym Xcode: **9 testów — PASS**, w tym odrzucenie pustego, obcego i niezgodnego z Team identyfikatora App Group. Wcześniejszy brak runtime Swift Testing z Command Line Tools rozwiązany instalacją Xcode.
- Pełny build Xcode obu targetów z wyłączonym podpisywaniem — **PASS** (arm64 i x86_64).
- Podpisywany build z Team: **PASS**, po lokalnym zatwierdzeniu dostępu do klucza przez użytkownika. `codesign --verify --deep --strict` potwierdził podpisy obu pakietów. Początkowy `errSecInternalComponent` został rozwiązany bez zmiany zabezpieczeń i bez zapisywania hasła.
- macOS `pluginkit` potwierdził rejestrację rozszerzenia `.extension`. Sama rejestracja nadal nie jest wizualnym testem galerii.
- Host: **PASS**, widoczne okno „Połączono · 19 ofert”. Plik w App Group ma identyczny SHA-256 jak rzeczywisty snapshot Laravela.
- Zapamiętanie folderu: **PASS**, host został zamknięty przez ⌘Q i uruchomiony ponownie; automatycznie odtworzył dostęp oraz komunikat „Połączono · 19 ofert”, bez ponownego dialogu wyboru.
- Odczyt istniejącego development NativePHP cache: **19 ofert**, wyłącznie PDO read-only; JSON poddawany również walidacji Swift. Nie wykonywano żądań do Google ani zmian danych rekrutacyjnych.
- Widget pojawił się w galerii i został dodany przez użytkownika na pulpit (zrzut z 15:47). Początkowo renderował błąd odczytu danych; było to rzeczywiste rozszerzenie WidgetKit, ale nie potwierdzenie poprawnego przepływu danych.
- Light/dark, wszystkie rodziny, konfiguracja wielu instancji, odczyt przez rozszerzenie po zamknięciu hosta i rzeczywiste kliknięcia deep linków wymagają dalszego testu systemowego. Nie zastępuj go SwiftUI Preview ani samym sukcesem builda.

### Potwierdzenie odświeżania na systemowym widżecie

Użytkownik potwierdził poprawne wyświetlanie widgetu po ponownym dodaniu wersji 0.1.1. Log procesu `RecruitFlowWidgetExtension` potwierdził odczyt 19 ofert z nowej App Group.

Następnie ponownie wyeksportowano rzeczywisty cache poleceniem `widgets:publish --native-cache` (PDO read-only, bez zapisów SQLite). Watcher hosta sam przekazał nowy plik do App Group; pliki były identyczne. W tej samej sekundzie rozszerzenie odczytało snapshot, potwierdzone wpisem `Widget snapshot read: 19 offers; schema 1`. Nie klikano ręcznie odświeżenia i nie zmieniano danych rekrutacyjnych. Obserwowany czas odświeżenia nie stanowi gwarancji czasu każdego kolejnego update’u przez WidgetKit.

Nowy test backendowy korzysta z SQLite `:memory:` i osobnego tymczasowego folderu: zapis modelu w transakcji nie publikuje danych przed commit, commit kolejkuje poprawny snapshot, rollback nie kolejkuje wycofanej zmiany. Eksport w zwykłych testach jest wyłączony przez konfigurację PHPUnit; test publikacji włącza go tylko dla swojego folderu. Test ujawnił błąd wcześniejszej metody `down()` migracji: SQLite nie pozwalało usunąć kolumny z istniejącym indeksem. Naprawiono kolejność usuwania indeksu i klucza obcego w kodzie; rollback uruchamiano wyłącznie w bazie testowej w pamięci.

### Zapamiętany folder

### Praktyczne automatyczne odświeżanie

Uruchom główną aplikację `php artisan native:run --no-dependencies` i pozostaw działający host RecruitFlow Widgets. W źródle Google Sheets włącz integrację i ustaw interwał. Istniejący NativePHP uruchamia Laravel Scheduler co minutę również w tle; `routes/console.php` kolejkuje `recruitment:sync`, a worker `recruitment-sync` obsługuje kolejkę `integrations`. `SynchronizeRecruitment` sprawdza interwał każdego źródła przed odczytem. Nie dodano drugiego schedulera ani odpytywania Google w rozszerzeniu.

Po skutecznym odczycie lub lokalnym zapisie obserwatory publikują snapshot, host wykrywa zmianę katalogu i wywołuje WidgetCenter. Przycisk „Odśwież” w hoście odświeża prezentację ostatniego snapshotu; nie oznacza nowego pobrania Google. Ręczne „Synchronizuj teraz” w głównej aplikacji pobiera aktualne dane przez istniejący serwis. Gdy główna aplikacja jest zamknięta, widget zachowuje ostatni snapshot, a nie pobiera nowych danych z arkusza.

### Ścieżka folderu

Sprawdzona bezwzględna ścieżka folderu development NativePHP:

`<project>/storage/app/private/widgets/native`

Zawiera `dashboard-v1.json`: schema v1, 19 ofert o unikalnych UUID, 4 profile, wymagane pola i statystyki; w chwili testu brak aktualnych zdarzeń HR. Folder wybiera się jednorazowo. Host zapisuje security-scoped bookmark w swoich UserDefaults, a przy następnym uruchomieniu rozwiązuje go i ponawia odczyt. Nie przechowuje tam sekretów.

Nie wpisujemy katalogu tego komputera na stałe do Swift. Pierwszy odczyt katalogu spoza sandboxu wymaga zgody w NSOpenPanel; automatyczne zgadywanie ścieżki nie zastępuje tej zgody. Po przeniesieniu/usunięciu folderu, utracie uprawnienia albo wyczyszczeniu ustawień hosta może być potrzebny ponowny wybór. Samo automatyczne odnajdywanie folderu nie uruchamia hosta przy logowaniu do macOS; autostart nie został włączony.

Nie wykonano migracji widgetowych, resetu SQLite, operacji OAuth, zmian arkusza, commita ani push.

### Naprawa odmowy dostępu rozszerzenia — 13.10.2026

`containermanagerd` odrzucił `group.pl.szymonpokusinski.recruitflow.widgets` z komunikatem o wymaganym prefiksie Team ID. Host mógł odczytać plik po lokalnej zgodzie, ale rozszerzenie nie miało takiej zgody. Odczyt w hoście nie był dowodem dostępu rozszerzenia.

Wersja 0.1.1/build 2 używa poprawnej grupy z prefiksem Team w obu entitlements i obu Info.plist. Snapshot trafia do `~/Library/Group Containers/WC7US5VH6R.pl.szymonpokusinski.recruitflow.widgets/dashboard-v1.json` przez API Foundation. Host ponownie odtworzył bookmark i przekazał identyczny snapshot 19 ofert, bez modyfikacji SQLite. Stary snapshot nie został usunięty.

Mac miał zarejestrowane dwie podpisane kopie tego samego rozszerzenia, a system uruchamiał wcześniejszy pakiet z globalnego DerivedData Xcode. Wyrejestrowano stare kopie w PlugInKit/LaunchServices, bez usuwania plików; aktualny rejestr wskazuje jedną wersję 0.1.1 z repozytorium. Istniejący widget starego pakietu może wymagać usunięcia i ponownego dodania przez galerię.

Przycisk „Odśwież” zawsze wywołuje teraz `WidgetCenter.reloadAllTimelines()`, także gdy plik ma ten sam SHA-256. Automatyczny watcher nadal pomija identyczne pliki. Diagnostyka loguje wyłącznie liczbę konfiguracji/rekordów i kody błędów, bez treści ofert i sekretów. Rzeczywisty odczyt rozszerzenia potwierdza wpis `Widget snapshot read: 19 offers; schema 1` z procesu `RecruitFlowWidgetExtension` — sam wpis z hosta nie wystarcza.

## Checklista testu systemowego

1. Podpisany host i rozszerzenie mają ten sam Team i App Group, a host uruchamia się bez błędu.
2. Po wybraniu istniejącego folderu komunikat pokazuje liczbę ofert, App Group przechowuje poprawny snapshot.
3. RecruitFlow pojawia się w galerii i daje dodać wszystkie rozmiary oraz niezależne instancje.
4. Porównaj prezentowane rzeczywiste oferty, ceny i brakujące oceny z główną aplikacją. Zmień kryteria instancji bez zmiany drugiej instancji.
5. Sprawdź jasny/ciemny motyw, długie tytuły oraz terminy HR. Używaj izolowanych fixture’ów do testów sztucznych danych, nie produkcyjnej SQLite.
6. Zamknij RecruitFlow i hosta: zachowany snapshot, widoczna data i późniejsze wygaśnięcie nowości.
7. Kliknij szczegóły przy działającej i zamkniętej zainstalowanej aplikacji NativePHP; sprawdź właściwy UUID.

## Przyszłe iOS/iPadOS

`RecruitFlowWidgetCore` jest biblioteką Foundation bez zależności od macOS, SQLite i OAuth. Widoki i App Intent są wydzielone od hosta AppKit. iOS extension może korzystać z tych samych modeli, formatowania i widoków, z odpowiednimi rodzinami platformy.

`WidgetSnapshotSource` oddziela odczyt od prezentacji. Przyszła aplikacja iOS musi dostarczać własny uwierzytelniony snapshot do własnego App Group (np. pobrany przez autoryzowane API), z polityką synchronizacji, zabezpieczeniem poświadczeń i kontrolą prywatności. iPhone nie uzyskuje dostępu do SQLite Maca ani jego kontenera App Group. Nie zbudowano aplikacji iOS ani publicznego serwera.

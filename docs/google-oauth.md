# Google Sheets: konfiguracja lokalnej integracji

RecruitFlow korzysta z OAuth 2.0 dla aplikacji **Desktop app**, PKCE S256,
jednorazowego `state` i tymczasowego odbiornika na `127.0.0.1` z losowym portem.
W NativePHP logowanie otwiera systemową przeglądarkę; w Herd użyj przycisku
„Otwórz logowanie Google w przeglądarce”. Kod autoryzacji i tokeny obsługuje
backend. Frontend nie otrzymuje access tokenu ani refresh tokenu.

## Google Cloud Console

Jeżeli masz już klienta Desktop app, sprawdź API i zakres, a następnie przejdź
do importu konfiguracji w RecruitFlow.

1. Otwórz [Google Cloud Console](https://console.cloud.google.com/) i wybierz
   własny projekt lub utwórz projekt dla RecruitFlow.
2. W **APIs & Services → Library** włącz **Google Sheets API**.
3. W **Google Auth Platform → Branding** skonfiguruj nazwę aplikacji,
   adres wsparcia i kontakt dewelopera. W **Audience** wybierz właściwą
   grupę użytkowników. Przy konfiguracji External / Testing dodaj swoje konto
   do **Test users**. Organizacja Workspace może ograniczać dostęp przez polityki.
4. W **Data Access** dodaj wyłącznie zakres:
   `https://www.googleapis.com/auth/spreadsheets.readonly`.
   Nie dodawaj zakresów Gmail ani zapisu Sheets. Ten zakres obejmuje arkusze
   dostępne dla wybranego konta; wybranie źródła w RecruitFlow nie ogranicza
   samego uprawnienia Google do jednego pliku.
5. W **Clients → Create client** wybierz **Desktop app**, nadaj nazwę
   i pobierz JSON klienta. Nie wybieraj Web application. Nie konfiguruj
   callbacku Herd: aplikacja używa losowego portu loopback, wspieranego dla
   klienta desktopowego.
6. Zachowaj pobrany plik poza repozytorium. Nie umieszczaj go w `.env`, kodzie
   ani wiadomościach. Publiczna dystrybucja aplikacji z tym zakresem może wymagać
   weryfikacji OAuth w Google; podczas rozwoju używaj własnych użytkowników testowych.

Źródła: [OAuth dla aplikacji desktopowych](https://developers.google.com/identity/protocols/oauth2/native-app),
[zakresy Google Sheets](https://developers.google.com/workspace/sheets/api/scopes).

## Połączenie konta w RecruitFlow

1. Otwórz **Ustawienia → Zarządzaj integracjami i źródłami → Ustawienia zaawansowane**.
   W Herd: `http://recruitflow.test/settings/integrations`.
2. Wybierz pobrany JSON w **Google OAuth → Plik JSON klienta OAuth**
   i kliknij **Importuj konfigurację**. Obsługiwany jest dokument z sekcją
   `installed`; pliki klientów webowych są odrzucane.
3. Wpisz własną etykietę konta, np. „Rekrutacja”. Ta etykieta nie jest
   zweryfikowanym adresem e-mail. Kliknij **Połącz konto Google**.
4. W systemowej przeglądarce wybierz właściwe konto i samodzielnie udziel zgody
   na odczyt arkuszy. Po powrocie konto otrzyma stan „Połączone”. Logowanie
   wygasa po 10 minutach; nie zamykaj aplikacji podczas autoryzacji.
5. Na macOS sekret klienta, verifier/state oraz tokeny przechowuje **Keychain**,
   pod usługą `pl.szymonpokusinski.recruitflow.integrations`, oddzielnie dla
   lokalnych identyfikatorów połączeń. macOS może poprosić o dostęp do pęku kluczy.
   SQLite przechowuje tylko identyfikatory, etykiety, zakresy i daty ważności.
6. Przy wygaśnięciu lub cofnięciu autoryzacji użyj ikony ponownego logowania.
   Odłączenie konta usuwa jego lokalne poświadczenia; najpierw usuń konfiguracje
   źródeł korzystających z konta. Dostęp aplikacji można dodatkowo cofnąć
   samodzielnie w ustawieniach bezpieczeństwa konta Google.

Domyślnym magazynem na macOS jest Keychain. W innych środowiskach dostępny jest
`INTEGRATION_CREDENTIAL_STORE=encrypted_file`: pliki szyfrowane przez Laravel
`APP_KEY`, z katalogiem 0700 i plikami 0600 w prywatnym storage. Nie zmieniaj
magazynu ani APP_KEY dla już połączonych kont bez ponownego logowania.
Nie ma automatycznego przejścia do słabszego magazynu po błędzie Keychain.

Herd i NativePHP używają **oddzielnych lokalnych baz SQLite** i konfiguracji
źródeł. Import klienta i podłączenie konta wykonaj w środowisku, z którego
zamierzasz korzystać. Nie kopiuj tokenów ani danych Keychain między bazami.

## Automatyczne podłączenie źródła

Domyślny ekran **Ustawienia → Integracje** pokazuje połączone konto,
URL arkusza, przycisk **Połącz i synchronizuj**, stan i liczbę rekordów.
Po połączeniu konta wystarczy wkleić URL i kliknąć ten przycisk.

`RecruitFlowSchemaDetector` pobiera metadane i nagłówki z pierwszego wiersza,
rozpoznaje techniczne klucze `offer_key`, `application_id`, `event_id`
i `profile_id`, dopasowuje kolumny oraz pomija zakładki pomocnicze.
Nazwy zakładek mogą się zmienić, jeśli ich techniczne nagłówki pozostają jednoznaczne.
Żaden spreadsheet ID nie jest wpisany w logice aplikacji.

Mapowanie, walidacja kluczy i relacji oraz import odbywają się automatycznie.
Konfiguracja i pełny cache zmieniają się atomowo dopiero po poprawnym odczycie
całości. Błąd zachowuje poprzedni cache i mapowanie.
Opcjonalne kolumny nie są wymagane; pozostałe kolumny zachowuje `extraData`.

Status portalu, status dostępności i etap aplikacji są oddzielnymi polami.
Pola aplikacji pochodzące z formuł odwołujących się do ofert są wyświetlane
na podstawie powiązanego rekordu oferty, który pozostaje źródłem prawdy.
Zdarzenia mogą wskazywać ofertę bez wskazania aplikacji; wiele zdarzeń może
należeć do tej samej oferty. Wczytywane są wszystkie profile, także wyłączone.

Obsługiwane są obliczone wartości formuł (bez pobierania ich treści), polskie
daty, ISO 8601, daty numeryczne Google Sheets, TAK/NIE, TRUE/FALSE i puste
komórki. Daty numeryczne są interpretowane w strefie czasowej arkusza.

**Ustawienia zaawansowane** zawierają import OAuth, zarządzanie kontami,
interwały, źródło główne, historię synchronizacji i opcjonalne ręczne mapowanie
nieznanych struktur. Rozpoznany schemat RecruitFlow nie wymaga używania
tego ekranu. Dla innych arkuszy detector może dopasować znane nazwy i aliasy;
niejednoznaczność zgłasza błąd zamiast zgadywać i zastępować istniejące dane.

Każda zakładka ma limit 50 000 wierszy danych. Przekroczenie limitu blokuje
operację zamiast zapisywać ucięty zbiór. Arkusze pozostają tylko do odczytu.

## Synchronizacja i praca offline

- **Synchronizuj** pobiera pełny aktualny zestaw przypisanych zakładek.
  Pobranie i walidacja odbywają się przed transakcją. Transakcja atomowo
  zastępuje cache źródła i przebudowuje spójny cache domenowy.
- Błąd internetu, autoryzacji, mapowania lub relacji zachowuje poprzedni cache.
  Historię i ostatni błąd widać w panelu. Log zawiera identyfikatory i kod
  błędu, bez zawartości arkuszy, tokenów ani odpowiedzi Google.
- Odświeżenie w nagłówku widoków odczytuje **lokalny cache**; odczyt Google
  uruchamia przycisk **Synchronizuj** w panelu integracji.
- NativePHP uruchamia scheduler podczas działania aplikacji. Interwały
  wykonują się tylko wtedy, gdy aplikacja działa. Po uśpieniu komputera
  synchronizacja wróci przy kolejnym uruchomieniu schedulera.
- W Herd uruchom w osobnym terminalu `php artisan schedule:work`.
  Jednorazowo: `php artisan integration:sync-due`.
- Podczas synchronizacji konfiguracja tego źródła jest zablokowana przed
  równoległymi zmianami. SQLite ma WAL i timeout zajętej bazy 5 sekund.
- Wyłączenie źródła usuwa jego udział w bieżącym zbiorze, a usunięcie usuwa
  także lokalny cache i historię tego źródła. Operacje nie zmieniają arkusza.
  Jeżeli inne aktywne źródło zależy od jego ofert/aplikacji, najpierw wyłącz
  zależne źródła; aplikacja blokuje pozostawienie osieroconych powiązań.
- Przed zmianą arkusza, konta albo mapowania wyłącz źródło. Zmiana tożsamości
  arkusza/konta usuwa poprzednie mapowanie i lokalny cache tego źródła.

## Rozszerzanie integracji

`DataSourceAdapterInterface` oddziela metadane, nagłówki i odczyt wierszy od
logiki domenowej. `DataSourceManager` wybiera adapter. `MappingSchema` i
`RecruitmentMapper` tworzą jednolite Offer, Application i RecruitmentEvent.
`SyncService` zarządza walidacją i transakcją, `CanonicalCache` odpowiada
za deduplikację oraz spójność relacji. Modele konfiguracji i kont są oddzielone
od modeli rekrutacyjnych. Dodanie nowego typu polega na dodaniu adaptera,
jego schematu/mapera i rejestracji w managerze — bez zmiany widoków rekrutacji.
Obecny adapter nie zawiera metod zapisu. Gmail nie został zaimplementowany.

# Warstwa providerów AI

Driver Gemini, konfiguracja klucza/modelu i opcjonalnego groundingu:
[GEMINI.md](GEMINI.md). Cursor zachowuje swój dotychczasowy przepływ.

Kategorie, wersjonowane prompty, cykliczne skany i zapis findings są opisane
w [SCANNING.md](SCANNING.md). Nadal używają tego samego kontraktu providerów.

Backend Laravel 12; bez nowego UI, endpointów, pakietów Composer lub zmian
w integracji Google. Klasy używają PHP 8.3 i typowanych, niemutowalnych DTO.

## Struktura i kontrakt

```text
app/Services/Ai/
  Contracts/AiProviderInterface.php
  DTO/{AiRequest,AiCapabilities,AiItem,AiResult}.php
  Providers/{FakeProvider,CursorProvider,GeminiProvider}.php
  Cursor/{CursorCloudClient,CursorPromptBuilder,CursorResultMapper}.php
  Gemini/{GeminiClient,GeminiPromptBuilder,GeminiResultMapper}.php
  AiJsonResultMapper.php
  AiResultSchema.php
  CategorySearchService.php
  AiClock.php
  AiProviderManager.php
  AiService.php
  AiResultMapper.php
  AiPayloadGuard.php
  AiCredentials.php
  AiProviderException.php
app/Models/Ai/{AiProviderSetting,AiRun}.php
config/ai_providers.php
database/migrations/2026_10_14_120000_create_ai_provider_tables.php
tests/Unit/Ai/
tests/Feature/Ai/
```

```php
interface AiProviderInterface
{
    public function key(): string;
    public function name(): string;
    public function supports(): AiCapabilities;
    public function search(string $category, string $prompt, array $options = []): AiResult;
    public function analyze(string $category, string $prompt, array $options = []): AiResult;
}
```

`AiCapabilities.operations` zawiera operacje działające w danym driverze.
`available=false` oraz `unavailable_reason` oznaczają brak możliwości wywołania.
`web_search` oddziela dostęp do wyszukiwania sieciowego od samej analizy.

`AiService` waliduje żądanie, pobiera pojedynczy wybór konfiguracji,
tworzy run, sprawdza możliwości, wywołuje driver i ponownie waliduje wynik.
Provider ani kategoria z odpowiedzi nie mogą podmienić wartości żądania.
Format nazwy modelu i limit elementów również podlegają weryfikacji.
Provider może rozwinąć alias modelu do konkretnej wersji: historia zachowuje
`requested_model` z żądania oraz `model` zwrócony w wyniku.
Nowe drivery nie wymagają zmian w `AiService`.

## Wspólny wynik

`AiResult::toArray()` / serializacja JSON:

```json
{
  "provider": "provider_key",
  "model": "model_id",
  "category": "job_offers",
  "requested_at": "2026-10-14T10:00:00+00:00",
  "request_id": "uuid-korelacji-runu",
  "metadata": {},
  "items": [
    {
      "title": "Stanowisko",
      "url": null,
      "price": null,
      "currency": null,
      "location": null,
      "description": null,
      "raw": {},
      "confidence": null,
      "external_id": null,
      "metadata": {}
    }
  ]
}
```

Powyżej jest wyłącznie ilustracja struktury, nie dane zapisywane w aplikacji.
Puste mapy PHP (`raw`, `metadata`) serializują się jako `[]`; mogą też zawierać
obiekty JSON reprezentowane tablicami asocjacyjnymi.

- `model` może być `null`, jeżeli provider nie ujawnia modelu.
- `confidence`: liczba **0–1**, opcjonalna; to pewność odpowiedzi providera,
  **nie** istniejący wynik dopasowania oferty `ai_match_score` 0–100.
- `price`: opcjonalna liczba nieujemna; `currency`: kod z trzech wielkich liter.
  Cena nie oznacza automatycznie miesięcznej pensji. Okres, podstawa i zakres
  wynagrodzenia wymagają jawnych danych w metadanych przyszłego adaptera.
- Brak wartości pozostaje `null`; `0` jest prawidłowe. Puste ciągi dla ceny,
  pewności, URL, waluty i zewnętrznego ID są normalizowane do `null`.
- Oryginalny tekst opisu, w tym nowe linie, jest zachowany.
- `metadata` wyniku zawiera informacje o wykonaniu niezależnie od liczby
  elementów, np. stan cleanup, także dla pustego wyniku.
- URL musi mieć protokół HTTP(S); nie jest przez tę warstwę automatycznie otwierany.
- Maksymalnie 100 elementów, JSON do 2 MB / głębokości 32. Nieznane pola
  głównego DTO są odrzucane; rozszerzenia należą do `metadata` / `raw`.

`AiResultMapper` stosuje Laravel Validator przed rzutowaniem danych na DTO.
Driver specyficzny dla API najpierw przekłada jego format do tego kontraktu,
a następnie wywołuje mapper. DTO jest wspólnym formatem dla przyszłego zapisu.
Ta wersja **nie zapisuje wyników do `offers` ani ocen AI**, nie tworzy outboxa
i nie uruchamia synchronizacji. Przyszła zaakceptowana operacja importu powinna
użyć istniejącego serwisu domenowego i deduplikacji, nie bezpośredniego `Offer::create`.

## Konfiguracja i sekrety

Domyślnie `AI_DEFAULT_PROVIDER=cursor`, `AI_FAKE_ENABLED=false`.
Starsze `AI_PROVIDER` jest odczytywane tylko przy braku `AI_DEFAULT_PROVIDER`.
Wybór w SQLite (`ai_provider_settings`, rekord `id=default`) ma pierwszeństwo
przed `.env`. Baza przechowuje wyłącznie klucz providera, opcjonalny model
i referencję do magazynu poświadczeń. Klasy driverów pochodzą wyłącznie
z rejestru `config/ai_providers.php`, nigdy z bazy ani parametrów żądania.

Po wykonaniu addytywnej migracji w wybranym środowisku:

```bash
php artisan migrate --path=database/migrations/2026_10_14_120000_create_ai_provider_tables.php
```

W kodzie backendu:

```php
app(\App\Services\Ai\AiProviderManager::class)->configure(
    provider: 'cursor',
    model: null,                  // null oznacza model z configu
    credentialRef: 'ai-cursor',   // null oznacza referencję z configu
);
```

Nie przekazuj kluczy API przez `options`. Są odrzucane również wewnątrz
zagnieżdżonych struktur. Istniejący `CredentialStore` używa macOS Keychain
na macOS, a na innych platformach istniejącego magazynu szyfrowanego projektu.
Wpis referencji powinien zawierać `['api_key' => $secret]`, zapisany lokalnie
przez `CredentialStore::write()`. Klucza nie wklejaj do komend zachowywanych
w historii terminala, dokumentacji ani repozytorium.

Alternatywnie konfiguracja odczytuje `CURSOR_API_KEY` z lokalnego `.env`.
`CURSOR_CREDENTIAL_REF` ma pierwszeństwo. Błąd magazynu nie powoduje fallbacku
do klucza z `.env`. Dla dystrybuowanej aplikacji desktopowej preferuj Keychain;
nie dołączaj `.env` z kluczami ani cache konfiguracji z sekretami do paczki aplikacji.

## Uruchomienie szkieletu

Kontrolowane, lokalne środowisko developerskie, po migracji:

```dotenv
AI_DEFAULT_PROVIDER=fake
AI_FAKE_ENABLED=true
```

Usuń ewentualny cache konfiguracji (`php artisan config:clear`). Jeżeli istnieje
wcześniejszy wybór w DB, jawnie ustaw go przez `configure('fake')`.

```php
$result = app(\App\Services\Ai\AiService::class)->search(
    category: 'job_offers',
    prompt: 'Kontrolowany test kontraktu.',
    options: ['max_items' => 5],
);
$array = $result->toArray();
```

FakeProvider domyślnie zwraca pustą listę. Dane fixture można podać przez
`config(['ai_providers.providers.fake.items' => $controlledItems])`
w testach lub izolowanym procesie developerskim. Każdy element jest oznaczony
`metadata.synthetic=true`; nie ma generatora ofert lub ocen.
Nie ma automatycznego fallbacku do FakeProvider po błędzie innego drivera.

## CursorProvider — Cloud Agents API v1

Zweryfikowane 2026-10-14 w oficjalnej dokumentacji:
[Cursor Cloud Agents API](https://cursor.com/docs/cloud-agent/api/endpoints).
Driver używa udokumentowanego wariantu **no-repo**: pomija `repos` i `env`
przy tworzeniu agenta. Laravel komunikuje się przez HTTPS z Cloud Agents v1.
Nie wymaga instalowania SDK, Cursor CLI ani dodatkowego procesu Node w paczce
NativePHP. Szczegóły przepływu, timeoutów, kluczy per model i uruchomienia:
[CURSOR.md](CURSOR.md).

Implementacja HTTP zastępuje wcześniejszy stub. Bez klucza nadal zwraca
`missing_credentials`. Testy automatyczne stosują `Http::fake`; nie deklarujemy
potwierdzonego działania na prawdziwym koncie Cursor bez testu z jego kluczem.
Warstwa pobierania nie jest endpointem chat-completions i nie otrzymuje repozytorium.

## Nowy provider

1. Dodaj osobną klasę implementującą `AiProviderInterface` w `Providers/`.
2. Zarejestruj jej driver i nazwany profil w `config/ai_providers.php`.
3. Pobieraj sekrety wyłącznie przez konfigurację / `AiCredentials`.
4. Jawnie zadeklaruj działające możliwości. Mapuj natywną odpowiedź API
   przez `AiResultMapper`, nie zwracaj providerowych typów do serwisu domenowego.
5. Dodaj testy transportu, mapowania, błędów i weryfikację rzeczywistego API
   przed oznaczeniem providera jako produkcyjnie działającego.
6. Wybierz klucz przez `.env` albo `AiProviderManager::configure()`.

Nie dodano `laravel/ai`; kontrakt aplikacji jest niezależny od SDK providerów.
Laravel automatycznie rozwiązuje te serwisy przez kontener; konsument używa
`CategorySearchService` (bez zapisu) lub `AiService` (z historią runów),
nie bezpośredniej instancji CursorProvider. Nowy przepływ wyszukiwania kategorii
nie wywołuje `AiService` i nie zapisuje nawet `ai_runs`.

## Historia runów i błędy

`ai_runs`: `running → succeeded|failed`, provider, model, requested_model, kategoria, operacja,
`requested_at`, `finished_at`, `duration_ms`, `items_count`, `request_id`,
`error_code`, `error`. Request ID generuje aplikacja; nie jest zależny od Cursor.
Nie zapisujemy promptów, surowych odpowiedzi, tokenów ani nagłówków HTTP.
Zarezerwowane klucze sekretów w `raw` / `metadata` są redagowane rekurencyjnie.
Treść dowolnego tekstu nie stanowi bezpiecznego miejsca przechowywania sekretów.

Nieoczekiwane wyjątki providerów otrzymują stały, bezpieczny komunikat;
ich oryginalne treści i łańcuch wyjątków nie są logowane ani propagowane.
`AiProviderException::requestId` pozwala powiązać błąd wywołania serwisu
z konkretnym wpisem historii, również przy równoległych wywołaniach.
Nieprawidłowe żądanie jest odrzucane przed utworzeniem runu.
Wyjątki konfiguracji i providera są rejestrowane po utworzeniu runu.
Brak tabel migracji lub niepoprawny wybór w konfiguracji jest błędem wdrożenia,
a nie pozornym sukcesem AI.

## Weryfikacja

```bash
php artisan test --compact tests/Unit/Ai tests/Feature/Ai
php artisan test --compact
vendor/bin/pint --test app/Services/Ai app/Models/Ai tests/Unit/Ai tests/Feature/Ai config/ai_providers.php database/migrations/2026_10_14_120000_create_ai_provider_tables.php
npm run test
npm run typecheck
npm run build
```

Testy AI korzystają z SQLite `:memory:` i `Http::preventStrayRequests()`.
Nie wymagają ani nie odczytują prawdziwych kluczy API. Testowy magazyn poświadczeń
jest mockowany, żądania do Cursor i zapisy do Google Sheets nie są wykonywane.
Migracja tworzy tylko dwie nowe tabele; nie zmienia istniejących tabel ofert,
aplikacji, OAuth ani synchronizacji. Migrację sprawdzamy automatycznie w SQLite
testowym; nie wykonujemy jej automatycznie na bazie zainstalowanej aplikacji.

Wynik poprzedniego checkpointu szkieletu 2026-10-14 (przed transportem Cursor):

- 41 nowych testów AI; pełny zestaw Laravel: **139 zaliczonych, 1455 asercji**.
- JavaScript frontend/desktop: **29 zaliczonych**.
- TypeScript strict: **zaliczony**.
- Produkcyjny build Vite: **zaliczony**.
- Pint i `git diff --check`: **zaliczone**.
- W tym checkpoincie nie testowano rzeczywistego Cursor API.
- Nie uruchomiono migracji na istniejącej bazie developerskiej ani bazie
  zainstalowanej aplikacji. Przed użyciem historii runów wykonaj podaną wyżej
  migrację w wybranym środowisku; automatyczna weryfikacja użyła bazy testowej.

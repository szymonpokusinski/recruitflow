# Cursor: wyszukiwanie i analiza kategorii

Implementacja backendowa Laravel 12 / PHP 8.3, bez UI i bez nowych zależności.
`CategorySearchService → AiProviderInterface → CursorProvider → CursorResultMapper → AiResult`.
Ten przepływ nie zapisuje ofert, ocen, outboxa ani historii runów do SQLite.
Istniejący `AiService` pozostaje osobnym konsumentem z opcjonalną historią DB;
`CategorySearchService` i komenda `ai:search` go nie wywołują.

## Runtime i protokół

Podstawa: [oficjalna dokumentacja Cloud Agents API v1](https://cursor.com/docs/cloud-agent/api/endpoints),
sprawdzona 2026-10-14. API v1 jest publiczną betą.

Wybieramy **Cursor-hosted cloud, bez repozytorium**. Pominięcie `repos` i `env`
tworzy no-repo agenta. REST jest odpowiedni dla NativePHP: nie wymaga Node SDK,
procesu CLI, lokalnego workera, uprawnień do plików ani repozytorium w chmurze.
Agent nie ma dostępu do lokalnego SQLite, Keychain ani OAuth Google.
Otrzymuje wyłącznie instrukcje, kategorię, prompt i jawne filtry.

1. `POST https://api.cursor.com/v1/agents` — `prompt.text`, `agentId=bc-UUID`,
   `mode=agent`, `autoCreatePR=false`, opcjonalnie `model.id`.
2. `GET /v1/agents/{agentId}/runs/{runId}` — polling konkretnie utworzonego runu.
3. Tylko `FINISHED` pozwala odczytać tekst z pola **`result`**.
   `ERROR`, `CANCELLED` i `EXPIRED` nie są częściowym sukcesem.
4. `CursorResultMapper` dekoduje i normalizuje JSON, następnie wspólny
   `AiResultMapper` stosuje Laravel Validator i tworzy niemutowalne DTO.
5. Przy przerwaniu znanego aktywnego runu próbujemy `POST .../cancel`.
   Po zakończeniu próbujemy `POST /v1/agents/{agentId}/archive`.

Nie przekazujemy `repos`, nazwanych środowisk, `envVars`, MCP ani danych prywatnych.
Nie konfigurujemy zdalnego Git ani nie wykonujemy push. Każde wyszukiwanie
ma osobnego agenta, więc kontekst poprzednich kategorii nie miesza się z nowym.
Prompt zabrania obchodzenia kontroli dostępu i pobierania treści OLX, dla których
projekt nie posiada wymaganej zgody. Nie omija on ograniczeń narzędzi/runtime
Cursor ani dostępności serwisów źródłowych.

## Instrukcje i schemat

Cloud Agents v1 przyjmuje **`prompt.text`**, nie osobny `system` role ani parametr
`response_format`. Nie wysyłamy nieistniejących pól API.
`CursorPromptBuilder` umieszcza stałe instrukcje przed JSON Schema oraz wejściem
użytkownika zakodowanym jako JSON. Odpowiedź ma być wyłącznie `{"items":[...]}`:
bez markdown, komentarzy i tekstu poza JSON. Nieznane fakty mają wartość `null`.

Instrukcja modelu nie jest gwarancją poprawnego JSON. Gwarancję dla konsumenta
daje walidacja: niepoprawna odpowiedź kończy się błędem i nigdy nie tworzy
fikcyjnego wyniku. `max_items` ogranicza schemat oraz akceptowaną odpowiedź.

Mapper toleruje jeden blok ` ```json ... ``` `, listę na najwyższym poziomie,
koperty `items`, `results` lub `offers`, aliasy `position/name → title`,
`offer_url/link → url`, `amount → price`, `externalId/id → external_id`.
Nazwy walut normalizuje do wielkich liter, obiekt lokalizacji łączy z dostępnych
pól, tablicę opisu łączy znakami nowej linii. Jawne `"87%"` staje się `0.87`;
niejednoznaczne `confidence=87` jest błędem, a nie automatycznym przeliczeniem.
Brak/blank ceny i pewności pozostaje `null`; zero pozostaje prawidłowym zerem.
Pole `salary` nie jest zgadywane jako ogólna cena ani miesięczne wynagrodzenie.

Oryginalny element zostaje w `raw`, z redakcją zarezerwowanych kluczy sekretów.
`metadata.cursor` wyniku i poszczególnych elementów zawiera zaufane `agent_id`, `run_id`, runtime, pochodzenie modelu
i stan cleanup; odpowiedź agenta nie może podmienić tych danych.
Provider, kategoria i czas żądania pochodzą z kodu aplikacji, nie z odpowiedzi AI.
Brak nagłówka `items` (`{}`) nie jest udawanym pustym wynikiem; jawne
`{"items":[]}` lub `[]` są poprawnym wynikiem bez znalezionych elementów.

## Konfiguracja

W lokalnym `.env`, nie w repo ani frontendzie:

```dotenv
AI_DEFAULT_PROVIDER=cursor
CURSOR_API_KEY=
CURSOR_MODEL=
CURSOR_TIMEOUT=180
```

Wstaw prawdziwy klucz lokalnie. Identyfikator modelu wybierz z `GET /v1/models`
zgodnie z dokumentacją Cursor. Puste `CURSOR_MODEL` pomija `model` w żądaniu:
Cursor używa domyślnego modelu konta/zespołu, a DTO pokazuje `model=null`, bo API
runu nie potwierdza jego nazwy. Przy jawnym modelu DTO zachowuje zadane ID oraz
`metadata.cursor.model_source=request`; nie udaje potwierdzonej wersji runtime.

`CURSOR_TIMEOUT` to łączny budżet startu, retry i oczekiwania na wynik (1–900 s).
Po zmianie `.env` usuń cache konfiguracji: `php artisan config:clear`.
Wybór z `ai_provider_settings` ma pierwszeństwo przed ustawieniem środowiska.
Jeżeli wcześniej wybrano FakeProvider w DB, ustaw `configure('cursor')`
przez istniejący `AiProviderManager`. Zmiana konfiguracji jest odrębna od
wyszukiwania; samo wyszukiwanie nie modyfikuje konfiguracji w DB.

Keychain jest preferowany w instalowanej aplikacji macOS:
`CURSOR_CREDENTIAL_REF` wskazuje wpis `['api_key' => $secret]` w istniejącym
`CredentialStore`. Referencja ma pierwszeństwo przed kluczem `.env`;
błąd magazynu nie powoduje użycia innego konta. Klucz trafia tylko do Basic Auth
w komunikacji z ustalonym hostem `api.cursor.com`; nigdy do promptu, JSON DTO,
logu runu, argv ani danych SQLite. Przekierowania HTTP są wyłączone.
Parametry przenoszące klucz/konfigurację są oznaczone `SensitiveParameter`.
Nie dystrybuuj `.env` ani cache konfiguracji zawierającego klucze.

### Osobny klucz dla modelu lub profilu

Każdy nazwany profil providera w `config/ai_providers.php` może mieć własne
`model`, `api_key=env(...)` i `credential_ref`. Ten sam driver może mieć wiele
profili, np. `cursor_research`, `cursor_analysis`.

Ponadto profil `cursor` obsługuje jawne nadpisania **per model**:

```php
'model_credentials' => [
    // Klucz to rzeczywiste ID z /v1/models, wybrane lokalnie.
    env('CURSOR_SECONDARY_MODEL', 'unset-secondary-model') => [
        'api_key' => env('CURSOR_SECONDARY_API_KEY'),
        'credential_ref' => env('CURSOR_SECONDARY_CREDENTIAL_REF') ?: null,
    ],
],
```

To przykład konfiguracji, nie domyślnie aktywowany model. Nie wpisuj literalnych
sekretów do pliku PHP. Jeśli istnieje konfiguracja poświadczeń dla wybranego
modelu, nie wracamy do klucza domyślnego przy braku/błędzie jego poświadczeń.
Model może używać oddzielnego klucza środowiskowego albo własnego wpisu Keychain.
`options.model` wybiera model, ale `options.api_key` i zagnieżdżone sekrety
są zabronione. Klucz ustawiasz w konfiguracji bezpiecznego magazynu.

## Wywołanie bez persystencji

```php
$result = app(\App\Services\Ai\CategorySearchService::class)->search(
    category: 'job_offers', // slug, UUID/id jako string albo ID jako int
    prompt: 'Znajdź aktualne oferty pasujące do tych kryteriów.',
    filters: [
        'location' => 'Warszawa',
        'budget' => ['min' => 7000, 'max' => 12000, 'currency' => 'PLN'],
        'keywords' => ['księgowość', 'ACCA'],
    ],
    options: ['max_items' => 5],
);
$normalized = $result->toArray();
```

`analyze()` ma taką samą sygnaturę. Kategorie nie są przypisane do branży IT.
Filtry: opcjonalna lokalizacja, budżet z walutą i lista słów kluczowych.
Nie wymuszamy nieudokumentowanych parametrów modelu, np. `temperature`:
Cursor zwraca wtedy `unsupported_options` przed rozpoczęciem pracy.

Do ręcznego wywołania backendu dodano komendę (prawdziwe wywołanie jest płatną
pracą cloud zgodnie z rozliczeniami konta Cursor):

```bash
php artisan ai:search job_offers --prompt='Znajdź aktualne oferty księgowego' --location=Warszawa --keyword=ACCA --max-items=5
```

Opcje: `--model`, `--budget-min`, `--budget-max`, `--currency`, powtarzalne
`--keyword`. Wynik to JSON; nie zapisujemy go do DB. Nie podawaj klucza w argv.
Komenda kończy się błędem przy braku klucza, nie zwraca danych zastępczych.
Długa praca cloud powinna być w przyszłym UI uruchamiana w zadaniu w tle;
obecny serwis jest synchroniczny i ma ograniczony czas oczekiwania.

## Retry, błędy i cleanup

- Retry transportu: ConnectionException oraz HTTP 408, 429, 500, 502, 503, 504.
  Maksymalnie 3 próby domyślnie, exponential backoff i `Retry-After`, w granicach
  wspólnego deadline. Retry nie startuje nowego agenta z nowym ID.
- Idempotencja: jeden `agentId=bc-UUID` na wywołanie. Po 409 pobieramy tylko
  tego agenta i odzyskujemy `latestRunId`; nie tworzymy dodatkowego runu.
- 400/401/403/404/422/501 i pozostałe błędy trwałe nie są ponawiane.
- `cursor_start_failed/timeout` różnią się od `cursor_run_failed/timeout`.
  401/403 zwracają `cursor_auth_failed` z `phase=start|run` i `httpStatus`.
- Błąd runu, anulowanie, wygaśnięcie lub niepoprawny JSON nie powodują
  automatycznego powtórzenia całej pracy AI ani zaakceptowania częściowych danych.
- `AiProviderException` ma bezpieczny komunikat, `requestId`, `phase`,
  `httpStatus`, `retryable` i zaufany kontekst cleanup. Oryginalna treść HTTP
  i łańcuch wyjątku nie są propagowane.
- Cleanup ma dodatkowy, ograniczony budżet do 5 sekund i jedną próbę każdej
  operacji. Nie maskuje pierwotnego błędu. Niepowodzenie archiwizacji jest
  widoczne w `result.metadata.cursor.cleanup` (również przy pustych `items`)
  albo `exception.context.cleanup`;
  może wymagać sprawdzenia agenta na koncie Cursor. Nie usuwamy agenta.

## Weryfikacja i ograniczenia

Testy `CursorResultMapperTest` i `CursorCategorySearchTest` wykorzystują
`Http::fake`, kontrolowane odpowiedzi i monotoniczny zegar testowy bez czekania.
Sprawdzają kompletny przepływ i CLI, filtry, ID kategorii, klucze per model,
Keychain, kontrakt JSON, zero/null, deadline, retry, idempotencję, błędy startu
i runu, cleanup, ochronę sekretów oraz **brak zapisów SQL**.

```bash
php artisan test --compact tests/Unit/Ai tests/Feature/Ai
php artisan test --compact
npm run test
npm run typecheck
npm run build
```

Zgodnie z decyzją użytkownika na tym etapie **nie wykonujemy prawdziwego
wywołania Cursor API**. Implementacja transportu i mapowanie są sprawdzone
automatycznie; dostęp konta, billing, no-repo runtime, możliwości wyszukiwania
w konkretnych portalach i jakość rzeczywistych odpowiedzi wymagają późniejszego
testu z lokalnie skonfigurowanym kluczem. Nie zmieniono OAuth, istniejących baz
ofert ani Google Sheets i nie wykonano git push.

Końcowy wynik weryfikacji 2026-10-14:

- **190 testów Laravel zaliczonych, 1698 asercji** — w tym 92 testy warstwy AI.
- **29 testów JavaScript frontend/desktop zaliczonych**.
- **TypeScript strict, build Vite, Pint i `git diff --check`: zaliczone**.
- `php artisan ai:search --help`: komenda zarejestrowana i dostępna.
- Pełne wywołanie komendy i serwisu: zweryfikowane testami z `Http::fake`.
- Prawdziwy Cursor cloud / NativePHP z kluczem: nieweryfikowany zgodnie
  z decyzją użytkownika; bez deklaracji sukcesu produkcyjnego.

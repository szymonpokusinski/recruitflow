# Gemini — driver wspólnego kontraktu AI

`GeminiProvider` implementuje ten sam `AiProviderInterface` co Cursor.
`CategorySearchService`, modele kategorii, `ScanCategoryJob`, scheduler,
deduplikacja i repozytorium findings pozostają bez zmian. Nie dodano UI,
pakietów Composer ani migracji; nie zmieniono OAuth i danych Google Sheets.

## Konfiguracja lokalna

W lokalnym `.env`, bez commitowania klucza:

```dotenv
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.8-flash
GEMINI_TIMEOUT=60
GEMINI_SEARCH_GROUNDING=false
GEMINI_CREDENTIAL_REF=
```

Wpisz swój klucz w `GEMINI_API_KEY`. Alternatywnie skonfiguruj referencję
`GEMINI_CREDENTIAL_REF` do istniejącego CredentialStore z `['api_key' => ...]`.
Na macOS jest to Keychain. Referencja ma pierwszeństwo; błąd magazynu nie
powoduje cichego użycia innego klucza. `model_credentials` w konfiguracji
providera obsługuje osobne klucze/referencje dla modeli tak samo jak Cursor.
Kluczy nie przekazujemy w promptach, filtrach, wynikach ani zwykłych tabelach DB.

Wybranie globalnego domyślnego providera jest opcjonalne:

```dotenv
AI_DEFAULT_PROVIDER=gemini
```

Istniejący wybór w `ai_provider_settings` ma pierwszeństwo przed `.env`.
Kategoria może mieć `default_provider=gemini`; parametr komendy nadpisuje wybór:

```bash
php artisan config:clear
php artisan ai:scan TWOJ_SLUG_KATEGORII --provider=gemini
php artisan ai:scan TWOJ_SLUG_KATEGORII --provider=gemini --queue
```

Potrzebna jest istniejąca aktywna kategoria z aktywnym promptem i migracje
warstwy skanowania opisane w [SCANNING.md](SCANNING.md). Nie tworzymy przykładowych
kategorii ani nie włączamy cyklicznych, płatnych wywołań automatycznie.
Po zmianie konfiguracji uruchom ponownie NativePHP/worker.

## Wywołanie bez zapisu

```php
$result = app(\App\Services\Ai\CategorySearchService::class)->search(
    category: 'job_offers',
    prompt: $userPrompt,
    filters: ['location' => 'Warszawa', 'keywords' => ['księgowość']],
    options: ['max_items' => 10],
    provider: 'gemini',
);
```

`analyze()` ma ten sam podpis. Dostępne opcje: `filters`, `model`, `max_items`.
Inne opcje są jawnie odrzucane. Provider wyłącznie pobiera i normalizuje;
zapis findings jest odpowiedzialnością istniejącego serwisu skanowania.
Wynik to `AiResult`: provider/model/category/requested_at/request_id,
items i metadane. Wartość modelu pochodzi z odpowiedzi API, nie z deklaracji
modelu w wygenerowanym JSON. Oryginalne rekordy są w `raw` po redakcji sekretów.
Zero i brak danych pozostają rozróżnione.

## REST, JSON i grounding

Używamy synchronicznego REST
`POST https://generativelanguage.googleapis.com/v1beta/interactions` z nagłówkiem
`x-goog-api-key`. Jest to endpoint aktualnych przykładów Google; osobny SDK,
proces Node lub lokalny agent nie są potrzebne w NativePHP. Wysyłamy
`store=false`, `stream=false`, `background=false`; brak rozmów przechowywanych
przez mechanizm Interactions nie stanowi deklaracji wyłączenia wszystkich
logów/retencji po stronie Google.

Osobne `system_instruction` wymaga wyłącznie JSON. `response_format` ustawia
`application/json` i obsługiwany podzbiór wspólnego `AiResultSchema`.
Ograniczenia semantyczne i limity są ponownie sprawdzane przez Laravel Validator.
Cursor i Gemini używają wspólnego `AiJsonResultMapper`; własne adaptery API
nie są zależne od siebie. Mapper toleruje pojedynczy blok JSON oraz warianty
`items/results/offers`, ale odrzuca dowolny tekst otaczający JSON i błędne dane.

`GEMINI_SEARCH_GROUNDING=true` dodaje narzędzie `google_search` i capability
`web_search=true`, jeśli istnieją poświadczenia. Flaga domyślnie jest wyłączona.
Przy wyłączonej fladze prompt pozwala wyłącznie analizować dostarczone dane,
bez udawania wyszukania aktualnych ofert. Kombinacja JSON + narzędzia wymaga
Gemini 3 lub nowszego; starsze modele z włączonym groundingiem są odrzucane
przed HTTP. Nie stosujemy cichej zmiany modelu ani wyłączania JSON po błędzie.

Włączenie narzędzia nie gwarantuje jego użycia przez model.
`metadata.gemini.search_performed` pochodzi z kroków `google_search_call`.
Zachowujemy zapytania, wyniki/sugestie Google i adnotacje cytowań w metadanych
całego wyniku. Offsety cytowań dotyczą oryginalnych bloków JSON, a nie opisów
poszczególnych ofert; bloki identyfikujemy hashem i długością. Nie duplikujemy
surowego JSON zawierającego potencjalne sekrety w metadanych. Przyszłe UI musi
poprawnie obsłużyć źródła i wymagania wyświetlania sugestii Google; obecnie nie
renderujemy otrzymanego HTML. Grounding może powodować dodatkowe koszty API.

## Błędy i weryfikacja

Wspólny deadline obejmuje żądania i backoff, maksymalnie 3 próby domyślnie.
Ponawiamy tylko transport/408/429/500/502/503/504; uwzględniamy `Retry-After`.
Nie ponawiamy błędów autoryzacji, schematu ani niekompletnych odpowiedzi.
Brak przekierowań chroni klucz przed wysłaniem na inny host.
`gemini_request_*` oznacza błąd transportu, `gemini_auth_failed` uprawnienia,
`gemini_run_failed` niezakończoną generację, `invalid_response` wadliwe dane.
Nie logujemy treści błędu HTTP, nagłówków ani promptu jako komunikatu wyjątku.

Stateless generacja nie ma tutaj udokumentowanego mechanizmu idempotency-key.
Ponowienie po utracie odpowiedzi może oznaczać drugie płatne generowanie;
liczba prób jest ograniczona i konfigurowalna. Deduplikacja lokalnych findings
oraz idempotencja joba nadal działają niezależnie od providera.

Testy używają `Http::fake` i zablokowanych rzeczywistych żądań. Obejmują
kontrakt, mapper, credentials/model, grounding, retry/deadline, błędne JSON,
przepływ bez persystencji oraz niezmieniony job/komendę i deduplikację.
Rzeczywiste Gemini API nie zostało wywołane w tym zadaniu — zgodnie z wcześniejszą
decyzją użytkownika weryfikujemy wyłącznie testy automatyczne.

Dokumentacja źródłowa, sprawdzona 14.10.2026:

- [Modele](https://ai.google.dev/gemini-api/docs/models)
- [Interactions API](https://ai.google.dev/gemini-api/docs/interactions-overview)
- [REST reference](https://ai.google.dev/api/interactions-api)
- [Structured outputs](https://ai.google.dev/gemini-api/docs/structured-output)
- [Google Search grounding](https://ai.google.dev/gemini-api/docs/google-search)

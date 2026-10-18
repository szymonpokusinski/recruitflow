# Pracuj.pl — wynik badania i audyt RecruitFlow (2026-10-13)

## Wynik

Automatyczny import konkretnego ogłoszenia po URL jest możliwy dla sprawdzonych publicznych ofert. Strona oferty udostępnia ustrukturyzowane dane `JobPosting` w JSON-LD oraz osadzony stan strony Next.js z obiektem `jobOffer`. W dziesięciu aktualnych ofertach zwykłe żądanie HTTPS z backendu Laravel zwróciło parsowalny dokument; bez plików HTML dostarczanych przez użytkownika, logowania do Pracuj.pl, CAPTCHA i przeglądarki automatycznej. Nie stwierdzono gwarantowanego, publicznego API JSON przeznaczonego dla integracji. Dostępność HTML może się zmienić i nie jest gwarancją kontraktową serwisu.

Weryfikacja odbyła się na *odczycie* publicznych ofert; wyniki nie zostały zapisane w produkcyjnej bazie ani Google Sheets. Jeden przypadek `curl` w środowisku terminala dostał HTTP 403 z `cf-mitigated: challenge`; oznacza to zależność dostępu od ścieżki sieciowej. Właściwy klient PHP używa zwykłego HTTPS bez proxy, ciasteczek i podszywania się pod przeglądarkę. HTTP 403 jest pokazywany jako błąd dostępu, bez próby obejścia ochrony.

## Audyt istniejącego systemu

- Istniejący model `App\Models\Recruitment\Offer` przechowuje pola uniwersalne w SQLite `offers.data`; `OfferFields` definiuje ich walidację i mapowanie do arkusza.
- Formularz Quasar `ManagementDialogs.vue`, API `/api/recruitment/offers`, `OfferRequest` i `LocalRecruitmentService` obsługują już ręczne tworzenie oraz edycję.
- `OfferIdentity` rozpoznaje duplikaty; lokalny zapis wraz z wpisami outbox wykonuje się transakcyjnie. Synchronizacja Google korzysta z obecnego mechanizmu, bez zmian OAuth lub bezpośrednich zapisów importera do arkusza.
- Importer dodaje wyłącznie etap podglądu i metadane pochodzenia. Nie wymaga migracji; nie resetuje bazy.
- Podgląd jest chroniony tym samym lokalnym middleware co pozostałe API rekrutacyjne. Na zapis importu wymagane jest osobne zatwierdzenie formularza.

## Ograniczenia

Serwis może zmienić strukturę osadzonego stanu lub politykę dostępu. W takim wypadku JSON-LD może dostarczyć mniej pól, a brak obu struktur daje jawny `parse_error`. Danych brakujących w ogłoszeniu nie wyliczamy ani nie wymyślamy. Wiele lokalizacji i wiele wariantów wynagrodzenia pozostaje w metadanych; użytkownik wybiera wariant stawki w formularzu. Importer nie ma uprawnień do zapisu w Pracuj.pl. Live test zapisu do Google Sheets nie był wykonywany — wcześniejsza decyzja użytkownika ograniczyła weryfikację do lokalnego SQLite i testów automatycznych.

Publiczne `robots.txt` nie blokuje ścieżki ogłoszeń, lecz nie stanowi gwarancji prawa do automatycznego pobierania. Formalnego kontraktu integracyjnego nie potwierdzono; szczegóły w `HTTP_ANALYSIS.md`.

Publiczne strony sprawdzone w badaniu: [oferta obsługi klienta](https://www.pracuj.pl/praca/specjalista-do-spraw-obslugi-klienta-legnica-hangarowa-13d,oferta,1005120488) oraz [Cloud Service Developer](https://www.pracuj.pl/praca/cloud-service-developer-krakow-aleja-pokoju-18,oferta,1005154144). Pozostałe adresy i wyniki zawiera `TEST_CASES.md`.

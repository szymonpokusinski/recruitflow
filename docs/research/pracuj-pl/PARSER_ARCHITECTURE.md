# Architektura importera

`PracujUrl` waliduje i normalizuje link oraz wyciąga ID. `PublicDnsResolver` i `CurlHttpTransport` odpowiadają za bezpieczne pobranie. `SafePracujClient` klasyfikuje odpowiedź i kontroluje przekierowania. `PracujPlParser` wybiera właściwy obiekt `jobOffer` według ID i JSON-LD `JobPosting`. `OfferNormalizer` mapuje tylko zweryfikowane wartości do `OfferDraftDTO` z ostrzeżeniami oraz pochodzeniem pól. HTTP, parsowanie i zapis są rozdzielone.

`POST /api/offers/import/preview` sprawdza najpierw lokalny duplikat po identyfikatorze dostawcy. Dla nowej oferty pobiera dane i zwraca podgląd `success` lub `partial`; żaden rekord nie powstaje. `ManagementDialogs.vue` wstawia wynik do istniejącego formularza. Gdy użytkownik kliknie `Zapisz`, istniejący endpoint rekrutacji waliduje dane i tworzy ofertę w SQLite oraz wpisy synchronizacji. `importDetails` pozostaje lokalnym JSON-em w ofercie; istniejąca mapa Sheets wysyła wyłącznie wspierane pola do arkusza.

Deduplikacja wykorzystuje `pracuj.pl:<id>` niezależnie od slugu i parametrów śledzenia oraz trwały `LOCAL_PRACUJ_<id>` dla lokalnego zapisu. Wyścig równoległych zapisów kończy się błędem walidacji, a nie drugą ofertą. Nie zmieniono schematu SQLite, OAuth, outboxa ani bezpośredniego Google adaptera.

Nieznana struktura lub uszkodzony JSON zwracają jednoznaczny `parse_error`; przerwy sieciowe i statusy HTTP mają odrębne kody. Pełny HTML nie jest trwale przechowywany.

# Zweryfikowane źródła sieciowe

| Źródło | Status | Zastosowanie |
| --- | --- | --- |
| `GET https://www.pracuj.pl/praca/<slug>,oferta,<id>` | Zweryfikowane na 10 publicznych ofertach; odpowiedź 2xx w kliencie PHP | Jedyny zdalny endpoint używany przez importer. |
| JSON-LD `JobPosting` osadzone w HTML | Zweryfikowane w aktywnych stronach | Fallback i niezależne źródło wybranych pól. |
| Next.js `__NEXT_DATA__` / `jobOffer` osadzone w HTML | Zweryfikowane w aktywnych stronach | Źródło szczegółów i wariantów. |
| Odrębny publiczny endpoint JSON ofert | Niepotwierdzony | Nie używać ani nie zakładać jego istnienia. |

Adresy testowe i ocena zakresu pól są w `TEST_CASES.md`. Importer nie korzysta z prywatnego API, endpointów z sesją ani danych dostępnych tylko po logowaniu.

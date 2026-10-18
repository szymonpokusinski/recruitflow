# Analiza HTTP

| Element | Obserwacja / implementacja |
| --- | --- |
| Wejście | Wyłącznie `https://www.pracuj.pl/praca/<slug>,oferta,<id>` lub odpowiadający adres `pracuj.pl`; query tracking jest usuwane. |
| Kod sukcesu | 2xx, `Content-Type: text/html`, maksymalnie 2 MB. |
| Struktury | `script#job-schema-org[type="application/ld+json"]` i `script#__NEXT_DATA__` z `queryKey=["jobOffer", id, "pl"]`. |
| Przekierowania | Maksymalnie dwa; tylko HTTPS, ten sam host z allowlisty i ten sam identyfikator oferty. |
| DNS / SSRF | Adres publiczny IPv4, wykluczenie prywatnych i zarezerwowanych zakresów, IP przypięte do żądania cURL przez `CURLOPT_RESOLVE`; TLS weryfikowane. |
| Limity | 5 s na połączenie, 12 s na żądanie, 2 MB odpowiedzi, brak automatycznych ponowień i cookies. |
| 403 | `access_denied`, bez CAPTCHA solverów i technik obchodzenia blokady. |
| 404/410/wygasła | `expired`; 429 `rate_limited`; 5xx `remote_error`; timeout i sieć mają osobne kody. |

Zwykły terminalowy `curl` dla jednej oferty zwrócił `403` z `cf-mitigated: challenge`. Ten sam publiczny adres otworzył się w normalnej przeglądarce, a bezpośredni klient PHP pobrał HTML przez HTTPS. Różnica wskazuje, że środowisko sieciowe wpływa na wynik; nie jest podstawą do trwałego zakładania, że każdy adres zawsze zadziała. Importer używa jawnego identyfikatora `RecruitFlow Desktop/1.0`, nie kopiuje nagłówków przeglądarki i nie używa sesji, proxy rotacyjnych ani CAPTCHA.

Nie pobieramy adresów URL z treści ogłoszenia. Parametr wejściowy przechodzi ścisłą walidację przed jakimkolwiek żądaniem. Odpowiedź jest czytana tylko z dozwolonego hosta. Nie zapisujemy pełnego HTML ani danych poświadczeń.

Oficjalny [robots.txt](https://www.pracuj.pl/robots.txt) nie wyłącza ścieżki `/praca/` z indeksowania. Nie jest to jednak licencja ani formalne pozwolenie na dowolny automatyczny pobór. Nie znaleziono publicznego kontraktu API ani jednoznacznej zgody serwisu na zewnętrzny importer. Dlatego implementacja pobiera jedną stronę tylko po świadomym kliknięciu użytkownika, nie wykonuje masowego crawlowania ani obchodzenia blokad i kończy się błędem przy 403. Przed komercyjną dystrybucją warto uzyskać potwierdzenie warunków od operatora Pracuj.pl.

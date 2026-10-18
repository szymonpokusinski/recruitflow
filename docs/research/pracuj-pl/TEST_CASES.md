# Przypadki i wyniki badań (2026-10-13)

## Odczyt na żywo, tylko publiczne dane

Poniższe 10 adresów zwróciło 2xx i parsowalną ofertę w kliencie Laravel. `partial` oznacza realny brak części pól, najczęściej wynagrodzenia; importer nie uzupełnia braków fikcyjną wartością. Każdy adres został pobrany jednokrotnie w sekwencyjnym smoke teście, bez zapisu do SQLite lub Google Sheets.

| ID | Oferta | Wynik | Firma / miasto | Płaca |
| --- | --- | --- | --- | --- |
| [1005120488](https://www.pracuj.pl/praca/specjalista-do-spraw-obslugi-klienta-legnica-hangarowa-13d,oferta,1005120488) | Obsługa klienta | success | KALA / Legnica | 5600–8000 PLN/mies. |
| [1005154144](https://www.pracuj.pl/praca/cloud-service-developer-krakow-aleja-pokoju-18,oferta,1005154144) | Cloud Service Developer | success | Scalo / Kraków | 160 PLN/godz. |
| [1005154219](https://www.pracuj.pl/praca/copywriter-warszawa,oferta,1005154219) | Copywriter | partial | Solid Security / Warszawa | Brak |
| [1005114474](https://www.pracuj.pl/praca/java-developer-katowice,oferta,1005114474) | Java Developer | success | Scalo / Katowice | 140 PLN/godz. |
| [1005139249](https://www.pracuj.pl/praca/key-account-specialist-z-jezykiem-niemieckim-bydgoszcz,oferta,1005139249) | Key Account Specialist | partial | ONLYBIO / Bydgoszcz | Brak |
| [1005154184](https://www.pracuj.pl/praca/user-experience-designer-branza-ubezpieczen-warszawa,oferta,1005154184) | UX Designer | partial | Edge One / Warszawa | Brak |
| [1005114433](https://www.pracuj.pl/praca/senior-golang-developer-with-python-warszawa,oferta,1005114433) | Senior Golang Developer | success | YOUR ITEAMS / Warszawa | 150–190 PLN/godz. |
| [1005111689](https://www.pracuj.pl/praca/german-payroll-lead-katowice,oferta,1005111689) | German Payroll Lead | partial | Strada / Katowice | Brak |
| [1005144848](https://www.pracuj.pl/praca/hotel-revenue-optimization-expert-f-m-x-katowice-aleja-murckowska-14,oferta,1005144848) | Hotel Revenue Expert | partial | eSky.pl / Katowice | Brak |
| [1005130255](https://www.pracuj.pl/praca/konsultant-konsultantka-call-center-zdalnie-zacznij-w-pazdzierniku-poznan,oferta,1005130255) | Call Center | success | EduGO / Poznań | 4806–10000 PLN/mies. |

## Testy automatyczne

Fixture `tests/Fixtures/PracujPl/observed-1005120488.php` zawiera zredukowaną postać zaobserwowanych publicznych danych, bez pełnego HTML. `PracujImportTest` sprawdza: pola uniwersalne, technologię i stawkę godzinową, wiele lokalizacji i umów, brak płacy, JSON-LD fallback, wygasłe ogłoszenie, zmianę schematu, podgląd bez zapisu, zatwierdzenie do SQLite/outbox, duplikat bez kolejnego HTTP, niedozwolone URL-e, 403/404/410/429/5xx, niebezpieczne przekierowanie i rozbieżne źródła danych. Testy frontendu sprawdzają scalanie importu z ręcznie wprowadzonymi polami.

Ręczny test UI na izolowanej bazie QA: link Cloud Service Developer → `Importuj ofertę` → automatycznie wypełniony pełny formularz → `Zapisz` → oferta widoczna na liście z danymi Scalo/Kraków. Ponowny import tego samego ID z innym slugiem i parametrem `utm` pokazał istniejącą ofertę i nie utworzył duplikatu.

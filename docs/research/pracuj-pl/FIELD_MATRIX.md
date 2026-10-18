# Macierz pól zaobserwowanych w strukturze ofert

| Pracuj.pl | Pole formularza RecruitFlow | Uwagi |
| --- | --- | --- |
| `jobOfferWebId` / ID w URL | `importDetails.providerOfferId` | Stała tożsamość dostawcy; lokalny klucz `LOCAL_PRACUJ_<id>`. |
| `attributes.jobTitle` | `title` | Fallback: JSON-LD `title`. |
| `attributes.displayEmployerName` | `company` | Fallback: JSON-LD `hiringOrganization.name`. |
| `attributes.leadingCategory` | `industry`, `jobCategory` | Ogólna kategoria; brak założenia branży IT. |
| `attributes.applying.applyUrl` | `applyUrl` | Tylko jeśli występuje. |
| `publicationDetails` | `publishedAt`, `applyDeadlineAt`, `portalStatus` | Daty normalizowane do YYYY-MM-DD; wygasłych nie importujemy. |
| `attributes.workplaces[]` | `location`, `region`, `country`, `address` | Pierwsza lokalizacja w formularzu, wszystkie w `importDetails.locations`. |
| `attributes.employment.workModes[]` | `workMode` | Znane kody m.in. `full-office`, `home-office`; nieznane pozostają puste. |
| `attributes.employment.positionLevels[]` | `seniority` | Nazwy źródłowe, bez wymyślania poziomu. |
| `attributes.employment.workSchedules[]` | `workload` | Tylko jednoznacznie znany wymiar. |
| `attributes.employment.typesOfContracts[]` | `employment` | Zachowane wszystkie nazwy umów. |
| `typesOfContracts[].salary` | `salaryMin`, `salaryMax`, `currency`, `salaryBasis`, `salaryPeriod` | Jeden wariant mapowany; wiele wariantów wymaga wyboru i pozostaje w metadanych. Brak płacy pozostaje pusty. |
| `sections[].subSections` — `technologies-expected/optional` | `requiredSkills`, `preferredSkills` | Elementy słownika i `customItems`, także dla ofert nie-IT. |
| `sections` — `responsibilities`, `requirements-expected/optional`, `offered` | `responsibilities`, `requirements`, `niceRequirements`, `benefits` | Zachowane treści punktów i kolejność. |
| `sections` — `about-project`, `about-us`, `recruitment-stages` | `description`, `companyDescription`, `recruitmentSteps` | Tekst bez znaczników HTML. |
| JSON-LD `JobPosting` | Tytuł, firma, lokalizacja, daty, opis, płaca | Fallback, gdy brak pola w danych osadzonych. |

`fieldSources` zapisuje źródło każdego odczytanego pola. Gdy wartość jest pusta, importer jej nie wpisuje do formularza. Dane własnych decyzji użytkownika (`myInterest`, `favorite`, `myNotes`, status aplikowania) nigdy nie pochodzą z ogłoszenia i nie są modyfikowane przez podgląd.

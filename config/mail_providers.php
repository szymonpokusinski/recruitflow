<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Preconfigured Mail Providers
    |--------------------------------------------------------------------------
    |
    | Central configuration for email providers recognized by RecruitFlow.
    | Maps providers and their verified domain aliases. Prepared for IMAP
    | and SMTP configuration in subsequent stages.
    |
    */
    'providers' => [
        'gmail' => [
            'id' => 'gmail',
            'name' => 'Gmail',
            'auth_type' => 'oauth',
            'oauth_provider' => 'google',
            'domains' => [
                'gmail.com',
                'googlemail.com',
            ],
            'imap' => [
                'host' => 'imap.gmail.com',
                'port' => 993,
                'encryption' => 'ssl',
            ],
            'smtp' => [
                'host' => 'smtp.gmail.com',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'doc_url' => 'https://developers.google.com/gmail/api/guides',
            'instructions' => [
                'title' => 'Jak podłączyć skrzynkę Gmail przez Google OAuth2?',
                'steps' => [
                    'RecruitFlow łączy się z Gmailem za pomocą oficjalnego protokołu Google OAuth2 + PKCE — hasło do konta nie jest wpisywane w aplikacji.',
                    'Wymagane jest wcześniejsze dodanie klienta OAuth: w sekcji Ustawienia → Integracje zaimportuj plik JSON aplikacji Desktop (client_secret_*.json) z Google Cloud Console.',
                    'W Google Cloud Console (APIs & Services → Library) upewnij się, że usługa „Gmail API” jest włączona dla Twojego projektu.',
                    'W konfiguracji ekranu zgody OAuth (OAuth consent screen) dodaj minimalny zakres uprawnień: https://www.googleapis.com/auth/gmail.metadata (dostęp do metadanych skrzynki bez treści wiadomości).',
                    'Jeśli projekt Google Cloud jest w fazie testowej (Testing), dodaj swój adres Gmail do listy użytkowników testowych (Test users).',
                    'Kliknij przycisk „Połącz z Google” poniżej, zaloguj się w oknie przeglądarki i zatwierdź uprawnienia.',
                ],
            ],
        ],

        'wp' => [
            'id' => 'wp',
            'name' => 'Poczta WP',
            'domains' => [
                'wp.pl',
                'wp.eu',
            ],
            'imap' => [
                'host' => 'imap.wp.pl',
                'port' => 993,
                'encryption' => 'ssl',
                'auth_method' => 'LOGIN',
            ],
            'smtp' => [
                'host' => 'smtp.wp.pl',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'doc_url' => 'https://pomoc.wp.pl/konfiguracja-programow-pocztowych',
            'instructions' => [
                'title' => 'Jak podłączyć skrzynkę Poczta WP?',
                'steps' => [
                    'Zaloguj się na poczta.wp.pl w przeglądarce.',
                    'Przejdź do: Ustawienia (ikona koła zębatego) → Opcje → zakładka IMAP/POP.',
                    'Włącz opcję obsługi protokołu IMAP (dostęp dla programów pocztowych).',
                    'Jeśli masz włączone logowanie dwustopniowe (2FA): przejdź do Bezpieczeństwo → Logowanie dwustopniowe → Hasła do programów pocztowych → kliknij „Utwórz hasło”, nazwij je (np. RecruitFlow) i wklej je w polu hasła w aplikacji.',
                    'Jeśli nie korzystasz z 2FA: możesz wpisać swoje standardowe hasło do konta WP.',
                ],
            ],
        ],

        'o2' => [
            'id' => 'o2',
            'name' => 'Poczta o2',
            'domains' => [
                'o2.pl',
                'tlen.pl',
                'prokonto.pl',
            ],
            'imap' => [
                'host' => 'poczta.o2.pl',
                'port' => 993,
                'encryption' => 'ssl',
                'auth_method' => 'LOGIN',
            ],
            'smtp' => [
                'host' => 'poczta.o2.pl',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'doc_url' => 'https://pomoc.o2.pl/konfiguracja-programow-pocztowych',
            'instructions' => [
                'title' => 'Jak podłączyć skrzynkę Poczta o2?',
                'steps' => [
                    'Zaloguj się na poczta.o2.pl w przeglądarce.',
                    'Przejdź do: Opcje (Ustawienia) → zakładka IMAP/POP.',
                    'Zaznacz włączenie protokołu IMAP („pobieraj i wysyłaj wiadomości przez IMAP/POP3”).',
                    'Jeśli masz włączone logowanie dwustopniowe (2FA): przejdź do Bezpieczeństwo → Logowanie dwustopniowe → Hasła do programów pocztowych → kliknij „Utwórz hasło” i wklej wygenerowane hasło w formularzu.',
                    'Jeśli logujesz się tradycyjnie (bez 2FA): wpisz swoje standardowe hasło do konta o2.',
                ],
            ],
        ],

        'onet' => [
            'id' => 'onet',
            'name' => 'Onet Poczta',
            'domains' => [
                'onet.pl',
                'onet.eu',
                'poczta.onet.pl',
                'op.pl',
                'vp.pl',
                'autograf.pl',
                'amorki.pl',
                'spoko.pl',
                'poczta.onet.eu',
                'onet.com.pl',
            ],
            'imap' => [
                'host' => 'imap.poczta.onet.pl',
                'port' => 993,
                'encryption' => 'ssl',
                'auth_method' => 'LOGIN',
            ],
            'smtp' => [
                'host' => 'smtp.poczta.onet.pl',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'doc_url' => 'https://pomoc.poczta.onet.pl/baza-wiedzy/kategoria/programy-pocztowe/',
            'instructions' => [
                'title' => 'Jak podłączyć skrzynkę Onet Poczta?',
                'steps' => [
                    'Zaloguj się na poczta.onet.pl w przeglądarce.',
                    'Przejdź do: Ustawienia (ikona koła zębatego w lewym menu) → Konto → Konto Główne.',
                    'W sekcji protokołów upewnij się, że opcja IMAP jest włączona.',
                    'Jeśli masz włączone logowanie dwuetapowe (2FA): przejdź do Bezpieczeństwo → Hasła do aplikacji, wygeneruj hasło dla programu pocztowego i wklej je w polu hasła.',
                    'Jeśli logujesz się tradycyjnie (bez 2FA): wpisz swoje standardowe hasło do konta Onet oraz upewnij się, że jako login podajesz pełny adres e-mail.',
                ],
            ],
        ],

        'interia' => [
            'id' => 'interia',
            'name' => 'Poczta Interia',
            'domains' => [
                'interia.pl',
                'interia.eu',
                'poczta.fm',
                'interbit.pl',
                'interia.com',
                'vip.interia.pl',
                'intmail.pl',
                'interiowy.pl',
                '1gb.pl',
                '2gb.pl',
            ],
            'imap' => [
                'host' => 'poczta.interia.pl',
                'port' => 993,
                'encryption' => 'ssl',
                'auth_method' => 'LOGIN',
            ],
            'smtp' => [
                'host' => 'poczta.interia.pl',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'doc_url' => 'https://pomoc.poczta.interia.pl/news-parametry-do-konfiguracji-programow-pocztowych,nId,2136067',
            'instructions' => [
                'title' => 'Jak podłączyć skrzynkę Poczta Interia?',
                'steps' => [
                    'Zaloguj się na poczta.interia.pl w przeglądarce.',
                    'Przejdź do: Ustawienia (ikona koła zębatego w prawym dolnym rogu) → Główne ustawienia → Parametry.',
                    'W sekcji „Program pocztowy” włącz opcję „Korzystam z programu pocztowego” (ustaw na TAK) i zapisz zmiany — bez tego serwer IMAP odrzuci połączenie.',
                    'Wpisz pełny adres e-mail (np. login@interia.pl lub adres w domenie poczta.fm) oraz standardowe hasło do poczty.',
                    'Rozwiązywanie problemów z logowaniem: upewnij się, że wpisujesz pełny adres e-mail. Jeśli na koncie aktywowano 2FA (logowanie dwuetapowe), tradycyjny dostęp przez IMAP zostaje zablokowany przez Interię — aby połączyć skrzynkę, należy wyłączyć 2FA na koncie.',
                ],
            ],
        ],

        'gazeta' => [
            'id' => 'gazeta',
            'name' => 'Gazeta.pl',
            'domains' => [
                'gazeta.pl',
                'wyborcza.pl',
                'g.pl',
            ],
            'imap' => [
                'host' => 'imap.gazeta.pl',
                'port' => 993,
                'encryption' => 'ssl',
                'auth_method' => 'LOGIN',
            ],
            'smtp' => [
                'host' => 'smtp.gazeta.pl',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'doc_url' => 'https://pomoc.gazeta.pl/pomoc/7,154322,32746451,instrukcja-konfiguracji-konta-e-mail-thunderbird-outlook.html',
            'instructions' => [
                'title' => 'Jak podłączyć skrzynkę Gazeta.pl?',
                'steps' => [
                    'Zaloguj się na poczta.gazeta.pl w przeglądarce.',
                    'Przejdź do: Ustawienia (ikona koła zębatego) → Konto główne i upewnij się, że protokół IMAP jest włączony.',
                    'W formularzu RecruitFlow wpisz pełny adres e-mail (np. twoj-login@gazeta.pl lub adres w domenie wyborcza.pl) oraz hasło do konta.',
                    'Jeśli korzystasz z logowania dwuetapowego (2FA): użyj wygenerowanego w ustawieniach konta kodu awaryjnego lub hasła dedykowanego dla programu pocztowego zamiast hasła głównego.',
                    'Jeśli nie korzystasz z 2FA: wpisz swoje standardowe hasło do konta pocztowego Gazeta.pl.',
                ],
            ],
        ],

        'outlook' => [
            'id' => 'outlook',
            'name' => 'Outlook',
            'domains' => [
                'outlook.com',
                'hotmail.com',
                'live.com',
                'msn.com',
                'outlook.pl',
                'hotmail.pl',
                'live.pl',
            ],
            'imap' => [
                'host' => 'outlook.office365.com',
                'port' => 993,
                'encryption' => 'ssl',
            ],
            'smtp' => [
                'host' => 'smtp.office365.com',
                'port' => 587,
                'encryption' => 'starttls',
            ],
        ],

        'generic' => [
            'id' => 'generic',
            'name' => 'Własny / Nieznany dostawca',
            'domains' => [],
            'imap' => [
                'host' => null,
                'port' => 993,
                'encryption' => 'ssl',
            ],
            'smtp' => [
                'host' => null,
                'port' => 465,
                'encryption' => 'ssl',
            ],
        ],
    ],
];

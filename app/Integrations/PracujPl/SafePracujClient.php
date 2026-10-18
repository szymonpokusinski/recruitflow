<?php

namespace App\Integrations\PracujPl;

use InvalidArgumentException;

final readonly class SafePracujClient
{
    public function __construct(private PublicDnsResolver $dns, private HttpTransport $transport) {}

    public function fetch(PracujUrl $target): HttpResult
    {
        $url = $target->url;
        for ($redirect = 0; $redirect <= 2; $redirect++) {
            $address = $this->dns->address(parse_url($url, PHP_URL_HOST));
            $response = $this->transport->get($url, $address);
            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('location');
                if ($location === null || ! str_starts_with($location, 'https://')) {
                    throw new ImportException('unsafe_redirect', 'Ogłoszenie przekierowuje na niedozwolony adres.', 422);
                }
                try {
                    $next = PracujUrl::from($location);
                } catch (InvalidArgumentException) {
                    throw new ImportException('unsafe_redirect', 'Ogłoszenie przekierowuje poza dozwoloną ofertę.', 422);
                }
                if ($next->offerId !== $target->offerId) {
                    throw new ImportException('unsafe_redirect', 'Przekierowanie prowadzi do innej oferty.', 422);
                }
                $url = $next->url;

                continue;
            }
            if ($response->status === 403) {
                throw new ImportException('access_denied', 'Pracuj.pl zablokował automatyczny odczyt tej oferty (HTTP 403).', 502);
            }
            if (in_array($response->status, [404, 410], true)) {
                throw new ImportException('expired', 'Ogłoszenie jest niedostępne lub wygasło.', 404);
            }
            if ($response->status === 429) {
                throw new ImportException('rate_limited', 'Pracuj.pl ograniczył liczbę żądań. Spróbuj później.', 503);
            }
            if ($response->status < 200 || $response->status >= 300) {
                throw new ImportException('remote_error', 'Pracuj.pl zwrócił błąd HTTP '.$response->status.'.', 502);
            }
            if (strlen($response->body) > 2_000_000 || ! str_contains(strtolower($response->header('content-type') ?? ''), 'text/html')) {
                throw new ImportException('unsupported_format', 'Odpowiedź nie jest stroną HTML ogłoszenia.', 502);
            }

            return $response;
        }

        throw new ImportException('redirect_loop', 'Zbyt wiele przekierowań ogłoszenia.', 502);
    }
}

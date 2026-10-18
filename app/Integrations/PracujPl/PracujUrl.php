<?php

namespace App\Integrations\PracujPl;

use InvalidArgumentException;

final readonly class PracujUrl
{
    private function __construct(public string $url, public string $offerId) {}

    public static function from(string $input): self
    {
        $input = trim($input);
        if (strlen($input) > 2048 || ! filter_var($input, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Podaj pełny adres HTTPS konkretnej oferty Pracuj.pl.');
        }
        $parts = parse_url($input);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || ! in_array($host, ['www.pracuj.pl', 'pracuj.pl'], true)
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Dozwolone są wyłącznie adresy HTTPS ofert z Pracuj.pl.');
        }
        $path = rawurldecode($parts['path'] ?? '');
        if (! preg_match('~^/praca/([\pL\pN._%-]+),oferta,(\d{8,12})/?$~u', $path, $matches)) {
            throw new InvalidArgumentException('Adres nie wskazuje konkretnego ogłoszenia Pracuj.pl.');
        }

        return new self('https://www.pracuj.pl/praca/'.rtrim($matches[1], '/').',oferta,'.$matches[2], $matches[2]);
    }
}

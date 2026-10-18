<?php

namespace App\Integrations\PracujPl;

class PublicDnsResolver
{
    public function address(string $host): string
    {
        $addresses = $this->lookup($host);
        if ($addresses === []) {
            throw new ImportException('network_error', 'Nie można rozpoznać adresu Pracuj.pl.', 502);
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ImportException('unsafe_address', 'Adres serwera ogłoszenia jest niedozwolony.', 422);
            }
        }

        return $addresses[0];
    }

    protected function lookup(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }
}

<?php

namespace App\Integrations\PracujPl;

interface HttpTransport
{
    public function get(string $url, string $pinnedAddress): HttpResult;
}

<?php

namespace App\Integrations\PracujPl;

final readonly class HttpResult
{
    public function __construct(public int $status, public array $headers, public string $body) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

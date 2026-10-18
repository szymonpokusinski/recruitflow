<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\CredentialStore;

final class InMemoryCredentialStore implements CredentialStore
{
    private array $values = [];

    public function read(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function write(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

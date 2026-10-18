<?php

namespace App\Integrations\Contracts;

interface CredentialStore
{
    public function read(string $key): ?array;

    public function write(string $key, array $value): void;

    public function delete(string $key): void;
}

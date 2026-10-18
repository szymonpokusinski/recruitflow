<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Validation\ValidationException;
use JsonException;

final class AiPayloadGuard
{
    private const SECRET_KEYS = ['apikey', 'token', 'accesstoken', 'refreshtoken', 'clientsecret', 'secret', 'authorization', 'password', 'credentials', 'cookie', 'setcookie'];

    public function validateJson(array $payload, string $field): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR, 32);
        } catch (JsonException) {
            throw ValidationException::withMessages([$field => 'Dane muszą być poprawnym JSON o głębokości maksymalnie 32.']);
        }
        if (strlen($encoded) > 2_000_000 || ! $this->isJsonTree($payload)) {
            throw ValidationException::withMessages([$field => 'Dane JSON przekraczają dozwolony rozmiar lub zawierają niedozwolone typy.']);
        }
    }

    public function containsSecrets(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($this->isSecretKey($key) || (is_array($value) && $this->containsSecrets($value))) {
                return true;
            }
        }

        return false;
    }

    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $payload[$key] = $this->isSecretKey($key) ? '[REDACTED]' : (is_array($value) ? $this->redact($value) : $value);
        }

        return $payload;
    }

    private function isSecretKey(string|int $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z]/', '', (string) $key));
        foreach (self::SECRET_KEYS as $secretKey) {
            if (str_ends_with($normalized, $secretKey)) {
                return true;
            }
        }

        return false;
    }

    private function isJsonTree(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (! $this->isJsonTree($entry)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }
}

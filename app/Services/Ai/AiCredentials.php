<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Integrations\Contracts\CredentialStore;
use SensitiveParameter;
use Throwable;

final class AiCredentials
{
    public function __construct(private readonly CredentialStore $store) {}

    /** A secure reference takes precedence over .env. Never fall back on vault failure. */
    public function apiKey(#[SensitiveParameter] array $configuration, ?string $model = null): ?string
    {
        $profiles = $configuration['model_credentials'] ?? [];
        if (! is_array($profiles)) {
            throw new AiProviderException('invalid_configuration');
        }
        if ($model !== null && array_key_exists($model, $profiles)) {
            // Explicit model credentials are isolated from the default account.
            $profile = $profiles[$model];
            if (! is_array($profile)) {
                throw new AiProviderException('invalid_configuration');
            }
            $configuration = [
                'api_key' => $profile['api_key'] ?? null,
                'credential_ref' => $profile['credential_ref'] ?? null,
            ];
        }
        if (! empty($configuration['credential_ref'])) {
            try {
                $value = $this->store->read($configuration['credential_ref'])['api_key'] ?? null;
            } catch (Throwable) {
                throw new AiProviderException('credential_store_error');
            }
        } else {
            $value = $configuration['api_key'] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}

<?php

namespace App\Integrations\Security;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\IntegrationException;
use Illuminate\Support\Facades\Crypt;

class EncryptedFileCredentialStore implements CredentialStore
{
    private function path(string $key): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $key)) {
            throw new IntegrationException('Nieprawidłowy identyfikator poświadczeń.', 'vault_error');
        }

        return storage_path('app/private/integration-credentials/'.$key.'.enc');
    }

    public function read(string $key): ?array
    {
        $path = $this->path($key);
        if (! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new \UnexpectedValueException;
            }

            return $decoded;
        } catch (\Throwable) {
            throw new IntegrationException('Nie można odszyfrować poświadczeń. Połącz konto ponownie.', 'vault_error');
        }
    }

    public function write(string $key, array $value): void
    {
        $path = $this->path($key);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $temp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temp, Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), LOCK_EX) === false) {
            throw new IntegrationException('Nie można zapisać poświadczeń.', 'vault_error');
        }
        chmod($temp, 0600);
        if (! rename($temp, $path)) {
            throw new IntegrationException('Nie można zapisać poświadczeń.', 'vault_error');
        }
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

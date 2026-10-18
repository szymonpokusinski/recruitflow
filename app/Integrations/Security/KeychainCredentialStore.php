<?php

namespace App\Integrations\Security;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\IntegrationException;
use Symfony\Component\Process\Process;

class KeychainCredentialStore implements CredentialStore
{
    private function key(string $key): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $key)) {
            throw new IntegrationException('Nieprawidłowy identyfikator poświadczeń.', 'vault_error');
        }

        return $key;
    }

    public function read(string $key): ?array
    {
        $process = new Process(['/usr/bin/security', 'find-generic-password', '-a', $this->key($key), '-s', config('integrations.keychain_service'), '-w']);
        $process->setTimeout(60)->run();
        if ($process->getExitCode() === 44) {
            return null;
        }
        if (! $process->isSuccessful()) {
            throw new IntegrationException('Nie można odczytać poświadczeń z Keychain. Sprawdź zgodę macOS.', 'vault_error');
        }
        $value = json_decode(base64_decode(trim($process->getOutput()), true) ?: '', true);
        if (! is_array($value)) {
            throw new IntegrationException('Poświadczenia Keychain są uszkodzone. Połącz konto ponownie.', 'vault_error');
        }

        return $value;
    }

    public function write(string $key, array $value): void
    {
        // The credential goes through stdin, never through process arguments or logs.
        $process = new Process(['/usr/bin/security', '-i']);
        $command = sprintf('add-generic-password -U -a %s -s %s -w %s', $this->key($key), config('integrations.keychain_service'), base64_encode(json_encode($value, JSON_THROW_ON_ERROR)));
        $process->setInput($command."\n")->setTimeout(60)->run();
        if (! $process->isSuccessful()) {
            throw new IntegrationException('Nie można zapisać poświadczeń w Keychain. Sprawdź zgodę macOS.', 'vault_error');
        }
        // Interactive security can return 0 even if its subcommand fails.
        $stored = $this->read($key);
        if ($stored !== $value) {
            throw new IntegrationException('Keychain nie potwierdził zapisu poświadczeń.', 'vault_error');
        }
    }

    public function delete(string $key): void
    {
        $process = new Process(['/usr/bin/security', 'delete-generic-password', '-a', $this->key($key), '-s', config('integrations.keychain_service')]);
        $process->setTimeout(60)->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 44) {
            throw new IntegrationException('Nie można usunąć poświadczeń z Keychain.', 'vault_error');
        }
    }
}

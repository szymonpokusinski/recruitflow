<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\Mail\Contracts\MailDriverInterface;

class FakeMailDriver implements MailDriverInterface
{
    /** @var array<int, array{host: string, port: int, username: string, has_password: bool}> */
    public array $testedConnections = [];

    /** @var array{success: bool, error: string|null, error_code: string|null}|null */
    public ?array $nextResult = null;

    public function testConnection(array $config): array
    {
        $this->testedConnections[] = [
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 0),
            'username' => (string) ($config['username'] ?? ''),
            'has_password' => ! empty($config['password']),
        ];

        if ($this->nextResult !== null) {
            $result = $this->nextResult;
            $this->nextResult = null;

            return $result;
        }

        // Default behavior: success if password is not 'wrong_password'
        $password = (string) ($config['password'] ?? '');
        if ($password === 'wrong_password') {
            return [
                'success' => false,
                'error' => 'Błędny adres e-mail lub hasło (sprawdź, czy IMAP jest włączony w ustawieniach poczty i czy wymagane jest hasło aplikacji).',
                'error_code' => 'authentication_failed',
            ];
        }

        if ($password === 'timeout') {
            return [
                'success' => false,
                'error' => 'Przekroczono limit czasu połączenia z serwerem pocztowym (timeout). Sprawdź dostępność hosta.',
                'error_code' => 'connection_timeout',
            ];
        }

        if ($password === 'tls_error') {
            return [
                'success' => false,
                'error' => 'Błąd weryfikacji certyfikatu SSL/TLS serwera pocztowego. Połączenie zostało przerwane ze względów bezpieczeństwa.',
                'error_code' => 'tls_error',
            ];
        }

        return [
            'success' => true,
            'error' => null,
            'error_code' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\Mail\Contracts\MailDriverInterface;
use SensitiveParameter;

class ImapMailDriver implements MailDriverInterface
{
    /**
     * Test IMAP connection, verify TLS, authenticate user, and close session cleanly.
     * Does not fetch any messages, headers, folders, or attachments.
     *
     * @param array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     timeout?: int
     * } $config
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     error_code: string|null
     * }
     */
    public function testConnection(#[SensitiveParameter] array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 993);
        $encryption = strtolower((string) ($config['encryption'] ?? 'ssl'));
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $timeout = (int) ($config['timeout'] ?? 10);

        if ($host === '' || $port <= 0 || $username === '') {
            return [
                'success' => false,
                'error' => 'Niekompletna konfiguracja serwera pocztowego (brak hosta, portu lub nazwy użytkownika).',
                'error_code' => 'invalid_configuration',
            ];
        }

        // Enforce strict TLS certificate verification
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $protocol = match ($encryption) {
            'ssl', 'tls' => 'ssl://',
            default => 'tcp://',
        };
        $remote = $protocol . $host . ':' . $port;

        $errno = 0;
        $errstr = '';

        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($fp)) {
            return $this->handleConnectionFailure($errno, $errstr);
        }

        stream_set_timeout($fp, $timeout);

        // 1. Read greeting
        $greeting = fgets($fp);
        $meta = stream_get_meta_data($fp);

        if ($meta['timed_out']) {
            @fclose($fp);

            return [
                'success' => false,
                'error' => 'Przekroczono limit czasu podczas oczekiwania na powitanie serwera IMAP.',
                'error_code' => 'timeout',
            ];
        }

        if ($greeting === false || ! str_starts_with(trim($greeting), '* OK')) {
            @fclose($fp);

            return [
                'success' => false,
                'error' => 'Serwer IMAP odrzucił połączenie lub powitanie było nieprawidłowe.',
                'error_code' => 'server_rejected',
            ];
        }

        // 2. Authenticate using LOGIN command
        $tag = 'A001';
        $escapedUser = addcslashes($username, "\\\"");
        $escapedPass = addcslashes($password, "\\\"");

        $written = @fwrite($fp, "{$tag} LOGIN \"{$escapedUser}\" \"{$escapedPass}\"\r\n");
        if ($written === false) {
            @fclose($fp);

            return [
                'success' => false,
                'error' => 'Nie udało się wysłać danych uwierzytelniających do serwera IMAP.',
                'error_code' => 'write_failed',
            ];
        }

        $authenticated = false;
        $errorMessage = null;
        $errorCode = null;

        while (! feof($fp)) {
            $line = fgets($fp);
            $meta = stream_get_meta_data($fp);

            if ($meta['timed_out']) {
                $errorMessage = 'Przekroczono limit czasu oczekiwania na odpowiedź serwera IMAP.';
                $errorCode = 'timeout';
                break;
            }

            if ($line === false) {
                break;
            }

            $trimmed = trim($line);

            if (str_starts_with($trimmed, "{$tag} OK")) {
                $authenticated = true;
                break;
            }

            if (str_starts_with($trimmed, "{$tag} NO")) {
                $errorMessage = 'Błędny adres e-mail lub hasło (sprawdź, czy IMAP jest włączony w ustawieniach poczty i czy wymagane jest hasło aplikacji).';
                $errorCode = 'authentication_failed';
                break;
            }

            if (str_starts_with($trimmed, "{$tag} BAD")) {
                $errorMessage = 'Serwer IMAP zgłosił błąd polecenia logowania.';
                $errorCode = 'bad_command';
                break;
            }
        }

        // 3. Close session cleanly
        @fwrite($fp, "A002 LOGOUT\r\n");
        @fclose($fp);

        if ($authenticated) {
            return [
                'success' => true,
                'error' => null,
                'error_code' => null,
            ];
        }

        return [
            'success' => false,
            'error' => $errorMessage ?? 'Nie udało się zweryfikować danych logowania do serwera IMAP.',
            'error_code' => $errorCode ?? 'unknown_error',
        ];
    }

    /**
     * @return array{success: false, error: string, error_code: string}
     */
    private function handleConnectionFailure(int $errno, string $errstr): array
    {
        $errLower = strtolower($errstr);

        if (str_contains($errLower, 'certificate') || str_contains($errLower, 'ssl') || str_contains($errLower, 'tls')) {
            return [
                'success' => false,
                'error' => 'Błąd weryfikacji certyfikatu SSL/TLS serwera pocztowego. Połączenie zostało przerwane ze względów bezpieczeństwa.',
                'error_code' => 'tls_error',
            ];
        }

        if ($errno === 110 || $errno === 60 || str_contains($errLower, 'timed out')) {
            return [
                'success' => false,
                'error' => 'Przekroczono limit czasu połączenia z serwerem pocztowym (timeout). Sprawdź dostępność hosta.',
                'error_code' => 'connection_timeout',
            ];
        }

        return [
            'success' => false,
            'error' => 'Nie można nawiązać połączenia z serwerem pocztowym. Sprawdź połączenie internetowe i zapory sieciowe.',
            'error_code' => 'connection_failed',
        ];
    }
}

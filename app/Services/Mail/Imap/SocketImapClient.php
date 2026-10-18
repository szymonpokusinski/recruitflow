<?php

declare(strict_types=1);

namespace App\Services\Mail\Imap;

use App\Services\Mail\Contracts\ImapClientInterface;
use RuntimeException;
use SensitiveParameter;

class SocketImapClient implements ImapClientInterface
{
    /** @var resource|null */
    private $stream = null;

    private int $tagCounter = 0;

    private int $timeout = 15;

    public function connect(#[SensitiveParameter] array $config): void
    {
        $this->disconnect();

        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 993);
        $encryption = strtolower((string) ($config['encryption'] ?? 'ssl'));
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $this->timeout = (int) ($config['timeout'] ?? 15);

        if ($host === '' || $port <= 0 || $username === '') {
            throw new RuntimeException('Niekompletna konfiguracja serwera IMAP (brak hosta, portu lub nazwy użytkownika).');
        }

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

        $errno = 0;
        $errstr = '';

        $fp = @stream_socket_client(
            $protocol . $host . ':' . $port,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($fp)) {
            $errLower = strtolower($errstr);
            if (str_contains($errLower, 'certificate') || str_contains($errLower, 'ssl') || str_contains($errLower, 'tls')) {
                throw new RuntimeException("Błąd weryfikacji certyfikatu SSL/TLS serwera IMAP [{$host}]. Połączenie przerwano ze względów bezpieczeństwa.");
            }
            if ($errno === 110 || $errno === 60 || str_contains($errLower, 'timed out')) {
                throw new RuntimeException("Przekroczono limit czasu połączenia z serwerem IMAP [{$host}:{$port}] (timeout).");
            }
            throw new RuntimeException("Nie można nawiązać połączenia z serwerem IMAP [{$host}:{$port}]: {$errstr} (kod: {$errno}).");
        }

        stream_set_timeout($fp, $this->timeout);
        $this->stream = $fp;

        // 1. Read greeting
        $greeting = $this->readLine();
        if ($greeting === null || ! str_starts_with(trim($greeting), '* OK')) {
            $this->disconnect();
            throw new RuntimeException("Serwer IMAP [{$host}] odrzucił połączenie lub powitanie było nieprawidłowe.");
        }

        // 2. Authenticate using LOGIN
        $tag = $this->nextTag();
        $escapedUser = addcslashes($username, "\\\"");
        $escapedPass = addcslashes($password, "\\\"");

        $this->writeLine("{$tag} LOGIN \"{$escapedUser}\" \"{$escapedPass}\"");

        $loginResponse = $this->readUntilTag($tag);
        if (! $loginResponse['ok']) {
            $this->disconnect();
            throw new RuntimeException("Błąd uwierzytelnienia na serwerze IMAP [{$host}]: " . ($loginResponse['message'] ?: 'błędny login lub hasło.'));
        }
    }

    public function examineFolder(string $folder = 'INBOX'): array
    {
        $this->assertConnected();

        $tag = $this->nextTag();
        // Strictly EXAMINE to enforce read-only mode (RFC 3501 section 6.3.2)
        $this->writeLine("{$tag} EXAMINE \"{$folder}\"");

        $response = $this->readUntilTag($tag);
        if (! $response['ok']) {
            throw new RuntimeException("Nie udało się otworzyć folderu [{$folder}] w trybie tylko do odczytu: {$response['message']}");
        }

        $exists = 0;
        $uidvalidity = null;
        $uidnext = null;

        foreach ($response['lines'] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\*\s+([0-9]+)\s+EXISTS/i', $trimmed, $m)) {
                $exists = (int) $m[1];
            }

            if (preg_match('/UIDVALIDITY\s+([0-9]+)/i', $trimmed, $m)) {
                $uidvalidity = (int) $m[1];
            }

            if (preg_match('/UIDNEXT\s+([0-9]+)/i', $trimmed, $m)) {
                $uidnext = (int) $m[1];
            }
        }

        if ($uidvalidity === null) {
            throw new RuntimeException("Serwer IMAP nie zwrócił parametru UIDVALIDITY dla folderu [{$folder}].");
        }

        return [
            'exists' => $exists,
            'uidvalidity' => $uidvalidity,
            'uidnext' => $uidnext,
        ];
    }

    public function searchUidsSince(int $sinceUid): array
    {
        $this->assertConnected();

        $tag = $this->nextTag();
        $startUid = $sinceUid + 1;
        $this->writeLine("{$tag} UID SEARCH UID {$startUid}:*");

        $response = $this->readUntilTag($tag);
        if (! $response['ok']) {
            throw new RuntimeException("Wyszukiwanie nowych wiadomości UID SEARCH nie powiodło się: {$response['message']}");
        }

        $uids = [];
        foreach ($response['lines'] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\*\s+SEARCH(.*)$/i', $trimmed, $m)) {
                $rawUids = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($rawUids as $num) {
                    if (ctype_digit($num)) {
                        $uidVal = (int) $num;
                        // Guard against servers that return $sinceUid when empty
                        if ($uidVal > $sinceUid) {
                            $uids[] = $uidVal;
                        }
                    }
                }
            }
        }

        sort($uids, SORT_NUMERIC);

        return array_values(array_unique($uids));
    }

    public function fetchHeadersBySequenceRange(int $startSeq, int $endSeq): array
    {
        $this->assertConnected();

        if ($startSeq <= 0 || $endSeq < $startSeq) {
            return [];
        }

        $tag = $this->nextTag();
        // Strictly BODY.PEEK to prevent marking messages as \Seen (RFC 3501 section 6.4.5)
        $this->writeLine("{$tag} FETCH {$startSeq}:{$endSeq} (UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT REPLY-TO DATE MESSAGE-ID IN-REPLY-TO REFERENCES)])");

        return $this->readFetchResponse($tag);
    }

    public function fetchHeadersByUids(array $uids): array
    {
        $this->assertConnected();

        if (empty($uids)) {
            return [];
        }

        $cleanUids = array_filter(array_map('intval', $uids), fn ($u) => $u > 0);
        if (empty($cleanUids)) {
            return [];
        }

        $tag = $this->nextTag();
        $uidList = implode(',', $cleanUids);
        // Strictly BODY.PEEK to prevent marking messages as \Seen (RFC 3501 section 6.4.5)
        $this->writeLine("{$tag} UID FETCH {$uidList} (UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT REPLY-TO DATE MESSAGE-ID IN-REPLY-TO REFERENCES)])");

        return $this->readFetchResponse($tag);
    }

    public function disconnect(): void
    {
        if ($this->stream !== null && is_resource($this->stream)) {
            try {
                $tag = $this->nextTag();
                @fwrite($this->stream, "{$tag} LOGOUT\r\n");
                $this->readUntilTag($tag);
            } catch (\Throwable) {
                // Ignore logout exceptions during close
            } finally {
                @fclose($this->stream);
                $this->stream = null;
            }
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @return array<array{uid: int, raw_headers: string}>
     */
    private function readFetchResponse(string $tag): array
    {
        $results = [];

        while (! feof($this->stream)) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }

            $trimmed = trim($line);

            // Check if tagged line reached
            if (str_starts_with($trimmed, "{$tag} ")) {
                if (! str_starts_with($trimmed, "{$tag} OK")) {
                    throw new RuntimeException("Pobieranie nagłówków FETCH nie powiodło się: {$trimmed}");
                }
                break;
            }

            // Check if untagged FETCH line
            if (preg_match('/^\*\s+([0-9]+)\s+FETCH\s+\(/i', $trimmed)) {
                $uid = null;
                if (preg_match('/\bUID\s+([0-9]+)\b/i', $trimmed, $m)) {
                    $uid = (int) $m[1];
                }

                $rawHeaders = '';

                // Check for literal {length}
                if (preg_match('/\{([0-9]+)\}\r?$/', $line, $mLit)) {
                    $length = (int) $mLit[1];
                    $rawHeaders = $this->readBytes($length);

                    // Read remainder of the FETCH line (e.g. ")\r\n" or " UID 123)\r\n")
                    $remainder = $this->readLine();
                    if ($uid === null && $remainder !== null && preg_match('/\bUID\s+([0-9]+)\b/i', $remainder, $m)) {
                        $uid = (int) $m[1];
                    }
                }

                if ($uid !== null) {
                    $results[] = [
                        'uid' => $uid,
                        'raw_headers' => $rawHeaders,
                    ];
                }
            }
        }

        return $results;
    }

    private function writeLine(string $cmd): void
    {
        if ($this->stream === null || ! is_resource($this->stream)) {
            throw new RuntimeException('Brak aktywnego połączenia z serwerem IMAP.');
        }

        $written = @fwrite($this->stream, $cmd . "\r\n");
        if ($written === false) {
            throw new RuntimeException('Nie udało się wysłać polecenia do serwera IMAP (błąd zapisu do gniazda).');
        }
    }

    private function readLine(): ?string
    {
        if ($this->stream === null || ! is_resource($this->stream)) {
            return null;
        }

        $line = fgets($this->stream);
        $meta = stream_get_meta_data($this->stream);
        if ($meta['timed_out']) {
            throw new RuntimeException('Przekroczono limit czasu oczekiwania na odpowiedź serwera IMAP (timeout).');
        }

        return $line !== false ? $line : null;
    }

    private function readBytes(int $length): string
    {
        if ($this->stream === null || ! is_resource($this->stream)) {
            return '';
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0 && ! feof($this->stream)) {
            $chunk = fread($this->stream, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $meta = stream_get_meta_data($this->stream);
        if ($meta['timed_out']) {
            throw new RuntimeException('Przekroczono limit czasu odczytu danych nagłówków IMAP (timeout).');
        }

        return $data;
    }

    /**
     * @return array{ok: bool, message: string, lines: array<string>}
     */
    private function readUntilTag(string $tag): array
    {
        $lines = [];
        $ok = false;
        $message = '';

        while (! feof($this->stream)) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }

            $trimmed = trim($line);
            $lines[] = $line;

            if (str_starts_with($trimmed, "{$tag} OK")) {
                $ok = true;
                $message = $trimmed;
                break;
            }

            if (str_starts_with($trimmed, "{$tag} NO") || str_starts_with($trimmed, "{$tag} BAD")) {
                $ok = false;
                $message = $trimmed;
                break;
            }
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'lines' => $lines,
        ];
    }

    private function nextTag(): string
    {
        return 'TAG' . (++$this->tagCounter);
    }

    private function assertConnected(): void
    {
        if ($this->stream === null || ! is_resource($this->stream)) {
            throw new RuntimeException('Brak nawiązanego połączenia z serwerem IMAP.');
        }
    }
}

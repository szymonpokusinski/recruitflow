<?php

namespace App\Console\Commands;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\Google\GoogleOAuthService;
use App\Integrations\IntegrationException;
use App\Models\Integrations\OAuthFlow;
use Illuminate\Console\Command;

class ListenForGoogleOAuth extends Command
{
    protected $signature = 'integration:oauth-listen {flow}';

    protected $description = 'Receive one Google PKCE callback on a temporary loopback socket';

    public function handle(GoogleOAuthService $oauth, CredentialStore $vault): int
    {
        $flow = OAuthFlow::findOrFail($this->argument('flow'));
        if ($flow->status !== 'starting' || $flow->expires_at->isPast()) {
            return self::FAILURE;
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (! $socket) {
            $flow->update(['status' => 'failed', 'error' => 'Nie można otworzyć lokalnego portu OAuth.']);

            return self::FAILURE;
        }
        $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $flow->update(['redirect_uri' => 'http://127.0.0.1:'.$port.'/', 'status' => 'waiting']);
        try {
            while ($flow->expires_at->isFuture()) {
                $current = $flow->fresh();
                if (! $current || $current->status !== 'waiting') {
                    return self::FAILURE;
                }
                $connection = @stream_socket_accept($socket, 1);
                if (! $connection) {
                    continue;
                }
                stream_set_timeout($connection, 3);
                $line = fgets($connection, 8192);
                $status = 400;
                $message = 'Nieprawidłowe żądanie.';
                $done = false;
                if (is_string($line) && preg_match('#^GET (/\?[^ ]+) HTTP/1\.[01]\r?\n$#', $line, $match)) {
                    parse_str(parse_url($match[1], PHP_URL_QUERY) ?: '', $query);
                    try {
                        $oauth->complete($flow->fresh(), $query);
                        $status = 200;
                        $message = 'Konto Google jest połączone. Możesz zamknąć tę kartę i wrócić do RecruitFlow.';
                        $done = true;
                    } catch (IntegrationException $e) {
                        $status = 400;
                        $message = $e->getMessage();
                        $done = $e->reason !== 'invalid_state';
                    } catch (\Throwable) {
                        $flow->update(['status' => 'failed', 'error' => 'Nie udało się bezpiecznie zakończyć logowania.']);
                        $message = 'Logowanie nie powiodło się. Wróć do RecruitFlow.';
                        $done = true;
                    }
                }
                $body = '<!doctype html><html lang="pl"><meta charset="utf-8"><title>RecruitFlow OAuth</title><p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p></html>';
                fwrite($connection, "HTTP/1.1 $status Result\r\nContent-Type: text/html; charset=utf-8\r\nCache-Control: no-store\r\nContent-Security-Policy: default-src 'none'\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
                fclose($connection);
                if ($done) {
                    return $status === 200 ? self::SUCCESS : self::FAILURE;
                }
            }
            $flow->update(['status' => 'expired', 'error' => 'Logowanie wygasło. Rozpocznij je ponownie.']);

            return self::FAILURE;
        } finally {
            fclose($socket);
            $vault->delete($flow->secret_ref);
        }
    }
}

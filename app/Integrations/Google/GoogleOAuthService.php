<?php

namespace App\Integrations\Google;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\IntegrationException;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Integrations\IntegrationSetting;
use App\Models\Integrations\OAuthFlow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Shell;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class GoogleOAuthService
{
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public const WRITE_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public const GMAIL_METADATA_SCOPE = 'https://www.googleapis.com/auth/gmail.metadata';

    public const GMAIL_READONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public const GMAIL_IMAP_SCOPE = 'https://mail.google.com/';

    public const USERINFO_EMAIL_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

    public const OPENID_SCOPE = 'openid';

    public function __construct(private CredentialStore $vault) {}

    public function configure(array $document): void
    {
        $installed = $document['installed'] ?? null;
        if (! is_array($installed) || ! is_string($installed['client_id'] ?? null) || (isset($installed['client_secret']) && ! is_string($installed['client_secret'])) || ! preg_match('/^[a-zA-Z0-9._-]+\.apps\.googleusercontent\.com$/', $installed['client_id'] ?? '')) {
            throw new IntegrationException('Zaimportuj plik JSON klienta OAuth typu Desktop app.', 'invalid_client');
        }
        $setting = IntegrationSetting::firstOrNew(['provider' => 'google']);
        if ($setting->exists && $setting->client_id !== $installed['client_id'] && IntegrationAccount::where('provider', 'google')->where('status', 'connected')->exists()) {
            throw new IntegrationException('Przed zmianą klienta OAuth odłącz istniejące konta Google.', 'client_in_use');
        }
        $key = $setting->secret_ref ?: (string) Str::uuid();
        $this->vault->write($key, ['client_secret' => (string) ($installed['client_secret'] ?? '')]);
        $setting->fill(['client_id' => $installed['client_id'], 'secret_ref' => $key])->save();
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function cleanExpiredFlows(): void
    {
        foreach (OAuthFlow::whereIn('status', ['starting', 'waiting', 'exchanging'])->where('expires_at', '<=', now())->get() as $flow) {
            $this->vault->delete($flow->secret_ref);
            $flow->update(['status' => 'expired', 'error' => 'Logowanie wygasło. Rozpocznij je ponownie.']);
        }
    }

    public function createFlow(string $name, ?IntegrationAccount $account = null, bool|string|array $scope = false): OAuthFlow
    {
        $this->cleanExpiredFlows();
        $setting = IntegrationSetting::where('provider', 'google')->first();
        if (! $setting) {
            throw new IntegrationException('Najpierw zaimportuj konfigurację Google OAuth.', 'client_missing');
        }
        $account ??= IntegrationAccount::create(['provider' => 'google', 'name' => $name, 'credential_ref' => (string) Str::uuid(), 'status' => 'pending']);
        foreach (OAuthFlow::where('integration_account_id', $account->id)->whereIn('status', ['starting', 'waiting'])->get() as $previous) {
            $this->vault->delete($previous->secret_ref);
            $previous->update(['status' => 'expired', 'error' => 'Rozpoczęto nowe logowanie.']);
        }
        $flow = OAuthFlow::create(['integration_account_id' => $account->id, 'secret_ref' => (string) Str::uuid(), 'expires_at' => now()->addSeconds(config('integrations.oauth_timeout_seconds'))]);

        $resolvedScope = match (true) {
            is_bool($scope) => $scope ? self::WRITE_SCOPE : self::SCOPE,
            is_array($scope) => implode(' ', $scope),
            default => (string) $scope,
        };

        try {
            $this->vault->write($flow->secret_ref, [
                'state' => bin2hex(random_bytes(32)),
                'verifier' => bin2hex(random_bytes(48)),
                'client_id' => $setting->client_id,
                'client_secret' => $this->vault->read($setting->secret_ref)['client_secret'] ?? '',
                'scope' => $resolvedScope,
            ]);
        } catch (\Throwable $e) {
            $flow->update(['status' => 'failed', 'error' => 'Nie można przygotować bezpiecznego logowania.']);
            throw $e;
        }

        return $flow;
    }

    public function start(string $name, ?IntegrationAccount $account = null, bool|string|array $scope = false): array
    {
        $flow = $this->createFlow($name, $account, $scope);
        if (config('nativephp-internal.running')) {
            ChildProcess::artisan(['integration:oauth-listen', $flow->id], 'oauth-'.$flow->id);
        } else {
            // All interpolated arguments are generated/quoted locally, not user input.
            $php = (new PhpExecutableFinder)->find(false);
            if (! $php) {
                throw new IntegrationException('Nie znaleziono PHP CLI dla odbiornika OAuth.', 'listener_error');
            }
            $command = '/usr/bin/nohup '.escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' integration:oauth-listen '.escapeshellarg($flow->id).' > /dev/null 2>&1 < /dev/null &';
            Process::fromShellCommandline($command, base_path())->setTimeout(5)->run();
        }
        $until = microtime(true) + 8;
        do {
            usleep(100000);
            $flow->refresh();
        } while ($flow->status === 'starting' && microtime(true) < $until);
        if ($flow->status !== 'waiting') {
            $flow->update(['status' => 'failed', 'error' => 'Nie udało się uruchomić odbiornika OAuth.']);
            $this->vault->delete($flow->secret_ref);
            throw new IntegrationException('Nie udało się uruchomić lokalnego odbiornika OAuth. Sprawdź logowanie w NativePHP.', 'listener_error');
        }
        $secrets = $this->vault->read($flow->secret_ref);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $secrets['client_id'], 'redirect_uri' => $flow->redirect_uri, 'response_type' => 'code',
            'scope' => $secrets['scope'] ?? self::SCOPE, 'state' => $secrets['state'], 'code_challenge' => self::challenge($secrets['verifier']),
            'code_challenge_method' => 'S256', 'access_type' => 'offline', 'prompt' => 'consent select_account',
        ], '', '&', PHP_QUERY_RFC3986);
        if (config('nativephp-internal.running')) {
            Shell::openExternal($url);
        }

        return ['id' => $flow->id, 'status' => $flow->status, 'authorization_url' => config('nativephp-internal.running') ? null : $url];
    }

    public function complete(OAuthFlow $flow, array $query): void
    {
        $secrets = $this->vault->read($flow->secret_ref);
        if (! $secrets || ! is_string($query['state'] ?? null) || ! hash_equals($secrets['state'], $query['state'])) {
            throw new IntegrationException('Odpowiedź OAuth nie pasuje do rozpoczętego logowania.', 'invalid_state');
        }
        if ($flow->expires_at->isPast() || ! OAuthFlow::whereKey($flow->id)->where('status', 'waiting')->update(['status' => 'exchanging'])) {
            throw new IntegrationException('Logowanie wygasło lub zostało już wykorzystane.', 'flow_expired');
        }
        try {
            if (isset($query['error'])) {
                throw new IntegrationException('Logowanie Google zostało anulowane lub odrzucone.', 'consent_denied');
            }
            if (! is_string($query['code'] ?? null) || $query['code'] === '' || strlen($query['code']) > 4096) {
                throw new IntegrationException('Brak kodu autoryzacji Google.', 'invalid_code');
            }
            $tokens = $this->tokenRequest(array_filter([
                'client_id' => $secrets['client_id'], 'client_secret' => $secrets['client_secret'],
                'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => $secrets['verifier'], 'redirect_uri' => $flow->redirect_uri,
            ], fn ($value) => $value !== ''), $secrets['scope'] ?? self::SCOPE);
            $account = $flow->account;
            $tokens['client_id'] = $secrets['client_id'];
            $tokens['client_secret'] = $secrets['client_secret'];
            $this->storeTokens($account, $tokens);
            $flow->update(['status' => 'connected', 'error' => null]);
        } catch (IntegrationException $e) {
            $flow->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $this->vault->delete($flow->secret_ref);
        }
    }

    private function tokenRequest(array $payload, string $requiredScope = self::SCOPE): array
    {
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(config('integrations.http_timeout_seconds'))->post('https://oauth2.googleapis.com/token', $payload);
        } catch (\Throwable) {
            throw new IntegrationException('Brak połączenia z Google. Spróbuj ponownie po odzyskaniu internetu.', 'offline');
        }
        if (! $response->successful()) {
            if ($response->json('error') === 'invalid_grant') {
                throw new IntegrationException('Autoryzacja wygasła. Połącz konto Google ponownie.', 'reauthorization_required');
            }
            throw new IntegrationException('Google odrzucił wymianę tokenu. Sprawdź klienta OAuth i udzielone uprawnienia.', 'oauth_error');
        }
        $data = $response->json();
        if (! is_array($data) || ! is_string($data['access_token'] ?? null) || ! isset($data['expires_in'])) {
            throw new IntegrationException('Nieprawidłowa odpowiedź serwera OAuth.', 'oauth_error');
        }
        $scopes = explode(' ', $data['scope'] ?? $requiredScope);
        if ($requiredScope === self::SCOPE || $requiredScope === self::WRITE_SCOPE) {
            $allowed = $requiredScope === self::WRITE_SCOPE ? [self::SCOPE, self::WRITE_SCOPE] : [self::SCOPE];
            if (! in_array($requiredScope, $scopes, true) || array_diff($scopes, $allowed)) {
                throw new IntegrationException('Google nie udzielił wybranego zakresu dostępu do arkuszy.', 'scope_mismatch');
            }
        }
        $data['scope'] = implode(' ', $scopes);

        return $data;
    }

    private function storeTokens(IntegrationAccount $account, array $tokens): void
    {
        $tokens['expires_at'] = now()->addSeconds((int) $tokens['expires_in'])->timestamp;
        $this->vault->write($account->credential_ref, $tokens);
        $account->update(['status' => 'connected', 'scopes' => explode(' ', $tokens['scope'] ?? self::SCOPE), 'token_expires_at' => now()->setTimestamp($tokens['expires_at'])]);
    }

    public function accessToken(IntegrationAccount $account, bool $forceRefresh = false): string
    {
        return Cache::lock('google-token-'.$account->id, 300)->block(5, function () use ($account, $forceRefresh) {
            if ($account->fresh()->status !== 'connected') {
                throw new IntegrationException('Połącz konto Google ponownie.', 'reauthorization_required');
            }
            $tokens = $this->vault->read($account->credential_ref);
            if (! $tokens) {
                throw new IntegrationException('Brak poświadczeń konta. Połącz konto ponownie.', 'reauthorization_required');
            }
            if (! $forceRefresh && ($tokens['expires_at'] ?? 0) > now()->timestamp + 60) {
                return $tokens['access_token'];
            }
            if (empty($tokens['refresh_token'])) {
                $account->update(['status' => 'reauthorization_required']);
                throw new IntegrationException('Brak tokenu odświeżania. Połącz konto Google ponownie.', 'reauthorization_required');
            }
            try {
                $accountScopes = $account->fresh()->scopes ?? [];
                $requiredScope = in_array(self::WRITE_SCOPE, $accountScopes, true)
                    ? self::WRITE_SCOPE
                    : (in_array(self::SCOPE, $accountScopes, true) ? self::SCOPE : implode(' ', $accountScopes));

                $new = $this->tokenRequest(
                    array_filter([
                        'client_id' => $tokens['client_id'],
                        'client_secret' => $tokens['client_secret'] ?? '',
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $tokens['refresh_token'],
                    ], fn ($value) => $value !== ''),
                    $requiredScope
                );
                $this->storeTokens($account, array_merge($tokens, $new));

                return $new['access_token'];
            } catch (IntegrationException $e) {
                if ($e->reason === 'reauthorization_required') {
                    $account->update(['status' => 'reauthorization_required']);
                } throw $e;
            }
        });
    }

    public function disconnect(IntegrationAccount $account): void
    {
        if ($account->sources()->exists()) {
            throw new IntegrationException('Najpierw usuń konfiguracje źródeł korzystających z tego konta.', 'account_in_use');
        }
        $this->vault->delete($account->credential_ref);
        foreach (OAuthFlow::where('integration_account_id', $account->id)->get() as $flow) {
            $this->vault->delete($flow->secret_ref);
        }
        $account->delete();
    }
}

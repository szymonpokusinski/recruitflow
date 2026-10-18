<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Google\GoogleOAuthService;
use App\Models\Integrations\IntegrationAccount;
use App\Models\MailAccount;
use App\Services\Mail\Contracts\MailDriverInterface;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

class MailConnectionService
{
    public function __construct(
        private readonly MailDriverInterface $driver,
        private readonly MailAccountService $accountService,
        private readonly MailProviderResolver $resolver,
    ) {}

    /**
     * Test connection for an existing MailAccount.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     error: string|null,
     *     error_code: string|null,
     *     provider: string,
     *     status: string,
     *     last_tested_at: string|null,
     *     last_test_result: string|null
     * }
     */
    public function testAccount(MailAccount $account, #[SensitiveParameter] ?string $overridePassword = null): array
    {
        $providerKey = strtolower((string) ($account->provider ?: ''));
        if ($providerKey === '' || $providerKey === 'generic') {
            $resolved = $this->resolver->resolveFromEmail($account->email);
            $providerKey = $resolved['provider'];
            $account->provider = $providerKey;
        }

        // Supported providers: WP, o2, Onet, Interia, Gazeta.pl, Gmail
        $supportedProviders = ['wp', 'o2', 'onet', 'interia', 'gazeta', 'gmail'];
        if (! in_array($providerKey, $supportedProviders, true)) {
            $errorMsg = 'Automatyczny test połączenia jest obecnie dostępny dla poczty WP, o2, Onet, Interia, Gazeta.pl oraz Gmail.';
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'error',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'unsupported_provider',
                'provider' => $providerKey,
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        if ($providerKey === 'gmail') {
            return $this->testGmailAccount($account);
        }


        $providerConfig = config("mail_providers.providers.{$providerKey}", []);
        $imap = $providerConfig['imap'] ?? [];

        $host = (string) ($account->imap_host ?: ($imap['host'] ?? ''));
        $port = (int) ($account->imap_port ?: ($imap['port'] ?? 993));
        $encryption = (string) ($account->imap_encryption ?: ($imap['encryption'] ?? 'ssl'));
        $username = (string) ($account->imap_username ?: $account->email);

        $password = $overridePassword ?: $this->accountService->getPassword($account);

        if (! is_string($password) || trim($password) === '') {
            $errorMsg = 'Brak hasła dla tego konta pocztowego. Podaj hasło, aby wykonać test.';
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'error',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'missing_password',
                'provider' => $providerKey,
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        $testResult = $this->driver->testConnection([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'timeout' => 10,
        ]);

        $isSuccess = $testResult['success'];

        $account->update([
            'last_tested_at' => now(),
            'last_test_result' => $isSuccess ? 'success' : 'failed',
            'last_error' => $isSuccess ? null : $testResult['error'],
            'status' => $isSuccess ? 'connected' : 'error',
            'imap_host' => $host,
            'imap_port' => $port,
            'imap_encryption' => $encryption,
            'imap_username' => $username,
        ]);

        return [
            'success' => $isSuccess,
            'message' => $isSuccess
                ? 'Połączenie z serwerem pocztowym powiodło się.'
                : ($testResult['error'] ?? 'Błąd połączenia z serwerem pocztowym.'),
            'error' => $isSuccess ? null : $testResult['error'],
            'error_code' => $testResult['error_code'] ?? null,
            'provider' => $providerKey,
            'status' => $account->status,
            'last_tested_at' => $account->last_tested_at?->toISOString(),
            'last_test_result' => $account->last_test_result,
        ];
    }

    /**
     * Test Gmail OAuth connection via Gmail API (users/me/profile).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     error: string|null,
     *     error_code: string|null,
     *     provider: string,
     *     status: string,
     *     last_tested_at: string|null,
     *     last_test_result: string|null
     * }
     */
    public function testGmailAccount(MailAccount $account): array
    {
        $credentialRef = $account->credential_ref;
        if (! $credentialRef || $account->auth_type !== 'oauth') {
            $errorMsg = 'Konto Gmail wymaga autoryzacji Google OAuth2. Kliknij „Połącz z Google”, aby autoryzować konto.';
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'error',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'missing_credentials',
                'provider' => 'gmail',
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        $integrationAccount = IntegrationAccount::where('credential_ref', $credentialRef)->first();
        if (! $integrationAccount || $integrationAccount->status !== 'connected') {
            $errorMsg = 'Autoryzacja Google dla tego konta wygasła lub została usunięta. Połącz konto Gmail ponownie.';
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'reauthorization_required',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'reauthorization_required',
                'provider' => 'gmail',
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        $scopes = $integrationAccount->scopes ?? [];
        $hasGmailScope = in_array(GoogleOAuthService::GMAIL_METADATA_SCOPE, $scopes, true)
            || in_array(GoogleOAuthService::GMAIL_READONLY_SCOPE, $scopes, true)
            || in_array(GoogleOAuthService::GMAIL_IMAP_SCOPE, $scopes, true);

        if (! $hasGmailScope) {
            $errorMsg = 'Konto Google zostało zalogowane, ale nie udzielono uprawnień do skrzynki Gmail (brak zakresu gmail.metadata). Połącz konto ponownie i udziel zgody na dostęp do poczty.';
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'error',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'insufficient_scopes',
                'provider' => 'gmail',
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        try {
            $token = app(GoogleOAuthService::class)->accessToken($integrationAccount);
        } catch (\Throwable $e) {
            $errorMsg = 'Nie udało się pobrać aktualnego tokenu Google: ' . $e->getMessage();
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'reauthorization_required',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'reauthorization_required',
                'provider' => 'gmail',
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        try {
            $response = Http::withToken($token)
                ->connectTimeout(5)
                ->timeout(10)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');
        } catch (\Throwable) {
            $errorMsg = 'Przekroczono limit czasu połączenia z Google API (timeout) lub brak połączenia z internetem.';
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'failed',
                'last_error' => $errorMsg,
                'status' => 'error',
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'error' => $errorMsg,
                'error_code' => 'timeout',
                'provider' => 'gmail',
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        if ($response->successful()) {
            $account->update([
                'last_tested_at' => now(),
                'last_test_result' => 'success',
                'last_error' => null,
                'status' => 'connected',
            ]);

            return [
                'success' => true,
                'message' => 'Połączenie z kontem Gmail przez Google OAuth2 powiodło się.',
                'error' => null,
                'error_code' => null,
                'provider' => 'gmail',
                'status' => $account->status,
                'last_tested_at' => $account->last_tested_at?->toISOString(),
                'last_test_result' => $account->last_test_result,
            ];
        }

        $statusCode = $response->status();
        $errorCode = match ($statusCode) {
            401 => 'reauthorization_required',
            403 => 'insufficient_scopes',
            default => 'api_error',
        };

        $googleMessage = (string) $response->json('error.message', '');
        $activationUrl = $response->json('error.details.0.metadata.activationUrl')
            ?? $response->json('error.details.2.links.0.url');

        if ($statusCode === 403 && (str_contains($googleMessage, 'is disabled') || str_contains($googleMessage, 'has not been used'))) {
            $url = $activationUrl ?: 'https://console.cloud.google.com/apis/library/gmail.googleapis.com';
            $errorMsg = "Usługa Gmail API nie jest jeszcze włączona w Twoim projekcie Google Cloud. Włącz ją w konsoli Google: {$url} a następnie kliknij „Testuj Gmail API”.";
            $errorCode = 'service_disabled';
        } else {
            $errorMsg = match ($statusCode) {
                401 => 'Autoryzacja Google wygasła. Wymagane ponowne połączenie konta.',
                403 => 'Google API odmówiło dostępu do profilu Gmail. Upewnij się, że usługa Gmail API jest włączona w projekcie Google Cloud oraz że projekt posiada wymagane uprawnienia.',
                default => 'Serwer Google zwrócił błąd podczas weryfikacji profilu poczty (kod HTTP: ' . $statusCode . ').',
            };
        }


        $account->update([
            'last_tested_at' => now(),
            'last_test_result' => 'failed',
            'last_error' => $errorMsg,
            'status' => 'error',
        ]);

        return [
            'success' => false,
            'message' => $errorMsg,
            'error' => $errorMsg,
            'error_code' => $errorCode,
            'provider' => 'gmail',
            'status' => $account->status,
            'last_tested_at' => $account->last_tested_at?->toISOString(),
            'last_test_result' => $account->last_test_result,
        ];
    }
}

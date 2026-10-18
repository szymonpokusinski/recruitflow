<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Mail\StoreMailAccountRequest;
use App\Http\Requests\Mail\UpdateMailAccountRequest;
use App\Integrations\Google\GoogleOAuthService;
use App\Integrations\IntegrationException;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Integrations\IntegrationSetting;
use App\Models\Integrations\OAuthFlow;
use App\Models\MailAccount;
use App\Services\MailAccountService;
use App\Services\MailConnectionService;
use App\Services\MailProviderResolver;
use App\Services\Mail\MailSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MailAccountController extends Controller
{
    public function index(MailAccountService $service): JsonResponse
    {
        return response()->json($service->list());
    }

    public function resolveProvider(Request $request, MailProviderResolver $resolver): JsonResponse
    {
        $email = (string) ($request->input('email') ?? $request->query('email') ?? '');
        $result = $resolver->resolveFromEmail($email);

        return response()->json($result);
    }

    public function store(StoreMailAccountRequest $request, MailAccountService $service): JsonResponse
    {
        $account = $service->create($request->validated());

        return response()->json($account, 201);
    }

    public function update(UpdateMailAccountRequest $request, string $id, MailAccountService $service): JsonResponse
    {
        $account = $service->get($id);
        $updated = $service->update($account, $request->validated());

        return response()->json($updated);
    }

    public function test(Request $request, string $id, MailAccountService $accountService, MailConnectionService $connectionService): JsonResponse
    {
        $account = $accountService->get($id);
        $overridePassword = $request->input('password');

        $result = $connectionService->testAccount(
            $account,
            is_string($overridePassword) && $overridePassword !== '' ? $overridePassword : null
        );

        return response()->json($result);
    }

    public function sync(string $id, MailAccountService $accountService, MailSyncService $syncService): JsonResponse
    {
        @set_time_limit(300);
        @ignore_user_abort(true);

        $account = $accountService->get($id);

        if (! in_array($account->status, ['active', 'connected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Konto pocztowe nie jest aktywne ani połączone. Sprawdź konfigurację konta w Ustawieniach.',
            ], 422);
        }

        $result = $syncService->syncAccount($account);

        if (! $result->success) {
            $isConflict = $result->error && str_contains($result->error, 'w toku');

            return response()->json([
                'success' => false,
                'message' => $result->error ?? 'Synchronizacja nie powiodła się.',
                'error' => $result->error,
            ], $isConflict ? 409 : 422);
        }

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Synchronizacja zakończona sukcesem. Sprawdzono %d nagłówków, zakwalifikowano %d wiadomości.',
                $result->fetchedCount,
                $result->qualifiedCount
            ),
            'result' => [
                'fetched_count' => $result->fetchedCount,
                'qualified_count' => $result->qualifiedCount,
                'ignored_count' => $result->ignoredCount,
                'unmatched_count' => $result->unmatchedCount,
            ],
            'sync_state' => $account->syncState()->first(),
        ]);
    }

    public function googleConnect(Request $request, GoogleOAuthService $oauth): JsonResponse
    {
        $setting = IntegrationSetting::where('provider', 'google')->first();
        if (! $setting) {
            return response()->json([
                'message' => 'Brak skonfigurowanego klienta Google OAuth. Przejdź do Ustawienia → Integracje i zaimportuj plik konfiguracyjny klienta Desktop app (client_secret_*.json) z Google Cloud Console.',
                'error_code' => 'client_missing',
            ], 422);
        }

        $email = (string) $request->input('email', '');
        $name = 'Gmail' . ($email !== '' ? ' (' . $email . ')' : '');

        $scopes = [
            GoogleOAuthService::OPENID_SCOPE,
            GoogleOAuthService::USERINFO_EMAIL_SCOPE,
            GoogleOAuthService::GMAIL_METADATA_SCOPE,
            GoogleOAuthService::GMAIL_READONLY_SCOPE,
        ];

        try {
            $auth = $oauth->start($name, null, $scopes);

            return response()->json([
                'flow_id' => $auth['id'],
                'authorization_url' => $auth['authorization_url'] ?? null,
                'status' => $auth['status'],
            ]);
        } catch (IntegrationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->reason,
            ], 422);
        }
    }

    public function googleFlowStatus(string $flowId, GoogleOAuthService $oauth, MailAccountService $service, MailConnectionService $connService): JsonResponse
    {
        $flow = OAuthFlow::find($flowId);
        if (! $flow) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'Nie znaleziono sesji logowania OAuth.',
            ], 404);
        }

        if ($flow->status === 'connected') {
            $account = $flow->account;
            if (! $account) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Nie znaleziono konta powiązanego z sesją logowania.',
                ], 422);
            }

            // Check granted scopes
            $scopes = $account->scopes ?? [];
            $hasGmailScope = in_array(GoogleOAuthService::GMAIL_METADATA_SCOPE, $scopes, true)
                || in_array(GoogleOAuthService::GMAIL_READONLY_SCOPE, $scopes, true)
                || in_array(GoogleOAuthService::GMAIL_IMAP_SCOPE, $scopes, true);

            // Fetch user email from Google UserInfo or Gmail profile
            $userEmail = null;
            try {
                $token = $oauth->accessToken($account);
                $infoRes = Http::withToken($token)->timeout(5)->get('https://www.googleapis.com/oauth2/v2/userinfo');
                if ($infoRes->successful()) {
                    $userEmail = $infoRes->json('email');
                }
            } catch (\Throwable) {
                // Ignore failure if offline
            }

            if (! $userEmail) {
                $userEmail = $account->name;
                if (str_starts_with($userEmail, 'Gmail (') && str_ends_with($userEmail, ')')) {
                    $userEmail = substr($userEmail, 7, -1);
                }
            }

            if (! filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $userEmail = 'konto@gmail.com';
            }

            // Find or create MailAccount for this email
            $mailAccount = MailAccount::where('email', $userEmail)->first();
            if (! $mailAccount) {
                $mailAccount = MailAccount::create([
                    'email' => $userEmail,
                    'name' => 'Gmail (' . $userEmail . ')',
                    'auth_type' => 'oauth',
                    'provider' => 'gmail',
                    'oauth_provider' => 'google',
                    'credential_ref' => $account->credential_ref,
                    'token_expires_at' => $account->token_expires_at,
                    'status' => $hasGmailScope ? 'connected' : 'error',
                    'last_tested_at' => now(),
                    'last_test_result' => $hasGmailScope ? 'success' : 'failed',
                    'last_error' => $hasGmailScope ? null : 'Zalogowano do konta Google, ale nie przyznano uprawnień do Gmaila (brak zakresu gmail.metadata). Połącz konto ponownie i zatwierdź uprawnienia.',
                ]);
            } else {
                $mailAccount->update([
                    'auth_type' => 'oauth',
                    'provider' => 'gmail',
                    'oauth_provider' => 'google',
                    'credential_ref' => $account->credential_ref,
                    'token_expires_at' => $account->token_expires_at,
                    'status' => $hasGmailScope ? 'connected' : 'error',
                    'last_tested_at' => now(),
                    'last_test_result' => $hasGmailScope ? 'success' : 'failed',
                    'last_error' => $hasGmailScope ? null : 'Zalogowano do konta Google, ale nie przyznano uprawnień do Gmaila (brak zakresu gmail.metadata). Połącz konto ponownie i zatwierdź uprawnienia.',
                ]);
            }

            if ($hasGmailScope) {
                // Test the connection via Gmail API to verify real mailbox access
                $testResult = $connService->testAccount($mailAccount);

                return response()->json([
                    'status' => $testResult['success'] ? 'connected' : 'error',
                    'mail_account' => $mailAccount->fresh(),
                    'message' => $testResult['success']
                        ? 'Konto Gmail zostało pomyślnie połączone przez Google OAuth2.'
                        : ($testResult['error'] ?? 'Wystąpił błąd podczas testu skrzynki Gmail.'),
                ]);
            }

            return response()->json([
                'status' => 'scope_missing',
                'mail_account' => $mailAccount->fresh(),
                'message' => 'Zalogowano do konta Google, ale nie przyznano uprawnień do Gmaila (brak zakresu gmail.metadata). W oknie logowania Google należy wyrazić zgodę na dostęp do poczty.',
            ]);
        }

        if ($flow->status === 'failed') {
            return response()->json([
                'status' => 'failed',
                'error' => $flow->error ?? 'Logowanie Google nie powiodło się.',
            ]);
        }

        if ($flow->expires_at->isPast()) {
            return response()->json([
                'status' => 'expired',
                'error' => 'Sesja logowania Google OAuth wygasła.',
            ]);
        }

        return response()->json([
            'status' => $flow->status,
        ]);
    }

    public function disconnectOAuth(string $id, MailAccountService $service): JsonResponse
    {
        $account = $service->get($id);
        if ($account->auth_type !== 'oauth') {
            return response()->json(['message' => 'To konto nie korzysta z uwierzytelniania OAuth.'], 400);
        }

        $credentialRef = $account->credential_ref;

        // Check if an IntegrationAccount is linked and whether it is used by any data_sources
        if ($credentialRef) {
            $integrationAccount = IntegrationAccount::where('credential_ref', $credentialRef)->first();
            if ($integrationAccount && $integrationAccount->sources()->count() === 0) {
                // Delete orphaned integration account and tokens
                app(\App\Integrations\Contracts\CredentialStore::class)->delete($credentialRef);
                $integrationAccount->delete();
            }
        }

        $account->delete();

        return response()->json(['message' => 'Konto Gmail zostało pomyślnie odłączone.']);
    }

    public function destroy(string $id, MailAccountService $service): JsonResponse
    {
        $account = $service->get($id);

        if ($account->auth_type === 'oauth' && $account->credential_ref) {
            $integrationAccount = IntegrationAccount::where('credential_ref', $account->credential_ref)->first();
            if ($integrationAccount && $integrationAccount->sources()->count() === 0) {
                app(\App\Integrations\Contracts\CredentialStore::class)->delete($account->credential_ref);
                $integrationAccount->delete();
            }
        }

        $service->delete($account);

        return response()->json(['message' => 'Konto pocztowe zostało usunięte.']);
    }

    public function toggleAutoSync(Request $request, string $id, MailAccountService $service): JsonResponse
    {
        $account = $service->get($id);
        $enabled = $request->has('auto_sync') ? $request->boolean('auto_sync') : null;
        $updated = $service->toggleAutoSync($account, $enabled);

        return response()->json($updated);
    }
}

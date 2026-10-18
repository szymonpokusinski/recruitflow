<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\Google\GoogleOAuthService;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Integrations\OAuthFlow;
use App\Models\MailAccount;
use App\Services\Mail\Contracts\MailDriverInterface;
use App\Services\Mail\FakeMailDriver;
use App\Services\MailAccountService;
use App\Services\MailConnectionService;
use App\Services\MailProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;


class MailConnectionTest extends TestCase
{
    use RefreshDatabase;

    private MailAccountMemoryStore $vault;

    private FakeMailDriver $fakeDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = new MailAccountMemoryStore;
        $this->app->instance(CredentialStore::class, $this->vault);

        $this->fakeDriver = new FakeMailDriver;
        $this->app->instance(MailDriverInterface::class, $this->fakeDriver);
    }

    public function test_automatic_imap_configuration_selection_for_supported_providers(): void
    {
        $accountService = app(MailAccountService::class);
        $connService = app(MailConnectionService::class);

        // 1. WP
        $wpAccount = $accountService->create([
            'email' => 'jan.kowalski@wp.pl',
            'password' => 'WpSecretPass123',
        ]);
        $connService->testAccount($wpAccount);

        $this->assertCount(1, $this->fakeDriver->testedConnections);
        $wpCall = $this->fakeDriver->testedConnections[0];
        $this->assertSame('imap.wp.pl', $wpCall['host']);
        $this->assertSame(993, $wpCall['port']);
        $this->assertSame('jan.kowalski@wp.pl', $wpCall['username']);
        $this->assertTrue($wpCall['has_password']);
        $this->assertSame('imap.wp.pl', $wpAccount->fresh()->imap_host);
        $this->assertSame(993, $wpAccount->fresh()->imap_port);
        $this->assertSame('ssl', $wpAccount->fresh()->imap_encryption);

        // 2. o2
        $o2Account = $accountService->create([
            'email' => 'adam.nowak@o2.pl',
            'password' => 'O2SecretPass456',
        ]);
        $connService->testAccount($o2Account);

        $this->assertCount(2, $this->fakeDriver->testedConnections);
        $o2Call = $this->fakeDriver->testedConnections[1];
        $this->assertSame('poczta.o2.pl', $o2Call['host']);
        $this->assertSame(993, $o2Call['port']);
        $this->assertSame('adam.nowak@o2.pl', $o2Call['username']);
        $this->assertTrue($o2Call['has_password']);
        $this->assertSame('poczta.o2.pl', $o2Account->fresh()->imap_host);
        $this->assertSame(993, $o2Account->fresh()->imap_port);

        // 3. Onet
        $onetAccount = $accountService->create([
            'email' => 'rekruter@onet.pl',
            'password' => 'OnetSecretPass789',
        ]);
        $connService->testAccount($onetAccount);

        $this->assertCount(3, $this->fakeDriver->testedConnections);
        $onetCall = $this->fakeDriver->testedConnections[2];
        $this->assertSame('imap.poczta.onet.pl', $onetCall['host']);
        $this->assertSame(993, $onetCall['port']);
        $this->assertSame('rekruter@onet.pl', $onetCall['username']);
        $this->assertTrue($onetCall['has_password']);
        $this->assertSame('imap.poczta.onet.pl', $onetAccount->fresh()->imap_host);
        $this->assertSame(993, $onetAccount->fresh()->imap_port);

        // 4. Interia
        $interiaAccount = $accountService->create([
            'email' => 'hr@poczta.fm',
            'password' => 'InteriaSecretPass012',
        ]);
        $connService->testAccount($interiaAccount);

        $this->assertCount(4, $this->fakeDriver->testedConnections);
        $interiaCall = $this->fakeDriver->testedConnections[3];
        $this->assertSame('poczta.interia.pl', $interiaCall['host']);
        $this->assertSame(993, $interiaCall['port']);
        $this->assertSame('hr@poczta.fm', $interiaCall['username']);
        $this->assertTrue($interiaCall['has_password']);
        $this->assertSame('poczta.interia.pl', $interiaAccount->fresh()->imap_host);
        $this->assertSame(993, $interiaAccount->fresh()->imap_port);

        // 5. Gazeta.pl
        $gazetaAccount = $accountService->create([
            'email' => 'rekruter@gazeta.pl',
            'password' => 'GazetaSecretPass345',
        ]);
        $connService->testAccount($gazetaAccount);

        $this->assertCount(5, $this->fakeDriver->testedConnections);
        $gazetaCall = $this->fakeDriver->testedConnections[4];
        $this->assertSame('imap.gazeta.pl', $gazetaCall['host']);
        $this->assertSame(993, $gazetaCall['port']);
        $this->assertSame('rekruter@gazeta.pl', $gazetaCall['username']);
        $this->assertTrue($gazetaCall['has_password']);
        $this->assertSame('imap.gazeta.pl', $gazetaAccount->fresh()->imap_host);
        $this->assertSame(993, $gazetaAccount->fresh()->imap_port);
        $this->assertSame('ssl', $gazetaAccount->fresh()->imap_encryption);
    }

    public function test_successful_authentication_via_api(): void
    {
        $accountService = app(MailAccountService::class);

        foreach (['test@onet.pl' => 'onet', 'test@interia.pl' => 'interia', 'test@wp.pl' => 'wp', 'test@gazeta.pl' => 'gazeta'] as $email => $expectedProvider) {
            $account = $accountService->create([
                'email' => $email,
                'password' => 'ValidPassword',
            ]);

            $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('provider', $expectedProvider)
                ->assertJsonPath('status', 'connected')
                ->assertJsonPath('last_test_result', 'success');

            $fresh = $account->fresh();
            $this->assertSame('connected', $fresh->status);
            $this->assertSame('success', $fresh->last_test_result);
            $this->assertNotNull($fresh->last_tested_at);
            $this->assertNull($fresh->last_error);
        }
    }

    public function test_authentication_failure_with_wrong_password(): void
    {
        $accountService = app(MailAccountService::class);
        $account = $accountService->create([
            'email' => 'test@onet.pl',
            'password' => 'wrong_password',
        ]);

        $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'authentication_failed')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('last_test_result', 'failed');

        $fresh = $account->fresh();
        $this->assertSame('error', $fresh->status);
        $this->assertSame('failed', $fresh->last_test_result);
        $this->assertStringContainsString('Błędny adres e-mail lub hasło', (string) $fresh->last_error);
    }

    public function test_handles_timeout_error(): void
    {
        $accountService = app(MailAccountService::class);
        $account = $accountService->create([
            'email' => 'timeout@interia.pl',
            'password' => 'timeout',
        ]);

        $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'connection_timeout')
            ->assertJsonPath('status', 'error');

        $this->assertSame('failed', $account->fresh()->last_test_result);
        $this->assertStringContainsString('timeout', strtolower((string) $account->fresh()->last_error));
    }

    public function test_handles_tls_verification_error(): void
    {
        $accountService = app(MailAccountService::class);
        $account = $accountService->create([
            'email' => 'tls@onet.pl',
            'password' => 'tls_error',
        ]);

        $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'tls_error')
            ->assertJsonPath('status', 'error');

        $this->assertSame('failed', $account->fresh()->last_test_result);
        $this->assertStringContainsString('certyfikatu', strtolower((string) $account->fresh()->last_error));
    }

    public function test_unsupported_provider_rejected_for_automatic_imap(): void
    {
        $accountService = app(MailAccountService::class);
        $account = $accountService->create([
            'email' => 'user@outlook.com',
            'password' => 'OutlookPass123',
        ]);

        $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'unsupported_provider');

        $this->assertStringContainsString('WP, o2, Onet, Interia, Gazeta.pl oraz Gmail', (string) $response->json('error'));
    }

    public function test_gmail_account_without_oauth_credentials_rejects_test(): void
    {
        $account = MailAccount::create([
            'email' => 'user@gmail.com',
            'auth_type' => 'password',
            'provider' => 'gmail',
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'missing_credentials');

        $this->assertStringContainsString('Google OAuth2', (string) $response->json('error'));
    }

    public function test_no_password_leaks_in_test_api_responses_or_logs(): void
    {
        $rawPassword = 'SuperSecretRawPassword!#999';
        $accountService = app(MailAccountService::class);
        $account = $accountService->create([
            'email' => 'leak@gazeta.pl',
            'password' => $rawPassword,
        ]);

        $response = $this->postJson("/api/mail-accounts/{$account->id}/test");

        $content = $response->getContent();
        $this->assertStringNotContainsString($rawPassword, $content);
        $this->assertStringNotContainsString('credential_ref', $content);
    }

    public function test_instructions_provided_for_all_supported_providers(): void
    {
        $resolver = app(MailProviderResolver::class);

        // 1. WP instructions
        $wpInfo = $resolver->resolveFromEmail('user@wp.pl');
        $this->assertNotNull($wpInfo['instructions']);
        $this->assertStringContainsString('WP', $wpInfo['instructions']['title']);
        $this->assertNotEmpty($wpInfo['doc_url']);
        $this->assertStringContainsString('pomoc.wp.pl', $wpInfo['doc_url']);

        $wpSteps = implode(' ', $wpInfo['instructions']['steps']);
        $this->assertStringContainsString('IMAP', $wpSteps);
        $this->assertStringContainsString('2FA', $wpSteps);

        // 2. o2 instructions
        $o2Info = $resolver->resolveFromEmail('user@o2.pl');
        $this->assertNotNull($o2Info['instructions']);
        $this->assertStringContainsString('o2', $o2Info['instructions']['title']);
        $this->assertNotEmpty($o2Info['doc_url']);
        $this->assertStringContainsString('pomoc.o2.pl', $o2Info['doc_url']);

        $o2Steps = implode(' ', $o2Info['instructions']['steps']);
        $this->assertStringContainsString('IMAP', $o2Steps);
        $this->assertStringContainsString('2FA', $o2Steps);

        // 3. Onet instructions
        $onetInfo = $resolver->resolveFromEmail('user@onet.pl');
        $this->assertNotNull($onetInfo['instructions']);
        $this->assertStringContainsString('Onet', $onetInfo['instructions']['title']);
        $this->assertNotEmpty($onetInfo['doc_url']);
        $this->assertStringContainsString('pomoc.poczta.onet.pl', $onetInfo['doc_url']);

        $onetSteps = implode(' ', $onetInfo['instructions']['steps']);
        $this->assertStringContainsString('IMAP', $onetSteps);
        $this->assertStringContainsString('2FA', $onetSteps);
        $this->assertStringContainsString('Hasła do aplikacji', $onetSteps);

        // 4. Interia instructions
        $interiaInfo = $resolver->resolveFromEmail('user@interia.pl');
        $this->assertNotNull($interiaInfo['instructions']);
        $this->assertStringContainsString('Interia', $interiaInfo['instructions']['title']);
        $this->assertNotEmpty($interiaInfo['doc_url']);
        $this->assertStringContainsString('pomoc.poczta.interia.pl', $interiaInfo['doc_url']);

        $interiaSteps = implode(' ', $interiaInfo['instructions']['steps']);
        $this->assertStringContainsString('Korzystam z programu pocztowego', $interiaSteps);
        $this->assertStringContainsString('2FA', $interiaSteps);

        // 5. Gazeta.pl instructions
        $gazetaInfo = $resolver->resolveFromEmail('user@gazeta.pl');
        $this->assertNotNull($gazetaInfo['instructions']);
        $this->assertStringContainsString('Gazeta.pl', $gazetaInfo['instructions']['title']);
        $this->assertNotEmpty($gazetaInfo['doc_url']);
        $this->assertStringContainsString('pomoc.gazeta.pl', $gazetaInfo['doc_url']);

        $gazetaSteps = implode(' ', $gazetaInfo['instructions']['steps']);
        $this->assertStringContainsString('IMAP', $gazetaSteps);
        $this->assertStringContainsString('2FA', $gazetaSteps);
    }

    public function test_no_messages_downloaded_during_imap_test(): void
    {
        // ImapMailDriver code inspection: strictly verifies only LOGIN and LOGOUT are used
        $driverSource = file_get_contents(app_path('Services/Mail/ImapMailDriver.php'));

        $this->assertStringContainsString('LOGIN', $driverSource);
        $this->assertStringContainsString('LOGOUT', $driverSource);

        // Ensures NO fetch, select, examine, search or body parsing exists in driver
        $this->assertStringNotContainsString('SELECT', $driverSource);
        $this->assertStringNotContainsString('EXAMINE', $driverSource);
        $this->assertStringNotContainsString('FETCH', $driverSource);
        $this->assertStringNotContainsString('SEARCH', $driverSource);
        $this->assertStringNotContainsString('BODY', $driverSource);
    }

    public function test_gmail_instructions_provided_with_official_documentation(): void
    {
        $resolver = app(MailProviderResolver::class);
        $gmailInfo = $resolver->resolveFromEmail('rekrutacja@gmail.com');

        $this->assertSame('gmail', $gmailInfo['provider']);
        $this->assertSame('Gmail', $gmailInfo['name']);
        $this->assertSame('oauth', $gmailInfo['auth_type']);
        $this->assertSame('google', $gmailInfo['oauth_provider']);
        $this->assertNotNull($gmailInfo['instructions']);
        $this->assertStringContainsString('Gmail', $gmailInfo['instructions']['title']);
        $this->assertNotEmpty($gmailInfo['doc_url']);
        $this->assertStringContainsString('developers.google.com', $gmailInfo['doc_url']);

        $gmailSteps = implode(' ', $gmailInfo['instructions']['steps']);
        $this->assertStringContainsString('OAuth2', $gmailSteps);
        $this->assertStringContainsString('Połącz z Google', $gmailSteps);
    }

    public function test_gmail_connection_test_successful_with_valid_token_and_metadata_scope(): void
    {
        $credRef = (string) Str::uuid();
        $this->vault->write($credRef, [
            'access_token' => 'valid-mock-gmail-token',
            'refresh_token' => 'mock-refresh-token',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock-client-id.apps.googleusercontent.com',
            'client_secret' => 'mock-secret',
        ]);

        $integrationAccount = IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Gmail (test@gmail.com)',
            'status' => 'connected',
            'credential_ref' => $credRef,
            'scopes' => [
                GoogleOAuthService::OPENID_SCOPE,
                GoogleOAuthService::USERINFO_EMAIL_SCOPE,
                GoogleOAuthService::GMAIL_METADATA_SCOPE,
            ],
            'token_expires_at' => now()->addHour(),
        ]);

        $mailAccount = MailAccount::create([
            'email' => 'test@gmail.com',
            'name' => 'Gmail (test@gmail.com)',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
            'credential_ref' => $credRef,
            'status' => 'pending',
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'test@gmail.com',
                'messagesTotal' => 42,
                'threadsTotal' => 20,
                'historyId' => '12345',
            ], 200),
        ]);

        $connService = app(MailConnectionService::class);
        $result = $connService->testAccount($mailAccount);

        $this->assertTrue($result['success']);
        $this->assertSame('connected', $result['status']);
        $this->assertSame('gmail', $result['provider']);
        $this->assertSame('connected', $mailAccount->fresh()->status);
        $this->assertSame('success', $mailAccount->fresh()->last_test_result);
        $this->assertNull($mailAccount->fresh()->last_error);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/profile'
                && $request->hasHeader('Authorization', 'Bearer valid-mock-gmail-token');
        });
    }

    public function test_gmail_connection_test_fails_if_gmail_scope_is_missing(): void
    {
        $credRef = (string) Str::uuid();
        $this->vault->write($credRef, [
            'access_token' => 'valid-sheets-only-token',
            'refresh_token' => 'mock-refresh-token',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock-client-id.apps.googleusercontent.com',
            'client_secret' => 'mock-secret',
        ]);

        // Account has only spreadsheets scope, NOT gmail.metadata
        IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Google Sheets Account',
            'status' => 'connected',
            'credential_ref' => $credRef,
            'scopes' => [
                GoogleOAuthService::SCOPE, // spreadsheets.readonly
            ],
            'token_expires_at' => now()->addHour(),
        ]);

        $mailAccount = MailAccount::create([
            'email' => 'test@gmail.com',
            'name' => 'Gmail (test@gmail.com)',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
            'credential_ref' => $credRef,
            'status' => 'pending',
        ]);

        $connService = app(MailConnectionService::class);
        $result = $connService->testAccount($mailAccount);

        // Crucial requirement: Do NOT show false "connected" status if Gmail scopes are not granted
        $this->assertFalse($result['success']);
        $this->assertSame('insufficient_scopes', $result['error_code']);
        $this->assertSame('error', $mailAccount->fresh()->status);
        $this->assertSame('failed', $mailAccount->fresh()->last_test_result);
        $this->assertStringContainsString('gmail.metadata', $mailAccount->fresh()->last_error);
    }

    public function test_gmail_oauth_disconnect_does_not_affect_google_sheets_account(): void
    {
        $sharedCredRef = (string) Str::uuid();
        $this->vault->write($sharedCredRef, [
            'access_token' => 'mock-shared-token',
            'refresh_token' => 'mock-refresh-token',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock-client-id.apps.googleusercontent.com',
            'client_secret' => 'mock-secret',
        ]);

        // Integration account linked to Google Sheets DataSource
        $integrationAccount = IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'HR Google Sheets Account',
            'status' => 'connected',
            'credential_ref' => $sharedCredRef,
            'scopes' => [GoogleOAuthService::SCOPE],
        ]);

        $source = DataSource::create([
            'integration_account_id' => $integrationAccount->id,
            'name' => 'Kandydaci Arkusz',
            'type' => 'spreadsheet',
            'external_id' => 'sheet-xyz',
            'status' => 'active',
        ]);

        // MailAccount also linked
        $mailAccount = MailAccount::create([
            'email' => 'hr@gmail.com',
            'name' => 'Gmail HR',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
            'credential_ref' => $sharedCredRef,
            'status' => 'connected',
        ]);

        $response = $this->postJson("/api/mail-accounts/{$mailAccount->id}/disconnect-oauth");
        $response->assertOk();

        // Mail account was removed
        $this->assertNull(MailAccount::find($mailAccount->id));

        // Sheets integration account and data source remain 100% intact!
        $this->assertNotNull(IntegrationAccount::find($integrationAccount->id));
        $this->assertNotNull(DataSource::find($source->id));
        $this->assertNotNull($this->vault->read($sharedCredRef));
    }

    public function test_no_messages_or_headers_downloaded_during_gmail_test(): void
    {
        $serviceSource = file_get_contents(app_path('Services/MailConnectionService.php'));

        // Profile endpoint is permitted
        $this->assertStringContainsString('users/me/profile', $serviceSource);

        // Forbidden endpoints
        $this->assertStringNotContainsString('users/me/messages', $serviceSource);
        $this->assertStringNotContainsString('users/me/threads', $serviceSource);
        $this->assertStringNotContainsString('users/me/drafts', $serviceSource);
        $this->assertStringNotContainsString('users/me/labels', $serviceSource);
    }
}

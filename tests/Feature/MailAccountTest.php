<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\Google\GoogleOAuthService;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Integrations\OAuthFlow;
use App\Models\MailAccount;
use App\Services\MailAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailAccountMemoryStore implements CredentialStore
{
    /** @var array<string, array<string, mixed>> */
    public array $values = [];

    public function read(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function write(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

class MailAccountTest extends TestCase
{
    use RefreshDatabase;

    private MailAccountMemoryStore $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = new MailAccountMemoryStore;
        $this->app->instance(CredentialStore::class, $this->vault);
    }

    public function test_can_list_empty_mail_accounts(): void
    {
        $response = $this->getJson('/api/mail-accounts');

        $response->assertOk()
            ->assertExactJson([]);
    }

    public function test_can_create_mail_account_with_encrypted_password(): void
    {
        $rawPassword = 'SuperSecretP@ssw0rd!123';

        $response = $this->postJson('/api/mail-accounts', [
            'email' => 'recruiter@example.com',
            'password' => $rawPassword,
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'recruiter@example.com')
            ->assertJsonPath('auth_type', 'password')
            ->assertJsonPath('status', 'active');

        // Verify API response never leaks password, ciphertext, or credential_ref
        $response->assertDontSee($rawPassword);
        $response->assertDontSee('credential_ref');
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('credential_ref', $response->json());

        // Verify record in SQLite database
        $this->assertDatabaseHas('mail_accounts', [
            'email' => 'recruiter@example.com',
            'auth_type' => 'password',
            'status' => 'active',
        ]);

        $account = MailAccount::where('email', 'recruiter@example.com')->firstOrFail();
        $this->assertNotNull($account->credential_ref);

        // Verify SQLite table does not contain plaintext password in any column
        $dbRow = DB::table('mail_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString($rawPassword, json_encode($dbRow));

        // Verify CredentialStore contains the encrypted credential and can be retrieved
        $this->assertSame($rawPassword, $this->vault->read($account->credential_ref)['password'] ?? null);

        // Verify service getPassword decrypts correctly
        $service = app(MailAccountService::class);
        $this->assertSame($rawPassword, $service->getPassword($account));
    }

    public function test_can_update_mail_account_email_without_changing_password(): void
    {
        $service = app(MailAccountService::class);
        $account = $service->create([
            'email' => 'old@example.com',
            'password' => 'ExistingPassword456',
        ]);

        $response = $this->putJson("/api/mail-accounts/{$account->id}", [
            'email' => 'new@example.com',
            'password' => '', // empty password means no change
        ]);

        $response->assertOk()
            ->assertJsonPath('email', 'new@example.com')
            ->assertDontSee('ExistingPassword456')
            ->assertDontSee('credential_ref');

        $this->assertDatabaseHas('mail_accounts', [
            'id' => $account->id,
            'email' => 'new@example.com',
        ]);

        // Password in vault remains untouched
        $this->assertSame('ExistingPassword456', $service->getPassword($account->fresh()));
    }

    public function test_can_update_mail_account_password(): void
    {
        $service = app(MailAccountService::class);
        $account = $service->create([
            'email' => 'user@example.com',
            'password' => 'InitialPassword',
        ]);

        $response = $this->putJson("/api/mail-accounts/{$account->id}", [
            'email' => 'user@example.com',
            'password' => 'NewRotatedPassword789',
        ]);

        $response->assertOk()
            ->assertDontSee('NewRotatedPassword789')
            ->assertDontSee('credential_ref');

        $this->assertSame('NewRotatedPassword789', $service->getPassword($account->fresh()));
    }

    public function test_can_delete_mail_account_and_its_credentials(): void
    {
        $service = app(MailAccountService::class);
        $account = $service->create([
            'email' => 'delete-me@example.com',
            'password' => 'ToDiscard',
        ]);

        $ref = $account->credential_ref;
        $this->assertNotNull($this->vault->read($ref));

        $response = $this->deleteJson("/api/mail-accounts/{$account->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Konto pocztowe zostało usunięte.');

        $this->assertDatabaseMissing('mail_accounts', [
            'id' => $account->id,
        ]);

        // Credential store secret is cleaned up
        $this->assertNull($this->vault->read($ref));
    }

    public function test_validation_rules_and_messages_in_polish(): void
    {
        // Required email and password
        $response = $this->postJson('/api/mail-accounts', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);

        // Invalid email format
        $response = $this->postJson('/api/mail-accounts', [
            'email' => 'invalid-email-address',
            'password' => 'ValidPass123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Duplicate email
        app(MailAccountService::class)->create([
            'email' => 'unique@example.com',
            'password' => 'Pass123',
        ]);

        $response = $this->postJson('/api/mail-accounts', [
            'email' => 'unique@example.com',
            'password' => 'Pass456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonFragment(['email' => ['Konto pocztowe z tym adresem e-mail już istnieje.']]);

        // Duplicate email on update for another account
        $second = app(MailAccountService::class)->create([
            'email' => 'second@example.com',
            'password' => 'Pass456',
        ]);

        $response = $this->putJson("/api/mail-accounts/{$second->id}", [
            'email' => 'unique@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Same email on update for current account is accepted
        $response = $this->putJson("/api/mail-accounts/{$second->id}", [
            'email' => 'second@example.com',
        ]);

        $response->assertOk();
    }

    public function test_api_responses_never_leak_secrets_or_ciphertexts(): void
    {
        $rawPassword = 'TopSecretPlainTextP@ssw0rd!#';

        $createResponse = $this->postJson('/api/mail-accounts', [
            'email' => 'leak-test@example.com',
            'password' => $rawPassword,
        ]);

        $createResponse->assertCreated();
        $this->assertStringNotContainsString($rawPassword, $createResponse->getContent());
        $this->assertStringNotContainsString('credential_ref', $createResponse->getContent());

        $listResponse = $this->getJson('/api/mail-accounts');
        $listResponse->assertOk();
        $this->assertStringNotContainsString($rawPassword, $listResponse->getContent());
        $this->assertStringNotContainsString('credential_ref', $listResponse->getContent());

        $account = MailAccount::where('email', 'leak-test@example.com')->firstOrFail();
        $updateResponse = $this->putJson("/api/mail-accounts/{$account->id}", [
            'email' => 'leak-test-updated@example.com',
        ]);

        $updateResponse->assertOk();
        $this->assertStringNotContainsString($rawPassword, $updateResponse->getContent());
        $this->assertStringNotContainsString('credential_ref', $updateResponse->getContent());
    }

    public function test_full_flow_form_api_sqlite_and_re_reading_account(): void
    {
        $email = 'flow-test@company.pl';
        $password = 'SecretImapPassword2026';

        // 1. Submit form via API POST
        $createRes = $this->postJson('/api/mail-accounts', [
            'email' => $email,
            'password' => $password,
        ])->assertCreated();

        $accountId = $createRes->json('id');
        $this->assertNotEmpty($accountId);

        // 2. Direct SQLite check: data persisted accurately in SQLite
        $this->assertDatabaseHas('mail_accounts', [
            'id' => $accountId,
            'email' => $email,
            'status' => 'active',
        ]);

        // 3. Re-read accounts via API GET
        $listRes = $this->getJson('/api/mail-accounts')->assertOk();
        $accounts = $listRes->json();
        $this->assertCount(1, $accounts);
        $this->assertSame($accountId, $accounts[0]['id']);
        $this->assertSame($email, $accounts[0]['email']);

        // 4. Verify password retrieval for subsequent IMAP stage
        $account = MailAccount::findOrFail($accountId);
        $service = app(MailAccountService::class);
        $this->assertSame($password, $service->getPassword($account));

        // 5. Update email and verify SQLite persistence
        $newEmail = 'updated-flow@company.pl';
        $this->putJson("/api/mail-accounts/{$accountId}", [
            'email' => $newEmail,
        ])->assertOk()->assertJsonPath('email', $newEmail);

        $this->assertDatabaseHas('mail_accounts', [
            'id' => $accountId,
            'email' => $newEmail,
        ]);
        $this->assertSame($password, $service->getPassword($account->fresh()));

        // 6. Delete account and verify SQLite removal
        $this->deleteJson("/api/mail-accounts/{$accountId}")->assertOk();
        $this->assertDatabaseMissing('mail_accounts', ['id' => $accountId]);
    }

    public function test_real_encrypted_file_credential_store_stores_ciphertext_not_plaintext(): void
    {
        $tempPath = storage_path('framework/testing/vault-'.\Illuminate\Support\Str::uuid());
        $original = $this->app->storagePath();
        $this->app->useStoragePath($tempPath);

        $realStore = new \App\Integrations\Security\EncryptedFileCredentialStore;
        $this->app->instance(CredentialStore::class, $realStore);

        $rawPassword = 'RealEncryptedPassword#2026';

        try {
            $createRes = $this->postJson('/api/mail-accounts', [
                'email' => 'encrypted-storage@example.com',
                'password' => $rawPassword,
            ])->assertCreated();

            $account = MailAccount::where('email', 'encrypted-storage@example.com')->firstOrFail();
            $encFile = $tempPath.'/app/private/integration-credentials/'.$account->credential_ref.'.enc';

            $this->assertFileExists($encFile);
            $fileContents = file_get_contents($encFile);

            // Ciphertext must never contain the raw password
            $this->assertStringNotContainsString($rawPassword, $fileContents);

            // Must decrypt back accurately
            $service = app(MailAccountService::class);
            $this->assertSame($rawPassword, $service->getPassword($account));

            // Clean up via service/delete
            $service->delete($account);
            $this->assertFileDoesNotExist($encFile);
        } finally {
            $this->app->useStoragePath($original);
            foreach (['app/private/integration-credentials', 'app/private', 'app', ''] as $dir) {
                if (is_dir($tempPath.'/'.$dir)) {
                    @rmdir($tempPath.'/'.$dir);
                }
            }
        }
    }

    public function test_google_flow_status_links_mail_account_when_connected(): void
    {
        $credRef = (string) Str::uuid();
        $this->vault->write($credRef, [
            'access_token' => 'mock-access-token',
            'refresh_token' => 'mock-refresh-token',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock-client.apps.googleusercontent.com',
            'client_secret' => 'mock-secret',
        ]);

        $integrationAccount = IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Gmail (recruiter@gmail.com)',
            'status' => 'connected',
            'credential_ref' => $credRef,
            'scopes' => [
                GoogleOAuthService::OPENID_SCOPE,
                GoogleOAuthService::USERINFO_EMAIL_SCOPE,
                GoogleOAuthService::GMAIL_METADATA_SCOPE,
            ],
            'token_expires_at' => now()->addHour(),
        ]);

        $flow = OAuthFlow::create([
            'integration_account_id' => $integrationAccount->id,
            'secret_ref' => 'ref-123',
            'status' => 'connected',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'recruiter@gmail.com',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'recruiter@gmail.com',
                'messagesTotal' => 15,
                'threadsTotal' => 8,
            ], 200),
        ]);

        $response = $this->getJson("/api/mail-accounts/google/flow/{$flow->id}");

        $response->assertOk()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('mail_account.email', 'recruiter@gmail.com')
            ->assertJsonPath('mail_account.provider', 'gmail')
            ->assertJsonPath('mail_account.auth_type', 'oauth');

        $this->assertDatabaseHas('mail_accounts', [
            'email' => 'recruiter@gmail.com',
            'provider' => 'gmail',
            'auth_type' => 'oauth',
            'status' => 'connected',
            'last_test_result' => 'success',
        ]);
    }

    public function test_google_flow_status_flags_missing_gmail_scope(): void
    {
        $credRef = (string) Str::uuid();
        $this->vault->write($credRef, [
            'access_token' => 'mock-access-token',
            'refresh_token' => 'mock-refresh-token',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock-client.apps.googleusercontent.com',
            'client_secret' => 'mock-secret',
        ]);

        // User completed login, but DID NOT grant gmail.metadata (only openid + email)
        $integrationAccount = IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Limited User',
            'status' => 'connected',
            'credential_ref' => $credRef,
            'scopes' => [
                GoogleOAuthService::OPENID_SCOPE,
                GoogleOAuthService::USERINFO_EMAIL_SCOPE,
            ],
            'token_expires_at' => now()->addHour(),
        ]);

        $flow = OAuthFlow::create([
            'integration_account_id' => $integrationAccount->id,
            'secret_ref' => 'ref-456',
            'status' => 'connected',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'limited@gmail.com',
            ], 200),
        ]);

        $response = $this->getJson("/api/mail-accounts/google/flow/{$flow->id}");

        // Crucial requirement: Must report scope_missing, never falsely "connected"
        $response->assertOk()
            ->assertJsonPath('status', 'scope_missing');

        $this->assertDatabaseHas('mail_accounts', [
            'email' => 'limited@gmail.com',
            'status' => 'error',
            'last_test_result' => 'failed',
        ]);

        $created = MailAccount::where('email', 'limited@gmail.com')->firstOrFail();
        $this->assertStringContainsString('gmail.metadata', $created->last_error);
    }

    public function test_destroy_mail_account_does_not_delete_integration_account_if_used_by_sheets(): void
    {
        $sharedCredRef = (string) Str::uuid();
        $this->vault->write($sharedCredRef, [
            'access_token' => 'mock-token',
            'refresh_token' => 'mock-refresh',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock-client.apps.googleusercontent.com',
            'client_secret' => 'mock-secret',
        ]);

        $integrationAccount = IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Active Sheets Account',
            'status' => 'connected',
            'credential_ref' => $sharedCredRef,
            'scopes' => [GoogleOAuthService::SCOPE],
        ]);

        DataSource::create([
            'integration_account_id' => $integrationAccount->id,
            'name' => 'Arkusz rekrutacji',
            'type' => 'spreadsheet',
            'external_id' => 'sheet-id-abc',
            'status' => 'active',
        ]);

        $mailAccount = MailAccount::create([
            'email' => 'sheets-user@gmail.com',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
            'credential_ref' => $sharedCredRef,
            'status' => 'connected',
        ]);

        // Delete the mail account
        $this->deleteJson("/api/mail-accounts/{$mailAccount->id}")->assertOk();

        // MailAccount is deleted
        $this->assertDatabaseMissing('mail_accounts', ['id' => $mailAccount->id]);

        // IntegrationAccount and credentials in store are NOT deleted because Sheets still uses them
        $this->assertDatabaseHas('integration_accounts', ['id' => $integrationAccount->id]);
        $this->assertNotNull($this->vault->read($sharedCredRef));
    }
}

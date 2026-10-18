<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SyncMailAccountCommand;
use App\Models\MailAccount;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncState;
use App\Services\MailAccountService;
use App\Services\Mail\Contracts\ImapClientInterface;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\DTO\MailSyncBatchResult;
use App\Services\Mail\MailSyncService;
use App\Services\Mail\Providers\GmailSyncProvider;
use App\Services\Mail\Providers\ImapSyncProvider;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use App\Services\Mail\Testing\FakeImapClient;
use Database\Seeders\MailSyncRuleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MailAutoSyncScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function sampleHeaders(
        string $from = 'Pracuj.pl <powiadomienia@pracuj.pl>',
        string $subject = 'Nowe oferty pracy dopasowane do profilu',
        string $messageId = '<msg1@pracuj.pl>',
        string $date = 'Thu, 15 Oct 2026 10:00:00 +0200'
    ): string {
        return implode("\r\n", [
            "From: {$from}",
            "Subject: {$subject}",
            "Date: {$date}",
            "Message-ID: {$messageId}",
            "Reply-To: pomoc@pracuj.pl",
            "In-Reply-To: <init@pracuj.pl>",
            "References: <ref1@pracuj.pl>",
            "",
            "",
        ]);
    }

    private function createImapAccount(string $email = 'user@wp.pl', bool $autoSync = false): MailAccount
    {
        $accountService = app(MailAccountService::class);

        return $accountService->create([
            'email' => $email,
            'name' => 'Konto IMAP ' . $email,
            'provider' => 'wp',
            'auth_type' => 'password',
            'password' => 'TajneHaslo123!',
            'status' => 'active',
            'auto_sync' => $autoSync,
        ]);
    }

    private function createGmailOAuthAccount(string $email = 'user@gmail.com', bool $autoSync = false): MailAccount
    {
        $credRef = (string) \Illuminate\Support\Str::uuid();

        \App\Models\Integrations\IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Konto Google ' . $email,
            'credential_ref' => $credRef,
            'status' => 'connected',
            'scopes' => [
                \App\Integrations\Google\GoogleOAuthService::OPENID_SCOPE,
                \App\Integrations\Google\GoogleOAuthService::USERINFO_EMAIL_SCOPE,
                \App\Integrations\Google\GoogleOAuthService::GMAIL_METADATA_SCOPE,
            ],
            'token_expires_at' => now()->addHour(),
        ]);

        $vault = app(\App\Integrations\Contracts\CredentialStore::class);
        $vault->write($credRef, [
            'access_token' => 'mock_google_access_token_123',
            'refresh_token' => 'mock_google_refresh_token_abc',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock_client.apps.googleusercontent.com',
            'client_secret' => 'mock_secret',
            'scope' => 'openid email ' . \App\Integrations\Google\GoogleOAuthService::GMAIL_METADATA_SCOPE,
        ]);

        return MailAccount::create([
            'email' => $email,
            'name' => 'Konto Gmail ' . $email,
            'provider' => 'gmail',
            'auth_type' => 'oauth',
            'oauth_provider' => 'google',
            'credential_ref' => $credRef,
            'status' => 'active',
            'auto_sync' => $autoSync,
        ]);
    }

    /**
     * Requirement 1 & 2: Schedule contains mail:sync --auto every 5 minutes without overlapping.
     */
    public function test_schedule_registers_mail_auto_sync_every_five_minutes_without_overlapping(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $autoSyncEvent = $events->first(function ($event) {
            return str_contains((string) $event->command, 'mail:sync')
                && str_contains((string) $event->command, '--auto');
        });

        $this->assertNotNull($autoSyncEvent, 'Komenda mail:sync --auto nie została zarejestrowana w harmonogramie!');
        $this->assertSame('*/5 * * * *', $autoSyncEvent->expression, 'Harmonogram powinien być uruchamiany co 5 minut (*/5 * * * *).');
        $this->assertTrue($autoSyncEvent->withoutOverlapping, 'Harmonogram powinien chronić przed nakładaniem za pomocą withoutOverlapping.');
        $this->assertSame('mail-auto-sync', $autoSyncEvent->description);
    }

    /**
     * Requirement 4: Auto-sync is disabled by default for conscious user activation, and toggleable via API.
     */
    public function test_auto_sync_disabled_by_default_and_can_be_toggled_via_api(): void
    {
        $account = $this->createImapAccount('candidate@o2.pl');

        // 1. By default auto_sync is false
        $this->assertFalse($account->auto_sync);

        // 2. Toggle via API PATCH /api/mail-accounts/{id}/toggle-auto-sync
        $response = $this->patchJson("/api/mail-accounts/{$account->id}/toggle-auto-sync");
        $response->assertOk();
        $response->assertJson(['auto_sync' => true]);
        $this->assertTrue($account->fresh()->auto_sync);

        // 3. Toggle off
        $response = $this->patchJson("/api/mail-accounts/{$account->id}/toggle-auto-sync");
        $response->assertOk();
        $response->assertJson(['auto_sync' => false]);
        $this->assertFalse($account->fresh()->auto_sync);

        // 4. Explicit boolean set
        $response = $this->patchJson("/api/mail-accounts/{$account->id}/toggle-auto-sync", ['auto_sync' => true]);
        $response->assertOk();
        $response->assertJson(['auto_sync' => true]);
        $this->assertTrue($account->fresh()->auto_sync);

        // 5. Verify /api/mail-sync-states presents auto_sync state
        $statesResponse = $this->getJson('/api/mail-sync-states');
        $statesResponse->assertOk();
        $statesResponse->assertJsonFragment([
            'email' => 'candidate@o2.pl',
            'auto_sync' => true,
        ]);
    }

    /**
     * Requirement 4 & Test: Disabled accounts are completely skipped during auto-sync.
     */
    public function test_disabled_accounts_are_skipped_by_auto_sync(): void
    {
        $this->seed(MailSyncRuleSeeder::class);

        $disabledAccount = $this->createImapAccount('disabled@wp.pl', autoSync: false);
        $enabledAccount = $this->createImapAccount('enabled@wp.pl', autoSync: true);

        $fakeClient = new FakeImapClient();
        $fakeClient->examineResult = ['exists' => 1, 'uidvalidity' => 100, 'uidnext' => 10];
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 1,
                'raw_headers' => $this->sampleHeaders(messageId: '<msg-enabled@pracuj.pl>'),
            ],
        ];

        app()->instance(ImapClientInterface::class, $fakeClient);

        // Run artisan mail:sync --auto
        $exitCode = Artisan::call('mail:sync', ['--auto' => true]);
        $this->assertSame(0, $exitCode);

        // Enabled account must be synced
        $enabledFresh = $enabledAccount->fresh();
        $this->assertNotNull($enabledFresh->last_synced_at);
        $this->assertNotNull($enabledFresh->syncState);
        $this->assertSame(1, MailMessage::where('mail_account_id', $enabledAccount->id)->count());

        // Disabled account must NOT be touched
        $disabledFresh = $disabledAccount->fresh();
        $this->assertNull($disabledFresh->last_synced_at);
        $this->assertNull($disabledFresh->syncState);
        $this->assertSame(0, MailMessage::where('mail_account_id', $disabledAccount->id)->count());
    }

    /**
     * Requirement 3 & Test: Multiple connected accounts (Gmail API + IMAP) synced in single automatic run.
     */
    public function test_multiple_connected_accounts_sync_gmail_and_imap_in_single_run(): void
    {
        $this->seed(MailSyncRuleSeeder::class);

        // Create 1 Gmail OAuth account and 1 IMAP account, both auto_sync = true
        $gmailAccount = $this->createGmailOAuthAccount('recruitment@gmail.com', autoSync: true);
        $imapAccount = $this->createImapAccount('recruitment@wp.pl', autoSync: true);

        // Configure Fake IMAP client
        $fakeImap = new FakeImapClient();
        $fakeImap->examineResult = ['exists' => 1, 'uidvalidity' => 555, 'uidnext' => 50];
        $fakeImap->sequenceHeadersResult = [
            [
                'uid' => 42,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <powiadomienia@pracuj.pl>',
                    subject: 'Nowa oferta IMAP',
                    messageId: '<imap-offer-42@pracuj.pl>'
                ),
            ],
        ];
        app()->instance(ImapClientInterface::class, $fakeImap);

        // Configure Gmail HTTP fake
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'recruitment@gmail.com',
                'historyId' => '99000',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [
                    ['id' => 'gmail_msg_101', 'threadId' => 'thread_101'],
                ],
                'resultSizeEstimate' => 1,
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/gmail_msg_101*' => Http::response([
                'id' => 'gmail_msg_101',
                'threadId' => 'thread_101',
                'labelIds' => ['INBOX'],
                'historyId' => '99000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'No Fluff Jobs <offers@nofluffjobs.com>'],
                        ['name' => 'Subject', 'value' => 'Nowa oferta Gmail'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                        ['name' => 'Message-ID', 'value' => '<gmail-offer-101@nofluffjobs.com>'],
                    ],
                ],
            ], 200),
        ]);

        // Run auto-sync
        $exitCode = Artisan::call('mail:sync', ['--auto' => true]);
        $this->assertSame(0, $exitCode);

        // Verify IMAP sync succeeded and committed IMAP cursors
        $imapState = $imapAccount->syncState->fresh();
        $this->assertSame(42, $imapState->imap_last_uid);
        $this->assertSame(555, $imapState->imap_uidvalidity);
        $this->assertSame('idle', $imapState->sync_status);
        $this->assertNotNull($imapState->last_synced_at);

        $savedImapMsg = MailMessage::where('mail_account_id', $imapAccount->id)->firstOrFail();
        $this->assertSame('555:42', $savedImapMsg->provider_message_id);
        $this->assertSame('to_review', $savedImapMsg->status);

        // Verify Gmail sync succeeded and committed Gmail historyId
        $gmailState = $gmailAccount->syncState->fresh();
        $this->assertSame('99000', $gmailState->history_id);
        $this->assertSame('idle', $gmailState->sync_status);
        $this->assertNotNull($gmailState->last_synced_at);

        $savedGmailMsg = MailMessage::where('mail_account_id', $gmailAccount->id)->firstOrFail();
        $this->assertSame('gmail_msg_101', $savedGmailMsg->provider_message_id);
        $this->assertSame('to_review', $savedGmailMsg->status);
    }

    /**
     * Requirement 5: Concurrency locking prevents parallel sync of the same account.
     */
    public function test_concurrency_lock_prevents_overlapping_sync_for_same_account(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount('concurrency@wp.pl', autoSync: true);

        // Simulate an ongoing sync holding the atomic lock
        $lockKey = "mail-sync-account-{$account->id}";
        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->get(), 'Nie udało się założyć blokady testowej.');

        try {
            $syncService = app(MailSyncService::class);
            $result = $syncService->syncAccount($account);

            // Attempting to sync while lock is held must return an error and fail
            $this->assertFalse($result->success);
            $this->assertStringContainsString('jest już w toku', $result->error);

            // Account status & cursors must remain uncorrupted
            $this->assertNull($account->fresh()->last_synced_at);
        } finally {
            $lock->release();
        }

        // Once lock is released, sync succeeds smoothly
        $fakeClient = new FakeImapClient();
        $fakeClient->examineResult = ['exists' => 0, 'uidvalidity' => 1234, 'uidnext' => 1];
        app()->instance(ImapClientInterface::class, $fakeClient);

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account);
        $this->assertTrue($result->success);
        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    /**
     * Requirement 6: Failure on one account never stops remaining accounts.
     */
    public function test_failure_on_one_mailbox_does_not_stop_remaining_mailboxes(): void
    {
        $this->seed(MailSyncRuleSeeder::class);

        // Account 1: Will fail
        $failingAccount = $this->createImapAccount('failing@wp.pl', autoSync: true);

        // Account 2: Will succeed
        $healthyAccount = $this->createImapAccount('healthy@wp.pl', autoSync: true);

        // Setup custom provider resolver where failing account throws an exception
        $mockProvider = new class implements \App\Services\Mail\Contracts\MailSyncProviderInterface {
            public function supports(MailAccount $account): bool
            {
                return true;
            }

            public function fetchBatch(MailAccount $account, MailSyncState $state): MailSyncBatchResult
            {
                if ($account->email === 'failing@wp.pl') {
                    throw new RuntimeException('Błąd połączenia z serwerem poczty (Connection timeout).');
                }

                return new MailSyncBatchResult(
                    messages: [
                        new MailMessageMetadataDTO(
                            providerMessageId: '999:1',
                            senderEmail: 'kontakt@pracuj.pl',
                            subject: 'Oferta dla Ciebie',
                            sentAt: now(),
                            threadId: null,
                            messageId: '<healthy@pracuj.pl>'
                        ),
                    ],
                    historyId: null,
                    imapLastUid: 1,
                    imapUidvalidity: 999,
                    hasMore: false,
                );
            }

            public function fetchMessageBody(MailAccount $account, string $providerMessageId): array
            {
                return ['success' => true];
            }
        };

        $resolver = app(MailSyncProviderResolver::class);
        $resolver->setOverrideProvider($mockProvider);

        // Run auto-sync
        $syncService = app(MailSyncService::class);
        $report = $syncService->syncDueAccounts(5);

        $this->assertSame(2, $report['total_due']);
        $this->assertSame(1, $report['success_count']);
        $this->assertSame(1, $report['failure_count']);

        // Account 1 (Failing): marked failed, no cursors committed, last_error recorded
        $failingState = $failingAccount->syncState->fresh();
        $this->assertSame('failed', $failingState->sync_status);
        $this->assertStringContainsString('Connection timeout', (string) $failingState->last_error);
        $this->assertNull($failingState->imap_last_uid);
        $this->assertNull($failingState->last_synced_at);

        // Account 2 (Healthy): marked idle, cursor committed, message saved
        $healthyState = $healthyAccount->syncState->fresh();
        $this->assertSame('idle', $healthyState->sync_status);
        $this->assertNull($healthyState->last_error);
        $this->assertSame(1, $healthyState->imap_last_uid);
        $this->assertSame(999, $healthyState->imap_uidvalidity);
        $this->assertNotNull($healthyState->last_synced_at);
        $this->assertSame(1, MailMessage::where('mail_account_id', $healthyAccount->id)->count());
    }

    /**
     * Requirement 7 & 10: Incremental sync resumes properly from previous cursor.
     */
    public function test_subsequent_auto_sync_resumes_from_saved_cursor(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount('cursor@wp.pl', autoSync: true);

        // 1. Initial sync (UID 100)
        $fakeClient = new FakeImapClient();
        $fakeClient->examineResult = ['exists' => 1, 'uidvalidity' => 888, 'uidnext' => 101];
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 100,
                'raw_headers' => $this->sampleHeaders(subject: 'Oferta 100', messageId: '<msg100@pracuj.pl>'),
            ],
        ];
        app()->instance(ImapClientInterface::class, $fakeClient);

        $syncService = app(MailSyncService::class);
        $res1 = $syncService->syncAccount($account);
        $this->assertTrue($res1->success);
        $this->assertSame(100, $account->syncState->fresh()->imap_last_uid);

        // 2. Incremental sync with UID 105
        $fakeClient->searchUidsResult = [105];
        $fakeClient->uidHeadersResult = [
            [
                'uid' => 105,
                'raw_headers' => $this->sampleHeaders(subject: 'Oferta 105', messageId: '<msg105@pracuj.pl>'),
            ],
        ];

        $res2 = $syncService->syncAccount($account);
        $this->assertTrue($res2->success);

        // Cursor advanced to 105
        $this->assertSame(105, $account->syncState->fresh()->imap_last_uid);
        // Both messages saved
        $this->assertSame(2, MailMessage::where('mail_account_id', $account->id)->count());
    }

    /**
     * Requirement 11: IMAP message identifiers incorporate UIDVALIDITY to prevent collision on rebuild.
     */
    public function test_imap_identifiers_incorporate_uidvalidity_preventing_collision(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount('collision@wp.pl', autoSync: true);

        // Pre-existing message from old mailbox generation (UIDVALIDITY 100, UID 50)
        MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => '100:50',
            'message_id' => '<old-msg@pracuj.pl>',
            'sender_email' => 'powiadomienia@pracuj.pl',
            'sender_domain' => 'pracuj.pl',
            'subject' => 'Stara oferta pracy',
            'sent_at' => now()->subMonths(2),
            'category' => 'job_offer',
            'status' => 'relevant',
        ]);

        // Mailbox rebuild on server: new UIDVALIDITY 200, which coincidentally also assigns UID 50 to a completely new message!
        $fakeClient = new FakeImapClient();
        $fakeClient->examineResult = ['exists' => 1, 'uidvalidity' => 200, 'uidnext' => 51];
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 50, // Same numerical UID, but under new UIDVALIDITY 200
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <powiadomienia@pracuj.pl>',
                    subject: 'Zupełnie nowa oferta pracy po przebudowie skrzynki',
                    messageId: '<brand-new-msg@pracuj.pl>'
                ),
            ],
        ];
        app()->instance(ImapClientInterface::class, $fakeClient);

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account);

        $this->assertTrue($result->success);

        // Verify both messages exist without collision in SQLite
        $this->assertSame(2, MailMessage::where('mail_account_id', $account->id)->count());
        $this->assertTrue(MailMessage::where('provider_message_id', '100:50')->exists());
        $this->assertTrue(MailMessage::where('provider_message_id', '200:50')->exists());
    }

    /**
     * Requirement 8 & 10: Restart of schedule respects 5-minute interval and force option.
     */
    public function test_schedule_interval_respects_due_time_and_force_flag(): void
    {
        $account = $this->createImapAccount('interval@wp.pl', autoSync: true);

        // Account has never been synced -> due immediately
        $this->assertTrue($account->isDueForSync(5));

        // Mark synced 2 minutes ago
        $account->update(['last_synced_at' => now()->subMinutes(2)]);
        $this->assertFalse($account->isDueForSync(5));

        // With force flag / interval 0 -> due
        $this->assertTrue($account->isDueForSync(0));

        // Mark synced 6 minutes ago -> due
        $account->update(['last_synced_at' => now()->subMinutes(6)]);
        $this->assertTrue($account->isDueForSync(5));
    }

    /**
     * Requirement 10: Temporary network unavailability is handled gracefully without crashing or corrupting cursors,
     * and resumes successfully once connectivity is restored.
     */
    public function test_temporary_network_outage_does_not_corrupt_cursors_and_recovers_when_network_returns(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount('offline@wp.pl', autoSync: true);

        // 1. Initial successful sync
        $fakeClient = new FakeImapClient();
        $fakeClient->examineResult = ['exists' => 1, 'uidvalidity' => 333, 'uidnext' => 10];
        $fakeClient->sequenceHeadersResult = [
            ['uid' => 9, 'raw_headers' => $this->sampleHeaders(messageId: '<m9@pracuj.pl>')],
        ];
        app()->instance(ImapClientInterface::class, $fakeClient);

        $syncService = app(MailSyncService::class);
        $res1 = $syncService->syncAccount($account);
        $this->assertTrue($res1->success);
        $this->assertSame(9, $account->syncState->fresh()->imap_last_uid);
        $this->assertSame('idle', $account->syncState->fresh()->sync_status);

        // 2. Network outage: socket/DNS failure throws RuntimeException
        $outageClient = new class extends FakeImapClient {
            public function connect(array $config): void
            {
                throw new RuntimeException('Connection refused: Network is unreachable (DNS resolution failed)');
            }
        };
        app()->instance(ImapClientInterface::class, $outageClient);

        $res2 = $syncService->syncAccount($account);
        $this->assertFalse($res2->success);
        $this->assertStringContainsString('Network is unreachable', $res2->error);

        // Cursor MUST remain at 9 (never cleared or altered on error)
        $failedState = $account->syncState->fresh();
        $this->assertSame('failed', $failedState->sync_status);
        $this->assertSame(9, $failedState->imap_last_uid);
        $this->assertStringContainsString('Network is unreachable', $failedState->last_error);

        // 3. Network restored 5 minutes later: new email UID 12 arrives
        $restoredClient = new FakeImapClient();
        $restoredClient->examineResult = ['exists' => 2, 'uidvalidity' => 333, 'uidnext' => 13];
        $restoredClient->searchUidsResult = [12];
        $restoredClient->uidHeadersResult = [
            ['uid' => 12, 'raw_headers' => $this->sampleHeaders(messageId: '<m12@pracuj.pl>')],
        ];
        app()->instance(ImapClientInterface::class, $restoredClient);

        $res3 = $syncService->syncAccount($account);
        $this->assertTrue($res3->success);

        $recoveredState = $account->syncState->fresh();
        $this->assertSame('idle', $recoveredState->sync_status);
        $this->assertNull($recoveredState->last_error);
        $this->assertSame(12, $recoveredState->imap_last_uid);
        $this->assertSame(2, MailMessage::where('mail_account_id', $account->id)->count());
    }
}

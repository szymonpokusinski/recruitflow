<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncState;
use App\Models\MailAccount;
use App\Services\Mail\MailSyncService;
use App\Services\Mail\Providers\GmailSyncProvider;
use App\Services\Mail\Providers\ImapSyncProvider;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use App\Services\Mail\Testing\FakeImapClient;
use App\Services\MailAccountService;
use Database\Seeders\MailSyncRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ImapSyncProviderTest extends TestCase
{
    use RefreshDatabase;

    private function createImapAccount(string $email = 'candidate@wp.pl', string $provider = 'wp'): MailAccount
    {
        $accountService = app(MailAccountService::class);

        return $accountService->create([
            'email' => $email,
            'name' => 'Konto IMAP',
            'provider' => $provider,
            'auth_type' => 'password',
            'password' => 'TajneHaslo123!',
            'status' => 'active',
        ]);
    }

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
            'Reply-To: pomoc@pracuj.pl',
            'In-Reply-To: <init@pracuj.pl>',
            'References: <ref1@pracuj.pl>',
            '',
            '',
        ]);
    }

    public function test_supports_returns_true_for_password_and_imap_accounts_and_false_for_oauth(): void
    {
        $provider = new ImapSyncProvider;

        // 1. WP password account -> supported
        $wpAccount = MailAccount::make([
            'email' => 'user@wp.pl',
            'auth_type' => 'password',
            'provider' => 'wp',
        ]);
        $this->assertTrue($provider->supports($wpAccount));

        // 2. o2, Onet, Interia, Gazeta.pl -> supported
        foreach (['o2', 'onet', 'interia', 'gazeta'] as $p) {
            $acc = MailAccount::make([
                'email' => "user@{$p}.pl",
                'auth_type' => 'password',
                'provider' => $p,
            ]);
            $this->assertTrue($provider->supports($acc));
        }

        // 3. Custom host with password -> supported
        $customAcc = MailAccount::make([
            'email' => 'user@custom-corp.com',
            'auth_type' => 'password',
            'imap_host' => 'imap.custom-corp.com',
        ]);
        $this->assertTrue($provider->supports($customAcc));

        // 4. Gmail OAuth -> NOT supported (must be handled by GmailSyncProvider)
        $gmailOAuth = MailAccount::make([
            'email' => 'user@gmail.com',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
        ]);
        $this->assertFalse($provider->supports($gmailOAuth));
    }

    public function test_first_sync_without_cursor_scans_inbox_up_to_300_and_commits_cursor(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 500, // Total messages in mailbox
            'uidvalidity' => 123456,
            'uidnext' => 2001,
        ];

        // 300 messages fetched (sequence numbers 201 to 500)
        // Message 500 is qualified from Pracuj.pl (UID 2000)
        // Message 499 is qualified with Interview in subject (UID 1999)
        // Others are unmatched private messages
        $headersList = [
            [
                'uid' => 2000,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <powiadomienia@pracuj.pl>',
                    subject: 'Nowe rekomendowane oferty pracy',
                    messageId: '<p2000@pracuj.pl>'
                ),
            ],
            [
                'uid' => 1999,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Rekruter <rekrutacja@techcorp.io>',
                    subject: 'Zaproszenie na rozmowę rekrutacyjną',
                    messageId: '<int1999@techcorp.io>'
                ),
            ],
            [
                'uid' => 1998,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Znajomy <znajomy@prywatny.pl>',
                    subject: 'Co tam słychać?',
                    messageId: '<pvt1998@prywatny.pl>'
                ),
            ],
        ];

        $fakeClient->sequenceHeadersResult = $headersList;

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(3, $result->fetchedCount);
        $this->assertEquals(2, $result->qualifiedCount);
        $this->assertEquals(1, $result->unmatchedCount);

        // Verify read-only EXAMINE was called for INBOX
        $this->assertContains('INBOX', $fakeClient->examinedFolders);

        // Verify sequence range calculation for 500 exists: startSeq = max(1, 500 - 299) = 201, endSeq = 500
        $this->assertCount(1, $fakeClient->fetchedSequenceRanges);
        $this->assertSame(201, $fakeClient->fetchedSequenceRanges[0]['start']);
        $this->assertSame(500, $fakeClient->fetchedSequenceRanges[0]['end']);

        // Verify disconnection occurred
        $this->assertTrue($fakeClient->disconnected);

        // Verify only qualified messages are saved in database
        $this->assertEquals(2, MailMessage::where('mail_account_id', $account->id)->count());
        $savedPracuj = MailMessage::where('provider_message_id', '123456:2000')->firstOrFail();
        $this->assertEquals('to_review', $savedPracuj->status);
        $this->assertEquals('pracuj.pl', $savedPracuj->sender_domain);

        // Verify cursor updated in MailSyncState
        $state = $account->syncState->fresh();
        $this->assertSame(2000, $state->imap_last_uid);
        $this->assertSame(123456, $state->imap_uidvalidity);
        $this->assertSame('idle', $state->sync_status);
        $this->assertNotNull($state->last_synced_at);
    }

    public function test_first_sync_on_empty_mailbox_commits_uidvalidity_and_null_last_uid(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 0,
            'uidvalidity' => 98765,
            'uidnext' => 1,
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(0, $result->fetchedCount);
        $this->assertEquals(0, $result->qualifiedCount);

        // Sequence fetch must not be called when exists = 0
        $this->assertEmpty($fakeClient->fetchedSequenceRanges);

        // UIDVALIDITY stored, last UID is null
        $state = $account->syncState->fresh();
        $this->assertNull($state->imap_last_uid);
        $this->assertSame(98765, $state->imap_uidvalidity);
    }

    public function test_incremental_sync_with_cursor_fetches_only_new_uids_and_advances_cursor(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        // Seed existing sync state with last UID 1000 and UIDVALIDITY 555
        MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 1000,
            'imap_uidvalidity' => 555,
            'last_synced_at' => now()->subHours(1),
        ]);

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 20,
            'uidvalidity' => 555, // Matches stored UIDVALIDITY
            'uidnext' => 1003,
        ];

        // Search returns 2 new UIDs
        $fakeClient->searchUidsResult = [1001, 1002];
        $fakeClient->uidHeadersResult = [
            [
                'uid' => 1001,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <powiadomienia@pracuj.pl>',
                    subject: 'Twoja aplikacja dotarła do pracodawcy',
                    messageId: '<p1001@pracuj.pl>'
                ),
            ],
            [
                'uid' => 1002,
                'raw_headers' => $this->sampleHeaders(
                    from: 'No Fluff Jobs <powiadomienia@nofluffjobs.com>',
                    subject: 'Nowe oferty pracy: Senior Backend Developer',
                    messageId: '<job1002@nofluffjobs.com>'
                ),
            ],
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(2, $result->fetchedCount);
        $this->assertEquals(2, $result->qualifiedCount);

        // Verify search was executed with since UID = 1000
        $this->assertContains(1000, $fakeClient->searchedSince);

        // Verify UID fetch was called for [1001, 1002]
        $this->assertCount(1, $fakeClient->fetchedUids);
        $this->assertEquals([1001, 1002], $fakeClient->fetchedUids[0]);

        // Verify cursor advanced to 1002
        $state = $account->syncState->fresh();
        $this->assertSame(1002, $state->imap_last_uid);
        $this->assertSame(555, $state->imap_uidvalidity);
    }

    public function test_incremental_sync_when_no_new_messages_leaves_cursor_unchanged_and_creates_no_records(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 1000,
            'imap_uidvalidity' => 777,
        ]);

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 15,
            'uidvalidity' => 777,
            'uidnext' => 1001,
        ];
        // No new UIDs returned
        $fakeClient->searchUidsResult = [];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(0, $result->fetchedCount);
        $this->assertEquals(0, $result->qualifiedCount);

        // No UID fetch was attempted
        $this->assertEmpty($fakeClient->fetchedUids);

        // Cursor remains 1000
        $state = $account->syncState->fresh();
        $this->assertSame(1000, $state->imap_last_uid);
        $this->assertSame(777, $state->imap_uidvalidity);
    }

    public function test_deduplication_prevents_duplicate_records_and_repeated_uids(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 100,
            'imap_uidvalidity' => 333,
        ]);

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 5,
            'uidvalidity' => 333,
            'uidnext' => 103,
        ];
        $fakeClient->searchUidsResult = [101, 101]; // Duplicate in search result
        $fakeClient->uidHeadersResult = [
            [
                'uid' => 101,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <kontakt@pracuj.pl>',
                    subject: 'Potwierdzenie aplikacji',
                    messageId: '<dup101@pracuj.pl>'
                ),
            ],
            [
                'uid' => 101, // Duplicate payload
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <kontakt@pracuj.pl>',
                    subject: 'Potwierdzenie aplikacji',
                    messageId: '<dup101@pracuj.pl>'
                ),
            ],
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);
        // Only 1 record created in database despite duplicate
        $this->assertEquals(1, MailMessage::where('mail_account_id', $account->id)->count());
        $this->assertSame(101, $account->syncState->fresh()->imap_last_uid);
    }

    public function test_uidvalidity_change_triggers_controlled_rescan_without_deleting_existing_or_manual_decisions(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        // 1. Existing message with user manual classification
        $existingMessage = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => '1000:100', // Old UID with old UIDVALIDITY
            'message_id' => '<important-offer@nofluffjobs.com>',
            'sender_email' => 'biuro@nofluffjobs.com',
            'sender_domain' => 'nofluffjobs.com',
            'subject' => 'Oferta pracy: Principal Engineer',
            'sent_at' => now()->subDays(10),
            'category' => 'job_offer',
            'status' => 'relevant', // User manually marked as relevant
            'is_manually_classified' => true,
        ]);

        // 2. Old sync state with UIDVALIDITY 1000
        MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 100,
            'imap_uidvalidity' => 1000,
            'last_synced_at' => now()->subDays(3),
        ]);

        // 3. Server now reports new UIDVALIDITY 2000!
        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 50,
            'uidvalidity' => 2000, // CHANGED!
            'uidnext' => 501,
        ];

        // Rescan returns the old message with NEW UID 250, plus a brand new message with UID 251
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 250, // Same RFC Message-ID as existing message, but renumbered UID
                'raw_headers' => $this->sampleHeaders(
                    from: 'biuro@nofluffjobs.com',
                    subject: 'Oferta pracy: Principal Engineer',
                    messageId: '<important-offer@nofluffjobs.com>'
                ),
            ],
            [
                'uid' => 251, // New message
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <powiadomienia@pracuj.pl>',
                    subject: 'Nowe ogłoszenia dla Ciebie',
                    messageId: '<p251@pracuj.pl>'
                ),
            ],
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);

        // Verify existing message manual classification was NOT overwritten!
        $refreshedExisting = $existingMessage->fresh();
        $this->assertEquals('relevant', $refreshedExisting->status);
        $this->assertTrue($refreshedExisting->is_manually_classified);
        // UID was updated to the new UID 250 with new UIDVALIDITY 2000
        $this->assertEquals('2000:250', $refreshedExisting->provider_message_id);

        // Verify new message was saved
        $this->assertEquals(1, MailMessage::where('provider_message_id', '2000:251')->count());

        // Total messages count in DB is 2 (no duplicates!)
        $this->assertEquals(2, MailMessage::where('mail_account_id', $account->id)->count());

        // Verify new UIDVALIDITY and new highest UID are committed
        $state = $account->syncState->fresh();
        $this->assertSame(2000, $state->imap_uidvalidity);
        $this->assertSame(251, $state->imap_last_uid);
    }

    public function test_failure_mid_sync_does_not_commit_cursor_and_marks_status_failed(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        $syncState = MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 500,
            'imap_uidvalidity' => 1000,
            'last_synced_at' => now()->subDay(),
        ]);

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 50,
            'uidvalidity' => 1000,
            'uidnext' => 505,
        ];
        $fakeClient->searchUidsResult = [501, 502];

        // Fetch throws network exception
        $fakeClient->throwOnFetch = new RuntimeException('Połączenie z serwerem pocztowym zostało zerwane (EPIPE).');

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('zerwane', $result->error ?? '');

        // Cursor MUST remain 500 and 1000
        $refreshedState = $syncState->fresh();
        $this->assertEquals('failed', $refreshedState->sync_status);
        $this->assertSame(500, $refreshedState->imap_last_uid);
        $this->assertSame(1000, $refreshedState->imap_uidvalidity);
        $this->assertNotNull($refreshedState->last_error);

        // Disconnect was still called cleanly in finally block
        $this->assertTrue($fakeClient->disconnected);
    }

    public function test_connection_failure_marks_sync_failed_without_cursor_update(): void
    {
        $account = $this->createImapAccount();

        $syncState = MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 300,
            'imap_uidvalidity' => 100,
        ]);

        $fakeClient = new FakeImapClient;
        $fakeClient->throwOnConnect = new RuntimeException('Błąd uwierzytelnienia na serwerze IMAP: błędne hasło.');

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertFalse($result->success);
        $this->assertSame('failed', $syncState->fresh()->sync_status);
        $this->assertSame(300, $syncState->fresh()->imap_last_uid);
    }

    public function test_read_only_and_seen_flag_protection_assertions(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 10,
            'uidvalidity' => 123,
            'uidnext' => 11,
        ];
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 10,
                'raw_headers' => $this->sampleHeaders(),
            ],
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $syncService->syncAccount($account, $imapProvider);

        // 1. Verify mailbox opened strictly with EXAMINE (read-only), NEVER with SELECT
        $this->assertSame(['INBOX'], $fakeClient->examinedFolders);

        // 2. Verify clean disconnection
        $this->assertTrue($fakeClient->disconnected);
    }

    public function test_no_body_and_no_attachments_are_saved_in_database(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = ['exists' => 1, 'uidvalidity' => 123, 'uidnext' => 2];
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 1,
                'raw_headers' => $this->sampleHeaders(
                    from: 'Pracuj.pl <kontakt@pracuj.pl>',
                    subject: 'Oferta Pracy: Senior Developer',
                    messageId: '<msg1@pracuj.pl>'
                ),
            ],
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $syncService->syncAccount($account, $imapProvider);

        $message = MailMessage::where('provider_message_id', '123:1')->firstOrFail();

        // Header sync must not eagerly fetch message bodies or attachment content.
        $this->assertEquals('Pracuj.pl', $message->sender_name);
        $this->assertEquals('kontakt@pracuj.pl', $message->sender_email);
        $this->assertEquals('pracuj.pl', $message->sender_domain);
        $this->assertEquals('Oferta Pracy: Senior Developer', $message->subject);
        $this->assertEquals('<msg1@pracuj.pl>', $message->message_id);

        $arrayData = $message->toArray();
        $this->assertArrayNotHasKey('body', $arrayData);
        $this->assertArrayNotHasKey('html', $arrayData);
        $this->assertNull($message->body_text);
        $this->assertNull($message->body_html);
        $this->assertNull($message->body_fetched_at);
        $this->assertNull($message->attachments);
        $this->assertFalse($message->has_attachments);
    }

    public function test_resolver_routes_wp_account_to_imap_provider_and_gmail_to_gmail_provider(): void
    {
        $resolver = app(MailSyncProviderResolver::class);

        $wpAccount = MailAccount::make([
            'email' => 'user@wp.pl',
            'auth_type' => 'password',
            'provider' => 'wp',
        ]);
        $this->assertInstanceOf(ImapSyncProvider::class, $resolver->resolve($wpAccount));

        $gmailAccount = MailAccount::make([
            'email' => 'user@gmail.com',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
        ]);
        $this->assertInstanceOf(GmailSyncProvider::class, $resolver->resolve($gmailAccount));
    }

    public function test_initial_sync_on_large_mailbox_strictly_limits_range_to_300(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        $fakeClient = new FakeImapClient;
        $fakeClient->examineResult = [
            'exists' => 1500, // Large mailbox with 1500 messages
            'uidvalidity' => 4567,
            'uidnext' => 1501,
        ];
        $fakeClient->sequenceHeadersResult = [
            [
                'uid' => 1500,
                'raw_headers' => $this->sampleHeaders(),
            ],
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);

        // For exists = 1500, sequence range must be 1201:1500 (1500 - 300 + 1 = 1201)
        $this->assertCount(1, $fakeClient->fetchedSequenceRanges);
        $this->assertSame(1201, $fakeClient->fetchedSequenceRanges[0]['start']);
        $this->assertSame(1500, $fakeClient->fetchedSequenceRanges[0]['end']);
    }

    public function test_multi_batch_incremental_sync_when_more_than_300_new_messages(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createImapAccount();

        MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'imap_last_uid' => 1000,
            'imap_uidvalidity' => 888,
        ]);

        // Simulating 350 new messages (1001 to 1350)
        $fakeClient = new class extends FakeImapClient
        {
            private int $callCount = 0;

            public function searchUidsSince(int $sinceUid): array
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    // First search: 350 new UIDs
                    return range(1001, 1350);
                }

                // Second search (after batch 1 advanced to 1300): remaining 50 UIDs
                return range(1301, 1350);
            }

            public function fetchHeadersByUids(array $uids): array
            {
                $this->fetchedUids[] = $uids;

                return array_map(function ($uid) {
                    return [
                        'uid' => $uid,
                        'raw_headers' => "From: Pracuj.pl <powiadomienia@pracuj.pl>\r\nSubject: Oferta {$uid}\r\nDate: Thu, 15 Oct 2026 12:00:00 +0200\r\nMessage-ID: <msg{$uid}@pracuj.pl>\r\n\r\n",
                    ];
                }, $uids);
            }
        };

        $fakeClient->examineResult = [
            'exists' => 350,
            'uidvalidity' => 888,
            'uidnext' => 1351,
        ];

        $imapProvider = new ImapSyncProvider(client: $fakeClient);
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($account, $imapProvider);

        $this->assertTrue($result->success);
        // Batch 1: 300 messages, Batch 2: 50 messages -> total 350
        $this->assertEquals(350, $result->fetchedCount);
        $this->assertEquals(350, $result->qualifiedCount);

        // Verify two UID fetch calls: first with 300, second with 50
        $this->assertCount(2, $fakeClient->fetchedUids);
        $this->assertCount(300, $fakeClient->fetchedUids[0]);
        $this->assertCount(50, $fakeClient->fetchedUids[1]);

        // Final cursor must be 1350
        $this->assertSame(1350, $account->syncState->fresh()->imap_last_uid);
    }
}

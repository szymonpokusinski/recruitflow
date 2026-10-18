<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncRule;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\MailSyncService;
use App\Services\Mail\Providers\FakeMailSyncProvider;
use App\Services\Mail\Providers\GmailSyncProvider;
use App\Services\Mail\Providers\ImapSyncProvider;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use Database\Seeders\MailSyncRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MailSyncEngineTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(string $email = 'candidate@example.com', string $provider = 'wp', string $authType = 'password'): MailAccount
    {
        return MailAccount::create([
            'email' => $email,
            'name' => 'Konto testowe',
            'auth_type' => $authType,
            'provider' => $provider,
            'status' => 'active',
        ]);
    }

    public function test_sync_engine_saves_qualified_messages_and_skips_unmatched_and_ignored(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createAccount();

        // Add a custom ignore rule for newsletters
        MailSyncRule::create([
            'name' => 'Ignoruj newslettery',
            'is_system' => false,
            'sender_domain' => 'newsletter-spam.com',
            'category' => 'other',
            'action' => 'ignore',
            'priority' => 150,
            'is_active' => true,
        ]);

        $fakeProvider = new FakeMailSyncProvider();
        $fakeProvider->setMessages(
            messages: [
                // 1. Qualified (Pracuj.pl domain rule)
                new MailMessageMetadataDTO(
                    providerMessageId: 'msg-pracuj-1',
                    senderEmail: 'powiadomienia@pracuj.pl',
                    subject: 'Twoja aplikacja została przekazana',
                    sentAt: now()->subMinutes(10),
                    threadId: 'th-1',
                    messageId: '<p1@pracuj.pl>',
                    senderName: 'Pracuj.pl',
                ),
                // 2. Qualified (Interview subject keyword rule)
                new MailMessageMetadataDTO(
                    providerMessageId: 'msg-interview-2',
                    senderEmail: 'rekruter@techcorp.io',
                    subject: 'Zaproszenie na rozmowę rekrutacyjną',
                    sentAt: now()->subMinutes(5),
                    threadId: 'th-2',
                    messageId: '<int2@techcorp.io>',
                    senderName: 'Anna Rekruter',
                ),
                // 3. Ignored by rule (newsletter-spam.com)
                new MailMessageMetadataDTO(
                    providerMessageId: 'msg-spam-3',
                    senderEmail: 'news@newsletter-spam.com',
                    subject: 'Cotygodniowy biuletyn reklamowy',
                    sentAt: now()->subMinutes(2),
                ),
                // 4. Unmatched / non-recruitment (bank / friend / utility)
                new MailMessageMetadataDTO(
                    providerMessageId: 'msg-bank-4',
                    senderEmail: 'kontakt@mojbank.pl',
                    subject: 'Wyciąg miesięczny z konta',
                    sentAt: now()->subMinutes(1),
                ),
            ],
            historyId: 'gmail_cursor_9999',
            imapLastUid: 1050,
            imapUidvalidity: 12345
        );

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account, $fakeProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(4, $result->fetchedCount);
        $this->assertEquals(2, $result->qualifiedCount);
        $this->assertEquals(1, $result->ignoredCount);
        $this->assertEquals(1, $result->unmatchedCount);

        // Verify only qualified messages are saved in MailMessage!
        $this->assertEquals(2, MailMessage::where('mail_account_id', $account->id)->count());

        $savedPracuj = MailMessage::where('provider_message_id', 'msg-pracuj-1')->first();
        $this->assertNotNull($savedPracuj);
        $this->assertEquals('pracuj.pl', $savedPracuj->sender_domain);
        $this->assertEquals('to_review', $savedPracuj->status);
        $this->assertFalse($savedPracuj->is_manually_classified);

        $savedInterview = MailMessage::where('provider_message_id', 'msg-interview-2')->first();
        $this->assertNotNull($savedInterview);
        $this->assertEquals('to_review', $savedInterview->status);

        // Ignored and unmatched messages must NOT exist in MailMessage
        $this->assertNull(MailMessage::where('provider_message_id', 'msg-spam-3')->first());
        $this->assertNull(MailMessage::where('provider_message_id', 'msg-bank-4')->first());

        // Verify MailSyncState updated correctly
        $state = $account->syncState;
        $this->assertNotNull($state);
        $this->assertEquals('idle', $state->sync_status);
        $this->assertNotNull($state->last_synced_at);
        $this->assertEquals(2, $state->total_messages_count);
        $this->assertEquals(2, $state->last_batch_count);
        $this->assertEquals('gmail_cursor_9999', $state->history_id);
        $this->assertEquals(1050, $state->imap_last_uid);
        $this->assertEquals(12345, $state->imap_uidvalidity);
        $this->assertNull($state->last_error);
    }

    public function test_deduplication_prevents_duplicate_records(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createAccount();

        $fakeProvider = new FakeMailSyncProvider();
        // Batch with duplicate message IDs within the same payload
        $fakeProvider->setMessages([
            new MailMessageMetadataDTO(
                providerMessageId: 'dup-id-1',
                senderEmail: 'kontakt@pracuj.pl',
                subject: 'Potwierdzenie aplikacji',
                sentAt: now(),
            ),
            new MailMessageMetadataDTO(
                providerMessageId: 'dup-id-1',
                senderEmail: 'kontakt@pracuj.pl',
                subject: 'Potwierdzenie aplikacji (duplikat w tej samej paczce)',
                sentAt: now(),
            ),
        ]);

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account, $fakeProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(1, MailMessage::where('mail_account_id', $account->id)->count());

        // Second sync with the same message again does not duplicate
        $fakeProvider2 = new FakeMailSyncProvider();
        $fakeProvider2->setMessages([
            new MailMessageMetadataDTO(
                providerMessageId: 'dup-id-1',
                senderEmail: 'kontakt@pracuj.pl',
                subject: 'Potwierdzenie aplikacji',
                sentAt: now(),
            ),
        ]);

        $syncService->syncAccount($account, $fakeProvider2);
        $this->assertEquals(1, MailMessage::where('mail_account_id', $account->id)->count());
    }

    public function test_sync_engine_never_overwrites_manual_user_decisions(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createAccount();

        // 1. Existing message in database that the user manually classified as 'relevant'
        $message = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg-manual-1',
            'sender_email' => 'biuro@nofluffjobs.com',
            'sender_domain' => 'nofluffjobs.com',
            'subject' => 'Oferta pracy: Senior PHP Developer',
            'sent_at' => now()->subDay(),
            'category' => 'job_offer',
            'status' => 'relevant', // User manually marked as relevant
            'is_manually_classified' => true,
        ]);

        // Add a rule that would otherwise set action to ignore
        MailSyncRule::create([
            'name' => 'Ignoruj NFJ',
            'is_system' => false,
            'sender_domain' => 'nofluffjobs.com',
            'category' => 'other',
            'action' => 'ignore',
            'priority' => 300,
            'is_active' => true,
        ]);

        $fakeProvider = new FakeMailSyncProvider();
        $fakeProvider->setMessages([
            new MailMessageMetadataDTO(
                providerMessageId: 'msg-manual-1',
                senderEmail: 'biuro@nofluffjobs.com',
                subject: 'Oferta pracy: Senior PHP Developer',
                sentAt: now()->subDay(),
            ),
        ]);

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account, $fakeProvider);

        $this->assertTrue($result->success);

        $refreshed = $message->fresh();
        $this->assertEquals('relevant', $refreshed->status);
        $this->assertEquals('job_offer', $refreshed->category);
        $this->assertTrue($refreshed->is_manually_classified);
    }

    public function test_cursor_is_not_updated_when_processing_fails(): void
    {
        $account = $this->createAccount();

        // Seed initial sync state with existing cursor
        $syncState = MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'history_id' => 'initial_cursor_100',
            'imap_last_uid' => 500,
            'imap_uidvalidity' => 9999,
            'last_synced_at' => now()->subDays(2),
        ]);

        $fakeProvider = new FakeMailSyncProvider();
        $fakeProvider->setThrowException(new RuntimeException('Symulowany błąd połączenia z serwerem poczty.'));

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account, $fakeProvider);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Symulowany błąd połączenia', $result->error ?? '');

        // Verify syncState after failure: cursors MUST NOT change!
        $refreshedState = $syncState->fresh();
        $this->assertEquals('failed', $refreshedState->sync_status);
        $this->assertEquals('initial_cursor_100', $refreshedState->history_id);
        $this->assertEquals(500, $refreshedState->imap_last_uid);
        $this->assertEquals(9999, $refreshedState->imap_uidvalidity);
        $this->assertNotNull($refreshedState->last_error);
        $this->assertNotNull($refreshedState->last_error_at);
    }

    public function test_provider_resolver_selects_gmail_or_imap_based_on_account(): void
    {
        $resolver = app(MailSyncProviderResolver::class);

        $gmailOauthAccount = $this->createAccount('user@gmail.com', provider: 'gmail', authType: 'oauth');
        $resolvedGmail = $resolver->resolve($gmailOauthAccount);
        $this->assertInstanceOf(GmailSyncProvider::class, $resolvedGmail);

        $wpImapAccount = $this->createAccount('user@wp.pl', provider: 'wp', authType: 'password');
        $resolvedImap = $resolver->resolve($wpImapAccount);
        $this->assertInstanceOf(ImapSyncProvider::class, $resolvedImap);

        $onetAccount = $this->createAccount('user@onet.pl', provider: 'onet', authType: 'password');
        $this->assertInstanceOf(ImapSyncProvider::class, $resolver->resolve($onetAccount));
    }
}

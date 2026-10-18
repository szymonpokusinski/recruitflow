<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\Google\GoogleOAuthService;
use App\Models\Integrations\IntegrationAccount;
use App\Models\MailAccount;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\DTO\MailSyncBatchResult;
use App\Services\Mail\MailSyncService;
use App\Services\Mail\Providers\FakeMailSyncProvider;
use App\Services\Mail\Providers\GmailSyncProvider;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use Database\Seeders\MailSyncRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GmailSyncProviderTest extends TestCase
{
    use RefreshDatabase;

    private function createGmailOAuthAccount(string $email = 'candidate@gmail.com'): array
    {
        $credRef = (string) Str::uuid();

        // Create integration account with gmail.metadata scope
        $integrationAccount = IntegrationAccount::create([
            'provider' => 'google',
            'name' => 'Konto Google',
            'credential_ref' => $credRef,
            'status' => 'connected',
            'scopes' => [
                GoogleOAuthService::OPENID_SCOPE,
                GoogleOAuthService::USERINFO_EMAIL_SCOPE,
                GoogleOAuthService::GMAIL_METADATA_SCOPE,
            ],
            'token_expires_at' => now()->addHour(),
        ]);

        // Mock token in credential store
        $vault = app(CredentialStore::class);
        $vault->write($credRef, [
            'access_token' => 'mock_google_access_token_123',
            'refresh_token' => 'mock_google_refresh_token_abc',
            'expires_at' => now()->addHour()->timestamp,
            'client_id' => 'mock_client.apps.googleusercontent.com',
            'client_secret' => 'mock_secret',
            'scope' => implode(' ', $integrationAccount->scopes),
        ]);

        // Create mail account
        $mailAccount = MailAccount::create([
            'email' => $email,
            'name' => 'Konto Gmail',
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
            'credential_ref' => $credRef,
            'status' => 'active',
        ]);

        return [$mailAccount, $integrationAccount];
    }

    public function test_supports_method_restricts_to_valid_gmail_oauth_accounts(): void
    {
        $provider = new GmailSyncProvider();

        // 1. Gmail OAuth -> supported
        $gmailOAuth = MailAccount::make([
            'auth_type' => 'oauth',
            'provider' => 'gmail',
            'oauth_provider' => 'google',
        ]);
        $this->assertTrue($provider->supports($gmailOAuth));

        // 2. Generic OAuth with google provider -> supported
        $googleOAuth = MailAccount::make([
            'auth_type' => 'oauth',
            'provider' => 'custom',
            'oauth_provider' => 'google',
        ]);
        $this->assertTrue($provider->supports($googleOAuth));

        // 3. Other OAuth provider (e.g. Outlook) -> NOT supported
        $outlookOAuth = MailAccount::make([
            'auth_type' => 'oauth',
            'provider' => 'outlook',
            'oauth_provider' => 'microsoft',
        ]);
        $this->assertFalse($provider->supports($outlookOAuth));

        // 4. Password accounts -> NOT supported
        $passwordAcc = MailAccount::make([
            'auth_type' => 'password',
            'provider' => 'wp',
        ]);
        $this->assertFalse($provider->supports($passwordAcc));

        $gmailPassword = MailAccount::make([
            'auth_type' => 'password',
            'provider' => 'gmail',
        ]);
        $this->assertFalse($provider->supports($gmailPassword));
    }

    public function test_first_sync_fetches_profile_baseline_and_scans_inbox_up_to_300_without_q_param(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        // Prepare fake responses:
        // 1. Profile: historyId = 9876543
        // 2. Messages list page 1: 100 messages + nextPageToken
        // 3. Messages list page 2: 100 messages + nextPageToken
        // 4. Messages list page 3: 100 messages + nextPageToken
        // 5. Messages list page 4: would have more, but sync should STOP at 300!
        $page1Messages = array_map(fn ($i) => ['id' => "msg-{$i}", 'threadId' => "th-{$i}"], range(1, 100));
        $page2Messages = array_map(fn ($i) => ['id' => "msg-{$i}", 'threadId' => "th-{$i}"], range(101, 200));
        $page3Messages = array_map(fn ($i) => ['id' => "msg-{$i}", 'threadId' => "th-{$i}"], range(201, 300));

        $requestsLog = [];

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'candidate@gmail.com',
                'historyId' => '9876543',
                'messagesTotal' => 1500,
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => function ($request) use (&$requestsLog, $page1Messages, $page2Messages, $page3Messages) {
                $requestsLog[] = $request->url();
                $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($query, $parsed);

                // Strictly ensure parameter 'q' is NOT used!
                $this->assertArrayNotHasKey('q', $parsed, 'Parameter q must NOT be used with gmail.metadata scope');
                $this->assertEquals('INBOX', $parsed['labelIds'] ?? null);

                $pageToken = $parsed['pageToken'] ?? null;
                if ($pageToken === null) {
                    return Http::response([
                        'messages' => $page1Messages,
                        'nextPageToken' => 'page_token_2',
                    ], 200);
                } elseif ($pageToken === 'page_token_2') {
                    return Http::response([
                        'messages' => $page2Messages,
                        'nextPageToken' => 'page_token_3',
                    ], 200);
                } elseif ($pageToken === 'page_token_3') {
                    return Http::response([
                        'messages' => $page3Messages,
                        'nextPageToken' => 'page_token_4',
                    ], 200);
                }

                // If page 4 is requested, fail test because limit is 300!
                $this->fail('Sync requested more than 300 messages!');
            },

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/*' => function ($request) {
                $url = $request->url();
                preg_match('/messages\/(msg-[0-9]+)/', $url, $m);
                $id = $m[1] ?? 'msg-unknown';

                // Message 1 is from Pracuj.pl (qualified)
                if ($id === 'msg-1') {
                    return Http::response([
                        'id' => $id,
                        'threadId' => 'th-1',
                        'labelIds' => ['INBOX'],
                        'internalDate' => '1713180000000',
                        'payload' => [
                            'headers' => [
                                ['name' => 'From', 'value' => 'Pracuj.pl <powiadomienia@pracuj.pl>'],
                                ['name' => 'Subject', 'value' => 'Nowe oferty pracy w Twoim profilu'],
                                ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 10:00:00 +0200'],
                                ['name' => 'Message-ID', 'value' => '<msg1@pracuj.pl>'],
                                ['name' => 'Reply-To', 'value' => 'pomoc@pracuj.pl'],
                                ['name' => 'In-Reply-To', 'value' => '<init@pracuj.pl>'],
                                ['name' => 'References', 'value' => '<ref@pracuj.pl>'],
                            ],
                        ],
                    ], 200);
                }

                // Message 2 has "Zaproszenie na rozmowę" in subject (qualified)
                if ($id === 'msg-2') {
                    return Http::response([
                        'id' => $id,
                        'threadId' => 'th-2',
                        'labelIds' => ['INBOX'],
                        'internalDate' => '1713180000000',
                        'payload' => [
                            'headers' => [
                                ['name' => 'From', 'value' => '"Tech Corp HR" <rekrutacja@techcorp.io>'],
                                ['name' => 'Subject', 'value' => 'Zaproszenie na rozmowę kwalifikacyjną'],
                                ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 11:00:00 +0200'],
                                ['name' => 'Message-ID', 'value' => '<msg2@techcorp.io>'],
                            ],
                        ],
                    ], 200);
                }

                // All other messages are private / unmatched (e.g. from bank or friend)
                return Http::response([
                    'id' => $id,
                    'threadId' => "th-{$id}",
                    'labelIds' => ['INBOX'],
                    'internalDate' => '1713180000000',
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'Znajomy <znajomy@prywatny.pl>'],
                            ['name' => 'Subject', 'value' => 'Co słychać w weekend?'],
                            ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                        ],
                    ],
                ], 200);
            },
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(300, $result->fetchedCount);
        $this->assertEquals(2, $result->qualifiedCount);
        $this->assertEquals(298, $result->unmatchedCount);

        // Verify only 2 qualified messages are saved in SQLite
        $this->assertEquals(2, MailMessage::where('mail_account_id', $mailAccount->id)->count());

        $msg1 = MailMessage::where('provider_message_id', 'msg-1')->firstOrFail();
        $this->assertEquals('Pracuj.pl', $msg1->sender_name);
        $this->assertEquals('powiadomienia@pracuj.pl', $msg1->sender_email);
        $this->assertEquals('pracuj.pl', $msg1->sender_domain);
        $this->assertEquals('pomoc@pracuj.pl', $msg1->reply_to);
        $this->assertEquals('<msg1@pracuj.pl>', $msg1->message_id);
        $this->assertEquals('to_review', $msg1->status);

        // Verify history_id cursor was committed to MailSyncState
        $state = $mailAccount->syncState;
        $this->assertEquals('9876543', $state->history_id);
        $this->assertEquals('idle', $state->sync_status);
        $this->assertNotNull($state->last_synced_at);
    }

    public function test_subsequent_sync_with_cursor_uses_history_list_and_saves_new_messages(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '1000',
            'last_synced_at' => now()->subHours(2),
        ]);

        $historyCalled = false;
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => function ($request) use (&$historyCalled) {
                $historyCalled = true;
                $url = $request->url();
                $this->assertStringContainsString('startHistoryId=1000', $url);
                $this->assertStringContainsString('labelId=INBOX', $url);
                $this->assertStringContainsString('historyTypes=messageAdded', $url);
                $this->assertStringContainsString('historyTypes=labelAdded', $url);

                return Http::response([
                    'historyId' => '1050',
                    'history' => [
                        [
                            'id' => 'h-1',
                            'messagesAdded' => [
                                [
                                    'message' => [
                                        'id' => 'msg-inc-1',
                                        'threadId' => 'th-inc-1',
                                        'labelIds' => ['INBOX', 'UNREAD'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-inc-1?*' => Http::response([
                'id' => 'msg-inc-1',
                'threadId' => 'th-inc-1',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <powiadomienia@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Nowa oferta dopasowana do Ciebie'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                        ['name' => 'Message-ID', 'value' => '<msg-inc-1@pracuj.pl>'],
                    ],
                ],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertTrue($historyCalled);
        $this->assertEquals(1, $result->fetchedCount);
        $this->assertEquals(1, $result->qualifiedCount);

        // Verify message saved in SQLite
        $this->assertEquals(1, MailMessage::where('mail_account_id', $mailAccount->id)->count());
        $saved = MailMessage::where('provider_message_id', 'msg-inc-1')->firstOrFail();
        $this->assertEquals('to_review', $saved->status);
        $this->assertEquals('pracuj.pl', $saved->sender_domain);

        // Verify cursor updated to 1050
        $this->assertEquals('1050', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_when_no_new_messages_updates_cursor_without_creating_records(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '2000',
            'last_synced_at' => now()->subHours(1),
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'historyId' => '2050',
                // No history entries (no changes in inbox)
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(0, $result->fetchedCount);
        $this->assertEquals(0, $result->qualifiedCount);
        $this->assertEquals(0, MailMessage::where('mail_account_id', $mailAccount->id)->count());

        // History cursor is advanced to 2050
        $this->assertEquals('2050', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_handles_multiple_pages_of_changes(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '3000',
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => function ($request) {
                $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($query, $parsed);
                $pageToken = $parsed['pageToken'] ?? null;

                if ($pageToken === null) {
                    return Http::response([
                        'historyId' => '3050',
                        'nextPageToken' => 'page_token_2',
                        'history' => [
                            [
                                'id' => 'h-p1',
                                'messagesAdded' => [
                                    ['message' => ['id' => 'msg-p1', 'threadId' => 'th-p1', 'labelIds' => ['INBOX']]],
                                ],
                            ],
                        ],
                    ], 200);
                }

                if ($pageToken === 'page_token_2') {
                    return Http::response([
                        'historyId' => '3100',
                        'history' => [
                            [
                                'id' => 'h-p2',
                                'messagesAdded' => [
                                    ['message' => ['id' => 'msg-p2', 'threadId' => 'th-p2', 'labelIds' => ['INBOX']]],
                                ],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([], 404);
            },

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-p1?*' => Http::response([
                'id' => 'msg-p1',
                'threadId' => 'th-p1',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <kontakt@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Potwierdzenie aplikacji p1'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                    ],
                ],
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-p2?*' => Http::response([
                'id' => 'msg-p2',
                'threadId' => 'th-p2',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <kontakt@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Potwierdzenie aplikacji p2'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:05:00 +0200'],
                    ],
                ],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(2, $result->fetchedCount);
        $this->assertEquals(2, $result->qualifiedCount);
        $this->assertEquals(2, MailMessage::where('mail_account_id', $mailAccount->id)->count());
        $this->assertEquals('3100', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_deduplicates_repeating_message_ids_across_events(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '4000',
        ]);

        $fetchCount = 0;
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'historyId' => '4050',
                'history' => [
                    [
                        'id' => 'h-1',
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-dup-repeat', 'threadId' => 'th-1', 'labelIds' => ['INBOX']]],
                        ],
                    ],
                    [
                        'id' => 'h-2',
                        'labelsAdded' => [
                            ['labelIds' => ['INBOX'], 'message' => ['id' => 'msg-dup-repeat', 'threadId' => 'th-1', 'labelIds' => ['INBOX']]],
                        ],
                    ],
                ],
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-dup-repeat?*' => function () use (&$fetchCount) {
                $fetchCount++;

                return Http::response([
                    'id' => 'msg-dup-repeat',
                    'threadId' => 'th-1',
                    'labelIds' => ['INBOX'],
                    'internalDate' => '1713180000000',
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'Pracuj.pl <oferty@pracuj.pl>'],
                            ['name' => 'Subject', 'value' => 'Rekomendacja stanowisk'],
                            ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                        ],
                    ],
                ], 200);
            },
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        // Metadata endpoint must only be queried ONCE for deduplicated candidate
        $this->assertEquals(1, $fetchCount);
        $this->assertEquals(1, $result->fetchedCount);
        $this->assertEquals(1, MailMessage::where('mail_account_id', $mailAccount->id)->count());
        $this->assertEquals('4050', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_handles_message_added_to_inbox_via_label_added(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '5000',
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'historyId' => '5050',
                'history' => [
                    [
                        'id' => 'h-label',
                        'labelsAdded' => [
                            [
                                'labelIds' => ['INBOX'],
                                'message' => [
                                    'id' => 'msg-moved-inbox',
                                    'threadId' => 'th-moved',
                                    'labelIds' => ['INBOX'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-moved-inbox?*' => Http::response([
                'id' => 'msg-moved-inbox',
                'threadId' => 'th-moved',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <powiadomienia@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Przeniesiona oferta do INBOX'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                    ],
                ],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->qualifiedCount);
        $this->assertEquals(1, MailMessage::where('provider_message_id', 'msg-moved-inbox')->count());
        $this->assertEquals('5050', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_skips_message_if_not_currently_in_inbox(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '6000',
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'historyId' => '6050',
                'history' => [
                    [
                        'id' => 'h-archived',
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-already-archived', 'threadId' => 'th-archived']],
                        ],
                    ],
                ],
            ], 200),

            // When metadata is fetched, message does NOT have INBOX label (e.g. was archived/trashed)
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-already-archived?*' => Http::response([
                'id' => 'msg-already-archived',
                'threadId' => 'th-archived',
                'labelIds' => ['CATEGORY_PERSONAL', 'TRASH'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <powiadomienia@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Wiadomość usunięta przed synchronizacją'],
                    ],
                ],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(0, $result->fetchedCount);
        $this->assertEquals(0, $result->qualifiedCount);
        $this->assertEquals(0, MailMessage::where('mail_account_id', $mailAccount->id)->count());
        $this->assertEquals('6050', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_skips_deleted_message_returning_404_on_metadata_fetch(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '7000',
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'historyId' => '7050',
                'history' => [
                    [
                        'id' => 'h-del',
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-permanently-deleted', 'threadId' => 'th-del']],
                        ],
                    ],
                ],
            ], 200),

            // Message was permanently deleted -> 404
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-permanently-deleted?*' => Http::response([
                'error' => ['message' => 'Not Found', 'code' => 404],
            ], 404),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(0, $result->fetchedCount);
        $this->assertEquals(0, MailMessage::where('mail_account_id', $mailAccount->id)->count());
        $this->assertEquals('7050', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_falls_back_to_300_inbox_scan_on_404_expired_history_id_preserving_existing_data(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        // 1. Existing message with manual decision
        $existingMessage = MailMessage::create([
            'mail_account_id' => $mailAccount->id,
            'provider_message_id' => 'msg-existing-retained',
            'sender_email' => 'biuro@nofluffjobs.com',
            'sender_domain' => 'nofluffjobs.com',
            'subject' => 'Oferta Tech Lead',
            'sent_at' => now()->subDays(5),
            'category' => 'job_offer',
            'status' => 'relevant',
            'is_manually_classified' => true,
        ]);

        // 2. Sync state with expired historyId
        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => 'expired_history_id_99999',
            'last_synced_at' => now()->subDays(35),
        ]);

        Http::fake([
            // Incremental sync returns 404 -> expired historyId
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'error' => ['message' => 'History id not found', 'code' => 404],
            ], 404),

            // Fallback calls profile to get new baseline
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'candidate@gmail.com',
                'historyId' => 'new_baseline_88888',
            ], 200),

            // Fallback scans inbox (up to 300 messages)
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [
                    ['id' => 'msg-existing-retained', 'threadId' => 'th-1'],
                    ['id' => 'msg-new-after-fallback', 'threadId' => 'th-2'],
                ],
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-existing-retained?*' => Http::response([
                'id' => 'msg-existing-retained',
                'threadId' => 'th-1',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'biuro@nofluffjobs.com'],
                        ['name' => 'Subject', 'value' => 'Oferta Tech Lead'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 10:00:00 +0200'],
                    ],
                ],
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-new-after-fallback?*' => Http::response([
                'id' => 'msg-new-after-fallback',
                'threadId' => 'th-2',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <powiadomienia@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Nowa oferta pracy'],
                        ['name' => 'Date', 'value' => 'Thu, 15 Oct 2026 12:00:00 +0200'],
                    ],
                ],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(2, $result->fetchedCount);

        // Verify existing message manual classification was NOT overwritten
        $refreshedExisting = $existingMessage->fresh();
        $this->assertEquals('relevant', $refreshedExisting->status);
        $this->assertTrue($refreshedExisting->is_manually_classified);

        // Verify newly discovered message was created
        $this->assertEquals(1, MailMessage::where('provider_message_id', 'msg-new-after-fallback')->count());

        // Verify new historyId baseline was committed
        $this->assertEquals('new_baseline_88888', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_incremental_sync_failure_does_not_commit_cursor(): void
    {
        [$mailAccount] = $this->createGmailOAuthAccount();

        $syncState = MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => 'stable_history_id_500',
            'last_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            // Incremental sync API fails with 500
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'error' => ['message' => 'Internal Gmail Server Error', 'code' => 500],
            ], 500),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('500', $result->error ?? '');

        // Cursor MUST remain stable_history_id_500!
        $refreshedState = $syncState->fresh();
        $this->assertEquals('failed', $refreshedState->sync_status);
        $this->assertEquals('stable_history_id_500', $refreshedState->history_id);
        $this->assertNotNull($refreshedState->last_error);
    }

    public function test_initial_sync_failure_does_not_commit_cursor(): void
    {
        [$mailAccount] = $this->createGmailOAuthAccount();

        $syncState = MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => null,
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'candidate@gmail.com',
                'historyId' => 'baseline_id_999',
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'error' => ['message' => 'Gmail API Unavailable', 'code' => 503],
            ], 503),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertFalse($result->success);
        $refreshedState = $syncState->fresh();
        $this->assertEquals('failed', $refreshedState->sync_status);
        $this->assertNull($refreshedState->history_id);
    }

    public function test_incremental_sync_never_overwrites_manual_user_decisions(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        [$mailAccount] = $this->createGmailOAuthAccount();

        $message = MailMessage::create([
            'mail_account_id' => $mailAccount->id,
            'provider_message_id' => 'msg-manual-inc',
            'sender_email' => 'powiadomienia@pracuj.pl',
            'sender_domain' => 'pracuj.pl',
            'subject' => 'Rekomendowane oferty',
            'sent_at' => now()->subDay(),
            'category' => 'other',
            'status' => 'ignored', // User manually ignored it
            'is_manually_classified' => true,
        ]);

        MailSyncState::create([
            'mail_account_id' => $mailAccount->id,
            'sync_status' => 'idle',
            'history_id' => '8000',
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response([
                'historyId' => '8050',
                'history' => [
                    [
                        'id' => 'h-1',
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-manual-inc', 'threadId' => 'th-m', 'labelIds' => ['INBOX']]],
                        ],
                    ],
                ],
            ], 200),

            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-manual-inc?*' => Http::response([
                'id' => 'msg-manual-inc',
                'threadId' => 'th-m',
                'labelIds' => ['INBOX'],
                'internalDate' => '1713180000000',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Pracuj.pl <powiadomienia@pracuj.pl>'],
                        ['name' => 'Subject', 'value' => 'Rekomendowane oferty'],
                    ],
                ],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $refreshed = $message->fresh();
        $this->assertEquals('ignored', $refreshed->status);
        $this->assertEquals('other', $refreshed->category);
        $this->assertTrue($refreshed->is_manually_classified);
    }

    public function test_token_refresh_on_401_retries_and_succeeds(): void
    {
        [$mailAccount] = $this->createGmailOAuthAccount();

        $callCount = 0;
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response(['error' => ['message' => 'Invalid Credentials', 'code' => 401]], 401);
                }

                return Http::response(['emailAddress' => 'candidate@gmail.com', 'historyId' => '777888'], 200);
            },
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'refreshed_access_token_456',
                'expires_in' => 3600,
                'scope' => GoogleOAuthService::GMAIL_METADATA_SCOPE,
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [],
            ], 200),
        ]);

        $gmailProvider = new GmailSyncProvider();
        $syncService = app(MailSyncService::class);

        $result = $syncService->syncAccount($mailAccount, $gmailProvider);

        $this->assertTrue($result->success);
        $this->assertEquals('777888', $mailAccount->syncState->fresh()->history_id);
    }

    public function test_mail_sync_service_processes_all_has_more_batches_before_committing_cursor(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = MailAccount::create([
            'email' => 'multi@test.pl',
            'name' => 'Konto wielopaczkowe',
            'auth_type' => 'password',
            'provider' => 'wp',
            'status' => 'active',
        ]);

        $fakeProvider = new FakeMailSyncProvider();
        // Queue Batch 1 (hasMore = true, historyId = 'batch_1_cursor')
        $fakeProvider->queueBatch(new MailSyncBatchResult(
            messages: [
                new MailMessageMetadataDTO(
                    providerMessageId: 'multi-1',
                    senderEmail: 'kontakt@pracuj.pl',
                    subject: 'Oferta 1',
                    sentAt: now(),
                ),
            ],
            historyId: 'cursor_page_1',
            hasMore: true
        ));

        // Queue Batch 2 (hasMore = false, final historyId = 'cursor_page_2')
        $fakeProvider->queueBatch(new MailSyncBatchResult(
            messages: [
                new MailMessageMetadataDTO(
                    providerMessageId: 'multi-2',
                    senderEmail: 'kontakt@pracuj.pl',
                    subject: 'Oferta 2',
                    sentAt: now(),
                ),
            ],
            historyId: 'cursor_page_2',
            hasMore: false
        ));

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account, $fakeProvider);

        $this->assertTrue($result->success);
        $this->assertEquals(2, $result->fetchedCount);
        $this->assertEquals(2, $result->qualifiedCount);

        // Both messages saved
        $this->assertEquals(2, MailMessage::where('mail_account_id', $account->id)->count());

        // Final cursor committed
        $this->assertEquals('cursor_page_2', $account->syncState->fresh()->history_id);
    }

    public function test_mail_sync_service_does_not_commit_cursor_if_second_batch_fails(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = MailAccount::create([
            'email' => 'fail_batch@test.pl',
            'name' => 'Konto fail batch',
            'auth_type' => 'password',
            'provider' => 'wp',
            'status' => 'active',
        ]);

        $syncState = MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'history_id' => 'original_stable_cursor',
        ]);

        // Custom provider where batch 1 succeeds, batch 2 throws
        $provider = new class implements \App\Services\Mail\Contracts\MailSyncProviderInterface {
            private int $call = 0;

            public function supports(MailAccount $account): bool
            {
                return true;
            }

            public function fetchBatch(MailAccount $account, MailSyncState $state): MailSyncBatchResult
            {
                $this->call++;
                if ($this->call === 1) {
                    return new MailSyncBatchResult(
                        messages: [
                            new MailMessageMetadataDTO(
                                providerMessageId: 'batch-1-msg',
                                senderEmail: 'kontakt@pracuj.pl',
                                subject: 'Aplikacja wysłana',
                                sentAt: now(),
                            ),
                        ],
                        historyId: 'uncommitted_intermediate_cursor',
                        hasMore: true
                    );
                }

                throw new RuntimeException('Błąd sieci w paczce 2');
            }

            public function fetchMessageBody(MailAccount $account, string $providerMessageId): array
            {
                return ['success' => true];
            }
        };

        $syncService = app(MailSyncService::class);
        $result = $syncService->syncAccount($account, $provider);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Błąd sieci w paczce 2', $result->error ?? '');

        // Verify that intermediate cursor was NEVER committed!
        $this->assertEquals('original_stable_cursor', $syncState->fresh()->history_id);
        $this->assertEquals('failed', $syncState->fresh()->sync_status);
    }

    public function test_artisan_mail_sync_command_executes_successfully(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = MailAccount::create([
            'email' => 'cli_test@example.com',
            'name' => 'CLI Test Account',
            'auth_type' => 'password',
            'provider' => 'wp',
            'status' => 'active',
        ]);

        // Inject FakeMailSyncProvider into resolver
        $resolver = app(MailSyncProviderResolver::class);
        $fake = new FakeMailSyncProvider();
        $fake->setMessages([
            new MailMessageMetadataDTO(
                providerMessageId: 'cli-msg-1',
                senderEmail: 'kontakt@pracuj.pl',
                subject: 'Potwierdzenie aplikacji',
                sentAt: now(),
            ),
        ]);
        $resolver->setOverrideProvider($fake);

        // Run artisan command for specific account
        $this->artisan('mail:sync', ['account' => 'cli_test@example.com'])
            ->expectsOutputToContain('Rozpoczynanie synchronizacji konta: cli_test@example.com')
            ->expectsOutputToContain('Synchronizacja zakończona sukcesem!')
            ->assertSuccessful();

        $this->assertEquals(1, MailMessage::where('mail_account_id', $account->id)->count());

        // Run artisan command with non-existing account
        $this->artisan('mail:sync', ['account' => 'nonexistent@example.com'])
            ->expectsOutputToContain('Nie znaleziono konta pocztowego')
            ->assertFailed();
    }
}

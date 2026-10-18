<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncRule;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\DTO\MailMessageMetadataDTO;
use App\Services\Mail\MailRuleEvaluator;
use App\Services\Mail\Providers\FakeMailSyncProvider;
use App\Services\Mail\Providers\MailSyncProviderResolver;
use Database\Seeders\MailSyncRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MailInboxTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(string $email = 'candidate@example.com'): MailAccount
    {
        return MailAccount::create([
            'email' => $email,
            'name' => 'Konto rekrutacyjne',
            'auth_type' => 'password',
            'provider' => 'wp',
            'status' => 'active',
        ]);
    }

    public function test_models_and_relationships_work_correctly(): void
    {
        $account = $this->createAccount();

        $rule = MailSyncRule::create([
            'name' => 'Pracuj.pl alerty',
            'is_system' => false,
            'mail_account_id' => $account->id,
            'portal_name' => 'Pracuj.pl',
            'sender_domain' => 'pracuj.pl',
            'category' => 'recruitment',
            'action' => 'qualify',
            'priority' => 100,
            'is_active' => true,
        ]);

        $message = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg_12345',
            'thread_id' => 'th_12345',
            'message_id' => '<abc@pracuj.pl>',
            'sender_email' => 'powiadomienia@pracuj.pl',
            'sender_name' => 'Pracuj.pl',
            'sender_domain' => 'pracuj.pl',
            'subject' => 'Nowa odpowiedź na Twoją aplikację',
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'to_review',
            'matched_rule_id' => $rule->id,
        ]);

        $syncState = MailSyncState::create([
            'mail_account_id' => $account->id,
            'sync_status' => 'idle',
            'total_messages_count' => 1,
            'relevant_messages_count' => 0,
            'ignored_messages_count' => 0,
        ]);

        $this->assertEquals($account->id, $message->mailAccount->id);
        $this->assertEquals($rule->id, $message->matchedRule->id);
        $this->assertEquals(1, $rule->matchedMessages()->count());
        $this->assertEquals($syncState->id, $account->syncState->id);
        $this->assertEquals(1, $account->messages()->count());
    }

    public function test_message_deduplication_by_account_and_provider_message_id(): void
    {
        $account = $this->createAccount();

        MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'unique_msg_id_100',
            'sender_email' => 'hr@company.com',
            'sender_domain' => 'company.com',
            'subject' => 'Zaproszenie na rozmowę',
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'to_review',
        ]);

        $this->expectException(QueryException::class);

        // Attempting duplicate insert with same mail_account_id and provider_message_id
        MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'unique_msg_id_100',
            'sender_email' => 'hr@company.com',
            'sender_domain' => 'company.com',
            'subject' => 'Duplikat wiadomości',
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'to_review',
        ]);
    }

    public function test_seeder_is_idempotent_and_preserves_user_toggle_state(): void
    {
        $this->seed(MailSyncRuleSeeder::class);

        $initialCount = MailSyncRule::count();
        $this->assertGreaterThan(10, $initialCount);

        // User turns off a system rule
        $pracujRule = MailSyncRule::where('system_key', 'system:pracuj')->firstOrFail();
        $this->assertTrue($pracujRule->is_active);

        $pracujRule->update(['is_active' => false]);
        $this->assertFalse($pracujRule->fresh()->is_active);

        // Re-run seeder
        $this->seed(MailSyncRuleSeeder::class);

        $afterCount = MailSyncRule::count();
        $this->assertEquals($initialCount, $afterCount);

        // Verify that is_active is STILL false (not overwritten!)
        $this->assertFalse($pracujRule->fresh()->is_active);
    }

    public function test_rule_evaluator_strict_priority_manual_then_user_then_system(): void
    {
        $evaluator = new MailRuleEvaluator();
        $account = $this->createAccount();

        // Seed system rules (e.g. Pracuj.pl qualifies domain 'pracuj.pl')
        $this->seed(MailSyncRuleSeeder::class);

        // Scenario 1: System rule matches
        $resSystem = $evaluator->evaluate('oferty@pracuj.pl', 'Nowe oferty pracy');
        $this->assertEquals('system_rule', $resSystem['source']);
        $this->assertEquals('to_review', $resSystem['status']);

        // Scenario 2: User custom rule overrides system rule (e.g. user wants to ignore pracuj newsletter)
        $userRule = MailSyncRule::create([
            'name' => 'Ignoruj newslettery pracuj',
            'is_system' => false,
            'sender_domain' => 'pracuj.pl',
            'subject_keywords' => ['newsletter', 'przegląd ofert'],
            'category' => 'other',
            'action' => 'ignore',
            'priority' => 200,
            'is_active' => true,
        ]);

        $resUser = $evaluator->evaluate('oferty@pracuj.pl', 'Tygodniowy newsletter ofert pracy');
        $this->assertEquals('user_rule', $resUser['source']);
        $this->assertEquals('ignored', $resUser['status']);
        $this->assertEquals($userRule->id, $resUser['matched_rule']->id);

        // Scenario 3: Manual user decision takes absolute precedence
        $manualMessage = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg_manual_1',
            'sender_email' => 'oferty@pracuj.pl',
            'sender_domain' => 'pracuj.pl',
            'subject' => 'Tygodniowy newsletter ofert pracy',
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'relevant', // Manually set to relevant
            'is_manually_classified' => true,
        ]);

        $resManual = $evaluator->evaluate(
            'oferty@pracuj.pl',
            'Tygodniowy newsletter ofert pracy',
            $account->id,
            $manualMessage
        );

        $this->assertEquals('manual', $resManual['source']);
        $this->assertEquals('relevant', $resManual['status']);
    }

    public function test_mail_messages_api_listing_filtering_and_status_update(): void
    {
        $account1 = $this->createAccount('first@test.pl');
        $account2 = $this->createAccount('second@test.pl');

        $msg1 = MailMessage::create([
            'mail_account_id' => $account1->id,
            'provider_message_id' => 'm1',
            'sender_email' => 'kontakt@pracuj.pl',
            'sender_name' => 'Pracuj.pl',
            'sender_domain' => 'pracuj.pl',
            'subject' => 'Zaproszenie na spotkanie rekrutacyjne',
            'sent_at' => now()->subDay(),
            'category' => 'recruitment',
            'status' => 'to_review',
        ]);

        $msg2 = MailMessage::create([
            'mail_account_id' => $account2->id,
            'provider_message_id' => 'm2',
            'sender_email' => 'hr@google.com',
            'sender_name' => 'Google Recruiter',
            'sender_domain' => 'google.com',
            'subject' => 'Interview with Google',
            'sent_at' => now(),
            'category' => 'job_offer',
            'status' => 'relevant',
        ]);

        // 1. List all messages
        $res = $this->getJson('/api/mail-messages');
        $res->assertOk();
        $res->assertJsonCount(2, 'messages');
        $res->assertJsonPath('counts.total', 2);
        $res->assertJsonPath('counts.to_review', 1);
        $res->assertJsonPath('counts.relevant', 1);

        // 2. Filter by account
        $resFiltered = $this->getJson('/api/mail-messages?mail_account_id=' . $account1->id);
        $resFiltered->assertOk();
        $resFiltered->assertJsonCount(1, 'messages');
        $resFiltered->assertJsonPath('messages.0.id', $msg1->id);

        // 3. Filter by search
        $resSearch = $this->getJson('/api/mail-messages?search=Google');
        $resSearch->assertOk();
        $resSearch->assertJsonCount(1, 'messages');
        $resSearch->assertJsonPath('messages.0.id', $msg2->id);

        // 4. Update message status manually
        $patchRes = $this->patchJson('/api/mail-messages/' . $msg1->id . '/status', [
            'status' => 'relevant',
        ]);
        $patchRes->assertOk();
        $this->assertEquals('relevant', $msg1->fresh()->status);
        $this->assertTrue($msg1->fresh()->is_manually_classified);
    }

    public function test_rules_api_crud_and_system_protection(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createAccount();

        // 1. List rules
        $listRes = $this->getJson('/api/mail-sync-rules');
        $listRes->assertOk();
        $rules = $listRes->json('rules');
        $this->assertNotEmpty($rules);

        // 2. Create custom rule
        $createRes = $this->postJson('/api/mail-sync-rules', [
            'name' => 'Reguła No Fluff Jobs IT',
            'mail_account_id' => $account->id,
            'portal_name' => 'No Fluff Jobs',
            'sender_domain' => 'nofluffjobs.com',
            'subject_keywords' => ['Frontend Developer', 'Vue'],
            'category' => 'job_offer',
            'action' => 'qualify',
            'priority' => 120,
            'is_active' => true,
        ]);
        $createRes->assertCreated();
        $createdRuleId = $createRes->json('rule.id');

        // 3. Update custom rule
        $updateRes = $this->putJson('/api/mail-sync-rules/' . $createdRuleId, [
            'name' => 'Zaktualizowana reguła NFJ',
            'category' => 'recruitment',
            'action' => 'qualify',
            'sender_domain' => 'nofluffjobs.com',
        ]);
        $updateRes->assertOk();
        $this->assertEquals('Zaktualizowana reguła NFJ', MailSyncRule::find($createdRuleId)->name);

        // 4. Toggle rule
        $toggleRes = $this->patchJson('/api/mail-sync-rules/' . $createdRuleId . '/toggle');
        $toggleRes->assertOk();
        $this->assertFalse(MailSyncRule::find($createdRuleId)->is_active);

        // 5. Delete custom rule
        $deleteRes = $this->deleteJson('/api/mail-sync-rules/' . $createdRuleId);
        $deleteRes->assertOk();
        $this->assertNull(MailSyncRule::find($createdRuleId));

        // 6. Attempt to delete a system rule -> should be rejected with 422
        $systemRule = MailSyncRule::where('is_system', true)->firstOrFail();
        $deleteSystemRes = $this->deleteJson('/api/mail-sync-rules/' . $systemRule->id);
        $deleteSystemRes->assertStatus(422);
        $this->assertNotNull(MailSyncRule::find($systemRule->id));

        // 7. System rule toggle works
        $toggleSystemRes = $this->patchJson('/api/mail-sync-rules/' . $systemRule->id . '/toggle');
        $toggleSystemRes->assertOk();
    }

    public function test_sync_states_api_returns_accounts_and_idle_status(): void
    {
        $account = $this->createAccount('sync_test@wp.pl');

        $res = $this->getJson('/api/mail-sync-states');
        $res->assertOk();

        $accounts = $res->json('accounts');
        $this->assertCount(1, $accounts);
        $this->assertEquals($account->id, $accounts[0]['account']['id']);
        $this->assertEquals('idle', $accounts[0]['sync_state']['sync_status']);
        $this->assertEquals('Synchronizacja nie została jeszcze uruchomiona', $accounts[0]['status_message']);
        $this->assertEquals('every_5_minutes', $accounts[0]['schedule_interval']);
    }

    public function test_mail_inbox_desktop_route_renders_successfully(): void
    {
        $response = $this->get('/mail');
        $response->assertOk();
    }

    public function test_manual_sync_endpoint_successfully_syncs_account_and_returns_counts(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createAccount('manual_sync@example.com');

        $fakeProvider = new FakeMailSyncProvider();
        $fakeProvider->setMessages(
            messages: [
                new MailMessageMetadataDTO(
                    providerMessageId: 'manual-msg-1',
                    senderEmail: 'rekrutacja@pracuj.pl',
                    subject: 'Twoja aplikacja na stanowisko Software Engineer',
                    sentAt: now()->subMinutes(15),
                    messageId: '<m1@pracuj.pl>',
                    senderName: 'Pracuj.pl',
                ),
                new MailMessageMetadataDTO(
                    providerMessageId: 'manual-msg-2',
                    senderEmail: 'newsletter@spam.pl',
                    subject: 'Codzienny newsletter sklepu',
                    sentAt: now()->subMinutes(10),
                ),
            ],
            imapLastUid: 50,
            imapUidvalidity: 100
        );

        app(MailSyncProviderResolver::class)->setOverrideProvider($fakeProvider);

        $response = $this->postJson('/api/mail-accounts/' . $account->id . '/sync');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'result' => [
                'fetched_count' => 2,
                'qualified_count' => 1,
                'ignored_count' => 0,
                'unmatched_count' => 1,
            ],
        ]);

        $this->assertDatabaseHas('mail_messages', [
            'mail_account_id' => $account->id,
            'provider_message_id' => 'manual-msg-1',
            'status' => 'to_review',
        ]);

        $syncState = $account->syncState()->first();
        $this->assertNotNull($syncState);
        $this->assertEquals('idle', $syncState->sync_status);
        $this->assertNotNull($syncState->last_synced_at);
        $this->assertEquals(1, $syncState->total_messages_count);
    }

    public function test_manual_sync_endpoint_fails_if_account_is_inactive(): void
    {
        $account = $this->createAccount('inactive@example.com');
        $account->update(['status' => 'inactive']);

        $response = $this->postJson('/api/mail-accounts/' . $account->id . '/sync');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Konto pocztowe nie jest aktywne ani połączone. Sprawdź konfigurację konta w Ustawieniach.',
        ]);
    }

    public function test_manual_sync_endpoint_accepts_connected_account(): void
    {
        $this->seed(MailSyncRuleSeeder::class);
        $account = $this->createAccount('connected_sync@example.com');
        $account->update(['status' => 'connected']);

        $fakeProvider = new FakeMailSyncProvider();
        $fakeProvider->setMessages(messages: []);
        app(MailSyncProviderResolver::class)->setOverrideProvider($fakeProvider);

        $response = $this->postJson('/api/mail-accounts/' . $account->id . '/sync');
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_manual_sync_endpoint_returns_409_when_sync_already_in_progress(): void
    {
        $account = $this->createAccount('locked@example.com');

        // Simulate an active atomic lock for this account
        $lock = Cache::lock("mail-sync-account-{$account->id}", 300);
        $this->assertTrue($lock->acquire());

        try {
            $response = $this->postJson('/api/mail-accounts/' . $account->id . '/sync');

            $response->assertStatus(409);
            $response->assertJson([
                'success' => false,
            ]);
            $this->assertStringContainsString('w toku', $response->json('message'));
        } finally {
            $lock->release();
        }
    }

    public function test_toggle_auto_sync_endpoint_updates_account_auto_sync_flag(): void
    {
        $account = $this->createAccount('autosync@example.com');
        $this->assertFalse((bool) $account->auto_sync);

        // Turn on
        $resOn = $this->patchJson('/api/mail-accounts/' . $account->id . '/toggle-auto-sync', [
            'auto_sync' => true,
        ]);
        $resOn->assertOk();
        $this->assertTrue((bool) $account->fresh()->auto_sync);

        // Turn off
        $resOff = $this->patchJson('/api/mail-accounts/' . $account->id . '/toggle-auto-sync', [
            'auto_sync' => false,
        ]);
        $resOff->assertOk();
        $this->assertFalse((bool) $account->fresh()->auto_sync);
    }

    public function test_show_message_returns_full_metadata_and_body(): void
    {
        $account = $this->createAccount('show@example.com');

        $message = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg_show_1',
            'subject' => 'Ważna informacja rekrutacyjna',
            'sender_email' => 'hr@recruitment.com',
            'sender_name' => 'HR Team',
            'sender_domain' => 'recruitment.com',
            'snippet' => 'Skrót wiadomości rekrutacyjnej...',
            'body_text' => 'Pełna treść tekstowa wiadomości.',
            'body_html' => '<p>Pełna treść tekstowa wiadomości.</p>',
            'to_email' => 'candidate@example.com',
            'has_attachments' => true,
            'attachments' => [
                ['filename' => 'zadanie.pdf', 'size' => 1024, 'mime' => 'application/pdf'],
            ],
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'to_review',
        ]);

        $response = $this->getJson('/api/mail-messages/' . $message->id);

        $response->assertOk();
        $response->assertJsonPath('mail_message.id', $message->id);
        $response->assertJsonPath('mail_message.subject', 'Ważna informacja rekrutacyjna');
        $response->assertJsonPath('mail_message.snippet', 'Skrót wiadomości rekrutacyjnej...');
        $response->assertJsonPath('mail_message.body_text', 'Pełna treść tekstowa wiadomości.');
        $response->assertJsonPath('mail_message.body_html', '<p>Pełna treść tekstowa wiadomości.</p>');
        $response->assertJsonPath('mail_message.has_attachments', true);
        $response->assertJsonPath('mail_message.attachments.0.filename', 'zadanie.pdf');
    }

    public function test_fetch_message_body_endpoint_fetches_and_persists_body(): void
    {
        $account = $this->createAccount('fetch@example.com');

        $message = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg_fetch_1',
            'subject' => 'Zaproszenie na spotkanie techniczne',
            'sender_email' => 'lead@recruitment.com',
            'sender_domain' => 'recruitment.com',
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'to_review',
        ]);

        $fakeProvider = new FakeMailSyncProvider();
        app(MailSyncProviderResolver::class)->setOverrideProvider($fakeProvider);

        $response = $this->postJson('/api/mail-messages/' . $message->id . '/fetch-body');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('mail_message.snippet', 'Testowy podgląd wiadomości e-mail');
        $this->assertStringContainsString('To jest testowa treść wiadomości rekrutacyjnej', (string) $response->json('mail_message.body_text'));

        $fresh = $message->fresh();
        $this->assertNotNull($fresh->body_fetched_at);
        $this->assertEquals('Testowy podgląd wiadomości e-mail', $fresh->snippet);
        $this->assertStringContainsString('To jest testowa treść', (string) $fresh->body_text);
    }

    public function test_fetch_message_body_endpoint_handles_requires_reauth(): void
    {
        $account = $this->createAccount('reauth@example.com');

        $message = MailMessage::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg_reauth_1',
            'subject' => 'Brak uprawnień do treści',
            'sender_email' => 'lead@recruitment.com',
            'sender_domain' => 'recruitment.com',
            'sent_at' => now(),
            'category' => 'recruitment',
            'status' => 'to_review',
        ]);

        $fakeProvider = (new FakeMailSyncProvider())->setCustomBodyResponse([
            'success' => false,
            'requires_reauth' => true,
            'error' => 'Konto wymaga ponownej autoryzacji Google',
        ]);
        app(MailSyncProviderResolver::class)->setOverrideProvider($fakeProvider);

        $response = $this->postJson('/api/mail-messages/' . $message->id . '/fetch-body');

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('requires_reauth', true);
        $this->assertStringContainsString('ponownej autoryzacji', (string) $response->json('error'));
    }
}

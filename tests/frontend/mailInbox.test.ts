import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mailInboxService } from '../../resources/js/services/mailInbox.ts';

if (!globalThis.document) {
    Object.defineProperty(globalThis, 'document', {
        value: { cookie: '' },
        configurable: true,
    });
}

test('mail inbox service fetches messages and builds query string correctly', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string; body?: unknown }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            calls.push({ url, method });

            return new Response(
                JSON.stringify({
                    messages: [
                        {
                            id: 'msg-1',
                            mail_account_id: 'acc-1',
                            provider_message_id: 'prov-1',
                            sender_email: 'kontakt@pracuj.pl',
                            sender_domain: 'pracuj.pl',
                            subject: 'Zaproszenie na spotkanie',
                            sent_at: '2026-10-15T10:00:00Z',
                            category: 'recruitment',
                            status: 'to_review',
                        },
                    ],
                    counts: {
                        total: 1,
                        new: 0,
                        to_review: 1,
                        relevant: 0,
                        ignored: 0,
                        job_offer: 0,
                        recruitment: 1,
                    },
                }),
                { status: 200 }
            );
        };

        const res = await mailInboxService.getMessages({
            mail_account_id: 'acc-1',
            status: 'to_review',
            search: 'spotkanie',
        });

        assert.equal(calls.length, 1);
        assert.ok(calls[0]?.url.includes('/api/mail-messages'));
        assert.ok(calls[0]?.url.includes('mail_account_id=acc-1'));
        assert.ok(calls[0]?.url.includes('status=to_review'));
        assert.ok(calls[0]?.url.includes('search=spotkanie'));
        assert.equal(res.messages.length, 1);
        assert.equal(res.counts.total, 1);

    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail inbox service updates message status manually', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string; body?: unknown }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            const body = init?.body ? JSON.parse(String(init.body)) : undefined;
            calls.push({ url, method, body });

            return new Response(
                JSON.stringify({
                    message: 'Status wiadomości został zaktualizowany.',
                    mail_message: {
                        id: 'msg-1',
                        status: body.status,
                        is_manually_classified: true,
                    },
                }),
                { status: 200 }
            );
        };

        const res = await mailInboxService.updateMessageStatus('msg-1', 'relevant');

        assert.equal(calls.length, 1);
        assert.equal(calls[0]?.url, '/api/mail-messages/msg-1/status');
        assert.equal(calls[0]?.method, 'PATCH');
        assert.deepEqual(calls[0]?.body, { status: 'relevant' });
        assert.equal(res.mail_message.status, 'relevant');

        assert.equal(res.mail_message.is_manually_classified, true);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail inbox service manages sync rules (CRUD + toggle)', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string; body?: unknown }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            const body = init?.body ? JSON.parse(String(init.body)) : undefined;
            calls.push({ url, method, body });

            if (url === '/api/mail-sync-rules' && method === 'GET') {
                return new Response(
                    JSON.stringify({
                        rules: [
                            {
                                id: 'rule-sys',
                                name: 'Pracuj.pl',
                                is_system: true,
                                is_active: true,
                            },
                        ],
                    })
                );
            }

            if (url === '/api/mail-sync-rules' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        message: 'Reguła została dodana.',
                        rule: {
                            id: 'rule-custom',
                            name: body.name,
                            is_system: false,
                            is_active: true,
                        },
                    }),
                    { status: 201 }
                );
            }

            if (url === '/api/mail-sync-rules/rule-custom/toggle' && method === 'PATCH') {
                return new Response(
                    JSON.stringify({
                        message: 'Reguła wyłączona.',
                        rule: {
                            id: 'rule-custom',
                            is_active: false,
                        },
                    })
                );
            }

            if (url === '/api/mail-sync-rules/rule-custom' && method === 'DELETE') {
                return new Response(
                    JSON.stringify({ message: 'Reguła została usunięta.' })
                );
            }

            return new Response('Not found', { status: 404 });
        };

        // 1. Get rules
        const rulesRes = await mailInboxService.getRules();
        assert.equal(rulesRes.rules.length, 1);
        assert.equal(rulesRes.rules[0]?.is_system, true);

        // 2. Create rule
        const created = await mailInboxService.createRule({
            name: 'Moja reguła',
            category: 'job_offer',
            action: 'qualify',
            sender_domain: 'mojafirma.pl',
        });
        assert.equal(created.rule.id, 'rule-custom');

        // 3. Toggle rule
        const toggled = await mailInboxService.toggleRule('rule-custom');
        assert.equal(toggled.rule.is_active, false);

        // 4. Delete rule
        const deleted = await mailInboxService.deleteRule('rule-custom');
        assert.equal(deleted.message, 'Reguła została usunięta.');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail inbox service fetches sync states and schedule info', async () => {
    const originalFetch = globalThis.fetch;

    try {
        globalThis.fetch = async () => {
            return new Response(
                JSON.stringify({
                    accounts: [
                        {
                            account: {
                                id: 'acc-1',
                                email: 'test@example.com',
                                status: 'active',
                            },
                            sync_state: {
                                id: 'state-1',
                                sync_status: 'idle',
                                last_synced_at: null,
                                total_messages_count: 0,
                                relevant_messages_count: 0,
                            },
                            schedule_interval: 'every_5_minutes',
                            status_message: 'Synchronizacja nie została jeszcze uruchomiona',
                        },
                    ],
                    summary: {
                        total_accounts: 1,
                        active_accounts: 1,
                        total_messages: 0,
                        relevant_messages: 0,
                    },
                })
            );
        };

        const res = await mailInboxService.getSyncStates();
        assert.equal(res.accounts.length, 1);
        assert.equal(res.accounts[0]?.status_message, 'Synchronizacja nie została jeszcze uruchomiona');
        assert.equal(res.accounts[0]?.schedule_interval, 'every_5_minutes');
        assert.equal(res.accounts[0]?.sync_state.sync_status, 'idle');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail inbox service triggers manual sync and toggles auto-sync', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string; body?: unknown }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            const body = init?.body ? JSON.parse(String(init.body)) : undefined;
            calls.push({ url, method, body });

            if (url === '/api/mail-accounts/acc-1/sync' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        success: true,
                        message: 'Synchronizacja konta została ukończona.',
                        result: {
                            fetched_count: 5,
                            qualified_count: 2,
                            ignored_count: 0,
                            unmatched_count: 3,
                        },
                        sync_state: {
                            id: 'state-1',
                            mail_account_id: 'acc-1',
                            sync_status: 'success',
                            last_synced_at: '2026-10-15T14:30:00Z',
                            total_messages_count: 5,
                            relevant_messages_count: 2,
                            ignored_messages_count: 0,
                            to_review_messages_count: 3,
                            last_batch_count: 2,
                            history_id: null,
                            imap_last_uid: null,
                            imap_uidvalidity: null,
                            last_error: null,
                            last_error_at: null,
                        },
                    }),
                    { status: 200 }
                );
            }

            if (url === '/api/mail-accounts/acc-1/toggle-auto-sync' && method === 'PATCH') {
                return new Response(
                    JSON.stringify({
                        id: 'acc-1',
                        email: 'user@wp.pl',
                        name: 'Moje konto',
                        provider: 'wp',
                        auth_type: 'password',
                        status: 'active',
                        auto_sync: body.auto_sync,
                    }),
                    { status: 200 }
                );
            }

            return new Response('Not found', { status: 404 });
        };

        // 1. Manual sync
        const syncResult = await mailInboxService.syncAccount('acc-1');
        assert.equal(syncResult.success, true);
        assert.equal(syncResult.result?.fetched_count, 5);
        assert.equal(syncResult.result?.qualified_count, 2);
        assert.equal(syncResult.sync_state?.sync_status, 'success');

        // 2. Toggle auto-sync
        const toggleResult = await mailInboxService.toggleAutoSync('acc-1', true);
        assert.equal(toggleResult.auto_sync, true);

        assert.equal(calls.length, 2);
        assert.equal(calls[0]?.url, '/api/mail-accounts/acc-1/sync');
        assert.equal(calls[0]?.method, 'POST');
        assert.equal(calls[1]?.url, '/api/mail-accounts/acc-1/toggle-auto-sync');
        assert.equal(calls[1]?.method, 'PATCH');
        assert.deepEqual(calls[1]?.body, { auto_sync: true });
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail inbox service fetches message details and fetches message body', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            calls.push({ url, method });

            if (url === '/api/mail-messages/msg-123' && method === 'GET') {
                return new Response(
                    JSON.stringify({
                        mail_message: {
                            id: 'msg-123',
                            subject: 'Zaproszenie na spotkanie',
                            snippet: 'Krótki opis...',
                            body_text: null,
                            body_html: null,
                            status: 'to_review',
                        },
                    }),
                    { status: 200 }
                );
            }

            if (url === '/api/mail-messages/msg-123/fetch-body' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        success: true,
                        mail_message: {
                            id: 'msg-123',
                            subject: 'Zaproszenie na spotkanie',
                            snippet: 'Krótki opis...',
                            body_text: 'Pełna treść e-maila',
                            body_html: '<p>Pełna treść e-maila</p>',
                            status: 'to_review',
                            body_fetched_at: '2026-10-15T15:00:00Z',
                        },
                    }),
                    { status: 200 }
                );
            }

            return new Response('Not found', { status: 404 });
        };

        // 1. Get message
        const messageRes = await mailInboxService.getMessage('msg-123');
        assert.equal(messageRes.mail_message.id, 'msg-123');
        assert.equal(messageRes.mail_message.subject, 'Zaproszenie na spotkanie');
        assert.equal(messageRes.mail_message.body_text, null);

        // 2. Fetch body
        const fetchRes = await mailInboxService.fetchMessageBody('msg-123');
        assert.equal(fetchRes.success, true);
        assert.equal(fetchRes.mail_message.body_text, 'Pełna treść e-maila');
        assert.equal(fetchRes.mail_message.body_html, '<p>Pełna treść e-maila</p>');

        assert.equal(calls.length, 2);
        assert.equal(calls[0]?.url, '/api/mail-messages/msg-123');
        assert.equal(calls[0]?.method, 'GET');
        assert.equal(calls[1]?.url, '/api/mail-messages/msg-123/fetch-body');
        assert.equal(calls[1]?.method, 'POST');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

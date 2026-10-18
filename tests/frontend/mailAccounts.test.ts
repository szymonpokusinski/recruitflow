import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mailAccountsService } from '../../resources/js/services/mailAccounts.ts';

if (!globalThis.document) {
    Object.defineProperty(globalThis, 'document', {
        value: { cookie: '' },
        configurable: true,
    });
}

test('mail accounts service handles CRUD requests correctly', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string; body?: unknown }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            const body = init?.body ? JSON.parse(String(init.body)) : undefined;
            calls.push({ url, method, body });

            if (url === '/api/mail-accounts' && method === 'GET') {
                return new Response(
                    JSON.stringify([
                        {
                            id: 'acc-1',
                            email: 'recruiter@company.com',
                            auth_type: 'password',
                            status: 'active',
                        },
                    ]),
                );
            }

            if (url === '/api/mail-accounts' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        id: 'acc-2',
                        email: body.email,
                        auth_type: 'password',
                        status: 'active',
                    }),
                    { status: 201 },
                );
            }

            if (url === '/api/mail-accounts/acc-2' && method === 'PUT') {
                return new Response(
                    JSON.stringify({
                        id: 'acc-2',
                        email: body.email,
                        auth_type: 'password',
                        status: 'active',
                    }),
                );
            }

            if (url === '/api/mail-accounts/acc-2' && method === 'DELETE') {
                return new Response(
                    JSON.stringify({ message: 'Konto pocztowe zostało usunięte.' }),
                );
            }

            return new Response('Not found', { status: 404 });
        };

        // 1. List
        const list = await mailAccountsService.list();
        assert.equal(list.length, 1);
        assert.equal(list[0]?.email, 'recruiter@company.com');

        // 2. Create
        const created = await mailAccountsService.create({
            email: 'new@company.com',
            password: 'secret',
        });
        assert.equal(created.id, 'acc-2');
        assert.equal(created.email, 'new@company.com');

        // 3. Update
        const updated = await mailAccountsService.update('acc-2', {
            email: 'updated@company.com',
        });
        assert.equal(updated.email, 'updated@company.com');

        // 4. Delete
        const deleted = await mailAccountsService.delete('acc-2');
        assert.equal(deleted.message, 'Konto pocztowe zostało usunięte.');

        assert.equal(calls.length, 4);
        assert.equal(calls[0]?.method, 'GET');
        assert.equal(calls[1]?.method, 'POST');
        assert.equal(calls[2]?.method, 'PUT');
        assert.equal(calls[3]?.method, 'DELETE');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail accounts service resolves provider from email', async () => {
    const originalFetch = globalThis.fetch;

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const body = init?.body ? JSON.parse(String(init.body)) : {};

            if (url === '/api/mail-accounts/resolve-provider') {
                if (body.email === 'test@wp.pl') {
                    return new Response(
                        JSON.stringify({
                            provider: 'wp',
                            name: 'Poczta WP',
                            is_known: true,
                            domain: 'wp.pl',
                        }),
                    );
                }
                if (body.email === 'test@firma.pl') {
                    return new Response(
                        JSON.stringify({
                            provider: 'generic',
                            name: 'Własny / Nieznany dostawca',
                            is_known: false,
                            domain: 'firma.pl',
                        }),
                    );
                }
            }

            return new Response('Not found', { status: 404 });
        };

        const wpResult = await mailAccountsService.resolveProvider('test@wp.pl');
        assert.equal(wpResult.provider, 'wp');
        assert.equal(wpResult.name, 'Poczta WP');
        assert.equal(wpResult.is_known, true);

        const genericResult = await mailAccountsService.resolveProvider('test@firma.pl');
        assert.equal(genericResult.provider, 'generic');
        assert.equal(genericResult.is_known, false);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail accounts service handles connection testing', async () => {
    const originalFetch = globalThis.fetch;

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';

            if (url === '/api/mail-accounts/acc-wp/test' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        success: true,
                        message: 'Połączenie z serwerem pocztowym powiodło się.',
                        error: null,
                        error_code: null,
                        provider: 'wp',
                        status: 'connected',
                        last_tested_at: '2026-10-15T10:00:00Z',
                        last_test_result: 'success',
                    }),
                );
            }

            return new Response('Not found', { status: 404 });
        };

        const result = await mailAccountsService.test('acc-wp');
        assert.equal(result.success, true);
        assert.equal(result.status, 'connected');
        assert.equal(result.provider, 'wp');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail accounts service handles Google OAuth methods', async () => {
    const originalFetch = globalThis.fetch;
    const calls: { url: string; method: string; body?: unknown }[] = [];

    try {
        globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const method = init?.method ?? 'GET';
            const body = init?.body ? JSON.parse(String(init.body)) : undefined;
            calls.push({ url, method, body });

            if (url === '/api/mail-accounts/google/connect' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        flow_id: 'flow-123',
                        authorization_url: 'https://accounts.google.com/o/oauth2/auth?...',
                        status: 'pending',
                    }),
                );
            }

            if (url === '/api/mail-accounts/google/flow/flow-123' && method === 'GET') {
                return new Response(
                    JSON.stringify({
                        status: 'connected',
                        message: 'Konto Gmail zostało pomyślnie połączone przez Google OAuth2.',
                    }),
                );
            }

            if (url === '/api/mail-accounts/acc-gmail/disconnect-oauth' && method === 'POST') {
                return new Response(
                    JSON.stringify({
                        message: 'Konto Gmail zostało pomyślnie odłączone.',
                    }),
                );
            }

            return new Response('Not found', { status: 404 });
        };

        const connectRes = await mailAccountsService.connectGoogle('user@gmail.com');
        assert.equal(connectRes.flow_id, 'flow-123');
        assert.equal(connectRes.status, 'pending');

        const flowRes = await mailAccountsService.checkGoogleFlow('flow-123');
        assert.equal(flowRes.status, 'connected');

        const disconnectRes = await mailAccountsService.disconnectOAuth('acc-gmail');
        assert.equal(disconnectRes.message, 'Konto Gmail zostało pomyślnie odłączone.');

        assert.equal(calls.length, 3);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('mail accounts service handles manual sync and toggleAutoSync', async () => {
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
                            fetched_count: 10,
                            qualified_count: 3,
                            ignored_count: 1,
                            unmatched_count: 6,
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

        const syncRes = await mailAccountsService.sync('acc-1');
        assert.equal(syncRes.success, true);
        assert.equal(syncRes.result?.fetched_count, 10);

        const toggleRes = await mailAccountsService.toggleAutoSync('acc-1', false);
        assert.equal(toggleRes.auto_sync, false);

        assert.equal(calls.length, 2);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

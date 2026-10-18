import { api } from './http.ts';
import type { MailAccount } from '../types/mailAccounts.ts';
import type {
    FetchBodyResponse,
    MailMessage,
    MailMessagesResponse,
    MailMessageStatus,
    MailSyncRule,
    MailSyncStatesResponse,
    ManualSyncResponse,
    SaveMailRulePayload,
} from '../types/mailInbox.ts';

export const mailInboxService = {
    getMessages: (params?: { mail_account_id?: string; category?: string; status?: string; search?: string }) => {
        const query = new URLSearchParams();
        if (params?.mail_account_id) query.set('mail_account_id', params.mail_account_id);
        if (params?.category) query.set('category', params.category);
        if (params?.status) query.set('status', params.status);
        if (params?.search) query.set('search', params.search);
        const qs = query.toString();
        return api<MailMessagesResponse>(`/api/mail-messages${qs ? `?${qs}` : ''}`);
    },

    getMessage: (id: string) =>
        api<{ mail_message: MailMessage }>(`/api/mail-messages/${encodeURIComponent(id)}`),

    fetchMessageBody: (id: string) =>
        api<FetchBodyResponse>(
            `/api/mail-messages/${encodeURIComponent(id)}/fetch-body`,
            'POST'
        ),

    updateMessageStatus: (id: string, status: MailMessageStatus) =>
        api<{ message: string; mail_message: MailMessage }>(
            `/api/mail-messages/${encodeURIComponent(id)}/status`,
            'PATCH',
            { status }
        ),

    getRules: () =>
        api<{ rules: MailSyncRule[] }>('/api/mail-sync-rules'),

    createRule: (payload: SaveMailRulePayload) =>
        api<{ message: string; rule: MailSyncRule }>('/api/mail-sync-rules', 'POST', payload),

    updateRule: (id: string, payload: Partial<SaveMailRulePayload>) =>
        api<{ message: string; rule: MailSyncRule }>(
            `/api/mail-sync-rules/${encodeURIComponent(id)}`,
            'PUT',
            payload
        ),

    deleteRule: (id: string) =>
        api<{ message: string }>(`/api/mail-sync-rules/${encodeURIComponent(id)}`, 'DELETE'),

    toggleRule: (id: string) =>
        api<{ message: string; rule: MailSyncRule }>(
            `/api/mail-sync-rules/${encodeURIComponent(id)}/toggle`,
            'PATCH'
        ),

    getSyncStates: () =>
        api<MailSyncStatesResponse>('/api/mail-sync-states'),

    syncAccount: (accountId: string) =>
        api<ManualSyncResponse>(
            `/api/mail-accounts/${encodeURIComponent(accountId)}/sync`,
            'POST'
        ),

    toggleAutoSync: (accountId: string, autoSync?: boolean) =>
        api<MailAccount>(
            `/api/mail-accounts/${encodeURIComponent(accountId)}/toggle-auto-sync`,
            'PATCH',
            autoSync !== undefined ? { auto_sync: autoSync } : {}
        ),
};

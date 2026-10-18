import { api } from './http.ts';
import type {
    GoogleOAuthConnectResponse,
    GoogleOAuthFlowStatusResponse,
    MailAccount,
    MailAccountConnectionTestResult,
    ResolvedProvider,
    SaveMailAccountPayload,
} from '../types/mailAccounts.ts';

const accountPath = (id: string) => `/api/mail-accounts/${encodeURIComponent(id)}`;

export const mailAccountsService = {
    list: () => api<MailAccount[]>('/api/mail-accounts'),
    create: (payload: SaveMailAccountPayload) =>
        api<MailAccount>('/api/mail-accounts', 'POST', payload),
    update: (id: string, payload: SaveMailAccountPayload) =>
        api<MailAccount>(accountPath(id), 'PUT', payload),
    delete: (id: string) =>
        api<{ message: string }>(accountPath(id), 'DELETE'),
    resolveProvider: (email: string) =>
        api<ResolvedProvider>('/api/mail-accounts/resolve-provider', 'POST', {
            email,
        }),
    test: (id: string, password?: string) =>
        api<MailAccountConnectionTestResult>(
            `${accountPath(id)}/test`,
            'POST',
            password ? { password } : {},
        ),
    connectGoogle: (email?: string) =>
        api<GoogleOAuthConnectResponse>('/api/mail-accounts/google/connect', 'POST', email ? { email } : {}),
    checkGoogleFlow: (flowId: string) =>
        api<GoogleOAuthFlowStatusResponse>(`/api/mail-accounts/google/flow/${encodeURIComponent(flowId)}`),
    disconnectOAuth: (id: string) =>
        api<{ message: string }>(`${accountPath(id)}/disconnect-oauth`, 'POST'),
    sync: (id: string) =>
        api<import('../types/mailInbox.ts').ManualSyncResponse>(`${accountPath(id)}/sync`, 'POST'),
    toggleAutoSync: (id: string, autoSync?: boolean) =>
        api<MailAccount>(`${accountPath(id)}/toggle-auto-sync`, 'PATCH', autoSync !== undefined ? { auto_sync: autoSync } : {}),
};

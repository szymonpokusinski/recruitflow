import { api } from './http.ts';
import type {
    IntegrationState,
    SourceDraft,
    DataSource,
    SpreadsheetMetadata,
    HeaderResult,
    MappingDraft,
    OAuthResult,
    OAuthFlowState,
    ConnectionTest,
} from '../types/integrations.ts';
const sourcePath = (id: string) =>
    `/api/integrations/sources/${encodeURIComponent(id)}`;
export const integrationsService = {
    autoConnect: (spreadsheet: string, integration_account_id: string) =>
        api<{
            status: string;
            records_count: number;
            counts: Partial<
                Record<
                    import('../types/integrations.ts').CollectionRole,
                    number
                >
            >;
        }>('/api/integrations/connect-source', 'POST', {
            spreadsheet,
            integration_account_id,
        }),
    state: () => api<IntegrationState>('/api/integrations/state'),
    configure: (file: File) => {
        const body = new FormData();
        body.append('client_file', file);
        return api('/api/integrations/google/client', 'POST', body);
    },
    connect: (name: string, account_id?: string, write_access = false) =>
        api<OAuthResult>('/api/integrations/google/connect', 'POST', {
            name,
            account_id,
            write_access,
        }),
    flow: (id: string) =>
        api<OAuthFlowState>(
            `/api/integrations/oauth/${encodeURIComponent(id)}`,
        ),
    disconnect: (id: string) =>
        api(`/api/integrations/accounts/${encodeURIComponent(id)}`, 'DELETE'),
    save: (draft: SourceDraft, id?: string) =>
        api<DataSource>(
            id ? sourcePath(id) : '/api/integrations/sources',
            id ? 'PUT' : 'POST',
            draft,
        ),
    remove: (id: string) => api(sourcePath(id), 'DELETE'),
    metadata: (id: string) =>
        api<SpreadsheetMetadata>(sourcePath(id) + '/metadata'),
    headers: (id: string, sheet_id: number, header_row: number) =>
        api<HeaderResult>(
            sourcePath(id) +
                `/headers?sheet_id=${sheet_id}&header_row=${header_row}`,
        ),
    mappings: (id: string, sheets: MappingDraft[]) =>
        api(sourcePath(id) + '/mappings', 'PUT', {
            sheets: sheets.map((sheet) => ({
                sheet_id: sheet.sheet_id,
                sheet_title: sheet.sheet_title,
                role: sheet.role,
                schema_profile: sheet.schema_profile ?? 'generic',
                header_row: sheet.header_row,
                mapping: Object.fromEntries(
                    Object.entries(sheet.mapping).filter(
                        ([, value]) => !!value,
                    ),
                ),
            })),
        }),
    test: (id: string) => api<ConnectionTest>(sourcePath(id) + '/test', 'POST'),
    sync: (id: string) => api(sourcePath(id) + '/sync', 'POST'),
    toggle: (id: string, enabled: boolean, primary = false) =>
        api(sourcePath(id) + '/enabled', 'PUT', { enabled, primary }),
    primary: (id: string, primary: boolean) =>
        api(sourcePath(id) + '/primary', 'PUT', { primary }),
};

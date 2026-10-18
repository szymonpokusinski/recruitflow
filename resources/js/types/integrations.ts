export type CollectionRole =
    | 'offers'
    | 'applications'
    | 'events'
    | 'profiles'
    | 'other';
export interface IntegrationAccount {
    id: string;
    name: string;
    status: string;
    token_expires_at: string | null;
    scopes: string[];
}
export interface FieldSchema {
    field: string;
    label: string;
    required: boolean;
}
export interface SourceConfiguration {
    schema_profile: 'generic' | 'recruitflow';
    sheet_id: number;
    sheet_title: string;
    role: CollectionRole;
    header_row: number;
    is_primary: boolean;
    mappings: { field: string; column_name: string }[];
}
export interface DataSource {
    records_count: number;
    id: string;
    name: string;
    external_id: string;
    integration_account_id: string;
    enabled: boolean;
    read_only: boolean;
    sync_interval_minutes: number;
    last_synced_at: string | null;
    last_tested_at: string | null;
    last_error: string | null;
    account: Pick<IntegrationAccount, 'id' | 'name' | 'status'>;
    configurations: SourceConfiguration[];
}
export interface SyncRun {
    id: string;
    data_source_id: string;
    status: string;
    trigger: string;
    records_count: number;
    started_at: string;
    finished_at: string | null;
    error: string | null;
}
export interface IntegrationState {
    recruitflow_required?: Partial<Record<CollectionRole, string[]>>;
    oauth: {
        configured: boolean;
        client_id: string | null;
        credential_store: string;
        scopes: string[];
    };
    accounts: IntegrationAccount[];
    sources: DataSource[];
    schemas: Partial<Record<CollectionRole, FieldSchema[]>>;
    runs: SyncRun[];
}
export interface SheetMetadata {
    id: number;
    title: string;
    row_count: number;
}
export interface SpreadsheetMetadata {
    title: string;
    sheets: SheetMetadata[];
}
export interface MappingDraft {
    schema_profile?: 'generic' | 'recruitflow';
    sheet_id: number;
    sheet_title: string;
    role: CollectionRole;
    header_row: number;
    mapping: Record<string, string>;
    columns: string[];
    loaded: boolean;
}
export interface HeaderResult {
    title: string;
    columns: string[];
    suggestions: Partial<Record<CollectionRole, Record<string, string>>>;
}
export interface OAuthResult {
    id: string;
    status: string;
    authorization_url: string | null;
}
export interface OAuthFlowState {
    id: string;
    status: string;
    error: string | null;
    expires_at: string;
}
export interface SourceDraft {
    name: string;
    spreadsheet: string;
    integration_account_id: string;
    sync_interval_minutes: number;
    read_only: boolean;
}
export interface ConnectionTest {
    title: string;
    sheets_count: number;
    records_count: number;
    mapping_valid: boolean;
}

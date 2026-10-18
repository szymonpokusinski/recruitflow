export type FieldValue =
    | string
    | number
    | boolean
    | null
    | string[]
    | { label: string; value: string }[];
export interface OfferField {
    key: string;
    label: string;
    type:
        | 'text'
        | 'url'
        | 'textarea'
        | 'select'
        | 'boolean'
        | 'number'
        | 'date'
        | 'datetime'
        | 'tags';
    section: string;
    options: string[];
}
export interface SyncState {
    status: string;
    pending: number;
    local: number;
    conflicts: number;
    blocked: number;
    last_error: string | null;
    last_synced_at: string | null;
    read_last_synced_at: string | null;
    sources_count: number;
}
export interface Conflict {
    id: string;
    entity_type: string;
    entity_id: string;
    fields: Record<string, { base: unknown; local: unknown; remote: unknown }>;
}
export interface Operation {
    id: number;
    entity_type: string;
    entity_id: string;
    status: string;
    attempts: number;
    last_error: string | null;
    unsupported_fields: string[] | null;
}
export interface Duplicate {
    local: { id: string; title: string; company: string };
    remote: { id: string; title: string; company: string };
    reason: string;
}
export interface ManagementState {
    sync: SyncState;
    conflicts: Conflict[];
    operations: Operation[];
    duplicates: Duplicate[];
    offerFields: OfferField[];
    stages: { value: string; label: string }[];
}
export interface Audit {
    id: string;
    entity_id: string;
    offer_key: string | null;
    action: string;
    actor: string;
    changes: Record<string, unknown>;
    created_at: string;
}

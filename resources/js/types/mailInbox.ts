export type MailMessageCategory = 'job_offer' | 'recruitment' | 'other';
export type MailMessageStatus = 'new' | 'to_review' | 'relevant' | 'ignored';
export type MailRuleAction = 'qualify' | 'ignore';

export interface MailAttachment {
    name: string;
    size: number;
    mime_type?: string;
    attachment_id?: string;
}

export interface FetchBodyResponse {
    success: boolean;
    requires_reauth?: boolean;
    scope_needed?: string;
    error?: string;
    mail_message: MailMessage;
}

export interface MailMessage {
    id: string;
    mail_account_id: string;
    provider_message_id: string;
    thread_id: string | null;
    message_id: string | null;
    sender_email: string;
    sender_name: string | null;
    sender_domain: string;
    reply_to: string | null;
    to_email?: string | null;
    cc_email?: string | null;
    subject: string;
    snippet?: string | null;
    body_text?: string | null;
    body_html?: string | null;
    has_attachments?: boolean;
    attachments?: MailAttachment[] | null;
    body_fetched_at?: string | null;
    sent_at: string;
    category: MailMessageCategory;
    status: MailMessageStatus;
    matched_rule_id: string | null;
    is_manually_classified: boolean;
    created_at?: string;
    updated_at?: string;
    mail_account?: {
        id: string;
        name: string | null;
        email: string;
        provider: string | null;
        status: string;
    };
    matched_rule?: {
        id: string;
        name: string;
        portal_name: string | null;
        is_system: boolean;
        action: MailRuleAction;
        category: MailMessageCategory;
    } | null;
}

export interface MailMessageCounts {
    total: number;
    new: number;
    to_review: number;
    relevant: number;
    ignored: number;
    job_offer: number;
    recruitment: number;
}

export interface MailMessagesResponse {
    messages: MailMessage[];
    counts: MailMessageCounts;
}

export interface MailSyncRule {
    id: string;
    system_key: string | null;
    name: string;
    is_system: boolean;
    mail_account_id: string | null;
    portal_name: string | null;
    sender_email: string | null;
    sender_domain: string | null;
    subject_keywords: string[] | null;
    category: MailMessageCategory;
    action: MailRuleAction;
    priority: number;
    is_active: boolean;
    matched_messages_count?: number;
    mail_account?: {
        id: string;
        name: string | null;
        email: string;
        provider: string | null;
    } | null;
    created_at?: string;
    updated_at?: string;
}

export interface SaveMailRulePayload {
    name: string;
    mail_account_id?: string | null;
    portal_name?: string | null;
    sender_email?: string | null;
    sender_domain?: string | null;
    subject_keywords?: string[] | null;
    category: MailMessageCategory;
    action: MailRuleAction;
    priority?: number;
    is_active?: boolean;
}

export interface MailSyncAccountItem {
    account: {
        id: string;
        name: string | null;
        email: string;
        provider: string | null;
        auth_type: string;
        status: string;
        auto_sync?: boolean;
        last_tested_at?: string | null;
        last_test_result?: string | null;
    };
    sync_state: {
        id: string;
        sync_status: string;
        last_synced_at: string | null;
        total_messages_count: number;
        relevant_messages_count: number;
        ignored_messages_count: number;
        to_review_messages_count: number;
        last_batch_count: number;
        history_id: string | null;
        imap_last_uid: number | null;
        imap_uidvalidity: number | null;
        last_error: string | null;
        last_error_at: string | null;
    };
    schedule_interval: string;
    status_message: string;
}

export interface MailSyncStatesResponse {
    accounts: MailSyncAccountItem[];
    summary: {
        total_accounts: number;
        active_accounts: number;
        total_messages: number;
        relevant_messages: number;
    };
}

export interface ManualSyncResponse {
    success: boolean;
    message: string;
    error?: string | null;
    result?: {
        fetched_count: number;
        qualified_count: number;
        ignored_count: number;
        unmatched_count: number;
    };
    sync_state?: MailSyncAccountItem['sync_state'];
}

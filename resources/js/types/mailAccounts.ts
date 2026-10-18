export interface MailAccount {
    id: string;
    email: string;
    name: string | null;
    auth_type: string;
    provider: string | null;
    imap_host: string | null;
    imap_port: number | null;
    imap_encryption: string | null;
    imap_username: string | null;
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_encryption: string | null;
    smtp_username: string | null;
    oauth_provider: string | null;
    token_expires_at: string | null;
    status: string;
    auto_sync?: boolean;
    last_tested_at?: string | null;
    last_test_result?: string | null;
    last_synced_at: string | null;
    last_error: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface SaveMailAccountPayload {
    email: string;
    password?: string;
    name?: string | null;
}

export interface ProviderInstruction {
    title: string;
    steps: string[];
}

export interface ResolvedProvider {
    provider: string;
    name: string;
    is_known: boolean;
    domain: string | null;
    doc_url?: string | null;
    instructions?: ProviderInstruction | null;
}

export interface MailAccountConnectionTestResult {
    success: boolean;
    message: string;
    error: string | null;
    error_code: string | null;
    provider: string;
    status: string;
    last_tested_at: string | null;
    last_test_result: string | null;
}

export interface GoogleOAuthConnectResponse {
    flow_id: string;
    authorization_url: string | null;
    status: string;
}

export interface GoogleOAuthFlowStatusResponse {
    status: string;
    message?: string;
    error?: string;
    mail_account?: MailAccount;
}

<?php

declare(strict_types=1);

namespace App\Services\Mail\Contracts;

use App\Models\MailAccount;
use App\Models\Mail\MailSyncState;
use App\Services\Mail\DTO\MailSyncBatchResult;

interface MailSyncProviderInterface
{
    /**
     * Determine if this provider supports the given mail account.
     */
    public function supports(MailAccount $account): bool;

    /**
     * Fetch a batch of message metadata for the given account starting from cursor in state.
     */
    public function fetchBatch(MailAccount $account, MailSyncState $state): MailSyncBatchResult;

    /**
     * Fetch complete message content (text, html, snippet, attachments).
     *
     * @return array{
     *     success: bool,
     *     requires_reauth?: bool,
     *     scope_needed?: string,
     *     error?: string,
     *     snippet?: ?string,
     *     body_text?: ?string,
     *     body_html?: ?string,
     *     to_email?: ?string,
     *     cc_email?: ?string,
     *     has_attachments?: bool,
     *     attachments?: array
     * }
     */
    public function fetchMessageBody(MailAccount $account, string $providerMessageId): array;
}

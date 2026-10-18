<?php

declare(strict_types=1);

namespace App\Services\Mail\DTO;

class MailSyncBatchResult
{
    /**
     * @param array<MailMessageMetadataDTO> $messages
     */
    public function __construct(
        public readonly array $messages = [],
        public readonly ?string $historyId = null,
        public readonly ?int $imapLastUid = null,
        public readonly ?int $imapUidvalidity = null,
        public readonly bool $hasMore = false,
    ) {}
}

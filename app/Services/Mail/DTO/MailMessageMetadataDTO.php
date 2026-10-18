<?php

declare(strict_types=1);

namespace App\Services\Mail\DTO;

use DateTimeInterface;

class MailMessageMetadataDTO
{
    public readonly string $senderDomain;

    /**
     * @param array<string, mixed> $rawHeaders
     */
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $senderEmail,
        public readonly string $subject,
        public readonly DateTimeInterface $sentAt,
        public readonly ?string $threadId = null,
        public readonly ?string $messageId = null,
        public readonly ?string $senderName = null,
        ?string $senderDomain = null,
        public readonly ?string $replyTo = null,
        public readonly array $rawHeaders = [],
        public readonly ?string $snippet = null,
        public readonly ?string $bodyText = null,
        public readonly ?string $bodyHtml = null,
        public readonly ?string $toEmail = null,
        public readonly ?string $ccEmail = null,
        public readonly bool $hasAttachments = false,
        public readonly ?array $attachments = null,
    ) {
        if ($senderDomain !== null && trim($senderDomain) !== '') {
            $this->senderDomain = strtolower(trim($senderDomain));
        } else {
            $email = strtolower(trim($senderEmail));
            $this->senderDomain = str_contains($email, '@')
                ? substr(strrchr($email, '@') ?: '', 1)
                : '';
        }
    }
}

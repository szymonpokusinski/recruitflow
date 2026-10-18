<?php

declare(strict_types=1);

namespace App\Services\Mail\DTO;

class MailSyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $fetchedCount = 0,
        public readonly int $qualifiedCount = 0,
        public readonly int $ignoredCount = 0,
        public readonly int $unmatchedCount = 0,
        public readonly ?string $error = null,
    ) {}
}

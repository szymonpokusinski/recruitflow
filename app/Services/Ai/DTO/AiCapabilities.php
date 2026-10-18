<?php

declare(strict_types=1);

namespace App\Services\Ai\DTO;

final readonly class AiCapabilities
{
    /** @param list<string> $operations Operations available in this implementation. */
    public function __construct(
        public array $operations,
        public bool $available,
        public bool $webSearch = false,
        public bool $structuredResults = true,
        public ?string $unavailableReason = null,
    ) {}

    public function supports(string $operation): bool
    {
        return $this->available && in_array($operation, $this->operations, true);
    }

    public function toArray(): array
    {
        return [
            'operations' => $this->operations,
            'available' => $this->available,
            'web_search' => $this->webSearch,
            'structured_results' => $this->structuredResults,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}

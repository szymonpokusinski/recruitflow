<?php

declare(strict_types=1);

namespace App\Services\Ai\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class AiResult implements JsonSerializable
{
    /** @param list<AiItem> $items */
    public function __construct(
        public string $provider,
        public ?string $model,
        public string $category,
        public CarbonImmutable $requestedAt,
        public array $items,
        public ?string $requestId = null,
        public array $metadata = [],
    ) {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('AiResult.items must be a list.');
        }
        foreach ($items as $item) {
            if (! $item instanceof AiItem) {
                throw new InvalidArgumentException('AiResult.items must contain AiItem DTOs.');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'category' => $this->category,
            'requested_at' => $this->requestedAt->toIso8601String(),
            'request_id' => $this->requestId,
            'metadata' => $this->metadata,
            'items' => array_map(fn (AiItem $item): array => $item->toArray(), $this->items),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

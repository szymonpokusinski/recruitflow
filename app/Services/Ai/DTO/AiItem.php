<?php

declare(strict_types=1);

namespace App\Services\Ai\DTO;

use JsonSerializable;

final readonly class AiItem implements JsonSerializable
{
    public function __construct(
        public string $title,
        public ?string $url = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?string $location = null,
        public ?string $description = null,
        public array $raw = [],
        public ?float $confidence = null,
        public ?string $externalId = null,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'price' => $this->price,
            'currency' => $this->currency,
            'location' => $this->location,
            'description' => $this->description,
            'raw' => $this->raw,
            'confidence' => $this->confidence,
            'external_id' => $this->externalId,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

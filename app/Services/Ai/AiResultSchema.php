<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Shared output contract. Provider adapters may remove unsupported schema keywords. */
final class AiResultSchema
{
    public function schema(int $maxItems = 100): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object', 'additionalProperties' => false, 'required' => ['items'],
            'properties' => ['items' => [
                'type' => 'array', 'maxItems' => $maxItems,
                'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['title', 'url', 'price', 'currency', 'location', 'description', 'raw', 'confidence', 'external_id', 'metadata'],
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'pattern' => '\\S'],
                        'url' => ['type' => ['string', 'null'], 'format' => 'uri', 'pattern' => '^https?://', 'maxLength' => 2048],
                        'price' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 999999999999],
                        'currency' => ['type' => ['string', 'null'], 'pattern' => '^[A-Z]{3}$'],
                        'location' => ['type' => ['string', 'null'], 'maxLength' => 500],
                        'description' => ['type' => ['string', 'null'], 'maxLength' => 50000],
                        'raw' => ['type' => 'object'],
                        'confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'external_id' => ['type' => ['string', 'null'], 'maxLength' => 255],
                        'metadata' => ['type' => 'object'],
                    ],
                ],
            ]],
        ];
    }
}

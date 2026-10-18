<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\DTO\AiItem;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class AiResultMapper
{
    public function __construct(private readonly AiPayloadGuard $guard) {}

    /** Validate before any casts; driver-specific adapters must produce this shape. */
    public function map(array $payload): AiResult
    {
        $this->guard->validateJson($payload, 'result');
        // Empty optional numbers must remain missing, never become (float) '' = 0.
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['url', 'price', 'currency', 'confidence', 'external_id'] as $field) {
                if (is_string($item[$field] ?? null) && trim($item[$field]) === '') {
                    $payload['items'][$index][$field] = null;
                }
            }
        }
        $data = Validator::make(['result' => $payload], [
            'result' => ['required', 'array:provider,model,category,requested_at,request_id,metadata,items'],
            'result.provider' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'result.model' => ['present', 'nullable', ...(($payload['model'] ?? null) !== null ? ['required'] : []), 'string', 'max:100', 'regex:/\S/u'],
            'result.category' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'result.requested_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'result.request_id' => ['sometimes', 'nullable', ...(($payload['request_id'] ?? null) !== null ? ['required'] : []), 'string', 'max:255', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
            'result.items' => ['present', 'array', 'list', 'max:100'],
            'result.metadata' => ['sometimes', 'array'],
            'result.items.*' => ['required', 'array:title,url,price,currency,location,description,raw,confidence,external_id,metadata'],
            'result.items.*.title' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'result.items.*.url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'result.items.*.price' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999'],
            'result.items.*.currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'result.items.*.location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'result.items.*.description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'result.items.*.raw' => ['sometimes', 'array'],
            'result.items.*.confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'result.items.*.external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'result.items.*.metadata' => ['sometimes', 'array'],
        ])->validate()['result'];

        $items = array_map(fn (array $item): AiItem => new AiItem(
            title: $item['title'],
            url: $item['url'] ?? null,
            price: isset($item['price']) ? (float) $item['price'] : null,
            currency: $item['currency'] ?? null,
            location: $item['location'] ?? null,
            description: $item['description'] ?? null,
            raw: $this->guard->redact($item['raw'] ?? []),
            confidence: isset($item['confidence']) ? (float) $item['confidence'] : null,
            externalId: $item['external_id'] ?? null,
            metadata: $this->guard->redact($item['metadata'] ?? []),
        ), $data['items']);

        return new AiResult(
            $data['provider'], $data['model'], $data['category'],
            CarbonImmutable::parse($data['requested_at']), $items, $data['request_id'] ?? null,
            $this->guard->redact($data['metadata'] ?? []),
        );
    }
}

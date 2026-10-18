<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AiJsonResultMapper
{
    public function __construct(private readonly AiResultMapper $mapper) {}

    public function map(string $reply, string $provider, ?string $model, string $category, CarbonImmutable $requestedAt, array $context, string $contextKey, ?string $requestId, ?array $itemContext = null): AiResult
    {
        try {
            if (strlen($reply) > 2_000_000) {
                throw new AiProviderException('invalid_response', $requestId, phase: 'run');
            }
            $text = trim($reply);
            // Tolerate a single JSON fence, never extract arbitrary prose fragments.
            if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $text, $match)) {
                $text = $match[1];
            }
            // Retain collection types: {} must not turn into a fake empty list.
            $shape = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
            $shapeRows = is_array($shape) ? $shape : (is_object($shape) ? ($shape->items ?? $shape->results ?? $shape->offers ?? null) : null);
            $payload = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new AiProviderException('invalid_response', $requestId, phase: 'run');
            }
            $rows = is_array($shape) ? $payload : ($payload['items'] ?? $payload['results'] ?? $payload['offers'] ?? null);
            if (! is_array($shapeRows) || ! is_array($rows) || ! array_is_list($rows)) {
                throw new AiProviderException('invalid_response', $requestId, phase: 'run');
            }
            $items = array_map(function (mixed $row) use ($context, $contextKey, $itemContext): array {
                if (! is_array($row) || array_is_list($row)) {
                    throw new AiProviderException('invalid_response', phase: 'run');
                }
                $location = $row['location'] ?? null;
                if (is_array($location)) {
                    $location = implode(', ', array_filter([
                        $location['city'] ?? null, $location['region'] ?? null, $location['country'] ?? null,
                    ], fn ($value) => is_string($value) && trim($value) !== ''));
                    $location = $location === '' ? null : $location;
                }
                $description = $row['description'] ?? $row['summary'] ?? null;
                if (is_array($description) && array_is_list($description) && count(array_filter($description, 'is_string')) === count($description)) {
                    $description = implode("\n", $description);
                }
                $confidence = $row['confidence'] ?? null;
                if (is_string($confidence) && preg_match('/^\s*(\d+(?:\.\d+)?)%\s*$/', $confidence, $match)) {
                    $confidence = (float) $match[1] / 100;
                }
                $currency = $row['currency'] ?? null;
                $metadata = $row['metadata'] ?? [];
                if (! is_array($metadata)) {
                    throw new AiProviderException('invalid_response', phase: 'run');
                }
                $externalId = $row['external_id'] ?? $row['externalId'] ?? $row['id'] ?? null;

                return [
                    'title' => $row['title'] ?? $row['position'] ?? $row['name'] ?? null,
                    'url' => $row['url'] ?? $row['offer_url'] ?? $row['link'] ?? null,
                    'price' => $row['price'] ?? $row['amount'] ?? null,
                    'currency' => is_string($currency) ? strtoupper(trim($currency)) : $currency,
                    'location' => $location,
                    'description' => $description,
                    'raw' => $row,
                    'confidence' => $confidence,
                    'external_id' => is_int($externalId) ? (string) $externalId : $externalId,
                    'metadata' => array_replace($metadata, [$contextKey => $itemContext ?? $context]),
                ];
            }, $rows);

            return $this->mapper->map([
                'provider' => $provider, 'model' => $model, 'category' => $category,
                'requested_at' => $requestedAt->toIso8601String(), 'request_id' => $requestId,
                'metadata' => [$contextKey => $context],
                'items' => $items,
            ]);
        } catch (JsonException|ValidationException|AiProviderException) {
            throw new AiProviderException('invalid_response', $requestId, phase: 'run', context: $context);
        }
    }
}

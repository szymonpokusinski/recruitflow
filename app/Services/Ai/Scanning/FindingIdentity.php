<?php

declare(strict_types=1);

namespace App\Services\Ai\Scanning;

use App\Recruitment\OfferIdentity;
use App\Services\Ai\DTO\AiItem;
use Illuminate\Support\Str;

final class FindingIdentity
{
    /** External IDs are scoped to provider and source host, never globally unique. */
    public function keys(AiItem $item, string $provider): array
    {
        $keys = [];
        $url = OfferIdentity::url($item->url);
        if ($url !== null) {
            $keys['url'] = hash('sha256', 'url|'.$url);
        }
        if ($item->externalId !== null && trim($item->externalId) !== '') {
            $host = $item->url !== null ? strtolower(parse_url($item->url, PHP_URL_HOST) ?? '') : '';
            $keys['external'] = hash('sha256', json_encode([$provider, $host, $item->externalId], JSON_THROW_ON_ERROR));
        }
        if ($keys === []) {
            // No guessed ID: exact normalized evidence is the last-resort identity.
            $keys['content'] = hash('sha256', json_encode([
                Str::lower(trim($item->title)),
                Str::lower(trim($item->location ?? '')),
                $item->metadata['company'] ?? null,
                trim($item->description ?? ''),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }

        return $keys;
    }
}

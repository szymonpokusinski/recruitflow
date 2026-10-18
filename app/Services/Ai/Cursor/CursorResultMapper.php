<?php

declare(strict_types=1);

namespace App\Services\Ai\Cursor;

use App\Services\Ai\AiJsonResultMapper;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;

final class CursorResultMapper
{
    public function __construct(private readonly AiJsonResultMapper $mapper) {}

    public function map(string $reply, string $provider, ?string $model, string $category, CarbonImmutable $requestedAt, array $context): AiResult
    {
        return $this->mapper->map($reply, $provider, $model, $category, $requestedAt, $context, 'cursor', $context['run_id'] ?? $context['agent_id'] ?? null);
    }
}

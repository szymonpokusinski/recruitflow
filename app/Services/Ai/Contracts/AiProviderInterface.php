<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTO\AiCapabilities;
use App\Services\Ai\DTO\AiResult;

interface AiProviderInterface
{
    public function key(): string;

    public function name(): string;

    public function supports(): AiCapabilities;

    /** @param array<string, mixed> $options */
    public function search(string $category, string $prompt, array $options = []): AiResult;

    /** @param array<string, mixed> $options */
    public function analyze(string $category, string $prompt, array $options = []): AiResult;
}

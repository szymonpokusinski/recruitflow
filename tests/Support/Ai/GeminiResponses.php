<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

final class GeminiResponses
{
    public static function completed(string $text = '{"items":[]}', array $overrides = []): array
    {
        return array_replace([
            'object' => 'interaction', 'id' => 'v1_gemini-controlled-test',
            'model' => 'gemini-3.8-flash', 'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]]],
        ], $overrides);
    }
}

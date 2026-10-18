<?php

declare(strict_types=1);

namespace App\Services\Ai\Gemini;

use App\Services\Ai\AiJsonResultMapper;
use App\Services\Ai\AiPayloadGuard;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class GeminiResultMapper
{
    public function __construct(private readonly AiJsonResultMapper $mapper, private readonly AiPayloadGuard $guard) {}

    public function map(array $response, string $provider, string $category, CarbonImmutable $requestedAt, string $requestedModel, bool $groundingEnabled): AiResult
    {
        $requestId = $response['id'] ?? null;
        if (! is_string($requestId) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/', $requestId)) {
            throw new AiProviderException('invalid_response', phase: 'run');
        }
        try {
            $this->guard->validateJson($response, 'response');
        } catch (ValidationException) {
            throw new AiProviderException('invalid_response', $requestId, 'run');
        }
        if (($response['object'] ?? null) !== 'interaction' || ! is_string($response['model'] ?? null)) {
            throw new AiProviderException('invalid_response', $requestId, 'run');
        }
        // Incomplete/safety/failed runs never masquerade as a valid empty result.
        if (($response['status'] ?? null) !== 'completed' || ! empty($response['errors'])) {
            throw new AiProviderException('gemini_run_failed', $requestId, 'run');
        }
        $steps = $response['steps'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw new AiProviderException('invalid_response', $requestId, 'run');
        }
        $output = null;
        $searchCalls = [];
        $searchResults = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                throw new AiProviderException('invalid_response', $requestId, 'run');
            }
            // Only the final model_output, never thought summaries or tool JSON.
            if (($step['type'] ?? null) === 'model_output') {
                $output = $step['content'] ?? null;
            } elseif (($step['type'] ?? null) === 'google_search_call') {
                $searchCalls[] = $step['arguments'] ?? [];
            } elseif (($step['type'] ?? null) === 'google_search_result') {
                $searchResults[] = $step['result'] ?? [];
            }
        }
        if (! is_array($output) || ! array_is_list($output) || $output === []) {
            throw new AiProviderException('invalid_response', $requestId, 'run');
        }
        $text = '';
        $annotations = [];
        foreach ($output as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text' || ! is_string($block['text'] ?? null)
                || ! is_array($block['annotations'] ?? []) || ! array_is_list($block['annotations'] ?? [])) {
                throw new AiProviderException('invalid_response', $requestId, 'run');
            }
            // Preserve offsets relative to each original text block for future citation UI.
            // Do not retain an unredacted JSON string duplicating secret-bearing raw
            // fields. Hash/length identify the original block for citation consumers.
            $annotations[] = ['text_sha256' => hash('sha256', $block['text']), 'text_length' => mb_strlen($block['text']), 'annotations' => $block['annotations'] ?? []];
            $text .= $block['text'];
        }
        $context = [
            'interaction_id' => $requestId, 'runtime' => 'cloud',
            'requested_model' => $requestedModel, 'model_source' => 'response',
            'grounding_enabled' => $groundingEnabled,
            'search_performed' => $searchCalls !== [],
            'search_calls' => $searchCalls, 'search_results' => $searchResults,
            'text_annotations' => $annotations, 'usage' => $response['usage'] ?? [],
        ];
        $context = $this->guard->redact($context);

        // Citation offsets belong to the whole response, not individual items.
        // Keep that evidence once in root metadata rather than duplicating it N times.
        return $this->mapper->map($text, $provider, $response['model'], $category, $requestedAt, $context, 'gemini', $requestId,
            array_intersect_key($context, array_flip(['interaction_id', 'runtime', 'requested_model', 'model_source', 'grounding_enabled', 'search_performed'])));
    }
}

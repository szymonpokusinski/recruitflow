<?php

declare(strict_types=1);

namespace App\Services\Ai\DTO;

use App\Services\Ai\AiPayloadGuard;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class AiRequest
{
    private function __construct(
        public string $operation,
        public string $category,
        public string $prompt,
        public array $options,
    ) {}

    public static function make(string $operation, string $category, string $prompt, array $options = []): self
    {
        $guard = new AiPayloadGuard;
        $guard->validateJson($options, 'options');
        if ($guard->containsSecrets($options)) {
            throw ValidationException::withMessages(['options' => 'Sekrety należy skonfigurować w .env lub bezpiecznym magazynie, poza parametrami żądania.']);
        }
        if (is_array($options['filters']['budget'] ?? null)) {
            foreach (['min', 'max', 'currency'] as $field) {
                $value = $options['filters']['budget'][$field] ?? null;
                if (is_string($value) && trim($value) === '') {
                    $options['filters']['budget'][$field] = null;
                }
            }
        }
        Validator::make(compact('operation', 'category', 'prompt', 'options'), [
            'operation' => ['required', 'in:search,analyze'],
            'category' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'prompt' => ['required', 'string', 'max:20000', 'regex:/\S/u'],
            'options' => ['array'],
            'options.model' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\S/u'],
            'options.max_items' => ['sometimes', 'integer', 'between:1,100'],
            'options.temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'options.filters' => ['sometimes', 'array:location,budget,keywords'],
            'options.filters.location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'options.filters.budget' => ['sometimes', 'array:min,max,currency'],
            'options.filters.budget.min' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999'],
            'options.filters.budget.max' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999'],
            'options.filters.budget.currency' => ['sometimes', 'nullable', 'regex:/^[A-Z]{3}$/'],
            'options.filters.keywords' => ['sometimes', 'array', 'list', 'max:30'],
            'options.filters.keywords.*' => ['required', 'string', 'max:200'],
        ])->validate();

        $budget = $options['filters']['budget'] ?? [];
        if (isset($budget['min'], $budget['max']) && (float) $budget['min'] > (float) $budget['max']) {
            throw ValidationException::withMessages(['options.filters.budget.max' => 'Maksymalny budżet nie może być mniejszy od minimalnego.']);
        }
        if (isset($options['max_items'])) {
            $options['max_items'] = (int) $options['max_items'];
        }
        if (isset($options['temperature'])) {
            $options['temperature'] = (float) $options['temperature'];
        }

        return new self($operation, $category, $prompt, $options);
    }
}

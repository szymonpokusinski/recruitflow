<?php

declare(strict_types=1);

namespace App\Services\Ai\Scanning;

use App\Models\Ai\AiCategory;
use App\Models\Ai\AiCategoryPrompt;
use App\Services\Ai\AiPayloadGuard;
use App\Services\Ai\DTO\AiRequest;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AiCategoryService
{
    public function __construct(private readonly AiPayloadGuard $guard) {}

    public function save(array $attributes, ?AiCategory $category = null): AiCategory
    {
        $values = array_replace($category?->only(['slug', 'name', 'is_active', 'schedule_cron', 'default_provider', 'settings']) ?? [
            'is_active' => false, 'schedule_cron' => null, 'default_provider' => null, 'settings' => [],
        ], $attributes);
        foreach (['schedule_cron', 'default_provider'] as $field) {
            if (is_string($values[$field] ?? null) && trim($values[$field]) === '') {
                $values[$field] = null;
            }
        }
        $data = Validator::make($values, [
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique('ai_categories', 'slug')->ignore($category?->id)],
            'name' => ['required', 'string', 'max:200'], 'is_active' => ['required', 'boolean'],
            'schedule_cron' => ['nullable', 'string', 'max:100', function ($field, $value, $fail) {
                if (! CronExpression::isValidExpression($value)) {
                    $fail('Nieprawidłowe wyrażenie cron.');
                }
            }],
            'default_provider' => ['nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'settings' => ['present', 'array:filters,options,timezone'],
            'settings.timezone' => ['sometimes', 'required', 'timezone:all'],
        ])->validate();
        $this->validateSettings($data['settings'], $data['slug']);

        return DB::transaction(function () use ($category, $data): AiCategory {
            $model = $category ?? new AiCategory;
            $model->fill($data)->save();

            return $model->fresh();
        });
    }

    public function validateSettings(array $settings, string $slug): void
    {
        $this->guard->validateJson($settings, 'settings');
        if ($this->guard->containsSecrets($settings)) {
            throw ValidationException::withMessages(['settings' => 'Klucze API należą do bezpiecznego magazynu providera, nie do ustawień kategorii.']);
        }
        Validator::make(['settings' => $settings], [
            'settings' => ['array:filters,options,timezone'],
            'settings.timezone' => ['sometimes', 'required', 'timezone:all'],
            'settings.options' => ['sometimes', 'array'],
            'settings.filters' => ['sometimes', 'array'],
        ])->validate();
        AiRequest::make('search', $slug, 'Validate category configuration.', array_replace($settings['options'] ?? [], ['filters' => $settings['filters'] ?? []]));
    }

    /** Immutable versions: activation swaps the active record, never rewrites history. */
    public function addPrompt(AiCategory $category, string $name, string $text, bool $activate = true): AiCategoryPrompt
    {
        Validator::make(compact('name', 'text'), ['name' => ['required', 'string', 'max:200'], 'text' => ['required', 'string', 'max:20000']])->validate();

        return DB::transaction(function () use ($category, $name, $text, $activate): AiCategoryPrompt {
            AiCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $version = ((int) $category->prompts()->max('version')) + 1;
            if ($activate) {
                $category->prompts()->where('is_active', true)->update(['is_active' => false]);
            }

            return $category->prompts()->create(['name' => $name, 'prompt_text' => $text, 'version' => $version, 'is_active' => $activate]);
        }, 3);
    }
}

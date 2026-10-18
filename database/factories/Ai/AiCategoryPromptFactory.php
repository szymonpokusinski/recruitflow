<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Models\Ai\AiCategory;
use App\Models\Ai\AiCategoryPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiCategoryPrompt> */
class AiCategoryPromptFactory extends Factory
{
    protected $model = AiCategoryPrompt::class;

    public function definition(): array
    {
        return ['category_id' => AiCategory::factory(), 'name' => 'Test promptu', 'prompt_text' => 'Zwróć wyłącznie kontrolowane dane testowe.', 'version' => 1, 'is_active' => false];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}

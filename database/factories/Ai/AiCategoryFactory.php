<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Models\Ai\AiCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiCategory> */
class AiCategoryFactory extends Factory
{
    protected $model = AiCategory::class;

    public function definition(): array
    {
        return [
            'slug' => 'category-'.fake()->unique()->uuid(), 'name' => 'Kontrolowana kategoria testowa',
            'is_active' => false, 'schedule_cron' => null, 'default_provider' => null, 'settings' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}

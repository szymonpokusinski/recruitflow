<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Models\Ai\AiCategory;
use App\Models\Ai\AiFinding;
use App\Models\Ai\AiSearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiFinding> */
class AiFindingFactory extends Factory
{
    protected $model = AiFinding::class;

    public function definition(): array
    {
        return [
            'category_id' => AiCategory::factory(),
            'run_id' => fn (array $attributes) => AiSearchRun::factory()->create(['category_id' => $attributes['category_id']])->id,
            'provider' => 'fake', 'model' => 'fake-v1', 'title' => 'Kontrolowany wynik testowy',
            'raw' => [], 'metadata' => ['synthetic' => true], 'hash' => hash('sha256', fake()->uuid()),
            'first_seen_at' => now(), 'last_seen_at' => now(), 'is_new' => true,
        ];
    }
}

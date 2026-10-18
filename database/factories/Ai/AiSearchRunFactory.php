<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Models\Ai\AiCategory;
use App\Models\Ai\AiSearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiSearchRun> */
class AiSearchRunFactory extends Factory
{
    protected $model = AiSearchRun::class;

    public function definition(): array
    {
        return [
            'category_id' => AiCategory::factory(), 'provider' => 'fake', 'model' => 'fake-v1',
            'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'meta' => [],
        ];
    }
}

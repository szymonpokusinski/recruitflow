<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Services\Ai\AiClock;

class FakeAiClock extends AiClock
{
    public float $time = 0;

    public array $delays = [];

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
        $this->time += $milliseconds / 1000;
    }
}

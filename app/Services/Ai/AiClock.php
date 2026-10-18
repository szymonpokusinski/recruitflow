<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Sleep;

/** Monotonic deadlines; replace in tests without real polling/backoff delays. */
class AiClock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function sleep(int $milliseconds): void
    {
        Sleep::for($milliseconds)->milliseconds();
    }
}

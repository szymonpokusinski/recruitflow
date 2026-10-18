<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('recruitment:sync')->everyMinute()->withoutOverlapping()
    ->when(fn () => ! config('widgets.read_only_preview'));

// NativePHP runs schedule:run every minute while the desktop app is open.
Schedule::command('ai:scan --due --queue')->everyMinute()->withoutOverlapping(2)
    ->name('ai-category-scans')
    ->when(fn () => config('ai_scanning.enabled') && ! config('widgets.read_only_preview'));

// Automatically synchronize active mail accounts enabled for auto-sync every 5 minutes
Schedule::command('mail:sync --auto')->everyFiveMinutes()->withoutOverlapping(10)
    ->name('mail-auto-sync')
    ->when(fn () => ! config('widgets.read_only_preview'));

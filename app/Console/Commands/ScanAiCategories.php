<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ai\AiCategory;
use App\Services\Ai\Scanning\CategoryScanDispatcher;
use App\Services\Ai\Scanning\CategoryScanService;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ScanAiCategories extends Command
{
    protected $signature = 'ai:scan {category? : Category ID or slug; omit for all active categories}
        {--provider= : Override the category and global provider}
        {--queue : Dispatch durable scans to the ai-scans queue}
        {--due : Process only categories due now; used by the desktop scheduler}';

    protected $description = 'Scan configured categories and upsert validated AI findings into SQLite';

    public function handle(CategoryScanService $scans, CategoryScanDispatcher $dispatcher): int
    {
        if (config('widgets.read_only_preview')) {
            $this->error('Podgląd tylko do odczytu nie uruchamia skanów.');

            return self::FAILURE;
        }
        if ($this->option('due') && ! config('ai_scanning.enabled')) {
            $this->info('Automatyczne skanowanie AI jest wyłączone.');

            return self::SUCCESS;
        }
        if ($this->option('due') && Queue::getDefaultDriver() !== 'database') {
            $this->error('Cykliczne skany wymagają QUEUE_CONNECTION=database. Scheduler tylko zleca pracę.');

            return self::FAILURE;
        }
        foreach (['ai_categories', 'ai_category_prompts', 'ai_search_runs', 'ai_findings', 'ai_finding_identities'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Brak tabel skanowania AI. Uruchom przygotowane migracje.');

                return self::FAILURE;
            }
        }
        $provider = $this->option('provider');
        if ($provider !== null && ! preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $provider)) {
            $this->error('Nieprawidłowy klucz providera.');

            return self::INVALID;
        }
        $query = AiCategory::query()->orderBy('id');
        $requested = $this->argument('category');
        if ($requested !== null) {
            $query->where(fn ($query) => $query->where('slug', $requested)->when(ctype_digit($requested), fn ($query) => $query->orWhere('id', (int) $requested)));
        } else {
            $query->where('is_active', true);
        }
        if ($this->option('due')) {
            $query->where('is_active', true)->whereNotNull('schedule_cron');
        }
        $categories = $query->get();
        if ($requested !== null && $categories->isEmpty()) {
            $this->error('Nie znaleziono kategorii.');

            return self::FAILURE;
        }
        $failed = 0;
        foreach ($categories as $category) {
            try {
                $slot = null;
                if ($this->option('due')) {
                    $now = CarbonImmutable::now('UTC')->startOfMinute();
                    $timezone = $category->settings['timezone'] ?? config('app.timezone', 'UTC');
                    if (! (new CronExpression($category->schedule_cron))->isDue($now->setTimezone($timezone))) {
                        continue;
                    }
                    $slot = $now;
                }
                $run = $this->option('queue') || $this->option('due')
                    ? $dispatcher->dispatch($category->id, $provider, $slot)
                    : $scans->scan($category->id, $provider);
                $this->line($category->slug.': '.$run->status.'; run='.$run->id.'; nowe='.($run->meta['new_count'] ?? 0));
                if ($run->status === 'failed') {
                    $failed++;
                    $this->error($run->error ?? 'Skan nie powiódł się.');
                }
            } catch (Throwable) {
                $failed++;
                $this->error('Kategoria '.$category->id.': nie udało się przygotować skanu.');
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

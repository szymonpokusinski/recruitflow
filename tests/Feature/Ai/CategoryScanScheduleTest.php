<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Jobs\ScanCategoryJob;
use App\Models\Ai\AiCategory;
use App\Models\Ai\AiFinding;
use App\Models\Ai\AiSearchRun;
use App\Services\Ai\Scanning\AiCategoryService;
use App\Services\Ai\Scanning\CategoryScanService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CategoryScanScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai_providers.default' => 'fake', 'ai_providers.providers.fake.enabled' => true,
            'ai_providers.providers.fake.items' => [['title' => 'Kontrolowany wynik']],
            'ai_scanning.enabled' => true,
            'queue.default' => 'database',
        ]);
        Queue::setDefaultDriver('database');
    }

    private function category(array $values = []): AiCategory
    {
        $category = AiCategory::factory()->active()->create($values + ['schedule_cron' => '* * * * *']);
        app(AiCategoryService::class)->addPrompt($category, 'Test', 'Kontrolowany prompt');

        return $category;
    }

    public function test_due_scan_dispatches_only_active_due_categories_and_keeps_others_manual(): void
    {
        Bus::fake([ScanCategoryJob::class]);
        $due = $this->category();
        $this->category(['schedule_cron' => null]);
        $this->category(['is_active' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00 UTC'));
        $this->category(['schedule_cron' => '30 12 * * *', 'settings' => ['timezone' => 'UTC']]);
        $this->artisan('ai:scan', ['--due' => true, '--queue' => true])->assertExitCode(0);
        Bus::assertDispatchedTimes(ScanCategoryJob::class, 1);
        Bus::assertDispatched(ScanCategoryJob::class, fn ($job) => $job->categoryId === $due->id && $job->queue === 'ai-scans');
        $this->assertDatabaseCount('ai_search_runs', 1);
        $this->assertSame('queued', AiSearchRun::query()->sole()->status);
    }

    public function test_due_slot_is_idempotent_even_after_the_worker_completed(): void
    {
        Bus::fake([ScanCategoryJob::class]);
        $category = $this->category();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:05 UTC'));
        $this->artisan('ai:scan', ['--due' => true, '--queue' => true])->assertExitCode(0);
        $run = AiSearchRun::query()->sole();
        (new ScanCategoryJob($category->id, runId: $run->id))->handle(app(CategoryScanService::class));
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->travel(10)->seconds();
        $this->artisan('ai:scan', ['--due' => true, '--queue' => true])->assertExitCode(0);
        Bus::assertDispatchedTimes(ScanCategoryJob::class, 1);
        $this->assertDatabaseCount('ai_search_runs', 1);
        $this->travel(1)->minutes();
        $this->artisan('ai:scan', ['--due' => true, '--queue' => true])->assertExitCode(0);
        Bus::assertDispatchedTimes(ScanCategoryJob::class, 2);
        $this->assertDatabaseCount('ai_search_runs', 2);
    }

    public function test_cron_uses_category_timezone_while_slot_is_stored_in_utc(): void
    {
        Bus::fake([ScanCategoryJob::class]);
        $category = $this->category(['schedule_cron' => '0 14 * * *', 'settings' => ['timezone' => 'Europe/Warsaw']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:10 UTC'));
        $this->artisan('ai:scan', ['--due' => true])->assertExitCode(0);
        Bus::assertDispatched(ScanCategoryJob::class, fn ($job) => $job->categoryId === $category->id);
        $this->assertSame('2026-10-14 12:00:00', AiSearchRun::query()->sole()->scheduled_for->format('Y-m-d H:i:s'));
    }

    public function test_disabled_automatic_scanning_does_not_dispatch_anything(): void
    {
        Bus::fake([ScanCategoryJob::class]);
        $this->category();
        config(['ai_scanning.enabled' => false]);
        $this->artisan('ai:scan', ['--due' => true])->assertExitCode(0);
        Bus::assertNotDispatched(ScanCategoryJob::class);
        $this->assertDatabaseCount('ai_search_runs', 0);
        $this->artisan('ai:scan')->assertExitCode(0);
        $this->assertDatabaseCount('ai_findings', 1);
    }

    public function test_scheduler_and_native_worker_are_configured_with_safe_timeouts(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'ai-category-scans');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->filtersPass($this->app));
        config(['ai_scanning.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['ai_scanning.enabled' => true, 'widgets.read_only_preview' => true]);
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertSame(['ai-scans'], config('nativephp.queue_workers.ai-scans.queues'));
        $this->assertSame(['integrations'], config('nativephp.queue_workers.recruitment-sync.queues'));
        $this->assertGreaterThan(config('nativephp.queue_workers.ai-scans.timeout'), config('queue.connections.database.retry_after'));
        $this->assertGreaterThan((new ScanCategoryJob(1))->timeout, config('ai_scanning.running_lease_seconds'));
    }

    public function test_read_only_preview_and_unknown_category_are_rejected_without_writes(): void
    {
        $category = $this->category();
        config(['widgets.read_only_preview' => true]);
        $this->artisan('ai:scan', ['category' => $category->slug])->assertExitCode(1);
        config(['widgets.read_only_preview' => false]);
        $this->artisan('ai:scan', ['category' => 'missing-category'])->assertExitCode(1);
        $this->assertDatabaseCount('ai_search_runs', 0);
    }

    public function test_database_queue_survives_serialization_and_worker_processes_pending_run(): void
    {
        config(['queue.default' => 'database']);
        Queue::setDefaultDriver('database');
        $category = $this->category();
        $this->artisan('ai:scan', ['category' => $category->slug, '--queue' => true])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 1);
        $run = AiSearchRun::query()->sole();
        $this->assertSame('queued', $run->status);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'ai-scans', '--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, AiFinding::all());
        Http::assertNothingSent();
    }

    public function test_sqlite_open_run_constraint_protects_against_parallel_claims(): void
    {
        $category = $this->category();
        AiSearchRun::factory()->create(['category_id' => $category->id, 'status' => 'queued']);
        $this->expectException(UniqueConstraintViolationException::class);
        AiSearchRun::factory()->create(['category_id' => $category->id, 'status' => 'running']);
    }
}

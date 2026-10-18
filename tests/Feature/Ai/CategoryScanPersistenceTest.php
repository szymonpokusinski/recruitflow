<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Ai\AiFinding;
use App\Models\Ai\AiSearchRun;
use App\Services\Ai\Scanning\AiCategoryService;
use App\Services\Ai\Scanning\CategoryScanService;
use App\Services\Ai\Scanning\FindingRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CategoryScanPersistenceTest extends TestCase
{
    public function test_sqlite_file_retains_queue_findings_and_review_state_after_reconnect(): void
    {
        Http::preventStrayRequests();
        $path = tempnam(sys_get_temp_dir(), 'recruitflow-ai-scan-');
        $this->assertNotFalse($path);
        config([
            'database.default' => 'scan_persistence',
            'database.connections.scan_persistence' => ['driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => true],
            'queue.default' => 'database',
            'ai_providers.default' => 'fake', 'ai_providers.providers.fake.enabled' => true,
            'ai_providers.providers.fake.items' => [['title' => 'Kontrolowany wynik', 'url' => 'https://example.org/test/job']],
        ]);
        Queue::setDefaultDriver('database');
        try {
            $this->artisan('migrate', ['--database' => 'scan_persistence', '--force' => true])->assertExitCode(0);
            $category = app(AiCategoryService::class)->save(['slug' => 'persistent-test', 'name' => 'Test trwałości', 'is_active' => true]);
            app(AiCategoryService::class)->addPrompt($category, 'Test', 'Kontrolowane dane testowe');
            $this->artisan('ai:scan', ['category' => $category->slug, '--queue' => true])->assertExitCode(0);
            $this->assertSame('queued', AiSearchRun::query()->sole()->status);
            $this->assertDatabaseCount('jobs', 1);
            DB::purge('scan_persistence');
            $this->assertDatabaseCount('jobs', 1);
            $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'ai-scans', '--once' => true, '--sleep' => 0])->assertExitCode(0);
            $this->assertSame('succeeded', AiSearchRun::query()->sole()->status);
            app(FindingRepository::class)->markSeen(AiFinding::query()->sole());
            DB::purge('scan_persistence');
            $this->assertFalse(AiFinding::query()->sole()->is_new);
            $run = app(CategoryScanService::class)->scan($category->id);
            $this->assertSame('succeeded', $run->status);
            $this->assertSame(0, $run->meta['new_count']);
            $this->assertDatabaseCount('ai_findings', 1);
            $this->assertDatabaseCount('offers', 0);
            Http::assertNothingSent();
        } finally {
            DB::disconnect('scan_persistence');
            unlink($path);
        }
    }
}

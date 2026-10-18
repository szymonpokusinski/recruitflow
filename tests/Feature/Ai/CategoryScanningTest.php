<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Jobs\ScanCategoryJob;
use App\Models\Ai\AiCategory;
use App\Models\Ai\AiCategoryPrompt;
use App\Models\Ai\AiFinding;
use App\Models\Ai\AiSearchRun;
use App\Services\Ai\AiClock;
use App\Services\Ai\Scanning\AiCategoryService;
use App\Services\Ai\Scanning\CategoryScanDispatcher;
use App\Services\Ai\Scanning\CategoryScanService;
use App\Services\Ai\Scanning\FindingRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\Ai\ControlledScanProvider;
use Tests\Support\Ai\FakeAiClock;
use Tests\TestCase;

class CategoryScanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        ControlledScanProvider::$calls = [];
        config([
            'ai_providers.default' => 'test_scan',
            'ai_providers.drivers.test_scan' => ControlledScanProvider::class,
            'ai_providers.providers.test_scan' => ['driver' => 'test_scan', 'enabled' => true, 'model' => 'test-model', 'items' => [['title' => 'Księgowy', 'url' => 'https://example.org/job/1', 'price' => 0, 'confidence' => 0]]],
        ]);
    }

    private function category(array $values = []): AiCategory
    {
        $category = AiCategory::factory()->active()->create($values);
        app(AiCategoryService::class)->addPrompt($category, 'Główny prompt', 'Znajdź oferty zgodnie z kryteriami.');

        return $category;
    }

    private function scan(AiCategory $category, ?string $provider = null): AiSearchRun
    {
        return app(CategoryScanService::class)->scan($category->id, $provider);
    }

    public function test_happy_path_persists_domain_findings_and_audits_prompt_version(): void
    {
        $category = $this->category(['settings' => ['filters' => ['location' => 'Warszawa'], 'options' => ['max_items' => 5]]]);
        $run = $this->scan($category);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('test_scan', $run->provider);
        $this->assertSame('test-model', $run->model);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->meta['new_count']);
        $this->assertSame(1, $run->meta['prompt_version']);
        $this->assertSame(hash('sha256', 'Znajdź oferty zgodnie z kryteriami.'), $run->meta['prompt_hash']);
        $finding = AiFinding::query()->sole();
        $this->assertSame($category->id, $finding->category_id);
        $this->assertSame($run->id, $finding->run_id);
        $this->assertTrue($finding->is_new);
        $this->assertSame(0.0, $finding->price);
        $this->assertSame(0.0, $finding->confidence);
        $this->assertSame(['location' => 'Warszawa'], ControlledScanProvider::$calls[0]['options']['filters']);
        foreach (['offers', 'applications', 'sync_outbox', 'ai_runs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_rescan_upserts_without_resetting_first_seen_or_user_review_flag(): void
    {
        $category = $this->category();
        $first = $this->scan($category);
        $finding = AiFinding::query()->sole();
        $firstSeen = $finding->first_seen_at;
        app(FindingRepository::class)->markSeen($finding);
        $this->travel(1)->minutes();
        config(['ai_providers.providers.test_scan.items' => [['title' => 'Księgowy — aktualizacja', 'url' => 'https://example.org/job/1?utm_source=ai#section', 'price' => 100]]]);
        $second = $this->scan($category);
        $finding->refresh();
        $this->assertDatabaseCount('ai_findings', 1);
        $this->assertSame(0, $second->meta['new_count']);
        $this->assertFalse($finding->is_new);
        $this->assertEquals($firstSeen, $finding->first_seen_at);
        $this->assertGreaterThan($firstSeen, $finding->last_seen_at);
        $this->assertSame('Księgowy — aktualizacja', $finding->title);
        $this->assertSame(100.0, $finding->price);
        $this->assertSame(0.0, $finding->confidence);
        $this->assertSame($second->id, $finding->run_id);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_duplicate_items_inside_one_response_create_only_one_finding(): void
    {
        $category = $this->category();
        $item = ['title' => 'A', 'url' => 'https://example.org/one'];
        config(['ai_providers.providers.test_scan.items' => [$item, $item]]);
        $run = $this->scan($category);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(2, $run->meta['received_count']);
        $this->assertSame(1, $run->meta['observed_count']);
        $this->assertSame(1, $run->meta['new_count']);
        $this->assertDatabaseCount('ai_findings', 1);
    }

    public function test_external_identity_survives_url_changes_and_aliases_are_retained(): void
    {
        $category = $this->category();
        config(['ai_providers.providers.test_scan.items' => [['title' => 'A', 'external_id' => 'stable-1', 'url' => 'https://example.org/a']]]);
        $this->scan($category);
        config(['ai_providers.providers.test_scan.items' => [['title' => 'A updated', 'external_id' => 'stable-1', 'url' => 'https://example.org/b']]]);
        $this->assertSame('succeeded', $this->scan($category)->status);
        config(['ai_providers.providers.test_scan.items' => [['title' => 'A older link', 'url' => 'https://example.org/a']]]);
        $this->scan($category);
        $this->assertDatabaseCount('ai_findings', 1);
        $this->assertDatabaseCount('ai_finding_identities', 3);
    }

    public function test_missing_url_can_be_promoted_without_creating_a_duplicate(): void
    {
        $category = $this->category();
        config(['ai_providers.providers.test_scan.items' => [['title' => 'A', 'external_id' => 'stable-1']]]);
        $this->scan($category);
        config(['ai_providers.providers.test_scan.items' => [['title' => 'A', 'external_id' => 'stable-1', 'url' => 'https://example.org/a']]]);
        $this->scan($category);
        $this->assertDatabaseCount('ai_findings', 1);
        $this->assertSame('https://example.org/a', AiFinding::query()->sole()->url);
    }

    public function test_external_ids_do_not_merge_distinct_source_hosts(): void
    {
        $category = $this->category();
        config(['ai_providers.providers.test_scan.items' => [
            ['title' => 'A', 'external_id' => '123', 'url' => 'https://first.example.org/a'],
            ['title' => 'B', 'external_id' => '123', 'url' => 'https://second.example.org/b'],
        ]]);
        $this->assertSame('succeeded', $this->scan($category)->status);
        $this->assertDatabaseCount('ai_findings', 2);
    }

    public function test_content_hash_dedupes_without_external_ids_or_urls(): void
    {
        $category = $this->category();
        config(['ai_providers.providers.test_scan.items' => [['title' => 'A', 'location' => 'Warszawa', 'description' => 'Obowiązki']]]);
        $this->scan($category);
        $this->scan($category);
        $this->assertDatabaseCount('ai_findings', 1);
    }

    public function test_provider_switch_same_url_dedupes_but_different_categories_remain_separate(): void
    {
        $category = $this->category();
        $this->scan($category);
        config(['ai_providers.providers.another' => ['driver' => 'test_scan', 'enabled' => true, 'items' => [['title' => 'A', 'url' => 'https://example.org/job/1']]]]);
        $this->assertSame('another', $this->scan($category, 'another')->provider);
        $this->assertDatabaseCount('ai_findings', 1);
        $this->scan($this->category());
        $this->assertDatabaseCount('ai_findings', 2);
    }

    public function test_provider_precedence_is_cli_then_category_then_global(): void
    {
        config(['ai_providers.providers.category_provider' => ['driver' => 'test_scan', 'enabled' => true], 'ai_providers.providers.override_provider' => ['driver' => 'test_scan', 'enabled' => true]]);
        $category = $this->category(['default_provider' => 'category_provider']);
        $this->assertSame('category_provider', $this->scan($category)->provider);
        $this->assertSame('override_provider', $this->scan($category, 'override_provider')->provider);
        $category->update(['default_provider' => null]);
        $this->assertSame('test_scan', $this->scan($category)->provider);
    }

    public function test_empty_result_is_success_and_does_not_delete_previous_findings(): void
    {
        $category = $this->category();
        $this->scan($category);
        config(['ai_providers.providers.test_scan.items' => []]);
        $run = $this->scan($category);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(0, $run->meta['received_count']);
        $this->assertSame(0, $run->meta['new_count']);
        $this->assertDatabaseCount('ai_findings', 1);
    }

    public function test_provider_failure_is_recorded_without_losing_data_or_leaking_error(): void
    {
        $category = $this->category();
        $this->scan($category);
        $before = AiFinding::query()->sole()->toArray();
        config(['ai_providers.providers.test_scan.mode' => 'error']);
        $run = $this->scan($category);
        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_failed', $run->meta['error_code']);
        $this->assertStringNotContainsString('private-error-token', $run->toJson());
        $this->assertSame($before, AiFinding::query()->sole()->toArray());
    }

    public function test_invalid_dto_is_rejected_before_upsert(): void
    {
        config(['ai_providers.providers.test_scan.mode' => 'invalid']);
        $run = $this->scan($this->category());
        $this->assertSame('failed', $run->status);
        $this->assertSame('invalid_response', $run->meta['error_code']);
        $this->assertDatabaseCount('ai_findings', 0);
    }

    public function test_invalid_cursor_json_uses_real_driver_path_with_http_fake(): void
    {
        $this->app->instance(AiClock::class, new FakeAiClock);
        config(['ai_providers.providers.cursor.api_key' => 'test-key', 'ai_providers.providers.cursor.credential_ref' => null]);
        $agent = '';
        Http::fake(['https://api.cursor.com/v1/*' => function ($request) use (&$agent) {
            if ($request->url() === 'https://api.cursor.com/v1/agents') {
                $agent = $request['agentId'];

                return Http::response(['agent' => ['id' => $agent], 'run' => ['id' => 'run-test', 'agentId' => $agent]], 201);
            }
            if (str_ends_with($request->url(), '/archive')) {
                return Http::response(['id' => $agent]);
            }

            return Http::response(['id' => 'run-test', 'agentId' => $agent, 'status' => 'FINISHED', 'result' => 'invalid JSON']);
        }]);
        $run = $this->scan($this->category(), 'cursor');
        $this->assertSame('failed', $run->status);
        $this->assertSame('invalid_response', $run->meta['error_code']);
        $this->assertDatabaseCount('ai_findings', 0);
        Http::assertSentCount(3);
    }

    public function test_identity_conflict_rolls_back_all_findings_in_the_response(): void
    {
        $category = $this->category();
        config(['ai_providers.providers.test_scan.items' => [
            ['title' => 'A', 'url' => 'https://example.org/a', 'external_id' => 'A'],
            ['title' => 'B', 'url' => 'https://example.org/b', 'external_id' => 'B'],
        ]]);
        $this->scan($category);
        $before = AiFinding::query()->orderBy('id')->get()->toArray();
        config(['ai_providers.providers.test_scan.items' => [
            ['title' => 'New record', 'url' => 'https://example.org/new'],
            ['title' => 'Conflicting record', 'url' => 'https://example.org/a', 'external_id' => 'B'],
        ]]);
        $run = $this->scan($category);
        $this->assertSame('failed', $run->status);
        $this->assertSame('identity_conflict', $run->meta['error_code']);
        $this->assertSame($before, AiFinding::query()->orderBy('id')->get()->toArray());
    }

    public function test_missing_prompt_and_inactive_category_do_not_call_provider(): void
    {
        $missing = AiCategory::factory()->active()->create();
        $this->assertSame('prompt_missing', $this->scan($missing)->meta['error_code']);
        $inactive = AiCategory::factory()->create();
        $this->assertSame('skipped', $this->scan($inactive)->status);
        $this->assertSame([], ControlledScanProvider::$calls);
    }

    public function test_prompt_versions_are_immutable_and_only_latest_activation_is_used(): void
    {
        $category = $this->category();
        $second = app(AiCategoryService::class)->addPrompt($category, 'Nowa wersja', 'Nowe kryteria');
        $this->assertSame(2, $second->version);
        $this->assertSame(1, $category->prompts()->where('is_active', true)->count());
        $this->assertSame('Znajdź oferty zgodnie z kryteriami.', $category->prompts()->where('version', 1)->first()->prompt_text);
        $run = $this->scan($category);
        $this->assertSame(2, $run->meta['prompt_version']);
        $this->assertSame('Nowe kryteria', ControlledScanProvider::$calls[0]['prompt']);
    }

    public function test_database_enforces_a_single_active_prompt(): void
    {
        $category = $this->category();
        $this->expectException(UniqueConstraintViolationException::class);
        AiCategoryPrompt::factory()->active()->create(['category_id' => $category->id, 'version' => 2]);
    }

    public function test_active_category_claim_prevents_overlapping_scans(): void
    {
        $category = $this->category();
        AiSearchRun::factory()->create(['category_id' => $category->id, 'status' => 'running', 'finished_at' => null]);
        $run = $this->scan($category);
        $this->assertSame('skipped', $run->status);
        $this->assertSame('category_busy', $run->meta['error_code']);
        $this->assertSame([], ControlledScanProvider::$calls);
    }

    public function test_job_replay_is_idempotent_even_after_the_run_finished(): void
    {
        $category = $this->category();
        $job = new ScanCategoryJob($category->id);
        $job->handle(app(CategoryScanService::class));
        unserialize(serialize($job))->handle(app(CategoryScanService::class));
        $this->assertCount(1, ControlledScanProvider::$calls);
        $this->assertDatabaseCount('ai_search_runs', 1);
        $this->assertDatabaseCount('ai_findings', 1);
    }

    public function test_stale_run_is_failed_then_new_scan_can_execute(): void
    {
        $category = $this->category();
        $stale = AiSearchRun::factory()->create(['category_id' => $category->id, 'status' => 'running', 'started_at' => now()->subMinutes(20), 'finished_at' => null]);
        $this->assertSame('succeeded', $this->scan($category)->status);
        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('interrupted', $stale->fresh()->meta['error_code']);
    }

    public function test_stale_provider_response_cannot_write_findings(): void
    {
        $category = $this->category();
        config(['ai_providers.providers.test_scan.callback' => fn () => $this->travel(20)->minutes()]);
        $run = $this->scan($category);
        $this->assertSame('failed', $run->status);
        $this->assertSame('stale_run', $run->meta['error_code']);
        $this->assertDatabaseCount('ai_findings', 0);
    }

    public function test_batch_continues_after_one_category_fails(): void
    {
        config(['ai_providers.providers.broken' => ['driver' => 'test_scan', 'enabled' => true, 'mode' => 'provider-error']]);
        $bad = $this->category(['default_provider' => 'broken']);
        $good = $this->category();
        $this->artisan('ai:scan')->assertExitCode(1);
        $this->assertDatabaseHas('ai_search_runs', ['category_id' => $bad->id, 'status' => 'failed']);
        $this->assertDatabaseHas('ai_search_runs', ['category_id' => $good->id, 'status' => 'succeeded']);
        $this->assertDatabaseCount('ai_findings', 1);
    }

    public function test_command_accepts_slug_id_and_explicit_provider_override(): void
    {
        $category = $this->category(['default_provider' => 'unknown_provider']);
        $this->artisan('ai:scan', ['category' => $category->slug, '--provider' => 'test_scan'])->assertExitCode(0);
        $this->artisan('ai:scan', ['category' => (string) $category->id, '--provider' => 'test_scan'])->assertExitCode(0);
        $this->assertDatabaseCount('ai_findings', 1);
        $this->assertDatabaseCount('ai_search_runs', 2);
    }

    public function test_queue_dispatch_is_durable_and_not_duplicated_while_waiting(): void
    {
        Bus::fake([ScanCategoryJob::class]);
        $category = $this->category();
        $dispatcher = app(CategoryScanDispatcher::class);
        $run = $dispatcher->dispatch($category->id);
        $second = $dispatcher->dispatch($category->id);
        $this->assertSame('queued', $run->status);
        $this->assertSame($run->id, $second->id);
        Bus::assertDispatchedTimes(ScanCategoryJob::class, 1);
        $this->assertSame([], ControlledScanProvider::$calls);
        (new ScanCategoryJob($category->id, runId: $run->id))->handle(app(CategoryScanService::class));
        $this->assertSame('succeeded', $run->fresh()->status);
    }

    public function test_factories_create_consistent_foreign_keys(): void
    {
        $finding = AiFinding::factory()->create();
        $this->assertSame($finding->category_id, $finding->run->category_id);
        $prompt = AiCategoryPrompt::factory()->create();
        $this->assertInstanceOf(AiCategory::class, $prompt->category);
        $this->assertFalse($prompt->is_active);
        $this->assertFalse($prompt->category->is_active);
    }

    public function test_category_configuration_rejects_secrets_cron_and_invalid_filters(): void
    {
        $service = app(AiCategoryService::class);
        $category = $service->save(['slug' => 'accounting', 'name' => 'Księgowość']);
        $this->assertSame([], $category->settings);
        foreach ([['settings' => ['options' => ['api_key' => 'secret']]], ['schedule_cron' => 'bad cron'], ['settings' => ['filters' => ['budget' => ['min' => 5, 'max' => 1]]]]] as $change) {
            try {
                $service->save($change, $category);
                $this->fail('Invalid configuration accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('ai_categories', 1);
    }

    public function test_scan_validates_directly_modified_settings_before_provider_access(): void
    {
        $category = $this->category(['settings' => ['options' => ['api_key' => 'never-store-in-meta']]]);
        $run = $this->scan($category);
        $this->assertSame('failed', $run->status);
        $this->assertSame('invalid_configuration', $run->meta['error_code']);
        $this->assertStringNotContainsString('never-store-in-meta', $run->toJson());
        $this->assertSame([], ControlledScanProvider::$calls);
    }
}

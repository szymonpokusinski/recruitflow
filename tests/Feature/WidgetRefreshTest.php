<?php

namespace Tests\Feature;

use App\Integrations\IntegrationException;
use App\Integrations\OutboxSynchronizer;
use App\Integrations\SyncService;
use App\Jobs\RefreshWidgetData;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Recruitment\RecruitmentSetting;
use App\Widgets\OpenWidgetDeepLink;
use App\Widgets\WidgetDeepLink;
use App\Widgets\WidgetSnapshotPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Native\Desktop\Events\App\OpenedFromURL;
use Tests\TestCase;

class WidgetRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_link_opens_settings_and_queues_a_read_only_refresh(): void
    {
        Queue::fake();
        Http::fake();
        $this->assertSame('/settings?widget=refresh', app(WidgetDeepLink::class)->path('recruitflow://refresh'));
        app(OpenWidgetDeepLink::class)->handle(new OpenedFromURL('recruitflow://refresh'));
        Queue::assertPushed(RefreshWidgetData::class);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/window/url')
            && str_contains($request['url'], '/settings?widget=refresh'));
    }

    public function test_no_google_source_publishes_local_data_without_pushing_outbox(): void
    {
        $sync = $this->mock(SyncService::class);
        $sync->shouldNotReceive('sync');
        $this->mock(OutboxSynchronizer::class)->shouldNotReceive('push');
        $publisher = app(WidgetSnapshotPublisher::class);
        (new RefreshWidgetData)->handle($sync, $publisher);
        $this->assertSame('succeeded', RecruitmentSetting::find('widget_refresh')->value['status']);
    }

    public function test_read_failure_keeps_local_records_and_reports_failure(): void
    {
        $account = IntegrationAccount::create(['provider' => 'google', 'name' => 'Fixture', 'credential_ref' => (string) Str::uuid(), 'status' => 'connected']);
        $source = DataSource::create(['integration_account_id' => $account->id, 'name' => 'Fixture', 'type' => 'google_sheets', 'external_id' => 'fixture',
            'enabled' => true, 'read_only' => true, 'sync_interval_minutes' => 30]);
        $sync = $this->mock(SyncService::class);
        $sync->shouldReceive('sync')->once()->withArgs(fn ($item, $trigger) => $item->id === $source->id && $trigger === 'widget')
            ->andThrow(new IntegrationException('Brak połączenia', 'network_error'));
        $this->mock(OutboxSynchronizer::class)->shouldNotReceive('push');
        $publisher = app(WidgetSnapshotPublisher::class);
        (new RefreshWidgetData)->handle($sync, $publisher);
        $this->assertSame('failed', RecruitmentSetting::find('widget_refresh')->value['status']);
        $this->assertDatabaseHas('data_sources', ['id' => $source->id, 'enabled' => true]);
    }

    public function test_preview_never_queues_a_refresh_or_writes_refresh_metadata(): void
    {
        config(['widgets.read_only_preview' => true]);
        Http::fake();
        Queue::fake();
        app(OpenWidgetDeepLink::class)->handle(new OpenedFromURL('recruitflow://refresh'));
        Queue::assertNothingPushed();
        (new RefreshWidgetData)->handle(app(SyncService::class), app(WidgetSnapshotPublisher::class));
        $this->assertDatabaseMissing('recruitment_settings', ['key' => 'widget_refresh']);
    }
}

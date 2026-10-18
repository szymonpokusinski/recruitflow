<?php

namespace Tests\Feature;

use App\Models\Integrations\DataSourceRecord;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\OfferEvaluation;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\RecruitmentSetting;
use App\Models\Recruitment\SearchProfile;
use App\Recruitment\LocalStateMerger;
use App\Recruitment\OfferFields;
use App\Widgets\WidgetDashboardService;
use App\Widgets\WidgetDeepLink;
use App\Widgets\WidgetSnapshotPublisher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Native\Desktop\Events\App\OpenedFromURL;
use Tests\TestCase;

class WidgetDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-13T12:00:00+02:00'));
    }

    private function offer(array $data = [], string $origin = 'local'): Offer
    {
        $id = (string) Str::uuid();

        return Offer::create(['id' => $id, 'external_key' => 'LOCAL_'.$id, 'origin' => $origin,
            'data' => array_replace(OfferFields::empty(), ['id' => $id, 'externalKey' => 'LOCAL_'.$id, 'title' => 'Księgowy',
                'company' => 'Firma testowa', 'createdAt' => now()->toIso8601String(), 'status' => 'open'], $data)]);
    }

    public function test_empty_snapshot_has_version_and_no_fabricated_sync_or_review_date(): void
    {
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame([], $snapshot['offers']);
        $this->assertNull($snapshot['lastSuccessfulSyncAt']);
        $this->assertNull($snapshot['statistics']['unreviewed']);
        $this->assertSame(0, $snapshot['statistics']['newToday']);
    }

    public function test_today_and_last_24_hours_use_first_seen_and_do_not_offer_closed_or_applied_jobs(): void
    {
        $today = $this->offer(['createdAt' => '2026-10-13T08:00:00+02:00']);
        $yesterday = $this->offer(['createdAt' => '2026-10-12T18:00:00+02:00']);
        $this->offer(['createdAt' => '2026-10-10T10:00:00+02:00']);
        $this->offer(['portalStatus' => 'Wygasła', 'status' => 'closed']);
        $this->offer(['cvSent' => true, 'applicationStage' => 'Kontakt HR']);
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertSame(1, $snapshot['statistics']['newToday']);
        $this->assertSame(2, $snapshot['statistics']['new24Hours']);
        $this->assertSame(1, $snapshot['statistics']['cvSent']);
        $this->assertSame(1, $snapshot['statistics']['activeProcesses']);
        $rows = collect($snapshot['offers'])->keyBy('id');
        $this->assertTrue($rows[$today->id]['isNewToday']);
        $this->assertFalse($rows[$yesterday->id]['isNewToday']);
        $this->assertTrue($rows[$yesterday->id]['isNew24Hours']);
    }

    public function test_duplicate_portal_urls_keep_earliest_detection_and_do_not_modify_sqlite(): void
    {
        $this->offer(['offerUrl' => 'https://www.pracuj.pl/praca/a,oferta,1005120488', 'createdAt' => '2026-10-01T08:00:00+02:00', 'extraData' => ['found_by' => 'ChatGPT']], 'google_sheets');
        $local = $this->offer(['offerUrl' => 'https://www.pracuj.pl/praca/b,oferta,1005120488?utm_source=gemini', 'createdAt' => now()->toIso8601String()]);
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertCount(1, $snapshot['offers']);
        $this->assertSame($local->id, $snapshot['offers'][0]['id']);
        $this->assertSame(1, $snapshot['deduplicatedCount']);
        $this->assertSame(0, $snapshot['statistics']['newToday']);
        $this->assertDatabaseCount('offers', 2);
    }

    public function test_unknown_remote_first_seen_salary_and_matching_stay_unknown_and_secrets_are_omitted(): void
    {
        $this->offer(['createdAt' => null, 'foundAt' => null, 'extraData' => ['token' => 'SECRET', 'mail_body' => 'PRIVATE_MAIL'],
            'myNotes' => 'PRIVATE_NOTES', 'aiMatchScore' => null], 'google_sheets');
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $row = $snapshot['offers'][0];
        $this->assertNull($row['firstSeenAt']);
        $this->assertNull($row['salary']['min']);
        $this->assertNull($row['match']);
        $this->assertSame(0, $snapshot['statistics']['newToday']);
        $json = json_encode($snapshot);
        foreach (['SECRET', 'PRIVATE_MAIL', 'PRIVATE_NOTES', 'myNotes', 'extraData', 'candidateEmail'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_repeated_remote_detection_cannot_move_first_seen_forward(): void
    {
        $offer = $this->offer(['createdAt' => '2026-10-01T08:00:00+02:00'], 'google_sheets');
        $incoming = array_replace($offer->data, ['createdAt' => now()->toIso8601String()]);
        $record = new DataSourceRecord(['external_key' => $offer->external_key, 'data' => $incoming]);
        app(LocalStateMerger::class)->persist('offers', $record, $incoming);
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertSame('2026-10-01T08:00:00+02:00', $snapshot['offers'][0]['firstSeenAt']);
        $this->assertSame(0, $snapshot['statistics']['new24Hours']);
        $this->assertSame('2026-10-01T08:00:00+02:00', $offer->fresh()->data['firstDetectedAt']);
    }

    public function test_active_profile_uses_its_saved_evaluation_without_relabeling_legacy_ai(): void
    {
        $offer = $this->offer(['aiMatchScore' => 0]);
        $profileId = (string) Str::uuid();
        SearchProfile::create(['id' => $profileId, 'external_key' => 'PROFILE_TEST', 'origin' => 'local', 'data' => ['id' => $profileId, 'name' => 'Księgowość']]);
        RecruitmentSetting::create(['key' => 'active_profile', 'value' => ['id' => $profileId]]);
        OfferEvaluation::create(['id' => (string) Str::uuid(), 'offer_id' => $offer->id, 'profile_id' => $profileId,
            'data' => ['aiMatchScore' => 92, 'source' => 'Zapisana analiza', 'aiEvaluatedAt' => '2026-10-01T08:00:00+02:00']]);
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertSame(92.0, $snapshot['offers'][0]['match']['score']);
        $this->assertSame($profileId, $snapshot['offers'][0]['match']['profileId']);
        $this->assertSame('2026-10-01T08:00:00+02:00', $snapshot['offers'][0]['match']['evaluatedAt']);
        $this->assertSame(1, $snapshot['statistics']['highMatches']);
        $this->assertCount(2, $snapshot['offers'][0]['matches']);
    }

    public function test_zero_ai_score_is_preserved_and_not_assigned_to_a_different_profile(): void
    {
        $this->offer(['aiMatchScore' => 0]);
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertSame(0.0, $snapshot['offers'][0]['match']['score']);
        $this->assertNull($snapshot['offers'][0]['match']['profileId']);
        $this->assertNull($snapshot['offers'][0]['match']['evaluatedAt']);
    }

    public function test_hr_snapshot_only_contains_event_metadata(): void
    {
        $offer = $this->offer();
        $id = (string) Str::uuid();
        RecruitmentEvent::create(['id' => $id, 'external_key' => $id, 'origin' => 'local', 'data' => ['id' => $id, 'offerId' => $offer->id,
            'direction' => 'Przychodząca', 'eventType' => 'Zaproszenie', 'occurredAt' => now()->subHour()->toIso8601String(),
            'interviewAt' => now()->addDay()->toIso8601String(), 'message' => 'PRIVATE_MESSAGE', 'contactEmail' => 'private@example.test']]);
        $snapshot = app(WidgetDashboardService::class)->snapshot();
        $this->assertSame(1, $snapshot['statistics']['newHRReplies']);
        $this->assertSame(1, $snapshot['statistics']['upcomingInterviews']);
        $this->assertStringNotContainsString('PRIVATE_MESSAGE', json_encode($snapshot));
        $this->assertStringNotContainsString('private@example.test', json_encode($snapshot));
    }

    public function test_snapshot_atomic_publication_preserves_previous_file_after_database_failure(): void
    {
        $directory = sys_get_temp_dir().'/recruitflow-widget-test-'.Str::uuid();
        config(['widgets.snapshot_directory' => $directory, 'widgets.enabled' => true]);
        $this->offer();
        $publisher = app(WidgetSnapshotPublisher::class);
        $this->assertNotNull($publisher->publish());
        $path = $directory.'/dashboard-v1.json';
        $before = file_get_contents($path);
        $this->assertSame(0600, fileperms($path) & 0777);
        Schema::drop('offers'); // Only this test's in-memory SQLite connection.
        $this->assertNull($publisher->publish());
        $this->assertSame($before, file_get_contents($path));
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        unlink($directory.'/.publish.lock');
        rmdir($directory);
    }

    public function test_deep_links_only_allow_known_internal_destinations(): void
    {
        $offer = $this->offer();
        $links = app(WidgetDeepLink::class);
        $this->assertSame('/offers/'.$offer->id, $links->path(WidgetDeepLink::offer($offer->id)));
        $this->assertSame('/offers?widget=new', $links->path('recruitflow://offers/new'));
        $this->assertSame('/activity', $links->path('recruitflow://activity'));
        foreach (['https://evil.test', 'recruitflow://offer/../../.env', 'recruitflow://user@activity', 'recruitflow://activity?url=https://evil.test', 'recruitflow://activity#x', 'recruitflow://offer/'.Str::uuid()] as $url) {
            $this->assertNull($links->path($url));
        }
        Http::fake();
        event(new OpenedFromURL(WidgetDeepLink::offer($offer->id)));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/window/open') && $request['url'] === url('/offers/'.$offer->id));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/window/url') && $request['id'] === 'main' && $request['url'] === url('/offers/'.$offer->id));
        $this->get('/offers?widget=new')->assertOk()->assertInertia(fn ($page) => $page->component('Offers')->where('widgetOfferIds', [$offer->id]));
    }
}

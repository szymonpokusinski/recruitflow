<?php

namespace Tests\Feature;

use App\Integrations\CanonicalCache;
use App\Integrations\Mapping\RecruitmentMapper;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceRecord;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\SyncConflict;
use App\Models\Recruitment\SyncOperation;
use App\Recruitment\ConflictResolver;
use App\Recruitment\LocalRecruitmentService;
use App\Recruitment\OfferIdentity;
use App\Recruitment\OfferMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $account = IntegrationAccount::create(['provider' => 'google', 'name' => 'Controlled only', 'credential_ref' => (string) Str::uuid(), 'status' => 'connected']);
        $this->source = DataSource::create(['name' => 'Controlled only', 'type' => 'google_sheets', 'external_id' => 'controlled_only_sheet_123', 'integration_account_id' => $account->id, 'enabled' => true, 'read_only' => true]);
    }

    private function imported(string $key, array $values = []): Offer
    {
        $data = ['id' => RecruitmentMapper::stableId('offers', $key), 'externalKey' => $key, 'company' => 'Controlled firm', 'title' => 'Accountant', 'offerUrl' => 'https://example.org/test-vacancy', 'myInterest' => 'Nowa', 'favorite' => false, 'myNotes' => '', 'priority' => 'Brak', 'cvSent' => false, 'cvSentAt' => null, 'applicationStage' => 'Nie aplikowano', 'schemaProfile' => 'recruitflow', 'status' => 'open', 'salaryMin' => null, 'salaryMax' => null, 'currency' => '', 'requirements' => [], 'description' => '', 'location' => '', 'workMode' => '', 'employment' => '', 'owner' => '', 'department' => '', 'createdAt' => null] + $values;
        $data = array_replace($data, $values);
        DataSourceRecord::updateOrCreate(['data_source_id' => $this->source->id, 'collection' => 'offers', 'external_key' => $key], ['data' => $data]);
        app(CanonicalCache::class)->rebuild();

        return Offer::findOrFail($data['id']);
    }

    private function command(array $values): array
    {
        return ['operationId' => (string) Str::uuid()] + $values;
    }

    public function test_import_preserves_user_fields_accepts_enrichment_and_creates_field_conflict(): void
    {
        $offer = $this->imported('remote-1');
        app(LocalRecruitmentService::class)->updateOffer($offer, $this->command(['myInterest' => 'Nie interesuje', 'favorite' => true, 'myNotes' => 'Private local notes', 'priority' => 'P1 — pilne']));
        $offer = $this->imported('remote-1', ['myInterest' => 'Bardzo interesuje', 'description' => 'New verified description']);
        $this->assertSame('Nie interesuje', $offer->data['myInterest']);
        $this->assertTrue($offer->data['favorite']);
        $this->assertSame('Private local notes', $offer->data['myNotes']);
        $this->assertSame('New verified description', $offer->data['description']);
        $conflict = SyncConflict::where('entity_id', $offer->id)->where('status', 'open')->firstOrFail();
        $this->assertSame('Bardzo interesuje', $conflict->fields['myInterest']['remote']);
        $this->assertSame('Nie aplikowano', $offer->data['applicationStage']);
        app(ConflictResolver::class)->resolve($conflict, $this->command(['choice' => 'remote']));
        $offer->refresh();
        $this->assertSame('Bardzo interesuje', $offer->data['myInterest']);
        $this->assertArrayNotHasKey('myInterest', $offer->local_overrides);
        $this->assertSame('Private local notes', $offer->data['myNotes']);
        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->source->update(['enabled' => false]);
        app(CanonicalCache::class)->rebuild();
        $this->assertDatabaseCount('offers', 1);
        $this->assertSame('Private local notes', Offer::first()->data['myNotes']);
        Http::assertNothingSent();
    }

    public function test_first_connection_merge_retains_local_cv_and_history_and_rebinds_identity(): void
    {
        $service = app(LocalRecruitmentService::class);
        $created = $service->createOffer($this->command(['company' => 'Controlled firm', 'title' => 'Accountant', 'offerUrl' => 'https://example.org/test-vacancy', 'myNotes' => 'Local history remains']));
        $local = Offer::findOrFail($created['id']);
        $service->markCv($local, $this->command(['sent' => true, 'date' => '2026-10-12', 'timezone' => 'Europe/Warsaw']));
        $remote = $this->imported('existing-remote-key');
        $this->assertCount(1, app(OfferIdentity::class)->duplicates());
        app(OfferMergeService::class)->merge($local, $remote, $this->command([]));
        $snapshot = app(CanonicalCache::class)->snapshot();
        $this->assertCount(1, $snapshot['offers']);
        $this->assertSame($created['id'], $snapshot['offers'][0]['id']);
        $this->assertTrue($snapshot['offers'][0]['cvSent']);
        $this->assertSame('existing-remote-key', $snapshot['offers'][0]['externalKey']);
        $this->assertSame('Local history remains', $snapshot['offers'][0]['myNotes']);
        $this->assertCount(1, $snapshot['applications']);
        $this->assertCount(1, $snapshot['activities']);
        $this->assertSame($created['id'], $snapshot['activities'][0]['offerId']);
        $this->assertDatabaseCount('offers', 2);
        $this->assertDatabaseCount('record_aliases', 2);
        $this->assertFalse(SyncOperation::where('entity_type', 'offers')->where('entity_id', $local->id)->where('payload->title', '')->exists());
    }

    public function test_latest_local_edit_is_used_when_resolving_a_conflict(): void
    {
        $offer = $this->imported('remote-2');
        $service = app(LocalRecruitmentService::class);
        $service->updateOffer($offer, $this->command(['myInterest' => 'Interesuje']));
        $offer = $this->imported('remote-2', ['myInterest' => 'Nie interesuje']);
        $service->updateOffer($offer, $this->command(['myInterest' => 'Bardzo interesuje']));
        $conflict = SyncConflict::where('status', 'open')->firstOrFail();
        app(ConflictResolver::class)->resolve($conflict, $this->command(['choice' => 'local']));
        $this->assertSame('Bardzo interesuje', $offer->fresh()->data['myInterest']);
        $this->assertSame('Nie interesuje', $offer->fresh()->remote_baseline['myInterest']);
        $this->assertSame('Bardzo interesuje', SyncOperation::where('entity_type', 'offers')->latest('id')->first()->payload['myInterest']);
        Http::assertNothingSent();
    }
}

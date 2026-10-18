<?php

namespace Tests\Feature;

use App\Integrations\CanonicalCache;
use App\Jobs\SynchronizeRecruitment;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentAudit;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SynchronizeRecruitment::class]);
        Http::preventStrayRequests();
    }

    private function command(array $data = []): array
    {
        return ['operationId' => (string) Str::uuid(), 'actor' => 'Test lokalny'] + $data;
    }

    private function create(array $data = []): Offer
    {
        $response = $this->postJson('/api/recruitment/offers', $this->command($data + ['company' => 'Kontrolowana firma', 'title' => 'Księgowy']));
        $response->assertOk();

        return Offer::findOrFail($response->json('id'));
    }

    public function test_local_mode_is_empty_and_writable_without_google(): void
    {
        $this->getJson('/api/recruitment/state')->assertOk()->assertJsonPath('mode', 'local')->assertJsonPath('writable', true)->assertJsonCount(0, 'snapshot.offers');
        $this->create(['industry' => 'Finanse', 'salaryMin' => 7000, 'salaryMax' => 9000, 'salaryPeriod' => 'Miesiąc', 'currency' => 'PLN', 'certifications' => ['ACCA'], 'customAttributes' => [['label' => 'System księgowy', 'value' => 'Symfonia']]]);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('sync_outbox', 2);
        $this->assertDatabaseCount('recruitment_audits', 1);
        $snapshot = app(CanonicalCache::class)->snapshot();
        $this->assertSame('Finanse', $snapshot['offers'][0]['industry']);
        $this->assertSame(['ACCA'], $snapshot['offers'][0]['certifications']);
        $this->assertSame('local', $snapshot['offers'][0]['syncStatus']);
        Http::assertNothingSent();
    }

    public function test_url_only_draft_and_command_retry_are_idempotent(): void
    {
        $values = $this->command(['offerUrl' => 'https://example.org/jobs/accountant?utm_source=test']);
        $first = $this->postJson('/api/recruitment/offers', $values)->assertOk()->json('id');
        $this->postJson('/api/recruitment/offers', $values)->assertOk()->assertJsonPath('id', $first);
        $offer = Offer::findOrFail($first);
        $this->assertSame('', $offer->data['title']);
        $this->assertSame('draft', $offer->data['completionStatus']);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('local_commands', 1);
        $this->postJson('/api/recruitment/offers', $this->command(['offerUrl' => 'https://example.org/jobs/accountant']))->assertUnprocessable()->assertJsonValidationErrors('offerUrl');
    }

    public function test_full_it_fields_and_attributes_are_editable_without_ai(): void
    {
        $offer = $this->create(['title' => 'PHP Developer', 'industry' => 'IT', 'programmingLanguages' => ['PHP', 'TypeScript'], 'backendFrameworks' => ['Laravel'], 'frontendFrameworks' => ['Vue'], 'workMode' => 'Zdalnie', 'description' => "Pierwszy wiersz\nDrugi", 'salaryMin' => 10000, 'salaryMax' => 15000]);
        $this->putJson('/api/recruitment/offers/'.$offer->id, $this->command(['expectedVersion' => 1, 'backendFrameworks' => ['Laravel', 'Symfony'], 'salaryMax' => 18000, 'customAttributes' => [['label' => 'API', 'value' => 'REST']]]))->assertOk();
        $offer->refresh();
        $this->assertSame(['Laravel', 'Symfony'], $offer->data['backendFrameworks']);
        $this->assertSame(18000.0, (float) $offer->data['salaryMax']);
        $this->assertArrayNotHasKey('aiMatchScore', $offer->data);
        $this->assertSame("Pierwszy wiersz\nDrugi", $offer->data['description']);
        $this->putJson('/api/recruitment/offers/'.$offer->id, $this->command(['expectedVersion' => 1, 'myNotes' => 'stara wersja']))->assertUnprocessable();
    }

    public function test_salary_dates_and_custom_attributes_validate_before_transaction(): void
    {
        $this->postJson('/api/recruitment/offers', $this->command())->assertUnprocessable();
        $this->postJson('/api/recruitment/offers', $this->command(['company' => 'Firma', 'title' => 'Kierowca', 'salaryMin' => 9, 'salaryMax' => 1]))->assertUnprocessable()->assertJsonValidationErrors('salaryMax');
        $this->postJson('/api/recruitment/offers', $this->command(['offerUrl' => 'javascript:alert(1)']))->assertUnprocessable();
        $this->postJson('/api/recruitment/offers', $this->command(['company' => 'Firma', 'title' => 'Kierowca', 'publishedAt' => '2026-10-12', 'applyDeadlineAt' => '2026-10-01']))->assertUnprocessable()->assertJsonValidationErrors('applyDeadlineAt');
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('sync_outbox', 0);
        $offer = $this->create(['salaryMin' => 5000, 'salaryMax' => 6000]);
        $this->putJson('/api/recruitment/offers/'.$offer->id, $this->command(['salaryMin' => 7000]))->assertUnprocessable()->assertJsonValidationErrors('salaryMax');
        $this->assertSame(5000.0, (float) $offer->fresh()->data['salaryMin']);
    }

    public function test_cv_stage_corrections_and_hr_events_preserve_history_without_duplicates(): void
    {
        $offer = $this->create();
        $cv = $this->command(['sent' => true, 'date' => '2026-10-12', 'timezone' => 'Europe/Warsaw', 'channel' => 'Formularz', 'cvVersion' => 'CV_2', 'reference' => 'REF-TEST']);
        $path = '/api/recruitment/offers/'.$offer->id;
        $applicationId = $this->postJson($path.'/cv', $cv)->assertOk()->json('id');
        $this->postJson($path.'/cv', $cv)->assertOk();
        $this->postJson($path.'/cv', $this->command(array_diff_key($cv, ['operationId' => true])))->assertOk();
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('recruitment_events', 1);
        $this->assertTrue($offer->fresh()->data['cvSent']);
        $this->assertSame('2026-10-12', $offer->fresh()->data['cvSentAt']);
        $this->postJson('/api/recruitment/applications/'.$applicationId.'/stage', $this->command(['stage' => 'hr_contact']))->assertOk();
        $this->postJson($path.'/cv', $this->command(['sent' => true, 'date' => '2026-10-11', 'timezone' => 'Europe/Warsaw', 'time' => '09:15', 'channel' => 'Formularz']))->assertOk();
        $this->assertSame('Kontakt HR', $offer->fresh()->data['applicationStage']);
        $this->assertSame('hr_contact', Application::findOrFail($applicationId)->data['status']);
        $this->postJson($path.'/cv', $this->command(['sent' => false, 'timezone' => 'Europe/Warsaw']))->assertOk();
        $this->assertFalse($offer->fresh()->data['cvSent']);
        $this->assertSame('Kontakt HR', $offer->fresh()->data['applicationStage']);
        $this->postJson('/api/recruitment/applications/'.$applicationId.'/events', $this->command(['eventType' => 'Weryfikacja uprawnień', 'occurredAt' => '2026-10-13T10:00:00+02:00', 'message' => 'Sprawdzenie dokumentów zawodowych']))->assertOk();
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('recruitment_events', 5);
        $event = RecruitmentEvent::where('data->eventSubtype', 'Weryfikacja uprawnień')->first();
        $this->assertSame('Inne', $event->data['eventType']);
        $this->putJson($path, $this->command(['myInterest' => 'Nie interesuje', 'favorite' => true, 'myNotes' => 'Ręczna decyzja']))->assertOk();
        $this->assertSame('Kontakt HR', $offer->fresh()->data['applicationStage']);
        $this->assertGreaterThanOrEqual(10, RecruitmentAudit::count());
        Http::assertNothingSent();
    }

    public function test_profiles_are_universal_and_selection_is_persistent(): void
    {
        $id = $this->postJson('/api/recruitment/profiles', $this->command(['name' => 'Księgowy', 'industry' => 'Finanse', 'skills' => ['ACCA'], 'enabled' => true, 'salaryMin' => 6500]))->assertOk()->json('id');
        $this->putJson('/api/recruitment/profile', ['profileId' => $id])->assertOk();
        $this->getJson('/api/recruitment/state')->assertJsonPath('snapshot.activeProfileId', $id)->assertJsonPath('snapshot.profiles.0.industry', 'Finanse');
        $this->assertTrue(SyncOperation::where('entity_type', 'profiles')->where('status', 'local')->exists());
    }
}

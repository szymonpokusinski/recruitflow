<?php

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\Mapping\RecruitFlowSchemaDetector;
use App\Integrations\Mapping\SheetValueNormalizer;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticMappingTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationAccount $account;

    private array $tables;

    private array $names;

    private bool $offline = false;

    private string $identifier = 'synthetic_automatic_sheet_12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $vault = new class implements CredentialStore
        {
            public array $values = [];

            public function read(string $key): ?array
            {
                return $this->values[$key] ?? null;
            }

            public function write(string $key, array $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
        $this->app->instance(CredentialStore::class, $vault);
        $this->account = IntegrationAccount::create(['name' => 'Connected account', 'provider' => 'google', 'status' => 'connected', 'credential_ref' => (string) Str::uuid()]);
        $vault->write($this->account->credential_ref, ['access_token' => 'fake-test-token', 'expires_at' => now()->addHour()->timestamp]);
        $this->tables = [
            'OFERTY' => [['offer_key', 'position', 'company', 'offer_url', 'city', 'work_model', 'effective_status', 'portal_status', 'application_stage', 'cv_sent', 'cv_sent_at', 'salary_currency', 'custom_column'], ['offer-1', 'Developer', 'Test company', 'https://example.com/job', 'Warszawa', 'Remote', 'AKTYWNA', 'Niepotwierdzona', 'Wysłano CV', 'TAK', '12.10.2026 14:30', 'EUR', 'preserved extra']],
            'APLIKACJE' => [['application_id', 'offer_key', 'position', 'company', 'application_stage', 'cv_sent', 'cv_sent_at', 'next_interview_at'], ['app-1', 'offer-1', 'STALE FORMULA TITLE', 'STALE FORMULA COMPANY', 'stale formula status', 'NIE', '', '']],
            'ZDARZENIA_HR' => [['event_id', 'offer_key', 'application_id', 'event_at', 'event_type', 'summary', 'requires_action'], ['event-1', 'offer-1', 'app-1', '12.10.2026 15:00', 'Potwierdzenie', 'Received', 'NIE'], ['event-2', 'offer-1', '', '12.10.2026 16:00', 'Inne', 'Offer event', 'TAK']],
            'PROFILE_SZUKANIA' => [['profile_id', 'profile_name', 'enabled', 'role_keywords'], ['PHP_MID', 'PHP', 'TAK', 'php'], ['JAVA', 'Java', 'NIE', 'java'], ['OTHER', 'Other', true, 'other'], ['FOURTH', 'Fourth', false, 'fourth']],
        ];
        $this->names = [...array_keys($this->tables), ...RecruitFlowSchemaDetector::AUXILIARY];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->offline) {
                throw new ConnectionException;
            }
            $this->assertSame('GET', $request->method());
            $url = rawurldecode($request->url());
            if (str_contains($url, '/values/')) {
                $range = explode('?', explode('/values/', $url)[1])[0];
                preg_match("/^'(.*)'!(\d+):(\d+)$/", $range, $match);
                $title = str_replace("''", "'", $match[1]);
                $this->assertArrayHasKey($title, $this->tables, 'Auxiliary tabs must never be imported');

                return Http::response(['values' => $match[2] === $match[3] ? [$this->tables[$title][0]] : $this->tables[$title]]);
            }

            return Http::response(['properties' => ['title' => 'Automatic test sheet', 'timeZone' => 'Europe/Warsaw'], 'sheets' => array_map(fn ($name, $index) => ['properties' => ['sheetId' => $index, 'title' => $name]], $this->names, array_keys($this->names))]);
        });
    }

    private function connect()
    {
        return $this->postJson('/api/integrations/connect-source', ['spreadsheet' => 'https://docs.google.com/spreadsheets/d/'.$this->identifier.'/edit', 'integration_account_id' => $this->account->id]);
    }

    private function addAiColumns(mixed $score = 87): void
    {
        $this->tables['OFERTY'][0] = [...$this->tables['OFERTY'][0], 'ai_match_score', 'ai_stack_score', 'ai_seniority_score', 'ai_location_score', 'ai_salary_score', 'ai_fit_reasons', 'ai_fit_concerns', 'ai_recommendation', 'ai_cv_suggestions', 'ai_evaluation_confidence', 'ai_evaluated_at'];
        $this->tables['OFERTY'][1] = [...$this->tables['OFERTY'][1], $score, 90, '', 0, 70, "  PHP\nLaravel\n", "Dojazd\nBrak widełek", 'Sprawdź ofertę', "Doprecyzuj doświadczenie\nNie zmieniaj faktów", 'wysoka', '12.10.2026 14:30'];
    }

    public function test_ai_evaluation_is_read_from_sheets_mapped_and_persisted_only_on_offer(): void
    {
        $this->addAiColumns();
        $this->connect()->assertOk();
        $offer = Offer::first()->data;
        $this->assertSame(87, $offer['aiMatchScore']);
        $this->assertSame(90, $offer['aiStackScore']);
        $this->assertNull($offer['aiSeniorityScore']);
        $this->assertSame(0, $offer['aiLocationScore']);
        $this->assertSame(70, $offer['aiSalaryScore']);
        $this->assertSame("  PHP\nLaravel\n", $offer['aiFitReasons']);
        $this->assertSame("Dojazd\nBrak widełek", $offer['aiFitConcerns']);
        $this->assertSame('Sprawdź ofertę', $offer['aiRecommendation']);
        $this->assertSame("Doprecyzuj doświadczenie\nNie zmieniaj faktów", $offer['aiCvSuggestions']);
        $this->assertSame('wysoka', $offer['aiEvaluationConfidence']);
        $this->assertSame('2026-10-12T12:30:00+00:00', $offer['aiEvaluatedAt']);
        $this->assertSame('12.10.2026 14:30', $offer['extraData']['ai_evaluated_at']);
        $this->assertArrayNotHasKey('aiMatchScore', Application::first()->data);
        $this->assertSame(11, DataSource::first()->configurations()->where('role', 'offers')->first()->mappings()->where('field', 'like', 'ai_%')->count());
        $this->getJson('/api/recruitment/state')->assertOk()->assertJsonPath('snapshot.offers.0.aiMatchScore', 87)->assertJsonPath('snapshot.offers.0.aiLocationScore', 0);
    }

    public function test_ai_sync_accepts_zero_and_null_updates_without_duplicates_and_rejects_out_of_range(): void
    {
        $this->addAiColumns(0);
        $this->connect()->assertOk();
        $this->assertSame(0, Offer::first()->data['aiMatchScore']);
        $id = Offer::first()->id;
        $this->tables['OFERTY'][1][13] = '';
        $this->connect()->assertOk();
        $this->assertNull(Offer::first()->data['aiMatchScore']);
        $this->tables['OFERTY'][1][13] = '87,5';
        $this->connect()->assertOk();
        $this->assertSame(87.5, Offer::first()->data['aiMatchScore']);
        $this->tables['OFERTY'][1][13] = 101;
        $this->connect()->assertUnprocessable()->assertJsonPath('reason', 'row_invalid');
        $this->assertSame(87.5, Offer::first()->data['aiMatchScore']);
        $this->assertSame($id, Offer::first()->id);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_existing_cache_projects_ai_fields_without_rewriting_offer_or_oauth(): void
    {
        $this->addAiColumns(0);
        $this->connect()->assertOk();
        $offer = Offer::first();
        $legacy = $offer->data;
        foreach (array_keys($legacy) as $key) {
            if (str_starts_with($key, 'ai')) {
                unset($legacy[$key]);
            }
        }
        $offer->update(['data' => $legacy]);
        $before = $offer->fresh()->getRawOriginal('data');
        $this->getJson('/api/recruitment/state')->assertOk()->assertJsonPath('snapshot.offers.0.aiMatchScore', 0)->assertJsonPath('snapshot.offers.0.aiStackScore', 90)->assertJsonPath('snapshot.offers.0.aiFitReasons', "  PHP\nLaravel\n");
        $this->assertSame($before, $offer->fresh()->getRawOriginal('data'));
        $this->assertSame('connected', $this->account->fresh()->status);
    }

    public function test_regular_sync_enriches_older_automatic_mapping_with_optional_ai_columns(): void
    {
        $this->addAiColumns();
        $this->connect()->assertOk();
        $source = DataSource::first();
        $source->configurations()->where('role', 'offers')->first()->mappings()->where('field', 'like', 'ai_%')->delete();
        $this->tables['OFERTY'][1][13] = 85;
        $this->postJson('/api/integrations/sources/'.$source->id.'/sync')->assertOk();
        $this->assertSame(85, Offer::first()->data['aiMatchScore']);
        $this->assertSame(90, Offer::first()->data['aiStackScore']);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_one_click_recognizes_main_tables_maps_columns_and_imports_every_profile(): void
    {
        $this->connect()->assertOk()->assertJsonPath('counts.offers', 1)->assertJsonPath('counts.applications', 1)->assertJsonPath('counts.events', 2)->assertJsonPath('counts.profiles', 4);
        $this->assertDatabaseCount('data_source_configurations', 4);
        $this->assertDatabaseCount('data_source_records', 8);
        $this->assertDatabaseCount('search_profiles', 4);
        $source = DataSource::first();
        $this->assertTrue($source->enabled);
        $this->assertSame('offer_key', $source->configurations()->where('role', 'offers')->first()->mappings()->where('field', 'id')->first()->column_name);
        $offer = Offer::first()->data;
        $application = Application::first()->data;
        $this->assertSame('AKTYWNA', $offer['effectiveStatus']);
        $this->assertSame('Niepotwierdzona', $offer['portalStatus']);
        $this->assertSame('Wysłano CV', $offer['applicationStage']);
        $this->assertTrue($offer['cvSent']);
        $this->assertSame('preserved extra', $offer['extraData']['custom_column']);
        $this->assertSame('Developer', $application['position']);
        $this->assertSame('Test company', $application['company']);
        $this->assertTrue($application['cvSent']);
        $this->assertSame('sent', $application['status']);
        $events = RecruitmentEvent::orderBy('external_key')->get()->pluck('data');
        $this->assertSame($offer['id'], $events[0]['offerId']);
        $this->assertSame($application['id'], $events[0]['applicationId']);
        $this->assertNull($events[1]['applicationId']);
        $this->assertTrue($events[1]['requiresAction']);
        $this->getJson('/api/recruitment/state')->assertJsonPath('mode', 'connected')->assertJsonCount(4, 'snapshot.profiles');
    }

    public function test_primary_selection_and_disabling_preserve_recruitflow_mapping_and_allow_resynchronization(): void
    {
        $this->connect()->assertOk();
        $source = DataSource::first();
        $offerId = Offer::first()->id;
        $mappingIds = $source->configurations()->with('mappings')->get()->flatMap(fn ($configuration) => $configuration->mappings->pluck('id'))->all();

        $this->putJson('/api/integrations/sources/'.$source->id.'/primary', ['primary' => true])->assertOk();
        $this->assertSame(['recruitflow'], $source->configurations()->pluck('schema_profile')->unique()->values()->all());
        $this->postJson('/api/integrations/sources/'.$source->id.'/sync')->assertOk();
        $this->assertSame($offerId, Offer::first()->id);

        $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => false])->assertOk();
        $this->assertSame(['recruitflow'], $source->configurations()->pluck('schema_profile')->unique()->values()->all());
        $this->assertSame($mappingIds, $source->configurations()->with('mappings')->get()->flatMap(fn ($configuration) => $configuration->mappings->pluck('id'))->all());

        $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => true])->assertOk();
        $this->assertSame($offerId, Offer::first()->id);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('recruitment_events', 2);
        $this->assertDatabaseCount('search_profiles', 4);
    }

    public function test_repeated_automatic_sync_is_idempotent_and_failure_preserves_cache_and_mapping(): void
    {
        $this->connect()->assertOk();
        $ids = Offer::pluck('id')->all();
        $mappingIds = DataSource::first()->configurations()->pluck('id')->all();
        $this->connect()->assertOk();
        $this->assertSame($ids, Offer::pluck('id')->all());
        $this->assertDatabaseCount('data_source_records', 8);
        $mappingIds = DataSource::first()->configurations()->pluck('id')->all();
        $this->tables['ZDARZENIA_HR'][2][0] = 'event-1';
        $this->connect()->assertUnprocessable()->assertJsonPath('reason', 'duplicate_key');
        $this->assertSame($ids, Offer::pluck('id')->all());
        $this->assertSame($mappingIds, DataSource::first()->configurations()->pluck('id')->all());
        $this->assertDatabaseCount('data_source_records', 8);
        $this->offline = true;
        $this->connect()->assertUnprocessable()->assertJsonPath('reason', 'offline');
        $this->assertTrue(DataSource::first()->enabled);
    }

    public function test_technical_signatures_recognize_renamed_tabs_and_optional_missing_fields(): void
    {
        $renamed = ['OFERTY' => 'Jobs custom', 'APLIKACJE' => 'Applications custom', 'ZDARZENIA_HR' => 'History custom', 'PROFILE_SZUKANIA' => 'Profiles custom'];
        foreach ($renamed as $old => $new) {
            $this->tables[$new] = $this->tables[$old];
            unset($this->tables[$old]);
        }
        $this->names = [...array_keys($this->tables), ...RecruitFlowSchemaDetector::AUXILIARY];
        $this->connect()->assertOk()->assertJsonPath('records_count', 8);
        $this->assertNull(Offer::first()->data['salaryMin']);
    }

    public function test_google_serial_polish_dates_booleans_and_empty_cells_are_normalized(): void
    {
        $serial = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInDays(CarbonImmutable::create(2026, 10, 12, 0, 0, 0, 'UTC')) + 14.5 / 24;
        $this->assertSame('2026-10-12T12:30:00+00:00', SheetValueNormalizer::date($serial, 'Europe/Warsaw', 'date'));
        $this->assertSame('2026-10-12T12:30:00+00:00', SheetValueNormalizer::date('12.10.2026 14:30', 'Europe/Warsaw', 'date'));
        $this->assertNull(SheetValueNormalizer::date('', 'Europe/Warsaw', 'date'));
        $this->assertTrue(SheetValueNormalizer::boolean('TAK', 'flag'));
        $this->assertFalse(SheetValueNormalizer::boolean('NIE', 'flag'));
        $this->assertNull(SheetValueNormalizer::boolean('', 'flag'));
        $this->tables['OFERTY'][1][10] = $serial;
        $this->connect()->assertOk();
        $this->assertSame('2026-10-12T12:30:00+00:00', Offer::first()->data['cvSentAt']);
    }

    public function test_unresolved_relations_and_missing_keys_cannot_replace_existing_data(): void
    {
        $this->connect()->assertOk();
        $this->tables['APLIKACJE'][1][1] = 'missing';
        $this->connect()->assertUnprocessable()->assertJsonPath('reason', 'missing_reference');
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('applications', 1);
        $this->tables['APLIKACJE'][1][1] = 'offer-1';
        $this->tables['OFERTY'][1][0] = '';
        $this->connect()->assertUnprocessable()->assertJsonPath('reason', 'row_invalid');
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_non_recruitflow_application_schema_uses_generic_aliases_without_forcing_offer_key(): void
    {
        $this->tables['APLIKACJE'] = [['application_id', 'offer_id', 'candidate_name', 'status'], ['app-1', 'offer-1', 'Example applicant', 'new']];
        $this->connect()->assertOk();
        $this->assertSame('generic', DataSource::first()->configurations()->where('role', 'applications')->first()->schema_profile);
        $this->assertSame('Example applicant', Application::first()->data['candidateName']);
    }

    public function test_removing_optional_column_does_not_block_regular_sync(): void
    {
        $this->connect()->assertOk();
        foreach ($this->tables['OFERTY'] as &$row) {
            array_splice($row, 5, 1);
        }
        unset($row);
        $this->postJson('/api/integrations/sources/'.DataSource::first()->id.'/sync')->assertOk();
        $this->assertSame('', Offer::first()->data['workMode']);
        $this->assertDatabaseCount('data_source_records', 8);
    }
}

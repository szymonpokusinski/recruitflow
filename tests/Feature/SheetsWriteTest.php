<?php

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\Google\GoogleOAuthService;
use App\Integrations\Google\GoogleSheetsGateway;
use App\Integrations\Google\GoogleSheetsSchema;
use App\Integrations\Google\GoogleSheetsWriter;
use App\Integrations\IntegrationException;
use App\Integrations\OutboxSynchronizer;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\SyncOperation;
use App\Recruitment\LocalRecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

// Stateful, isolated Google grid simulation. It tests serialized requests,
// formulas, retries and verification; it is not evidence of a live Google write.
class ControlledSheetsGateway extends GoogleSheetsGateway
{
    public array $tables = [];

    public array $requests = [];

    public bool $offline = false;

    public bool $discardWrite = false;

    public bool $moveHeaders = false;

    public function __construct() {}

    public function grid(DataSource $source, string $title, string $range): array
    {
        if ($this->offline) {
            throw new IntegrationException('Brak internetu', 'offline');
        }
        [$start, $end] = array_map('intval', explode(':', $range));
        $rows = array_slice($this->tables[$title]['rows'], $start - 1, $end - $start + 1);
        if ($this->moveHeaders && $start === 1 && $end === 1) {
            $rows[0][0] = ['effectiveValue' => ['stringValue' => 'moved']];
        }

        return array_map(fn ($cells) => ['values' => $cells], $rows);
    }

    public function request(DataSource $source, string $method, string $suffix = '', array $data = []): array
    {
        if ($this->offline) {
            throw new IntegrationException('Brak internetu', 'offline');
        }
        if ($method === 'GET' && $suffix === '') {
            return ['properties' => ['timeZone' => 'Europe/Warsaw'], 'sheets' => array_map(fn ($title, $table) => ['properties' => ['sheetId' => $table['id'], 'title' => $title, 'gridProperties' => ['rowCount' => 1000]]], array_keys($this->tables), array_values($this->tables))];
        }
        if ($method === 'GET') {
            $range = rawurldecode(substr($suffix, strlen('/values/')));
            preg_match("/^'(.*)'!([A-Z]+)(\d+):/", $range, $matches);
            $title = str_replace("''", "'", $matches[1]);
            $index = 0;
            foreach (str_split($matches[2]) as $letter) {
                $index = $index * 26 + ord($letter) - 64;
            }
            $values = array_map(fn ($row) => [$row[$index - 1]['effectiveValue']['stringValue'] ?? $row[$index - 1]['userEnteredValue']['stringValue'] ?? ''], array_slice($this->tables[$title]['rows'], (int) $matches[3] - 1));

            return ['values' => $values];
        }
        $this->requests[] = $data;
        if ($this->discardWrite) {
            return [];
        }
        foreach ($data['requests'] as $request) {
            if (isset($request['appendCells'])) {
                $item = $request['appendCells'];
                $title = $this->title($item['sheetId']);
                foreach ($item['rows'] as $row) {
                    $this->tables[$title]['rows'][] = $this->effective($row['values']);
                }
            }
            if (isset($request['updateCells'])) {
                $item = $request['updateCells'];
                $range = $item['range'];
                $title = $this->title($range['sheetId']);
                $row = $range['startRowIndex'];
                $column = $range['startColumnIndex'];
                $existing = $this->tables[$title]['rows'][$row][$column] ?? [];
                $this->tables[$title]['rows'][$row][$column] = $this->effective([array_replace($existing, $item['rows'][0]['values'][0], ['effectiveValue' => $item['rows'][0]['values'][0]['userEnteredValue']])])[0];
            }
            if (isset($request['copyPaste'])) {
                $item = $request['copyPaste'];
                $src = $item['source'];
                $dst = $item['destination'];
                $title = $this->title($src['sheetId']);
                $cell = $this->tables[$title]['rows'][$src['startRowIndex']][$src['startColumnIndex']];
                $this->tables[$title]['rows'][$dst['startRowIndex']][$dst['startColumnIndex']] = $cell;
            }
        }

        return [];
    }

    private function title(int $id): string
    {
        foreach ($this->tables as $title => $table) {
            if ($table['id'] === $id) {
                return $title;
            }
        } throw new \LogicException('Unknown sheet');
    }

    private function effective(array $cells): array
    {
        return array_map(fn ($cell) => $cell + (! empty($cell['userEnteredValue']) ? ['effectiveValue' => $cell['userEnteredValue']] : []), $cells);
    }

    public static function text(string $value): array
    {
        return ['userEnteredValue' => ['stringValue' => $value], 'effectiveValue' => ['stringValue' => $value]];
    }
}

class WriteTestCredentials implements CredentialStore
{
    public function read(string $key): ?array
    {
        return ['access_token' => 'controlled-token', 'refresh_token' => 'controlled-refresh', 'expires_at' => now()->addHour()->timestamp];
    }

    public function write(string $key, array $value): void {}

    public function delete(string $key): void {}
}

class SheetsWriteTest extends TestCase
{
    use RefreshDatabase;

    private ControlledSheetsGateway $gateway;

    private DataSource $source;

    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $account = IntegrationAccount::create(['provider' => 'google', 'name' => 'Controlled test', 'status' => 'connected', 'credential_ref' => (string) Str::uuid(), 'scopes' => [GoogleOAuthService::WRITE_SCOPE]]);
        $this->source = DataSource::create(['type' => 'google_sheets', 'name' => 'Controlled test only', 'external_id' => 'controlled_test_sheet_123', 'integration_account_id' => $account->id, 'enabled' => true, 'read_only' => false, 'sync_interval_minutes' => 15]);
        $this->gateway = new ControlledSheetsGateway;
        $headers = ['Vacancies renamed' => ['company', 'open_offer', 'offer_key', 'position', 'my_interest', 'salary_min', 'cv_sent', 'cv_sent_at', 'application_stage'], 'Applications custom' => ['offer_key', 'application_id', 'company', 'position', 'offer_url', 'cv_sent', 'cv_sent_at', 'application_stage', 'cv_version_sent', 'application_source'], 'HR custom' => ['summary', 'offer_key', 'event_id', 'event_type', 'event_at', 'application_id'], 'Audit custom' => ['offer_key', 'change_id', 'field', 'change_at', 'old_value', 'new_value', 'actor', 'source', 'notes']];
        foreach ($headers as $title => $columns) {
            $id = count($this->gateway->tables) + 10;
            $this->gateway->tables[$title] = ['id' => $id, 'rows' => [array_map(ControlledSheetsGateway::text(...), $columns)]];
        }
        $this->gateway->tables['Vacancies renamed']['rows'][] = [ControlledSheetsGateway::text('Template company'), ['userEnteredValue' => ['formulaValue' => '=HYPERLINK(A2)'], 'effectiveValue' => ['stringValue' => 'Open']], ControlledSheetsGateway::text('template-offer'), ControlledSheetsGateway::text('Accountant')];
        $template = [ControlledSheetsGateway::text('template-offer'), ControlledSheetsGateway::text('template-app')];
        for ($i = 2; $i <= 7; $i++) {
            $template[$i] = ['userEnteredValue' => ['formulaValue' => '=INDEX(Vacancies!A:A,2)'], 'effectiveValue' => ['stringValue' => 'derived']];
        }
        $this->gateway->tables['Applications custom']['rows'][] = $template;
        $roles = ['offers', 'applications', 'events'];
        foreach ($roles as $i => $role) {
            DataSourceConfiguration::create(['data_source_id' => $this->source->id, 'sheet_id' => 10 + $i, 'sheet_title' => array_keys($headers)[$i], 'role' => $role, 'header_row' => 1, 'schema_profile' => 'recruitflow', 'is_primary' => true]);
        }
        $this->app->instance(GoogleSheetsGateway::class, $this->gateway);
        $this->schema = app(GoogleSheetsSchema::class)->inspect($this->source);
    }

    private function operation(string $role, array $payload, array $base = []): SyncOperation
    {
        return SyncOperation::create(['operation_id' => (string) Str::uuid(), 'command_id' => (string) Str::uuid(), 'entity_type' => $role, 'entity_id' => (string) Str::uuid(), 'offer_key' => $payload['offerKey'] ?? $payload['externalKey'], 'payload' => $payload, 'base_values' => $base, 'base_version' => hash('sha256', json_encode($base)), 'source_id' => $this->source->id, 'status' => 'pending']);
    }

    public function test_reordered_headers_literal_text_formula_preservation_and_idempotent_readback(): void
    {
        app(GoogleSheetsSchema::class)->assertWritable($this->schema);
        $writer = app(GoogleSheetsWriter::class);
        $offer = $this->operation('offers', ['externalKey' => 'LOCAL_controlled', 'company' => '=IMPORTXML("not-a-formula")', 'title' => 'Księgowy', 'salaryMin' => 0, 'customAttributes' => [['label' => 'cert', 'value' => 'ACCA']]]);
        $result = $writer->write($this->source, $offer, $this->schema);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame(['customAttributes'], $result['unsupported']);
        $row = $this->gateway->tables['Vacancies renamed']['rows'][2];
        $this->assertSame('=IMPORTXML("not-a-formula")', $row[0]['userEnteredValue']['stringValue']);
        $this->assertSame(0.0, $row[5]['userEnteredValue']['numberValue']);
        $this->assertArrayHasKey('formulaValue', $row[1]['userEnteredValue']);
        $writer->write($this->source, $offer, $this->schema);
        $this->assertCount(3, $this->gateway->tables['Vacancies renamed']['rows']);
        $application = $this->operation('applications', ['externalKey' => 'LOCAL_APP_controlled', 'offerKey' => 'LOCAL_controlled', 'company' => 'Never replace formula', 'cvSent' => true, 'cvVersion' => 'CV controlled', 'applicationStage' => 'Wysłano CV']);
        $writer->write($this->source, $application, $this->schema);
        $app = $this->gateway->tables['Applications custom']['rows'][2];
        $this->assertArrayHasKey('formulaValue', $app[2]['userEnteredValue']);
        $this->assertArrayHasKey('formulaValue', $app[5]['userEnteredValue']);
        $this->assertSame('CV controlled', $app[8]['userEnteredValue']['stringValue']);
        $writer->write($this->source, $this->operation('events', ['externalKey' => 'LOCAL_EVENT_controlled', 'offerKey' => 'LOCAL_controlled', 'message' => "Line one\nLine two", 'eventType' => 'Notatka', 'occurredAt' => '2026-10-12T10:15:00+02:00']), $this->schema);
        $writer->write($this->source, $this->operation('history', ['externalKey' => 'controlled-audit', 'offerKey' => 'LOCAL_controlled', 'field' => 'company', 'newValue' => 'Controlled', 'occurredAt' => '2026-10-12T10:15:00+02:00']), $this->schema);
        $this->assertCount(2, $this->gateway->tables['HR custom']['rows']);
        $this->assertCount(2, $this->gateway->tables['Audit custom']['rows']);
        Http::assertNothingSent();
    }

    public function test_field_conflict_stops_all_mutation_and_preserves_remote_stage(): void
    {
        $this->gateway->tables['Vacancies renamed']['rows'][1][8] = ControlledSheetsGateway::text('Kontakt HR');
        $operation = $this->operation('offers', ['externalKey' => 'template-offer', 'applicationStage' => 'Wysłano CV'], ['applicationStage' => 'Planowana aplikacja']);
        $result = app(GoogleSheetsWriter::class)->write($this->source, $operation, $this->schema);
        $this->assertSame('Kontakt HR', $result['conflicts']['applicationStage']['remote']);
        $this->assertCount(0, $this->gateway->requests);
    }

    public function test_duplicate_keys_bad_enum_and_moved_headers_fail_closed(): void
    {
        $writer = app(GoogleSheetsWriter::class);
        $operation = $this->operation('offers', ['externalKey' => 'template-offer', 'myInterest' => 'Interesuje']);
        $this->schema['roles']['offers']['validations']['my_interest'] = ['condition' => ['type' => 'ONE_OF_LIST', 'values' => [['userEnteredValue' => 'Nie interesuje']]]];
        try {
            $writer->write($this->source, $operation, $this->schema);
            $this->fail('Enum mismatch must fail');
        } catch (IntegrationException $e) {
            $this->assertSame('validation_mismatch', $e->reason);
        }
        $this->schema['roles']['offers']['validations'] = [];
        $this->gateway->moveHeaders = true;
        try {
            $writer->write($this->source, $operation, $this->schema);
            $this->fail('Moved headers must fail');
        } catch (IntegrationException $e) {
            $this->assertSame('schema_changed', $e->reason);
        }
        $this->gateway->moveHeaders = false;
        $this->gateway->tables['Vacancies renamed']['rows'][] = $this->gateway->tables['Vacancies renamed']['rows'][1];
        try {
            $writer->write($this->source, $operation, $this->schema);
            $this->fail('Duplicate identity must fail');
        } catch (IntegrationException $e) {
            $this->assertSame('duplicate_key', $e->reason);
        }
        $this->assertCount(0, $this->gateway->requests);
    }

    public function test_outbox_backoff_retains_changes_and_retries_without_duplicates(): void
    {
        $created = app(LocalRecruitmentService::class)->createOffer(['operationId' => (string) Str::uuid(), 'company' => 'Test company', 'title' => 'Księgowy', 'salaryMin' => 0]);
        $this->gateway->offline = true;
        app(OutboxSynchronizer::class)->push();
        $operation = SyncOperation::where('entity_type', 'offers')->first();
        $this->assertSame('retry', $operation->status);
        $this->assertSame(1, $operation->attempts);
        $this->assertNotNull($operation->next_retry_at);
        $this->assertSame('Księgowy', Offer::findOrFail($created['id'])->data['title']);
        $this->gateway->offline = false;
        $operation->update(['next_retry_at' => now()->subSecond()]);
        app(OutboxSynchronizer::class)->push();
        $this->assertSame('synced', $operation->fresh()->status);
        $this->assertNotNull($operation->fresh()->synced_at);
        $this->assertCount(3, $this->gateway->tables['Vacancies renamed']['rows']);
        $this->assertCount(2, $this->gateway->tables['Audit custom']['rows']);
    }

    public function test_verification_failure_does_not_report_synced(): void
    {
        $this->gateway->discardWrite = true;
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nie potwierdzono');
        app(GoogleSheetsWriter::class)->write($this->source, $this->operation('offers', ['externalKey' => 'LOCAL_failed', 'company' => 'Test']), $this->schema);
    }

    public function test_gateway_enforces_scope_and_sanitizes_google_errors(): void
    {
        $this->app->instance(CredentialStore::class, new WriteTestCredentials);
        $gateway = new GoogleSheetsGateway(app(GoogleOAuthService::class));
        $this->source->update(['read_only' => true]);
        try {
            $gateway->request($this->source, 'POST', ':batchUpdate', []);
            $this->fail('Readonly must fail');
        } catch (IntegrationException $e) {
            $this->assertSame('write_permission', $e->reason);
        }
        Http::assertNothingSent();
        $this->source->update(['read_only' => false]);
        Http::fake(['sheets.googleapis.com/*' => Http::sequence()->push(['error' => 'private external detail'], 403)->push(['error' => 'private external detail'], 429)->push(['error' => 'private external detail'], 503)]);
        foreach ([403 => 'access_denied', 429 => 'rate_limited', 503 => 'google_error'] as $status => $reason) {
            try {
                $gateway->request($this->source, 'POST', ':batchUpdate', []);
                $this->fail('HTTP failure must throw');
            } catch (IntegrationException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('private', $e->getMessage());
            }
        }
        $this->schema['roles']['offers']['profile'] = 'generic';
        $this->expectException(IntegrationException::class);
        app(GoogleSheetsSchema::class)->assertWritable($this->schema);
    }
}

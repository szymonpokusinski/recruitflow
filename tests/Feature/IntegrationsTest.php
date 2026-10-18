<?php

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\DataSourceManager;
use App\Integrations\Google\GoogleOAuthService;
use App\Integrations\Google\SpreadsheetIdentifier;
use App\Integrations\IntegrationException;
use App\Integrations\Mapping\MappingSchema;
use App\Integrations\Mapping\RecruitmentMapper;
use App\Integrations\Security\EncryptedFileCredentialStore;
use App\Integrations\SyncService;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Integrations\IntegrationSetting;
use App\Models\Integrations\OAuthFlow;
use App\Models\Recruitment\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Shell;
use Tests\TestCase;

class MemoryCredentials implements CredentialStore
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
}

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private MemoryCredentials $vault;

    private IntegrationAccount $account;

    private array $tables;

    private int $googleStatus = 200;

    private bool $refreshRejected = false;

    private bool $offline = false;

    private bool $broadScope = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->vault = new MemoryCredentials;
        $this->app->instance(CredentialStore::class, $this->vault);
        $this->account = IntegrationAccount::create(['provider' => 'google', 'name' => 'Test account', 'status' => 'connected', 'credential_ref' => (string) Str::uuid()]);
        $this->vault->write($this->account->credential_ref, ['access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token', 'expires_at' => now()->addHour()->timestamp, 'client_id' => 'test.apps.googleusercontent.com', 'client_secret' => 'test-client-secret']);
        $this->tables = [
            "Jobs '2026" => [['Kod', '', 'Stanowisko', 'Etap'], ['job-1', '', 'PHP developer', 'Aktywna']],
            'Candidates renamed' => [['Kod aplikacji', 'Oferta', 'Osoba'], ['app-1', 'job-1', 'Test candidate']],
            'History custom' => [['Kod zdarzenia', 'Aplikacja', 'Opis', 'Data'], ['event-1', 'app-1', 'Imported event', '12.10.2026 14:30']],
        ];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->offline) {
                throw new ConnectionException('Network failure');
            }
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token') && $this->broadScope) {
                return Http::response(['access_token' => 'too-broad-token', 'expires_in' => 3600, 'scope' => GoogleOAuthService::SCOPE.' https://www.googleapis.com/auth/drive']);
            }
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token') && $this->refreshRejected) {
                return Http::response(['error' => 'invalid_grant', 'error_description' => 'secret detail'], 400);
            }
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600, 'scope' => GoogleOAuthService::SCOPE]);
            }
            if (! str_starts_with($request->url(), 'https://sheets.googleapis.com/')) {
                throw new \RuntimeException('Unexpected HTTP endpoint');
            }
            if ($this->googleStatus !== 200) {
                return Http::response(['error' => 'Never expose this response'], $this->googleStatus);
            }
            $url = rawurldecode($request->url());
            if (str_contains($url, '/values/')) {
                $range = explode('?', explode('/values/', $url)[1])[0];
                preg_match("/^'(.*)'!(\d+):(\d+)$/", $range, $match);
                $title = str_replace("''", "'", $match[1]);
                $rows = $this->tables[$title];

                return Http::response(['values' => $match[2] === $match[3] ? [$rows[0]] : $rows]);
            }

            return Http::response(['properties' => ['title' => 'Custom test sheet', 'timeZone' => 'Europe/Warsaw'], 'sheets' => [
                ['properties' => ['sheetId' => 42, 'title' => "Jobs '2026"]],
                ['properties' => ['sheetId' => 57, 'title' => 'Candidates renamed']],
                ['properties' => ['sheetId' => 99, 'title' => 'History custom']],
            ]]);
        });
    }

    private function source(string $id = 'aaaaaaaaaaaaaaaaaaaa'): DataSource
    {
        return app(DataSourceManager::class)->save(['name' => 'Test source', 'spreadsheet' => $id, 'integration_account_id' => $this->account->id, 'sync_interval_minutes' => 15, 'read_only' => true]);
    }

    private function map(DataSource $source, bool $all = true): void
    {
        $sheets = [['sheet_id' => 42, 'sheet_title' => 'Untrusted UI title', 'role' => 'offers', 'header_row' => 1, 'mapping' => ['id' => 'Kod', 'title' => 'Stanowisko', 'status' => 'Etap']]];
        if ($all) {
            $sheets = [...$sheets,
                ['sheet_id' => 57, 'sheet_title' => '', 'role' => 'applications', 'header_row' => 1, 'mapping' => ['id' => 'Kod aplikacji', 'offer_id' => 'Oferta', 'candidate_name' => 'Osoba']],
                ['sheet_id' => 99, 'sheet_title' => '', 'role' => 'events', 'header_row' => 1, 'mapping' => ['id' => 'Kod zdarzenia', 'application_id' => 'Aplikacja', 'message' => 'Opis', 'occurred_at' => 'Data']],
            ];
        }
        app(DataSourceManager::class)->saveMapping($source, $sheets);
    }

    public function test_source_crud_is_local_readonly_and_validates_identifier(): void
    {
        $values = ['name' => 'My sheet', 'spreadsheet' => 'https://docs.google.com/spreadsheets/d/aaaaaaaaaaaaaaaaaaaa/edit?gid=42', 'integration_account_id' => $this->account->id, 'sync_interval_minutes' => 8, 'read_only' => true];
        $id = $this->postJson('/api/integrations/sources', $values)->assertCreated()->assertJsonPath('external_id', 'aaaaaaaaaaaaaaaaaaaa')->assertJsonPath('enabled', false)->json('id');
        $this->putJson('/api/integrations/sources/'.$id, [...$values, 'name' => 'Renamed'])->assertOk()->assertJsonPath('name', 'Renamed');
        $this->postJson('/api/integrations/sources', [...$values, 'read_only' => false])->assertUnprocessable()->assertJsonValidationErrors('read_only');
        $this->postJson('/api/integrations/sources', [...$values, 'spreadsheet' => 'https://evil.example/aaaaaaaaaaaaaaaaaaaa'])->assertUnprocessable()->assertJsonPath('reason', 'invalid_spreadsheet');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->getJson('/api/integrations/state')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->deleteJson('/api/integrations/sources/'.$id)->assertOk();
        $this->assertDatabaseCount('data_sources', 0);
        Http::assertNothingSent();
    }

    public function test_metadata_headers_mapping_and_activation_use_dynamic_titles_and_columns(): void
    {
        $source = $this->source();
        $this->getJson('/api/integrations/sources/'.$source->id.'/metadata')->assertOk()->assertJsonPath('sheets.0.id', 42);
        $this->getJson('/api/integrations/sources/'.$source->id.'/headers?sheet_id=42&header_row=1')->assertOk()->assertJsonPath('columns.1', 'Stanowisko');
        $this->map($source);
        $this->assertSame("Jobs '2026", $source->configurations()->where('role', 'offers')->first()->sheet_title);
        $this->postJson('/api/integrations/sources/'.$source->id.'/test')->assertOk()->assertJsonPath('records_count', 3);
        $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => true, 'primary' => true])->assertOk();
        $this->getJson('/api/recruitment/state')->assertOk()->assertJsonPath('mode', 'connected')->assertJsonPath('writable', true)->assertJsonPath('snapshot.offers.0.title', 'PHP developer')->assertJsonPath('snapshot.offers.0.salaryMin', null)->assertJsonPath('snapshot.applications.0.status', 'unknown')->assertJsonPath('snapshot.activities.0.occurredAt', '2026-10-12T12:30:00+00:00');
        $this->assertDatabaseCount('data_source_records', 3);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('demo', false));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sheets.googleapis.com') && $request->method() !== 'GET');
    }

    public function test_failed_network_sync_keeps_previous_cache_and_records_sanitized_error(): void
    {
        $source = $this->source();
        $this->map($source);
        app(SyncService::class)->sync($source, 'activation', true);
        $before = Offer::first()->data;
        $last = $source->fresh()->last_synced_at;
        $this->googleStatus = 503;
        $this->postJson('/api/integrations/sources/'.$source->id.'/sync')->assertUnprocessable()->assertJsonPath('reason', 'google_error')->assertDontSee('Never expose');
        $this->assertSame($before, Offer::first()->data);
        $this->assertTrue($last->equalTo($source->fresh()->last_synced_at));
        $this->assertDatabaseHas('sync_runs', ['status' => 'failed', 'error_code' => 'google_error']);
        $this->assertDatabaseCount('data_source_records', 3);
    }

    public function test_duplicate_keys_and_missing_references_roll_back_activation(): void
    {
        $source = $this->source();
        $this->map($source);
        $this->tables["Jobs '2026"][] = ['job-1', '', 'Duplicate', 'open'];
        $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => true])->assertUnprocessable()->assertJsonPath('reason', 'duplicate_key');
        array_pop($this->tables["Jobs '2026"]);
        $this->tables['Candidates renamed'][1][1] = 'missing-offer';
        $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => true])->assertUnprocessable()->assertJsonPath('reason', 'missing_reference');
        $this->assertFalse($source->fresh()->enabled);
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('data_source_records', 0);
    }

    public function test_schema_changes_keep_cache_and_require_mapping_correction(): void
    {
        $source = $this->source();
        $this->map($source, false);
        app(SyncService::class)->sync($source, 'activation', true);
        $this->tables["Jobs '2026"][0][2] = 'Renamed column';
        $this->postJson('/api/integrations/sources/'.$source->id.'/sync')->assertUnprocessable()->assertJsonPath('reason', 'schema_changed');
        $this->assertSame('PHP developer', Offer::first()->data['title']);
        $this->putJson('/api/integrations/sources/'.$source->id.'/mappings', ['sheets' => []])->assertUnprocessable();
    }

    public function test_sources_deduplicate_by_stable_key_and_primary_resolves_conflicts(): void
    {
        $one = $this->source();
        $two = $this->source('bbbbbbbbbbbbbbbbbbbb');
        $this->map($one, false);
        $this->map($two, false);
        app(SyncService::class)->sync($one, 'activation', true);
        app(SyncService::class)->sync($two, 'activation', true);
        $this->assertDatabaseCount('offers', 1);
        $stable = Offer::first()->id;
        $this->assertSame(RecruitmentMapper::stableId('offers', 'job-1'), $stable);
        $this->tables["Jobs '2026"][1][2] = 'Changed by second source';
        $this->postJson('/api/integrations/sources/'.$two->id.'/sync')->assertUnprocessable()->assertJsonPath('reason', 'key_conflict');
        $this->putJson('/api/integrations/sources/'.$two->id.'/primary', ['primary' => true])->assertOk();
        $this->postJson('/api/integrations/sources/'.$two->id.'/sync')->assertOk();
        $this->assertSame($stable, Offer::first()->id);
        $this->assertSame('Changed by second source', Offer::first()->data['title']);
        $this->putJson('/api/integrations/sources/'.$one->id.'/primary', ['primary' => true])->assertOk();
        $this->assertDatabaseCount('offers', 1);
        $this->assertSame(1, DB::table('data_source_configurations')->where('is_primary', true)->where('role', 'offers')->count());
        $this->assertSame('PHP developer', Offer::first()->data['title']);
    }

    public function test_conflicting_disabled_source_can_be_activated_as_primary(): void
    {
        $one = $this->source();
        $two = $this->source('bbbbbbbbbbbbbbbbbbbb');
        $this->map($one, false);
        $this->map($two, false);
        app(SyncService::class)->sync($one, 'activation', true);
        $this->tables["Jobs '2026"][1][2] = 'Authoritative title';
        $this->putJson('/api/integrations/sources/'.$two->id.'/enabled', ['enabled' => true, 'primary' => true])->assertOk();
        $this->assertSame('Authoritative title', Offer::first()->data['title']);
    }

    public function test_scheduler_respects_intervals_and_disabled_sources(): void
    {
        $one = $this->source();
        $two = $this->source('bbbbbbbbbbbbbbbbbbbb');
        $this->map($one, false);
        app(SyncService::class)->sync($one, 'activation', true);
        $this->artisan('integration:sync-due')->assertSuccessful();
        $this->assertDatabaseCount('sync_runs', 1);
        $this->travel(16)->minutes();
        $this->artisan('integration:sync-due')->assertSuccessful();
        $this->assertDatabaseCount('sync_runs', 2);
        $this->assertSame(0, $two->runs()->count());
    }

    public function test_oauth_json_is_imported_without_plaintext_secrets_in_database_or_api(): void
    {
        $file = UploadedFile::fake()->createWithContent('desktop-client.json', json_encode(['installed' => ['client_id' => 'test.apps.googleusercontent.com', 'client_secret' => 'test-oauth-secret']]));
        $this->post('/api/integrations/google/client', ['client_file' => $file], ['Accept' => 'application/json'])->assertOk();
        $setting = IntegrationSetting::first();
        $this->assertSame('test-oauth-secret', $this->vault->read($setting->secret_ref)['client_secret']);
        $this->assertStringNotContainsString('test-oauth-secret', json_encode(DB::table('integration_settings')->get()));
        $this->getJson('/api/integrations/state')->assertOk()->assertDontSee('test-oauth-secret')->assertDontSee('credential_ref')->assertDontSee('test-access-token');
        Http::assertNothingSent();
    }

    public function test_pkce_state_validation_token_exchange_and_replay_protection(): void
    {
        $oauth = app(GoogleOAuthService::class);
        $oauth->configure(['installed' => ['client_id' => 'test.apps.googleusercontent.com', 'client_secret' => 'test-oauth-secret']]);
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', GoogleOAuthService::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
        $flow = $oauth->createFlow('Label');
        $flow->update(['status' => 'waiting', 'redirect_uri' => 'http://127.0.0.1:56789/']);
        $secrets = $this->vault->read($flow->secret_ref);
        try {
            $oauth->complete($flow, ['state' => 'wrong', 'code' => 'code']);
            $this->fail('Invalid state accepted');
        } catch (IntegrationException $e) {
            $this->assertSame('invalid_state', $e->reason);
        }
        Http::assertNothingSent();
        $oauth->complete($flow, ['state' => $secrets['state'], 'code' => 'test-code']);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token' && $request['code_verifier'] === $secrets['verifier'] && $request['redirect_uri'] === 'http://127.0.0.1:56789/');
        $this->assertSame('connected', $flow->fresh()->status);
        $this->assertNull($this->vault->read($flow->secret_ref));
        $this->assertSame('fresh-token', $this->vault->read($flow->account->credential_ref)['access_token']);
        $this->assertSame([GoogleOAuthService::SCOPE], $flow->account->fresh()->scopes);
        $this->getJson('/api/integrations/oauth/'.$flow->id)->assertOk()->assertDontSee('fresh-token')->assertDontSee('test-code')->assertDontSee('secret_ref');
        try {
            $oauth->complete($flow, ['state' => $secrets['state'], 'code' => 'test-code']);
            $this->fail('Replay accepted');
        } catch (IntegrationException) {
        }
        Http::assertSentCount(1);
    }

    public function test_expired_refresh_token_requires_reauthorization_without_leaking_google_error(): void
    {
        $tokens = $this->vault->read($this->account->credential_ref);
        $tokens['expires_at'] = 0;
        $this->vault->write($this->account->credential_ref, $tokens);
        $this->refreshRejected = true;
        $source = $this->source();
        $this->getJson('/api/integrations/sources/'.$source->id.'/metadata')->assertUnprocessable()->assertJsonPath('reason', 'reauthorization_required')->assertDontSee('secret detail');
        $this->assertSame('reauthorization_required', $this->account->fresh()->status);
    }

    public function test_refresh_retains_refresh_token_and_readonly_scope(): void
    {
        $tokens = $this->vault->read($this->account->credential_ref);
        $tokens['expires_at'] = 0;
        $this->vault->write($this->account->credential_ref, $tokens);
        $this->assertSame('fresh-token', app(GoogleOAuthService::class)->accessToken($this->account));
        $this->assertSame('test-refresh-token', $this->vault->read($this->account->credential_ref)['refresh_token']);
        $this->assertSame([GoogleOAuthService::SCOPE], $this->account->fresh()->scopes);
    }

    public function test_account_removal_requires_sources_to_be_removed_first(): void
    {
        $source = $this->source();
        $this->deleteJson('/api/integrations/accounts/'.$this->account->id)->assertUnprocessable()->assertJsonPath('reason', 'account_in_use');
        $this->deleteJson('/api/integrations/sources/'.$source->id)->assertOk();
        $this->deleteJson('/api/integrations/accounts/'.$this->account->id)->assertOk();
        $this->assertNull($this->vault->read($this->account->credential_ref));
    }

    public function test_auto_mapping_uses_aliases_and_rejects_ambiguous_matches(): void
    {
        $this->assertSame(['id' => 'ID_OFERTY', 'title' => 'Stanowisko'], MappingSchema::suggest('offers', ['ID_OFERTY', 'Stanowisko', 'Unknown']));
        $this->assertArrayNotHasKey('id', MappingSchema::suggest('offers', ['ID_OFERTY', 'offer_id']));
        foreach (['http://docs.google.com/spreadsheets/d/aaaaaaaaaaaaaaaaaaaa/edit', 'https://docs.google.com.evil.test/spreadsheets/d/aaaaaaaaaaaaaaaaaaaa/edit', 'https://user@docs.google.com/spreadsheets/d/aaaaaaaaaaaaaaaaaaaa/edit', 'https://docs.google.com:1234/spreadsheets/d/aaaaaaaaaaaaaaaaaaaa/edit'] as $url) {
            try {
                SpreadsheetIdentifier::parse($url);
                $this->fail('Unsafe URL accepted');
            } catch (IntegrationException) {
            }
        }
    }

    public function test_integrations_page_preserves_inertia_layout_and_native_metadata(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->get('/settings/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Integrations')->where('runtime', 'native')->where('demo', false));
    }

    public function test_network_disconnect_preserves_connected_mode_and_cache(): void
    {
        $source = $this->source();
        $this->map($source, false);
        app(SyncService::class)->sync($source, 'activation', true);
        $this->offline = true;
        $this->postJson('/api/integrations/sources/'.$source->id.'/sync')->assertUnprocessable()->assertJsonPath('reason', 'offline');
        $this->getJson('/api/recruitment/state')->assertOk()->assertJsonPath('mode', 'connected')->assertJsonPath('snapshot.offers.0.title', 'PHP developer');
    }

    public function test_google_401_refreshes_once_and_requests_reauthorization_after_second_rejection(): void
    {
        $source = $this->source();
        $this->googleStatus = 401;
        $this->getJson('/api/integrations/sources/'.$source->id.'/metadata')->assertUnprocessable()->assertJsonPath('reason', 'reauthorization_required');
        Http::assertSentCount(3);
        $this->assertSame('reauthorization_required', $this->account->fresh()->status);
    }

    public function test_extra_oauth_scope_is_rejected_and_callback_secrets_are_cleaned(): void
    {
        $oauth = app(GoogleOAuthService::class);
        $oauth->configure(['installed' => ['client_id' => 'test.apps.googleusercontent.com']]);
        $flow = $oauth->createFlow('Test');
        $flow->update(['status' => 'waiting', 'redirect_uri' => 'http://127.0.0.1:50001/']);
        $secret = $this->vault->read($flow->secret_ref);
        $this->broadScope = true;
        try {
            $oauth->complete($flow, ['state' => $secret['state'], 'code' => 'code']);
            $this->fail('Broad scope accepted');
        } catch (IntegrationException $e) {
            $this->assertSame('scope_mismatch', $e->reason);
        }
        $this->assertSame('failed', $flow->fresh()->status);
        $this->assertNull($this->vault->read($flow->secret_ref));
        $this->assertNull($this->vault->read($flow->account->credential_ref));
    }

    public function test_denied_and_expired_oauth_flows_never_exchange_codes(): void
    {
        $oauth = app(GoogleOAuthService::class);
        $oauth->configure(['installed' => ['client_id' => 'test.apps.googleusercontent.com']]);
        $flow = $oauth->createFlow('Test');
        $flow->update(['status' => 'waiting']);
        $secret = $this->vault->read($flow->secret_ref);
        try {
            $oauth->complete($flow, ['state' => $secret['state'], 'error' => 'access_denied']);
            $this->fail('Denial accepted');
        } catch (IntegrationException $e) {
            $this->assertSame('consent_denied', $e->reason);
        }
        $this->assertNull($this->vault->read($flow->secret_ref));
        $expired = $oauth->createFlow('Expired');
        $expired->update(['expires_at' => now()->subMinute()]);
        $oauth->cleanExpiredFlows();
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNull($this->vault->read($expired->secret_ref));
        Http::assertNothingSent();
    }

    public function test_invalid_date_blocks_sync_without_normalizing_to_another_date(): void
    {
        $source = $this->source();
        $this->map($source);
        foreach (['2026-02-30', '31.02.2026', 'nonsense'] as $invalid) {
            $this->tables['History custom'][1][3] = $invalid;
            $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => true])->assertUnprocessable()->assertJsonPath('reason', 'row_invalid');
        }
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_editing_source_is_blocked_during_synchronization_and_lock_does_not_create_a_run(): void
    {
        $source = $this->source();
        $lock = Cache::lock('sync-source-'.$source->id, 1800);
        $this->assertTrue($lock->get());
        try {
            $this->deleteJson('/api/integrations/sources/'.$source->id)->assertUnprocessable()->assertJsonPath('reason', 'sync_running');
            $this->postJson('/api/integrations/sources/'.$source->id.'/sync')->assertUnprocessable()->assertJsonPath('reason', 'sync_running');
            $this->assertDatabaseCount('sync_runs', 0);
        } finally {
            $lock->release();
        }
        $this->assertDatabaseCount('data_sources', 1);
    }

    public function test_encrypted_fallback_never_persists_plaintext_and_detects_tampering(): void
    {
        $path = storage_path('framework/testing/vault-'.Str::uuid());
        $original = $this->app->storagePath();
        $this->app->useStoragePath($path);
        $vault = new EncryptedFileCredentialStore;
        $key = (string) Str::uuid();
        $file = $path.'/app/private/integration-credentials/'.$key.'.enc';
        try {
            $vault->write($key, ['access_token' => 'test-only-secret']);
            $this->assertStringNotContainsString('test-only-secret', file_get_contents($file));
            $this->assertSame(['access_token' => 'test-only-secret'], $vault->read($key));
            $this->assertSame(0600, fileperms($file) & 0777);
            file_put_contents($file, 'tampered');
            try {
                $vault->read($key);
                $this->fail('Tampering accepted');
            } catch (IntegrationException $e) {
                $this->assertSame('vault_error', $e->reason);
            }
        } finally {
            $vault->delete($key);
            $this->app->useStoragePath($original);
            foreach (['app/private/integration-credentials', 'app/private', 'app', ''] as $dir) {
                if (is_dir($path.'/'.$dir)) {
                    rmdir($path.'/'.$dir);
                }
            }
        }
    }

    public function test_large_snapshot_uses_stable_ids_and_removes_only_records_absent_from_successful_sync(): void
    {
        $source = $this->source();
        $this->map($source, false);
        for ($i = 2; $i <= 220; $i++) {
            $this->tables["Jobs '2026"][] = ['job-'.$i, '', 'Role '.$i, 'open'];
        }
        app(SyncService::class)->sync($source, 'activation', true);
        $this->assertDatabaseCount('offers', 220);
        $stable = Offer::where('external_key', 'job-1')->first()->id;
        array_pop($this->tables["Jobs '2026"]);
        app(SyncService::class)->sync($source);
        $this->assertDatabaseCount('offers', 220);
        $this->assertTrue(Offer::where('external_key', 'job-220')->first()->data['remoteMissing']);
        $this->assertSame($stable, Offer::where('external_key', 'job-1')->first()->id);
    }

    public function test_primary_resolves_three_conflicting_sources_regardless_of_insertion_order(): void
    {
        $one = $this->source();
        $two = $this->source('bbbbbbbbbbbbbbbbbbbb');
        $three = $this->source('cccccccccccccccccccc');
        foreach ([$one, $two, $three] as $source) {
            $this->map($source, false);
        }
        app(SyncService::class)->sync($one, 'activation', true, true);
        $this->tables["Jobs '2026"][1][2] = 'Second';
        app(SyncService::class)->sync($two, 'activation', true);
        $this->tables["Jobs '2026"][1][2] = 'Third';
        app(SyncService::class)->sync($three, 'activation', true, true);
        $this->assertDatabaseCount('offers', 1);
        $this->assertSame('Third', Offer::first()->data['title']);
    }

    public function test_mapping_validation_and_row_limit_cannot_activate_incomplete_data(): void
    {
        $source = $this->source();
        $invalid = ['sheets' => [['sheet_id' => 42, 'sheet_title' => 'Anything', 'role' => 'offers', 'header_row' => 1, 'mapping' => ['id' => 'Kod']]]];
        $this->putJson('/api/integrations/sources/'.$source->id.'/mappings', $invalid)->assertUnprocessable()->assertJsonPath('reason', 'mapping_invalid');
        $this->map($source, false);
        config(['integrations.max_rows' => 1]);
        $this->tables["Jobs '2026"][] = ['job-2', '', 'Role 2', 'open'];
        $this->putJson('/api/integrations/sources/'.$source->id.'/enabled', ['enabled' => true])->assertUnprocessable()->assertJsonPath('reason', 'row_limit');
        $this->assertFalse($source->fresh()->enabled);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_native_oauth_opens_system_browser_with_pkce_and_only_readonly_scope(): void
    {
        config(['nativephp-internal.running' => true]);
        $oauth = app(GoogleOAuthService::class);
        $oauth->configure(['installed' => ['client_id' => 'test.apps.googleusercontent.com']]);
        ChildProcess::shouldReceive('artisan')->once()->andReturnUsing(function ($command, $alias) {
            $this->assertSame('integration:oauth-listen', $command[0]);
            $this->assertSame('oauth-'.$command[1], $alias);
            OAuthFlow::findOrFail($command[1])->update(['status' => 'waiting', 'redirect_uri' => 'http://127.0.0.1:50001/']);

            return ChildProcess::getFacadeRoot();
        });
        Shell::shouldReceive('openExternal')->once()->with(\Mockery::on(function ($url) {
            $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame(GoogleOAuthService::SCOPE, $query['scope']);
            $this->assertSame('S256', $query['code_challenge_method']);
            $flow = OAuthFlow::latest()->first();
            $secrets = $this->vault->read($flow->secret_ref);
            $this->assertSame(GoogleOAuthService::challenge($secrets['verifier']), $query['code_challenge']);
            $this->assertSame($secrets['state'], $query['state']);
            $this->assertSame('http://127.0.0.1:50001/', $query['redirect_uri']);
            $this->assertArrayNotHasKey('code_verifier', $query);

            return true;
        }));
        $result = $oauth->start('Native label');
        $this->assertNull($result['authorization_url']);
        $this->assertSame('waiting', $result['status']);
        Http::assertNothingSent();
    }
}

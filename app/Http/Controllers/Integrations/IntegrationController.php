<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\SaveDataSourceRequest;
use App\Http\Requests\Integrations\SaveMappingsRequest;
use App\Integrations\AutoConnectService;
use App\Integrations\CanonicalCache;
use App\Integrations\DataSourceManager;
use App\Integrations\Google\GoogleOAuthService;
use App\Integrations\Google\GoogleSheetsSchema;
use App\Integrations\Google\SpreadsheetIdentifier;
use App\Integrations\IntegrationException;
use App\Integrations\Mapping\MappingSchema;
use App\Integrations\Mapping\RecruitFlowSchemaDetector;
use App\Integrations\SyncService;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\IntegrationAccount;
use App\Models\Integrations\IntegrationSetting;
use App\Models\Integrations\OAuthFlow;
use App\Models\Integrations\SyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('Integrations');
    }

    public function advanced(): Response
    {
        return Inertia::render('IntegrationAdvanced');
    }

    public function autoConnect(Request $request, DataSourceManager $manager, AutoConnectService $automatic): JsonResponse
    {
        $values = $request->validate(['spreadsheet' => ['required', 'string', 'max:2048'], 'integration_account_id' => ['nullable', 'uuid', 'exists:integration_accounts,id']]);
        $external = SpreadsheetIdentifier::parse($values['spreadsheet']);
        $source = DataSource::where('type', 'google_sheets')->where('external_id', $external)->first();
        $account = isset($values['integration_account_id']) ? IntegrationAccount::findOrFail($values['integration_account_id']) : ($source?->account ?? IntegrationAccount::where('provider', 'google')->where('status', 'connected')->first());
        if (! $account || $account->status !== 'connected') {
            throw new IntegrationException('Najpierw połącz konto Google.', 'reauthorization_required');
        }
        if ($source && $source->integration_account_id !== $account->id) {
            throw new IntegrationException('To źródło korzysta z innego konta. Zmień konto w ustawieniach zaawansowanych.', 'account_mismatch');
        }
        $source ??= $manager->save(['name' => 'Google Sheets', 'spreadsheet' => $values['spreadsheet'], 'integration_account_id' => $account->id, 'sync_interval_minutes' => 15, 'read_only' => true]);

        return response()->json($automatic->connect($source));
    }

    public function state(GoogleOAuthService $oauth): JsonResponse
    {
        $oauth->cleanExpiredFlows();
        $setting = IntegrationSetting::where('provider', 'google')->first();

        return response()->json([
            'oauth' => ['configured' => (bool) $setting, 'client_id' => $setting?->client_id, 'credential_store' => config('integrations.credential_store'), 'scopes' => [GoogleOAuthService::SCOPE]],
            'accounts' => IntegrationAccount::orderBy('name')->get(['id', 'provider', 'name', 'status', 'scopes', 'token_expires_at']),
            'sources' => DataSource::with(['account:id,name,status', 'configurations.mappings'])->withCount('records')->orderBy('name')->get(),
            'schemas' => MappingSchema::publicSchemas(),
            'recruitflow_required' => collect(RecruitFlowSchemaDetector::FIELDS)->map(fn ($fields, $role) => RecruitFlowSchemaDetector::required($role)),
            'runs' => SyncRun::latest('started_at')->limit(30)->get(),
        ]);
    }

    public function configureOAuth(Request $request, GoogleOAuthService $oauth): JsonResponse
    {
        $request->validate(['client_file' => ['required', 'file', 'max:32']]);
        try {
            $document = json_decode(file_get_contents($request->file('client_file')->getRealPath()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new IntegrationException('Plik nie zawiera poprawnego JSON klienta Desktop app.', 'invalid_client');
        }
        if (! is_array($document)) {
            throw new IntegrationException('Nieprawidłowa konfiguracja OAuth.', 'invalid_client');
        }
        $oauth->configure($document);

        return response()->json(['configured' => true]);
    }

    public function connect(Request $request, GoogleOAuthService $oauth): JsonResponse
    {
        $values = $request->validate(['name' => ['required', 'string', 'max:120'], 'account_id' => ['nullable', 'uuid', 'exists:integration_accounts,id'], 'write_access' => ['sometimes', 'boolean']]);
        $account = isset($values['account_id']) ? IntegrationAccount::findOrFail($values['account_id']) : null;

        return response()->json($oauth->start($values['name'], $account, $values['write_access'] ?? false));
    }

    public function flow(OAuthFlow $flow): JsonResponse
    {
        return response()->json(['id' => $flow->id, 'status' => $flow->status, 'error' => $flow->error, 'expires_at' => $flow->expires_at]);
    }

    public function disconnect(IntegrationAccount $account, GoogleOAuthService $oauth): JsonResponse
    {
        $oauth->disconnect($account);

        return response()->json(['deleted' => true]);
    }

    public function store(SaveDataSourceRequest $request, DataSourceManager $manager): JsonResponse
    {
        return response()->json($manager->save($request->validated()), 201);
    }

    public function update(SaveDataSourceRequest $request, DataSource $source, DataSourceManager $manager): JsonResponse
    {
        return response()->json($manager->save($request->validated(), $source));
    }

    public function destroy(DataSource $source, DataSourceManager $manager): JsonResponse
    {
        $manager->delete($source);

        return response()->json(['deleted' => true]);
    }

    public function metadata(DataSource $source, DataSourceManager $manager): JsonResponse
    {
        return response()->json($manager->adapter($source)->metadata($source));
    }

    public function headers(Request $request, DataSource $source, DataSourceManager $manager): JsonResponse
    {
        $values = $request->validate(['sheet_id' => ['required', 'integer', 'min:0'], 'header_row' => ['required', 'integer', 'min:1', 'max:100']]);
        $headers = $manager->adapter($source)->headers($source, $values['sheet_id'], $values['header_row']);
        $suggestions = [];
        foreach (array_keys(MappingSchema::REQUIRED) as $role) {
            $suggestions[$role] = MappingSchema::suggest($role, $headers['columns']);
        }

        return response()->json([...$headers, 'suggestions' => $suggestions]);
    }

    public function mappings(SaveMappingsRequest $request, DataSource $source, DataSourceManager $manager): JsonResponse
    {
        $manager->saveMapping($source, $request->validated('sheets'));

        return response()->json(['saved' => true]);
    }

    public function toggle(Request $request, DataSource $source, DataSourceManager $manager, SyncService $sync): JsonResponse
    {
        $values = $request->validate(['enabled' => ['required', 'boolean'], 'primary' => ['sometimes', 'boolean']]);
        if ($values['enabled']) {
            $sync->sync($source, 'activation', true, $values['primary'] ?? false);
        } else {
            $manager->disable($source);
        }

        return response()->json(['saved' => true]);
    }

    public function primary(Request $request, DataSource $source, DataSourceManager $manager): JsonResponse
    {
        $values = $request->validate(['primary' => ['required', 'boolean']]);
        $manager->primary($source, $values['primary']);

        return response()->json(['saved' => true]);
    }

    public function test(DataSource $source, SyncService $sync): JsonResponse
    {
        return response()->json($sync->test($source));
    }

    public function sync(DataSource $source, SyncService $sync): JsonResponse
    {
        return response()->json($sync->sync($source));
    }

    public function writeAccess(Request $request, DataSource $source, GoogleSheetsSchema $schemas): JsonResponse
    {
        $values = $request->validate(['enabled' => ['required', 'boolean']]);
        $lock = Cache::lock('sync-source-'.$source->id, 360);
        if (! $lock->get()) {
            throw new IntegrationException('Synchronizacja trwa. Spróbuj ponownie.', 'sync_running');
        }
        try {
            $source->refresh()->load('account');
            if ($values['enabled']) {
                if (! $source->enabled || ! in_array(GoogleOAuthService::WRITE_SCOPE, $source->account->scopes ?? [], true)) {
                    throw new IntegrationException('Włącz źródło i ponownie połącz konto z uprawnieniami do edycji arkuszy.', 'write_permission');
                }
                $schema = $schemas->inspect($source);
                $schemas->assertWritable($schema);
                foreach (['offers', 'applications', 'events'] as $role) {
                    if (! $source->configurations()->where('role', $role)->where('is_primary', true)->exists()) {
                        throw new IntegrationException('Najpierw wskaż to źródło jako główne dla ofert, aplikacji i zdarzeń.', 'primary_required');
                    }
                }
                $source->update(['read_only' => false, 'schema_metadata' => $schema]);
            } else {
                $source->update(['read_only' => true]);
            }

            return response()->json(['saved' => true]);
        } finally {
            $lock->release();
        }
    }

    public function snapshot(CanonicalCache $cache): JsonResponse
    {
        $sources = DataSource::where('enabled', true)->get(['id', 'name', 'last_synced_at', 'last_error']);

        return response()->json(['mode' => $sources->isEmpty() ? 'local' : 'connected', 'snapshot' => $cache->snapshot(), 'sources' => $sources, 'writable' => true]);
    }
}

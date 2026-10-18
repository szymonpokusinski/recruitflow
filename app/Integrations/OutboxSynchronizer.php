<?php

namespace App\Integrations;

use App\Integrations\Google\GoogleOAuthService;
use App\Integrations\Google\GoogleSheetsSchema;
use App\Integrations\Google\GoogleSheetsWriter;
use App\Models\Integrations\DataSource;
use App\Models\Recruitment\SyncConflict;
use App\Models\Recruitment\SyncOperation;
use App\Recruitment\EntityRegistry;
use App\Recruitment\Outbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OutboxSynchronizer
{
    public function __construct(private Outbox $outbox, private GoogleSheetsSchema $schema, private GoogleSheetsWriter $writer) {}

    public function bind(): void
    {
        foreach (SyncOperation::whereIn('status', ['local', 'pending', 'retry'])->get() as $operation) {
            if ($operation->entity_type === 'profiles' || ($operation->entity_type === 'history' && ! $operation->offer_key)) {
                continue;
            }
            $source = $this->outbox->target($operation->entity_type, $operation->source_id);
            if ($source && $source->enabled && ! $source->read_only && in_array(GoogleOAuthService::WRITE_SCOPE, $source->account->scopes ?? [], true)) {
                $operation->update(['source_id' => $source->id, 'status' => $operation->status === 'local' ? 'pending' : $operation->status]);
            } elseif (in_array($operation->status, ['pending', 'retry'], true)) {
                $operation->update(['status' => 'local']);
            }
        }
    }

    public function push(int $limit = 10): int
    {
        $this->bind();
        $processed = 0;
        foreach (DataSource::where('enabled', true)->where('read_only', false)->get() as $source) {
            $lock = Cache::lock('sync-source-'.$source->id, 360);
            if (! $lock->get()) {
                continue;
            }
            try {
                // A process killed after a successful HTTP write retries by stable key.
                SyncOperation::where('source_id', $source->id)->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(6))->update(['status' => 'retry', 'next_retry_at' => now()]);
                $operations = SyncOperation::where('source_id', $source->id)->whereIn('status', ['pending', 'retry'])->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))->orderBy('id')->limit($limit)->get();
                if ($operations->isEmpty()) {
                    continue;
                }
                $schema = null;
                foreach ($operations as $operation) {
                    if (! $this->outbox->target($operation->entity_type, $source->id)) {
                        $operation->update(['status' => 'local']);

                        continue;
                    }
                    if ($operation->offer_key && SyncOperation::where('source_id', $source->id)->where('offer_key', $operation->offer_key)->where('id', '<', $operation->id)->whereNotIn('status', ['synced', 'superseded'])->exists()) {
                        continue;
                    }
                    $operation->update(['status' => 'processing', 'attempts' => $operation->attempts + 1]);
                    try {
                        $schema ??= $this->schema->inspect($source);
                        $this->schema->assertWritable($schema);
                        $result = $this->writer->write($source, $operation->fresh(), $schema);
                        if ($result['conflicts']) {
                            DB::transaction(function () use ($operation, $source, $result) {
                                SyncConflict::create(['id' => (string) Str::uuid(), 'operation_id' => $operation->operation_id, 'entity_type' => $operation->entity_type, 'entity_id' => $operation->entity_id, 'source_id' => $source->id, 'fields' => $result['conflicts'], 'status' => 'open']);
                                $operation->update(['status' => 'conflict', 'error_code' => 'field_conflict', 'last_error' => 'Porównaj lokalną i zdalną wersję zmienionych pól.']);
                            });
                        } else {
                            DB::transaction(function () use ($operation, $source, $result) {
                                $operation->update(['status' => 'synced', 'synced_at' => now(), 'next_retry_at' => null, 'last_error' => null, 'error_code' => null, 'unsupported_fields' => $result['unsupported']]);
                                $model = EntityRegistry::MODELS[$operation->entity_type] ?? null;
                                $entity = $model ? $model::find($operation->entity_id) : null;
                                if ($entity) {
                                    $accepted = array_diff_key($operation->payload, array_flip($result['unsupported']));
                                    $entity->update(['source_id' => $source->id, 'remote_baseline' => array_replace($entity->remote_baseline ?? [], $accepted)]);
                                }
                            });
                        }
                        $processed++;
                    } catch (\Throwable $error) {
                        $reason = $error instanceof IntegrationException ? $error->reason : 'local_error';
                        $message = $error instanceof IntegrationException ? $error->getMessage() : 'Błąd synchronizacji. Zmiana pozostaje w lokalnej kolejce.';
                        $transient = in_array($reason, ['offline', 'rate_limited', 'google_error', 'verification_failed'], true) && $operation->attempts < 8;
                        $delay = min(3600, 15 * (2 ** min($operation->attempts - 1, 8))) + random_int(0, 5);
                        $operation->update(['status' => $transient ? 'retry' : 'blocked', 'next_retry_at' => $transient ? now()->addSeconds($delay) : null, 'error_code' => $reason, 'last_error' => $message]);
                        $source->update(['last_error' => $message]);
                        Log::warning('Outbox synchronization failed', ['operation_id' => $operation->operation_id, 'source_id' => $source->id, 'reason' => $reason]);
                    }
                }
            } finally {
                $lock->release();
            }
        }

        return $processed;
    }

    public function state(): array
    {
        $counts = SyncOperation::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $sources = DataSource::where('enabled', true)->get();
        $conflicts = SyncConflict::where('status', 'open')->count();
        $pending = ($counts['pending'] ?? 0) + ($counts['retry'] ?? 0) + ($counts['processing'] ?? 0);
        $lastError = SyncOperation::whereNotNull('last_error')->whereNotIn('status', ['synced', 'superseded'])->latest('id')->first();

        return ['status' => $conflicts ? 'conflict' : (($counts['processing'] ?? 0) ? 'syncing' : ($lastError ? ($lastError->error_code === 'write_permission' || $lastError->error_code === 'access_denied' ? 'permission' : 'error') : ($pending ? 'pending' : ($sources->isEmpty() ? 'local' : (SyncOperation::where('status', 'synced')->exists() && ! ($counts['local'] ?? 0) ? 'synced' : 'connected'))))), 'pending' => $pending, 'local' => $counts['local'] ?? 0, 'conflicts' => $conflicts, 'blocked' => $counts['blocked'] ?? 0, 'last_error' => $lastError?->last_error, 'last_synced_at' => SyncOperation::where('status', 'synced')->max('synced_at'), 'read_last_synced_at' => $sources->max('last_synced_at')?->toIso8601String(), 'sources_count' => $sources->count()];
    }
}

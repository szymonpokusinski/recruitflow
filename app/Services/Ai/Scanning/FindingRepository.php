<?php

declare(strict_types=1);

namespace App\Services\Ai\Scanning;

use App\Models\Ai\AiFinding;
use App\Models\Ai\AiSearchRun;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FindingRepository
{
    public function __construct(private readonly FindingIdentity $identity) {}

    /** Called within the run transaction; any ambiguous identity rolls it back. */
    public function upsert(AiSearchRun $run, AiResult $result): array
    {
        $new = [];
        $observed = [];
        $now = CarbonImmutable::now('UTC');
        foreach ($result->items as $item) {
            $keys = $this->identity->keys($item, $result->provider);
            $hash = reset($keys);
            $ids = DB::table('ai_finding_identities')->where('category_id', $run->category_id)
                ->whereIn('identity_hash', array_values($keys))->pluck('finding_id')->all();
            $ids = array_merge($ids, AiFinding::query()->where('category_id', $run->category_id)->where('hash', $hash)->pluck('id')->all());

            // Promote records whose URL appeared/disappeared without crossing hosts.
            if ($ids === [] && $item->externalId !== null) {
                $candidates = AiFinding::query()->where('category_id', $run->category_id)
                    ->where('provider', $result->provider)->where('external_id', $item->externalId);
                if ($item->url !== null) {
                    $candidates->whereNull('url');
                }
                $ids = $candidates->pluck('id')->all();
            }
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                throw new AiScanException('identity_conflict');
            }
            $finding = $ids ? AiFinding::query()->findOrFail($ids[0]) : new AiFinding;
            $created = ! $finding->exists;
            $data = $item->toArray();
            // Missing optional facts do not erase previously observed facts.
            if (! $created) {
                foreach (['url', 'price', 'currency', 'location', 'description', 'confidence', 'external_id'] as $field) {
                    if ($data[$field] === null && ! ($field === 'external_id' && $finding->provider !== $result->provider)) {
                        unset($data[$field]);
                    }
                }
            }
            $finding->fill($data + [
                'category_id' => $run->category_id, 'run_id' => $run->id,
                'provider' => $result->provider, 'model' => $result->model,
            ]);
            if ($created) {
                $finding->fill(['hash' => $hash, 'first_seen_at' => $now, 'is_new' => true]);
            }
            $finding->last_seen_at = $now;
            $finding->save();
            foreach ($keys as $type => $key) {
                $existing = DB::table('ai_finding_identities')->where('category_id', $run->category_id)->where('identity_hash', $key)->first();
                if ($existing !== null && $existing->finding_id !== $finding->id) {
                    throw new AiScanException('identity_conflict');
                }
                if ($existing === null) {
                    DB::table('ai_finding_identities')->insert(['category_id' => $run->category_id, 'finding_id' => $finding->id, 'type' => $type, 'identity_hash' => $key]);
                }
            }
            $observed[] = $finding->id;
            if ($created) {
                $new[] = $finding->id;
            }
        }

        return ['received_count' => count($result->items), 'observed_count' => count(array_unique($observed)), 'new_count' => count(array_unique($new)), 'new_finding_ids' => array_values(array_unique($new))];
    }

    public function markSeen(AiFinding $finding): void
    {
        $finding->update(['is_new' => false]);
    }
}

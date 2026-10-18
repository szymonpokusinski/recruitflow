<?php

namespace Tests\Feature;

use App\Models\Recruitment\Offer;
use App\Recruitment\OfferFields;
use App\Widgets\WidgetSnapshotPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WidgetPublicationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_model_observer_publishes_only_committed_changes(): void
    {
        $directory = sys_get_temp_dir().'/recruitflow-publication-'.Str::uuid();
        config(['widgets.enabled' => true, 'widgets.companion_enabled' => false, 'widgets.snapshot_directory' => $directory]);
        $publisher = app(WidgetSnapshotPublisher::class);
        $publisher->publish();
        $path = $directory.'/dashboard-v1.json';
        $id = (string) Str::uuid();
        try {
            DB::transaction(function () use ($id, $publisher, $path) {
                Offer::create(['id' => $id, 'external_key' => 'TEST_'.$id, 'origin' => 'local',
                    'data' => array_replace(OfferFields::empty(), ['id' => $id, 'externalKey' => 'TEST_'.$id,
                        'title' => 'Księgowy', 'company' => 'Firma testowa', 'createdAt' => now()->toIso8601String()])]);
                $publisher->flush();
                $this->assertSame(0, json_decode(file_get_contents($path), true)['totalOffers']);
            });
            $publisher->flush();
            $snapshot = json_decode(file_get_contents($path), true);
            $this->assertSame(1, $snapshot['totalOffers']);
            $this->assertSame('Księgowy', $snapshot['offers'][0]['title']);
            $before = file_get_contents($path);
            try {
                DB::transaction(function () use ($id) {
                    Offer::findOrFail($id)->update(['data' => array_replace(Offer::findOrFail($id)->data, ['title' => 'Wycofana zmiana'])]);
                    throw new \RuntimeException('Intentional rollback in isolated SQLite test');
                });
            } catch (\RuntimeException) {
                // No afterCommit callback should survive this rollback.
            }
            $publisher->flush();
            $this->assertSame($before, file_get_contents($path));
            $this->assertSame('Księgowy', Offer::findOrFail($id)->data['title']);
        } finally {
            config(['widgets.enabled' => false]);
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            unlink($directory.'/.publish.lock');
            rmdir($directory);
        }
    }

    public function test_native_and_herd_publish_to_distinct_runtime_folders(): void
    {
        config(['widgets.snapshot_directory' => null, 'nativephp-internal.running' => true]);
        $publisher = app(WidgetSnapshotPublisher::class);
        $this->assertSame(storage_path('app/private/widgets/native'), $publisher->directory());
        config(['nativephp-internal.running' => false]);
        $this->assertSame(storage_path('app/private/widgets/herd'), $publisher->directory());
    }
}

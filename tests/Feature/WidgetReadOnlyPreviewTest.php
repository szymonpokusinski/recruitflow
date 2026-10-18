<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WidgetReadOnlyPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_verification_cannot_write_offers_or_dispatch_google_sync(): void
    {
        Queue::fake();
        config(['widgets.read_only_preview' => true]);
        $this->postJson('/api/recruitment/synchronize', ['read' => true])->assertForbidden();
        $this->postJson('/api/recruitment/offers', ['company' => 'Nie zapisywać'])->assertForbidden();
        $this->assertDatabaseCount('offers', 0);
        Queue::assertNothingPushed();
        $this->get('/offers')->assertOk();
        $this->get('/activity')->assertOk();
    }

    public function test_native_preview_opens_sqlite_with_read_only_pdo_flags(): void
    {
        $path = sys_get_temp_dir().'/recruitflow-readonly-'.Str::uuid().'.sqlite';
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE preview_check (id INTEGER PRIMARY KEY)');
        $pdo = null;
        config(['widgets.read_only_preview' => true, 'nativephp-internal.running' => true,
            'database.connections.nativephp' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
        try {
            app()->getProvider(AppServiceProvider::class)->boot();
            $this->assertSame(0, DB::connection('nativephp')->table('preview_check')->count());
            try {
                DB::connection('nativephp')->table('preview_check')->insert(['id' => 1]);
                $this->fail('The verification connection must refuse writes');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('readonly', $exception->getMessage());
            }
        } finally {
            DB::purge('nativephp');
            unlink($path);
        }
    }
}

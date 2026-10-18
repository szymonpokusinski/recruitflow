<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SQLitePersistenceTest extends TestCase
{
    public function test_offline_offer_application_history_and_outbox_survive_process_restart(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'recruitflow-persistence-');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database'];
        try {
            $migration = new Process([PHP_BINARY, 'artisan', 'migrate', '--force'], base_path(), $environment);
            $migration->mustRun();
            $bootstrap = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); Illuminate\\Support\\Facades\\Http::preventStrayRequests();';
            $create = $bootstrap.' $service=app(App\\Recruitment\\LocalRecruitmentService::class); $result=$service->createOffer(["operationId"=>(string) Illuminate\\Support\\Str::uuid(),"company"=>"Persistence TEST only","title"=>"Kierowca","industry"=>"Transport","salaryMin"=>4500,"salaryMax"=>6500,"customAttributes"=>[["label"=>"Prawo jazdy","value"=>"C+E"]]]); $offer=App\\Models\\Recruitment\\Offer::findOrFail($result["id"]); $service->updateOffer($offer,["operationId"=>(string) Illuminate\\Support\\Str::uuid(),"description"=>"Trwały opis offline"]); $service->markCv($offer,["operationId"=>(string) Illuminate\\Support\\Str::uuid(),"sent"=>true,"date"=>"2026-10-12","timezone"=>"Europe/Warsaw"]); echo json_encode($result);';
            $writer = new Process([PHP_BINARY, '-r', $create], base_path(), $environment);
            $writer->mustRun();
            $id = json_decode($writer->getOutput(), true, flags: JSON_THROW_ON_ERROR)['id'];
            // A new PHP process reopens the same file: no shared in-memory state.
            $read = $bootstrap.' echo json_encode(["snapshot"=>app(App\\Integrations\\CanonicalCache::class)->snapshot(),"outbox"=>App\\Models\\Recruitment\\SyncOperation::count(),"integrity"=>Illuminate\\Support\\Facades\\DB::select("PRAGMA integrity_check")[0]->integrity_check]);';
            $reader = new Process([PHP_BINARY, '-r', $read], base_path(), $environment);
            $reader->mustRun();
            $result = json_decode($reader->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $result['integrity']);
            $this->assertSame($id, $result['snapshot']['offers'][0]['id']);
            $this->assertSame('Trwały opis offline', $result['snapshot']['offers'][0]['description']);
            $this->assertTrue($result['snapshot']['offers'][0]['cvSent']);
            $this->assertSame('C+E', $result['snapshot']['offers'][0]['customAttributes'][0]['value']);
            $this->assertCount(1, $result['snapshot']['applications']);
            $this->assertCount(1, $result['snapshot']['activities']);
            $this->assertGreaterThanOrEqual(10, $result['outbox']);
        } finally {
            @unlink($database);
            @unlink($database.'-wal');
            @unlink($database.'-shm');
        }
    }
}

<?php

use App\Models\Recruitment\Offer;
use App\Recruitment\OfferFields;
use App\Widgets\WidgetDashboardService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Cross-language verification uses only an in-memory SQLite connection.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
    'cache.default' => 'array', 'widgets.enabled' => false, 'app.timezone' => 'Europe/Warsaw']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
$id = (string) Str::uuid();
Offer::create(['id' => $id, 'external_key' => 'TEST_'.$id, 'origin' => 'local',
    'data' => array_replace(OfferFields::empty(), ['id' => $id, 'externalKey' => 'TEST_'.$id,
        'company' => 'Firma testowa', 'title' => 'Księgowy — test izolowany', 'status' => 'open',
        'salaryMin' => 6000, 'salaryMax' => 8000, 'currency' => 'PLN', 'salaryPeriod' => 'Miesiąc',
        'createdAt' => now()->toIso8601String(), 'aiMatchScore' => 0])]);
$snapshot = app(WidgetDashboardService::class)->snapshot();
file_put_contents($argv[1], json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo 'Isolated Laravel snapshot exported', PHP_EOL;

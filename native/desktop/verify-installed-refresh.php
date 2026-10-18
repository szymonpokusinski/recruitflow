<?php

use App\Integrations\SyncService;
use App\Jobs\RefreshWidgetData;
use App\Models\Integrations\DataSource;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentSetting;
use App\Widgets\WidgetSnapshotPublisher;
use Illuminate\Contracts\Console\Kernel;

// Read-only Google integration verification against the installed application's cache.
// Never dispatch the outbox or write to Google Sheets. No credentials are printed.
$application = '/Applications/RecruitFlow.app/Contents/Resources/build/app';
$data = getenv('HOME').'/Library/Application Support/RecruitFlow';
foreach ([
    'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'NATIVEPHP_RUNNING' => 'true',
    'NATIVEPHP_STORAGE_PATH' => $data.'/storage', 'LARAVEL_STORAGE_PATH' => $data.'/storage',
    'NATIVEPHP_DATABASE_PATH' => $data.'/database/database.sqlite',
    'APP_CONFIG_CACHE' => $data.'/bootstrap/cache/config.php',
    'APP_SERVICES_CACHE' => $data.'/bootstrap/cache/services.php',
    'APP_PACKAGES_CACHE' => $data.'/bootstrap/cache/packages.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $application.'/vendor/autoload.php';
$app = require $application.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('widgets.read_only_preview')) {
    throw new RuntimeException('The installed application is still in preview mode');
}
$sources = DataSource::where('enabled', true)->get();
if ($sources->isEmpty() || $sources->contains(fn ($source) => ! $source->read_only)) {
    throw new RuntimeException('Verification requires at least one enabled read-only source');
}
(new RefreshWidgetData)->handle($app->make(SyncService::class), $app->make(WidgetSnapshotPublisher::class));
$status = RecruitmentSetting::find('widget_refresh')->value['status'];
echo 'Installed widget refresh: '.$status.'; offers: '.Offer::count().PHP_EOL;
if ($status !== 'succeeded') {
    exit(1);
}

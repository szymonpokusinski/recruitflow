<?php

use App\Http\Controllers\DesktopController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\MailAccountController;
use App\Http\Controllers\PracujImportController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Middleware\LocalIntegrationAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', [DesktopController::class, 'dashboard'])->name('desktop.home');
Route::get('/offers', [DesktopController::class, 'offers'])->name('desktop.offers');
Route::get('/offers/{offerId}', [DesktopController::class, 'offer'])->name('desktop.offer');
Route::get('/applications', [DesktopController::class, 'applications'])->name('desktop.applications');
Route::get('/applications/{applicationId}', [DesktopController::class, 'application'])->name('desktop.application');
Route::get('/activity', [DesktopController::class, 'activity'])->name('desktop.activity');
Route::get('/mail', [DesktopController::class, 'mailInbox'])->name('desktop.mail');
Route::get('/settings', [DesktopController::class, 'settings'])->name('desktop.settings');
Route::get('/profiles', [RecruitmentController::class, 'profiles']);
Route::view('/menu-bar', 'desktop')->name('desktop.menu');

Route::get('/settings/integrations', [IntegrationController::class, 'page'])->name('desktop.integrations');

Route::get('/settings/integrations/advanced', [IntegrationController::class, 'advanced']);

Route::middleware(LocalIntegrationAccess::class)->group(function () {
    Route::post('/api/offers/import/preview', [PracujImportController::class, 'preview']);
    $recruitment = RecruitmentController::class;
    Route::get('/api/recruitment/manage', [$recruitment, 'state']);
    Route::get('/api/recruitment/audits', [$recruitment, 'audits']);
    Route::post('/api/recruitment/offers', [$recruitment, 'store']);
    Route::put('/api/recruitment/offers/{offer}', [$recruitment, 'update']);
    Route::post('/api/recruitment/offers/{offer}/cv', [$recruitment, 'cv']);
    Route::post('/api/recruitment/offers/{offer}/stage', [$recruitment, 'stage']);
    Route::put('/api/recruitment/applications/{application}', [$recruitment, 'updateApplication']);
    Route::post('/api/recruitment/applications/{application}/stage', [$recruitment, 'applicationStage']);
    Route::post('/api/recruitment/applications/{application}/events', [$recruitment, 'event']);
    Route::post('/api/recruitment/synchronize', [$recruitment, 'synchronize']);
    Route::post('/api/recruitment/operations/{operation}/retry', [$recruitment, 'retry']);
    Route::post('/api/recruitment/conflicts/{conflict}/resolve', [$recruitment, 'resolve']);
    Route::post('/api/recruitment/merge', [$recruitment, 'merge']);
    Route::post('/api/recruitment/profiles', [$recruitment, 'saveProfile']);
    Route::put('/api/recruitment/profiles/{profile}', [$recruitment, 'saveProfile']);
    Route::put('/api/recruitment/profile', [$recruitment, 'selectProfile']);
    $controller = IntegrationController::class;
    Route::put('/api/integrations/sources/{source}/write-access', [$controller, 'writeAccess']);
    Route::post('/api/integrations/connect-source', [$controller, 'autoConnect']);
    Route::get('/api/integrations/state', [$controller, 'state']);
    Route::post('/api/integrations/google/client', [$controller, 'configureOAuth']);
    Route::post('/api/integrations/google/connect', [$controller, 'connect']);
    Route::get('/api/integrations/oauth/{flow}', [$controller, 'flow']);
    Route::delete('/api/integrations/accounts/{account}', [$controller, 'disconnect']);
    Route::post('/api/integrations/sources', [$controller, 'store']);
    Route::put('/api/integrations/sources/{source}', [$controller, 'update']);
    Route::delete('/api/integrations/sources/{source}', [$controller, 'destroy']);
    Route::get('/api/integrations/sources/{source}/metadata', [$controller, 'metadata']);
    Route::get('/api/integrations/sources/{source}/headers', [$controller, 'headers']);
    Route::put('/api/integrations/sources/{source}/mappings', [$controller, 'mappings']);
    Route::post('/api/integrations/sources/{source}/test', [$controller, 'test']);
    Route::post('/api/integrations/sources/{source}/sync', [$controller, 'sync']);
    Route::put('/api/integrations/sources/{source}/enabled', [$controller, 'toggle']);
    Route::put('/api/integrations/sources/{source}/primary', [$controller, 'primary']);
    Route::get('/api/recruitment/state', [$controller, 'snapshot']);

    Route::get('/api/mail-accounts', [MailAccountController::class, 'index']);
    Route::match(['get', 'post'], '/api/mail-accounts/resolve-provider', [MailAccountController::class, 'resolveProvider']);
    Route::post('/api/mail-accounts/google/connect', [MailAccountController::class, 'googleConnect']);
    Route::get('/api/mail-accounts/google/flow/{flowId}', [MailAccountController::class, 'googleFlowStatus']);
    Route::post('/api/mail-accounts', [MailAccountController::class, 'store']);
    Route::post('/api/mail-accounts/{id}/test', [MailAccountController::class, 'test']);
    Route::post('/api/mail-accounts/{id}/sync', [MailAccountController::class, 'sync']);
    Route::post('/api/mail-accounts/{id}/disconnect-oauth', [MailAccountController::class, 'disconnectOAuth']);
    Route::put('/api/mail-accounts/{id}', [MailAccountController::class, 'update']);
    Route::patch('/api/mail-accounts/{id}/toggle-auto-sync', [MailAccountController::class, 'toggleAutoSync']);
    Route::delete('/api/mail-accounts/{id}', [MailAccountController::class, 'destroy']);

    // Mail Inbox & Sync (Etap 7.1)
    Route::get('/api/mail-messages', [\App\Http\Controllers\Mail\MailMessageController::class, 'index']);
    Route::get('/api/mail-messages/{id}', [\App\Http\Controllers\Mail\MailMessageController::class, 'show']);
    Route::post('/api/mail-messages/{id}/fetch-body', [\App\Http\Controllers\Mail\MailMessageController::class, 'fetchBody']);
    Route::patch('/api/mail-messages/{id}/status', [\App\Http\Controllers\Mail\MailMessageController::class, 'updateStatus']);
    Route::get('/api/mail-sync-rules', [\App\Http\Controllers\Mail\MailSyncRuleController::class, 'index']);
    Route::post('/api/mail-sync-rules', [\App\Http\Controllers\Mail\MailSyncRuleController::class, 'store']);
    Route::put('/api/mail-sync-rules/{id}', [\App\Http\Controllers\Mail\MailSyncRuleController::class, 'update']);
    Route::delete('/api/mail-sync-rules/{id}', [\App\Http\Controllers\Mail\MailSyncRuleController::class, 'destroy']);
    Route::patch('/api/mail-sync-rules/{id}/toggle', [\App\Http\Controllers\Mail\MailSyncRuleController::class, 'toggle']);
    Route::get('/api/mail-sync-states', [\App\Http\Controllers\Mail\MailSyncStateController::class, 'index']);
});

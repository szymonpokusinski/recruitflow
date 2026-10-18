<?php

namespace App\Providers;

use App\Integrations\Contracts\CredentialStore;
use App\Integrations\IntegrationException;
use App\Integrations\PracujPl\CurlHttpTransport;
use App\Integrations\PracujPl\HttpTransport;
use App\Integrations\Security\EncryptedFileCredentialStore;
use App\Integrations\Security\KeychainCredentialStore;
use App\Models\Integrations\SyncRun;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\OfferEvaluation;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\RecruitmentSetting;
use App\Models\Recruitment\SearchProfile;
use App\Widgets\OpenWidgetDeepLink;
use App\Widgets\WidgetSnapshotObserver;
use App\Widgets\WidgetSnapshotPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\App\OpenedFromURL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HttpTransport::class, CurlHttpTransport::class);
        $this->app->singleton(WidgetSnapshotPublisher::class);
        $this->app->bind(CredentialStore::class, function () {
            return match (config('integrations.credential_store')) {
                'keychain' => new KeychainCredentialStore,
                'encrypted_file' => new EncryptedFileCredentialStore,
                default => throw new IntegrationException('Nieznany magazyn poświadczeń.', 'vault_error'),
            };
        });
        $this->app->bind(\App\Services\Mail\Contracts\MailDriverInterface::class, \App\Services\Mail\ImapMailDriver::class);
        $this->app->bind(\App\Services\Mail\Contracts\ImapClientInterface::class, \App\Services\Mail\Imap\SocketImapClient::class);
        $this->app->singleton(\App\Services\Mail\Providers\MailSyncProviderResolver::class);
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('widgets.read_only_preview') && config('nativephp-internal.running')) {
            config(['database.connections.nativephp.options' => [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]]);
            DB::purge('nativephp');
        }
        foreach ([Offer::class, Application::class,
            RecruitmentEvent::class, SearchProfile::class,
            OfferEvaluation::class, RecruitmentSetting::class,
            SyncRun::class] as $model) {
            $model::observe(WidgetSnapshotObserver::class);
        }
        $this->app->terminating(fn () => app(WidgetSnapshotPublisher::class)->flush());
        Queue::after(fn () => app(WidgetSnapshotPublisher::class)->flush());
        Event::listen(OpenedFromURL::class, OpenWidgetDeepLink::class);
    }
}

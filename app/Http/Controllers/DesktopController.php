<?php

namespace App\Http\Controllers;

use App\Widgets\WidgetDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DesktopController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Dashboard');
    }

    public function offers(Request $request): Response
    {
        $ids = $request->query('widget') === 'new'
            ? collect(app(WidgetDashboardService::class)->snapshot()['offers'])->filter(fn ($offer) => $offer['active'] && ! $offer['cvSent'] && $offer['isNew24Hours'])->pluck('id')->all()
            : null;

        return Inertia::render('Offers', ['widgetOfferIds' => $ids]);
    }

    public function offer(string $offerId): Response
    {
        return Inertia::render('OfferShow', ['offerId' => $offerId]);
    }

    public function applications(): Response
    {
        return Inertia::render('Applications');
    }

    public function application(string $applicationId): Response
    {
        return Inertia::render('ApplicationShow', ['applicationId' => $applicationId]);
    }

    public function activity(): Response
    {
        return Inertia::render('Activity');
    }

    public function settings(): Response
    {
        return Inertia::render('Settings');
    }

    public function mailInbox(): Response
    {
        return Inertia::render('MailInbox');
    }
}

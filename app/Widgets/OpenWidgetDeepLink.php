<?php

namespace App\Widgets;

use App\Jobs\RefreshWidgetData;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;

final class OpenWidgetDeepLink
{
    public function handle(OpenedFromURL $event): void
    {
        $path = is_string($event->url) ? app(WidgetDeepLink::class)->path($event->url) : null;
        if ($path !== null) {
            if ($event->url === 'recruitflow://refresh' && ! config('widgets.read_only_preview')) {
                RefreshWidgetData::dispatch();
            }
            Window::open('main')->url(url($path))->title(config('app.name'))
                ->width(1100)->height(760)->minWidth(800)->minHeight(560)->showDevTools(false)
                // NativePHP only focuses an existing window; navigate it explicitly after open.
                ->afterOpen(fn () => Window::get('main')->url(url($path)));
        }
    }
}

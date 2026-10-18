<?php

namespace App\Providers;

use App\Widgets\WidgetSnapshotPublisher;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Menu::default();

        Window::open('main')
            ->route('desktop.home')
            ->title(config('app.name'))
            ->showDevTools(false)
            ->width(1100)
            ->height(760)
            ->minWidth(800)
            ->minHeight(560);

        MenuBar::create()
            ->route('desktop.menu')
            ->label(config('app.name'))
            ->tooltip('RecruitFlow Desktop')
            ->width(360)
            ->height(420)
            ->resizable(false)
            ->showDockIcon()
            ->withContextMenu(Menu::make(
                Menu::quit('Zakończ RecruitFlow'),
            ));
        app(WidgetSnapshotPublisher::class)->queue();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [];
    }
}

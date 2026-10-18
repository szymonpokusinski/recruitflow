<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NativeDesktopTest extends TestCase
{
    public function test_native_boot_opens_the_home_page_and_keeps_the_dock_and_quit_menu_available(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        app(config('nativephp.provider'))->boot();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/window/open')
            && $request->method() === 'POST'
            && $request['id'] === 'main'
            && $request['title'] === 'RecruitFlow'
            && $request['url'] === route('desktop.home')
        );

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/menu')
            && in_array('appMenu', array_column($request['items'], 'role'), true)
            && in_array('editMenu', array_column($request['items'], 'role'), true)
        );

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/menu-bar/create')
            && $request['url'] === route('desktop.menu')
            && $request['showDockIcon'] === true
            && in_array('quit', array_column($request['contextMenu'], 'role'), true)
        );

        Http::assertSentCount(3);
    }
}

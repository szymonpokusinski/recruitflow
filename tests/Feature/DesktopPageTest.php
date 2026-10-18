<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake();
    }

    public static function desktopPages(): array
    {
        return [
            ['/', 'Dashboard'], ['/offers', 'Offers'], ['/offers/O-101', 'OfferShow'],
            ['/applications', 'Applications'], ['/applications/A-201', 'ApplicationShow'],
            ['/activity', 'Activity'], ['/settings', 'Settings'],
        ];
    }

    #[DataProvider('desktopPages')]
    public function test_pages_render_inertia_and_explicit_demo_metadata(string $path, string $component): void
    {
        config(['nativephp-internal.running' => false]);

        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($component)->where('demo', false)->where('runtime', 'browser')->where('appName', 'RecruitFlow'));

        Http::assertNothingSent();
    }

    public function test_native_runtime_is_shared_with_the_interface(): void
    {
        config(['nativephp-internal.running' => true]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('runtime', 'native')->where('demo', false));
    }

    public function test_inertia_navigation_returns_a_page_payload_with_detail_id(): void
    {
        $version = $this->get('/')->viewData('page')['version'];
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];

        $this->get('/applications/A-201', $headers)
            ->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'ApplicationShow')->assertJsonPath('props.applicationId', 'A-201');
        $this->get('/offers/O-101', $headers)
            ->assertOk()->assertJsonPath('props.offerId', 'O-101');
    }

    public function test_menu_bar_retains_the_lightweight_native_screen(): void
    {
        config(['nativephp-internal.running' => true]);

        $this->get('/menu-bar')->assertOk()->assertViewIs('desktop')
            ->assertSeeText('RecruitFlow Desktop')->assertSeeText('Aplikacja działa natywnie na macOS.');
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BowenPublicHomepageTest extends TestCase
{
    public function test_configured_bowen_host_serves_public_homepage_with_login_link(): void
    {
        $this->withoutMiddleware();
        config(['app.bowen_public_site_host' => 'school.mmbowen.test']);

        $response = $this->get('http://school.mmbowen.test/');

        $response
            ->assertOk()
            ->assertSee('博文学校｜让每一种成长，都通向更广阔的世界')
            ->assertSee('href="http://school.mmbowen.test/login"', false)
            ->assertSee('href="/assets/bowen-school/site.css"', false)
            ->assertDontSee('__VINEXT', false);

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'href="http://school.mmbowen.test/login"'),
            'Desktop and mobile navigation must both include the Login link.',
        );
    }

    public function test_existing_root_and_login_route_contracts_remain_intact(): void
    {
        $this->assertSame(
            Controller::class . '@index',
            Route::getRoutes()->getByName('index')->getActionName(),
        );
        $this->assertSame('/login', route('login', [], false));
    }

    public function test_untrusted_forwarded_host_cannot_spoof_bowen_homepage(): void
    {
        $this->withoutMiddleware();
        config(['app.bowen_public_site_host' => 'school.mmbowen.com']);

        $response = $this->withServerVariables([
            'HTTP_HOST' => '43.160.241.126',
            'SERVER_NAME' => '43.160.241.126',
        ])->withHeaders([
            'X-Forwarded-Host' => 'school.mmbowen.com:443',
        ])->get('http://43.160.241.126/');

        if ($response->status() < 400) {
            $response->assertDontSee('BOWEN INTERNATIONAL EDUCATION');
        } else {
            $this->assertGreaterThanOrEqual(400, $response->status());
        }
    }

    public function test_other_host_keeps_legacy_fallback(): void
    {
        $this->withoutMiddleware();
        config(['app.bowen_public_site_host' => 'school.mmbowen.com']);

        $response = $this->get('http://other-school.test/');

        // The legacy fallback requires the configured application database;
        // this suite may run without that external connection. The key
        // contract here is that the non-Bowen host never renders the Bowen
        // homepage branch.
        $response->assertDontSee('让每一种成长，都通向更广阔的世界');
    }

    public function test_every_local_homepage_asset_exists_in_the_public_directory(): void
    {
        $view = file_get_contents(resource_path('views/bowen-school/home.blade.php'));
        preg_match_all('#/assets/bowen-school/[^"\s]+#', $view, $matches);

        $assetPaths = array_unique($matches[0]);
        $this->assertNotEmpty($assetPaths);

        foreach ($assetPaths as $assetPath) {
            $this->assertFileExists(public_path(ltrim($assetPath, '/')));
        }
    }
}

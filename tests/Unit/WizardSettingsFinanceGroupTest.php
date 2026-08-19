<?php

namespace Tests\Unit;

use App\Http\Middleware\WizardSettings;
use App\Services\CachingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WizardSettingsFinanceGroupTest extends TestCase
{
    public function test_incomplete_wizard_allows_all_super_admin_routes_after_a_school_is_installed(): void
    {
        foreach (['finance-groups.index', 'schools.index', 'subscriptions.index'] as $routeName) {
            $this->assertSame(200, $this->runMiddleware($routeName, true, true)->getStatusCode());
        }
    }

    public function test_incomplete_wizard_still_redirects_normal_and_finance_group_routes_on_a_fresh_install(): void
    {
        foreach (['schools.index', 'finance-groups.index'] as $routeName) {
            $response = $this->runMiddleware($routeName, true, false);

            $this->assertTrue($response->isRedirect(route('wizard-settings.index')));
        }
    }

    public function test_wizard_bootstrap_route_remains_available_on_a_fresh_install(): void
    {
        $response = $this->runMiddleware('wizard-settings.index', true, false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NEXT', $response->getContent());
    }

    public function test_global_wizard_lifecycle_does_not_turn_a_normal_user_into_a_finance_group_authority(): void
    {
        // WizardSettings only removes its own setup redirect. Controller
        // authorization remains a separate central Super Admin boundary.
        $response = $this->runMiddleware('finance-groups.index', false, true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NEXT', $response->getContent());
    }

    private function runMiddleware(string $routeName, bool $isSuperAdmin, bool $hasInstalledSchool): Response
    {
        $actor = Mockery::mock();
        $actor->shouldReceive('hasRole')->with('Super Admin')->andReturn($isSuperAdmin);

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($actor);

        $request = Request::create('/'.$routeName);
        $route = new Route(['GET'], '/'.$routeName, static fn (): Response => new Response('NEXT'));
        $route->name($routeName);
        $request->setRouteResolver(static fn (): Route => $route);

        $cache = new class extends CachingService {
            public function removeSystemCache($key): void {}

            public function getSystemSettings(array|string $key = '*')
            {
                return [
                    'wizard_checkMark' => 0,
                    'system_settings_wizard_checkMark' => 0,
                ];
            }
        };

        $middleware = new class($cache, $hasInstalledSchool) extends WizardSettings {
            public function __construct(CachingService $cache, private readonly bool $hasInstalledSchool)
            {
                parent::__construct($cache);
            }

            protected function hasInstalledSchool(): bool
            {
                return $this->hasInstalledSchool;
            }
        };

        return $middleware->handle($request, static fn (): Response => new Response('NEXT'));
    }
}

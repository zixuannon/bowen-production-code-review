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
    public function test_incomplete_wizard_allows_all_finance_group_route_names_for_central_super_admin(): void
    {
        foreach (['finance-groups.index', 'finance-groups.store', 'finance-groups.update'] as $routeName) {
            $this->assertSame(200, $this->runMiddleware($routeName, true)->getStatusCode());
        }
    }

    public function test_incomplete_wizard_still_redirects_unrelated_super_admin_routes(): void
    {
        $response = $this->runMiddleware('schools.index', true);

        $this->assertTrue($response->isRedirect(route('wizard-settings.index')));
    }

    public function test_allowlist_does_not_turn_a_normal_user_into_a_finance_group_authority(): void
    {
        // WizardSettings only removes its own setup redirect. Controller
        // authorization remains a separate central Super Admin boundary.
        $response = $this->runMiddleware('finance-groups.index', false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NEXT', $response->getContent());
    }

    private function runMiddleware(string $routeName, bool $isSuperAdmin): Response
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

        return (new WizardSettings($cache))->handle($request, static fn (): Response => new Response('NEXT'));
    }
}

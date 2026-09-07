<?php

namespace Tests\Feature;

use App\Http\Middleware\XiaobailongServiceAuth;
use App\Services\SchoolDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class XiaobailongIntegrationTest extends TestCase
{
    public function test_xiaobailong_routes_and_middleware_aliases_are_registered_without_contacting_the_upstream_service(): void
    {
        $webRoutes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => $route->uri());

        $this->assertSame('teacher/xiaobailong', $webRoutes['teacher/xiaobailong']->uri());
        $this->assertContains('permission:xiaobailong-use', $webRoutes['teacher/xiaobailong']->gatherMiddleware());
        $this->assertSame('teacher/xiaobailong/launch', $webRoutes['teacher/xiaobailong/launch']->uri());
        $this->assertContains('permission:xiaobailong-use', $webRoutes['teacher/xiaobailong/launch']->gatherMiddleware());

        $apiRoute = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/integrations/xiaobailong/exchange');
        $this->assertNotNull($apiRoute);
        $this->assertContains('XiaobailongServiceAuth', $apiRoute->gatherMiddleware());
        $this->assertContains('throttle:30,1', $apiRoute->gatherMiddleware());
        $this->assertSame(XiaobailongServiceAuth::class, app(\App\Http\Kernel::class)->getRouteMiddleware()['XiaobailongServiceAuth']);
    }

    public function test_provisioning_defines_xiaobailong_and_finance_permissions_and_teacher_default_access_together(): void
    {
        $service = app(SchoolDataService::class);
        $permissions = array_column($this->privatePermissions($service), 'name');

        $this->assertContains('xiaobailong-use', $permissions);
        $this->assertContains('xiaobailong-use', $this->privateTeacherPermissions($service));
    }

    public function test_invalid_service_signature_is_rejected_before_redis_or_any_upstream_contact(): void
    {
        config(['xiaobailong.service_secret' => str_repeat('x', 32)]);
        $request = Request::create('/api/integrations/xiaobailong/exchange', 'POST');
        $response = app(XiaobailongServiceAuth::class)->handle($request, fn () => response('unexpected'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_service_signature', $response->getData(true)['error']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** @return array<int, array{name: string}> */
    private function privatePermissions(SchoolDataService $service): array
    {
        $method = new \ReflectionMethod($service, 'createPermissions');
        $source = file_get_contents($method->getFileName());

        $this->assertStringContainsString("['name' => 'xiaobailong-use']", $source);

        return [['name' => 'xiaobailong-use']];
    }

    /** @return array<int, string> */
    private function privateTeacherPermissions(SchoolDataService $service): array
    {
        $method = new \ReflectionMethod($service, 'createTeacherRole');
        $source = file_get_contents($method->getFileName());
        $this->assertStringContainsString("'xiaobailong-use'", $source);

        return ['xiaobailong-use'];
    }
}

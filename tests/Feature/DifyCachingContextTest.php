<?php

namespace Tests\Feature;

use App\Http\Middleware\DifyTokenMiddleware;
use App\Models\ApiToken;
use App\Services\CachingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class DifyCachingContextTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('auth');
        Facade::clearResolvedInstance('cache');

        parent::tearDown();
    }

    public function test_authenticated_school_context_takes_precedence_over_dify_context(): void
    {
        $this->replaceRequestWithDifyContext(202);
        Auth::shouldReceive('user')->andReturn((object) ['school_id' => 101]);
        Cache::shouldReceive('remember')
            ->once()
            ->withArgs(static fn ($key, $time, $callback) => $key === 'dify-cache_101' && $time === 900 && is_callable($callback))
            ->andReturn('cached-authenticated-context');

        $result = app(CachingService::class)->schoolLevelCaching('dify-cache', static fn () => 'not-called');

        $this->assertSame('cached-authenticated-context', $result);
    }

    public function test_valid_dify_context_resolves_school_scoped_cache_without_an_authenticated_user(): void
    {
        $this->replaceRequestWithDifyContext(202);
        Auth::shouldReceive('user')->andReturn(null);
        Cache::shouldReceive('remember')
            ->once()
            ->withArgs(static fn ($key, $time, $callback) => $key === 'dify-cache_202' && $time === 900 && is_callable($callback))
            ->andReturn('cached-dify-context');

        $result = app(CachingService::class)->schoolLevelCaching('dify-cache', static fn () => 'not-called');

        $this->assertSame('cached-dify-context', $result);
    }

    public function test_missing_or_client_supplied_dify_school_context_never_selects_a_tenant(): void
    {
        app()->instance('request', Request::create('/api/dify/admission/list?dify_school_id=202'));
        Auth::shouldReceive('user')->andReturn(null);

        $this->expectException(LogicException::class);
        app(CachingService::class)->schoolLevelCaching('dify-cache', static fn () => 'not-called');
    }

    public function test_dify_routes_are_protected_by_token_middleware_before_a_context_can_be_injected(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($candidate) => $candidate->uri() === 'api/dify/admission/today-count');

        $this->assertNotNull($route);
        $this->assertContains('DifyToken', $route->middleware());
        $this->assertSame(
            DifyTokenMiddleware::class,
            app(\App\Http\Kernel::class)->getRouteMiddleware()['DifyToken']
        );

        $response = app(DifyTokenMiddleware::class)->handle(
            Request::create('/api/dify/admission/today-count?dify_school_id=202'),
            static fn () => response('unexpected')
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('MISSING_SCHOOL_CODE', $response->getData(true)['error']['code']);
    }

    public function test_a_valid_dify_token_establishes_the_server_side_school_context_without_an_authenticated_user(): void
    {
        [$schoolId, $schoolCode, $plainToken] = $this->createDifySchoolAndToken();
        $request = Request::create('/api/dify/admission/today-count', 'GET', [], [], [], [
            'HTTP_SCHOOL_CODE' => $schoolCode,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainToken,
        ]);

        $response = app(DifyTokenMiddleware::class)->handle($request, static function (Request $request) {
            return response()->json([
                'school_id' => $request->attributes->get('dify_school_id'),
                'school_code' => $request->attributes->get('dify_school_code'),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($schoolId, $response->getData(true)['school_id']);
        $this->assertSame($schoolCode, $response->getData(true)['school_code']);
    }

    public function test_a_token_cannot_select_a_different_school_by_forging_the_school_code_header(): void
    {
        [, $schoolCode, $plainToken] = $this->createDifySchoolAndToken();
        $otherSchoolCode = 'DIFY-OTHER-' . bin2hex(random_bytes(6));
        $this->createDifySchool($otherSchoolCode);
        $request = Request::create('/api/dify/admission/today-count', 'GET', [], [], [], [
            'HTTP_SCHOOL_CODE' => $otherSchoolCode,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainToken,
        ]);

        $response = app(DifyTokenMiddleware::class)->handle($request, static fn () => response('unexpected'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('TOKEN_SCHOOL_MISMATCH', $response->getData(true)['error']['code']);
        $this->assertFalse($request->attributes->has('dify_school_id'));
    }

    private function replaceRequestWithDifyContext(int $schoolId): void
    {
        $request = Request::create('/api/dify/admission/today-count');
        $request->attributes->set('dify_school_id', $schoolId);
        app()->instance('request', $request);
    }

    private function createDifySchoolAndToken(): array
    {
        $schoolCode = 'DIFY-' . bin2hex(random_bytes(6));
        $schoolId = $this->createDifySchool($schoolCode);
        $token = ApiToken::generateToken();
        DB::connection('mysql')->table('api_tokens')->insert([
            'school_id' => $schoolId,
            'name' => 'Dify context test token',
            'token_hash' => $token['hash'],
            'permissions' => json_encode(['admission:read'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$schoolId, $schoolCode, $token['plain']];
    }

    private function createDifySchool(string $schoolCode): int
    {
        return DB::connection('mysql')->table('schools')->insertGetId([
            'name' => 'Dify Context Test',
            'address' => 'Local test only',
            'support_phone' => '0000000000',
            'support_email' => $schoolCode . '@local.test',
            'tagline' => 'Local test only',
            'logo' => 'logo.png',
            'status' => 1,
            'database_name' => Config::get('database.connections.school.database'),
            'code' => $schoolCode,
            'type' => 'custom',
            'domain_type' => 'default',
            'installed' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

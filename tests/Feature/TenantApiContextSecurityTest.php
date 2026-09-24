<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\TenantConnectionScope;
use App\Services\TrustedTenantContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

require_once __DIR__.'/../../app/Services/TenantConnectionScope.php';
require_once __DIR__.'/../../app/Http/Middleware/RequireApiFamily.php';
require_once __DIR__.'/../../app/Services/TrustedTenantContextService.php';

final class TenantApiContextSecurityTest extends TestCase
{
    private array $mysql;
    private array $school;
    private string $central;
    private string $schoolA;
    private string $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = config('database.connections.mysql');
        $this->school = config('database.connections.school');
        $this->central = tempnam(sys_get_temp_dir(), 'tenant_context_central_');
        $this->schoolA = tempnam(sys_get_temp_dir(), 'tenant_context_a_');
        $this->schoolB = tempnam(sys_get_temp_dir(), 'tenant_context_b_');

        Config::set('database.connections.mysql', $this->sqlite($this->central));
        Config::set('database.connections.school', $this->sqlite($this->schoolA));
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');
        $this->schema();
        $this->seedContexts();
        session()->flush();
        Auth::forgetUser();
    }

    protected function tearDown(): void
    {
        session()->flush();
        Auth::forgetUser();
        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->mysql);
        Config::set('database.connections.school', $this->school);
        DB::setDefaultConnection('mysql');
        @unlink($this->central);
        @unlink($this->schoolA);
        @unlink($this->schoolB);
        parent::tearDown();
    }

    public function test_api_token_cannot_select_another_school_and_never_leaks_context(): void
    {
        $request = $this->apiRequest('MMBOWEN02', '1|school-a-token', 'student');
        $before = config('database.connections.school.database');

        try {
            app(TrustedTenantContextService::class)->forApiRequest($request, fn () => response('unexpected'));
            $this->fail('A School A token must not be accepted for School B.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), [401, 403]);
        }

        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($before, config('database.connections.school.database'));
    }

    public function test_valid_api_token_runs_only_in_authorized_school_and_restores_context(): void
    {
        $request = $this->apiRequest('MMBOWEN01', '1|school-a-token', 'student');
        $before = config('database.connections.school.database');

        $response = app(TrustedTenantContextService::class)->forApiRequest($request, function () {
            $this->assertSame('school', DB::getDefaultConnection());
            $this->assertSame(10, Auth::id());
            $this->assertSame(1, Auth::user()->school_id);
            return response('', 204);
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($before, config('database.connections.school.database'));
    }

    public function test_invalid_school_code_is_rejected_without_context_switch(): void
    {
        $request = $this->apiRequest('NOT-A-SCHOOL', '1|school-a-token', 'student');

        try {
            app(TrustedTenantContextService::class)->forApiRequest($request, fn () => response('unexpected'));
            $this->fail('Unknown canonical code must fail closed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        }

        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_web_context_requires_canonical_school_matching_authenticated_actor_and_restores_after_failure(): void
    {
        $request = Request::create('/tenant-probe');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 1, 10);
        $before = config('database.connections.school.database');

        try {
            app(TrustedTenantContextService::class)->forWebRequest($request, function () {
                $this->assertSame('school', DB::getDefaultConnection());
                $this->assertSame(10, Auth::id());
                throw new \RuntimeException('validation failure');
            });
            $this->fail('The callback exception should escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('validation failure', $exception->getMessage());
        }

        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($before, config('database.connections.school.database'));
        $this->assertSame('school', $request->session()->get('db_connection_name'));

        $request->session()->put('school_database_name', $this->schoolB);
        try {
            app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('unexpected'));
            $this->fail('A School A actor must not select School B.');
        } catch (AuthorizationException) {
            $this->assertSame('mysql', DB::getDefaultConnection());
        }

        $this->establishTenantSession($request, 2, 20);
        $response = app(TrustedTenantContextService::class)->forWebRequest($request, function () {
            $this->assertSame('school', DB::getDefaultConnection());
            $this->assertSame(20, Auth::id());
            return response('', 204);
        });
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_retained_tenant_session_pins_actor_lookup_to_central_before_school_is_configured(): void
    {
        $request = Request::create('/tenant-probe');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 1, 10);

        // Reproduce a fresh PHP-FPM request: the retained session identifies
        // the tenant, but the process-local school connection has no database
        // configured yet.
        Config::set('database.connections.school.database', null);
        DB::purge('school');

        $queriesOnUnconfiguredSchool = [];
        DB::listen(function ($query) use (&$queriesOnUnconfiguredSchool): void {
            if ($query->connectionName === 'school'
                && config('database.connections.school.database') === null) {
                $queriesOnUnconfiguredSchool[] = $query->sql;
            }
        });

        $service = app(TrustedTenantContextService::class);
        $response = $service->forWebRequest($request, function () use ($service, $request) {
            $this->assertSame('school', DB::getDefaultConnection());
            $this->assertSame($this->schoolA, config('database.connections.school.database'));
            $this->assertSame(10, Auth::id());
            $this->assertSame(1, $service->trustedSchoolIdForCurrentRequest($request));
            $this->assertSame(10, $service->trustedTenantUserForCurrentRequest($request)?->id);

            return response('', 204);
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $queriesOnUnconfiguredSchool);
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertNull(config('database.connections.school.database'));
        $this->assertSame('school', $request->session()->get('db_connection_name'));
        $this->assertNull($service->trustedSchoolIdForCurrentRequest($request));
    }

    public function test_web_context_never_turns_a_central_administrator_into_a_tenant_actor(): void
    {
        $request = Request::create('/tenant-probe');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(Auth::getName(), 99);
        $request->session()->put('school_database_name', $this->schoolA);

        try {
            app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('unexpected'));
            $this->fail('A central administrator must not be transformed into a tenant actor by session context.');
        } catch (AuthorizationException) {
            $this->assertSame('mysql', DB::getDefaultConnection());
            $this->assertSame(99, Auth::id());
            $this->assertNull(Auth::user()->getRawOriginal('school_id'));
        }
    }

    public function test_guest_web_request_cannot_inherit_a_stale_tenant_default_connection(): void
    {
        $request = Request::create('/guest-probe');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('school_database_name', $this->schoolA);
        DB::setDefaultConnection('school');

        try {
            app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('', 204));
            $this->fail('A raw tenant database session value must never select a tenant.');
        } catch (AuthorizationException) {
            $this->assertSame('school', DB::getDefaultConnection());
        }

        $this->assertSame('school', DB::getDefaultConnection());
    }

    public function test_tenant_only_front_desk_requires_no_central_duplicate_and_is_revalidated_in_its_own_tenant(): void
    {
        $this->addTenantStaff($this->schoolA, 77, 1, 'Front Desk');
        $this->assertFalse(DB::connection('mysql')->table('users')->where('id', 77)->exists());

        $request = Request::create('/collections');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 1, 77);

        $response = app(TrustedTenantContextService::class)->forWebRequest($request, function () {
            $this->assertSame('school', DB::getDefaultConnection());
            $this->assertSame(77, Auth::id());
            $this->assertSame(1, Auth::user()->school_id);
            $this->assertTrue(Auth::user()->hasRole('Front Desk'));

            return response('', 204);
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_tenant_only_standard_roles_can_establish_own_school_context_without_central_duplicates(): void
    {
        foreach (['School Admin', 'Principal', 'School Accountant'] as $offset => $role) {
            $userId = 80 + $offset;
            $this->addTenantStaff($this->schoolA, $userId, 1, $role);
            $request = Request::create('/own-school');
            $request->setLaravelSession(app('session.store'));
            $this->establishTenantSession($request, 1, $userId);

            $response = app(TrustedTenantContextService::class)->forWebRequest($request, function () use ($userId, $role) {
                $this->assertSame($userId, Auth::id());
                $this->assertTrue(Auth::user()->hasRole($role));

                return response('', 204);
            });

            $this->assertSame(204, $response->getStatusCode());
            $this->assertFalse(DB::connection('mysql')->table('users')->where('id', $userId)->exists());
        }
    }

    public function test_missing_or_tampered_tenant_assertion_is_denied_and_public_entry_safely_clears_stale_context(): void
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 1, 10);
        $request->session()->put(TrustedTenantContextService::TENANT_SESSION_ASSERTION, ['version' => 1]);

        $this->expectException(AuthorizationException::class);
        app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('', 204));
    }

    public function test_central_user_with_a_coincidental_numeric_id_cannot_establish_tenant_trust(): void
    {
        // User id 10 exists in both fixtures. Without the login-issued
        // assertion it must still be rejected rather than using Central as a
        // fallback proof for the tenant identity.
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(Auth::getName(), 10);
        $request->session()->put('db_connection_name', 'school');
        $request->session()->put('school_database_name', $this->schoolA);

        $this->expectException(AuthorizationException::class);
        app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('', 204));
    }

    public function test_stale_pre_assertion_session_can_reach_login_entry_only_after_its_untrusted_tenant_context_is_cleared(): void
    {
        $request = Request::create('/login');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(Auth::getName(), 10);
        $request->session()->put('db_connection_name', 'school');
        $request->session()->put('school_database_name', $this->schoolA);

        $response = app(TrustedTenantContextService::class)->forWebRequest($request, function () {
            $this->assertSame('mysql', DB::getDefaultConnection());

            return response('', 204);
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($request->session()->get('school_database_name'));
        $this->assertNull($request->session()->get(TrustedTenantContextService::TENANT_SESSION_ASSERTION));
        $this->assertNull($request->session()->get(Auth::getName()));
    }

    public function test_missing_inactive_or_wrong_school_tenant_user_is_denied_after_login_assertion(): void
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 1, 10);
        DB::connection('school')->table('users')->where('id', 10)->update(['status' => 0]);

        try {
            app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('', 204));
            $this->fail('An inactive tenant user must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('mysql', DB::getDefaultConnection());
        }

        DB::connection('school')->table('users')->where('id', 10)->update(['status' => 1]);
        DB::connection('mysql')->table('schools')->where('id', 1)->update(['status' => 0]);
        try {
            app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('', 204));
            $this->fail('An inactive School must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('mysql', DB::getDefaultConnection());
        }
    }

    public function test_deleted_tenant_user_is_denied_after_a_valid_login_assertion(): void
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 2, 20);
        $school = School::on('mysql')->findOrFail(2);
        app(TenantConnectionScope::class)->forSchool($school, function (): void {
            DB::connection('school')->table('users')->where('id', 20)->update(['deleted_at' => now()]);
        });

        $this->expectException(AuthorizationException::class);
        app(TrustedTenantContextService::class)->forWebRequest($request, fn () => response('', 204));
    }

    public function test_logout_cleanup_removes_the_assertion_and_prevents_a_retained_tenant_context(): void
    {
        $request = Request::create('/logout');
        $request->setLaravelSession(app('session.store'));
        $this->establishTenantSession($request, 1, 10);
        app(TrustedTenantContextService::class)->clearTenantLoginAssertion($request);

        $this->assertNull($request->session()->get(TrustedTenantContextService::TENANT_SESSION_ASSERTION));
        $this->assertNull($request->session()->get('school_database_name'));
        $this->assertNull($request->session()->get(Auth::getName()));
    }

    public function test_global_view_consumers_cannot_treat_a_retained_session_as_trusted_tenant_context(): void
    {
        $request = Request::create('/denied-route');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('db_connection_name', 'school');
        $request->session()->put('school_database_name', $this->schoolA);
        Config::set('database.connections.school.database', null);
        DB::purge('school');

        $queriesOnUnconfiguredSchool = [];
        DB::listen(function ($query) use (&$queriesOnUnconfiguredSchool): void {
            if ($query->connectionName === 'school'
                && config('database.connections.school.database') === null) {
                $queriesOnUnconfiguredSchool[] = $query->sql;
            }
        });

        $this->assertNull(app(TrustedTenantContextService::class)
            ->trustedSchoolIdForCurrentRequest($request));
        $this->assertNull(app(TrustedTenantContextService::class)
            ->trustedTenantUserForCurrentRequest($request));
        $this->assertSame([], $queriesOnUnconfiguredSchool);
    }

    public function test_denied_error_page_renders_without_querying_an_unconfigured_tenant_connection(): void
    {
        $request = Request::create('/central-finance/denied-route');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(Auth::getName(), 10);
        $request->session()->put('db_connection_name', 'school');
        $request->session()->put('school_database_name', $this->schoolA);
        $this->app->instance('request', $request);
        Auth::forgetUser();
        Config::set('database.connections.school.database', null);
        DB::purge('school');

        $queriesOnUnconfiguredSchool = [];
        DB::listen(function ($query) use (&$queriesOnUnconfiguredSchool): void {
            if ($query->connectionName === 'school'
                && config('database.connections.school.database') === null) {
                $queriesOnUnconfiguredSchool[] = $query->sql;
            }
        });

        $response = app(\App\Exceptions\Handler::class)
            ->render($request, new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE[3D000]', $response->getContent());
        $this->assertSame([], $queriesOnUnconfiguredSchool);
    }

    public function test_not_found_error_page_renders_without_querying_an_unconfigured_tenant_connection(): void
    {
        $request = Request::create('/missing-route');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(Auth::getName(), 10);
        $request->session()->put('db_connection_name', 'school');
        $request->session()->put('school_database_name', $this->schoolA);
        $this->app->instance('request', $request);
        Auth::forgetUser();
        Config::set('database.connections.school.database', null);
        DB::purge('school');

        $queriesOnUnconfiguredSchool = [];
        DB::listen(function ($query) use (&$queriesOnUnconfiguredSchool): void {
            if ($query->connectionName === 'school'
                && config('database.connections.school.database') === null) {
                $queriesOnUnconfiguredSchool[] = $query->sql;
            }
        });

        $response = app(\App\Exceptions\Handler::class)
            ->render($request, new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE[3D000]', $response->getContent());
        $this->assertSame([], $queriesOnUnconfiguredSchool);
    }

    public function test_connection_scope_restores_success_and_exception_paths_used_by_workers(): void
    {
        $school = School::on('mysql')->findOrFail(1);
        $scope = app(TenantConnectionScope::class);
        $before = config('database.connections.school.database');

        $scope->forSchool($school, function () {
            $this->assertSame('school', DB::getDefaultConnection());
        });
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($before, config('database.connections.school.database'));

        try {
            $scope->forSchool($school, fn () => throw new \RuntimeException('job failed'));
        } catch (\RuntimeException $exception) {
            $this->assertSame('job failed', $exception->getMessage());
        }
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($before, config('database.connections.school.database'));
        $jobSource = (string) file_get_contents(__DIR__.'/../../app/Jobs/SetupSchoolDatabase.php');
        $this->assertStringContainsString('TenantConnectionScope', $jobSource);
        $this->assertStringContainsString('$connections->preserve', $jobSource);
    }

    private function apiRequest(string $schoolCode, string $token, string $family): Request
    {
        $request = Request::create('/api/probe', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_SCHOOL_CODE' => $schoolCode,
        ]);
        $route = new Route(['GET'], 'api/probe', fn () => response('', 204));
        $route->middleware('apiFamily:'.$family);
        $route->setContainer(app());
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function schema(): void
    {
        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id(); $table->string('code'); $table->string('database_name'); $table->tinyInteger('status')->default(1); $table->boolean('installed')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::connection('mysql')->create('users', function ($table): void {
            $table->id(); $table->unsignedBigInteger('school_id')->nullable(); $table->tinyInteger('status')->default(1); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        foreach ([$this->schoolA, $this->schoolB] as $database) {
            Config::set('database.connections.school', $this->sqlite($database));
            DB::purge('school');
            Schema::connection('school')->create('users', function ($table): void {
                $table->id(); $table->unsignedBigInteger('school_id')->nullable(); $table->tinyInteger('status')->default(1); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->timestamps(); $table->softDeletes();
            });
            Schema::connection('school')->create('roles', function ($table): void {
                $table->id(); $table->string('name'); $table->string('guard_name'); $table->unsignedBigInteger('school_id')->nullable(); $table->timestamps();
            });
            Schema::connection('school')->create('model_has_roles', function ($table): void {
                $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id');
            });
            Schema::connection('school')->create('personal_access_tokens', function ($table): void {
                $table->id(); $table->string('tokenable_type'); $table->unsignedBigInteger('tokenable_id'); $table->string('name'); $table->string('token', 64)->unique(); $table->text('abilities')->nullable(); $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
            });
        }
        Config::set('database.connections.school', $this->sqlite($this->schoolA));
        DB::purge('school');
    }

    private function seedContexts(): void
    {
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'code' => 'MMBOWEN01', 'database_name' => $this->schoolA, 'status' => 1, 'installed' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'MMBOWEN02', 'database_name' => $this->schoolB, 'status' => 1, 'installed' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'first_name' => 'Tenant', 'last_name' => 'Actor', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'school_id' => 2, 'first_name' => 'Tenant', 'last_name' => 'Second', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 99, 'school_id' => null, 'first_name' => 'Central', 'last_name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->seedTenant($this->schoolA, 1, 10, 'school-a-token');
        $this->seedTenant($this->schoolB, 2, 20, 'school-b-token');
        Config::set('database.connections.school', $this->sqlite($this->schoolA));
        DB::purge('school');
    }

    private function seedTenant(string $database, int $schoolId, int $userId, string $token): void
    {
        Config::set('database.connections.school', $this->sqlite($database));
        DB::purge('school');
        DB::connection('school')->table('users')->insert(['id' => $userId, 'school_id' => $schoolId, 'status' => 1, 'first_name' => 'Tenant', 'last_name' => 'Actor', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('school')->table('roles')->insert(['id' => 1, 'name' => 'Student', 'guard_name' => 'web', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('school')->table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);
        DB::connection('school')->table('personal_access_tokens')->insert(['id' => 1, 'tokenable_type' => User::class, 'tokenable_id' => $userId, 'name' => 'test', 'token' => hash('sha256', $token), 'abilities' => json_encode(['student-api']), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sqlite(string $database): array
    {
        return ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true];
    }

    private function establishTenantSession(Request $request, int $schoolId, int $tenantUserId): void
    {
        $school = School::on('mysql')->findOrFail($schoolId);
        $request->session()->put(Auth::getName(), $tenantUserId);
        $request->session()->put('db_connection_name', 'school');
        $request->session()->put('school_database_name', $school->database_name);

        app(TenantConnectionScope::class)->forSchool($school, function () use ($request, $school, $tenantUserId): void {
            $user = User::on('school')->findOrFail($tenantUserId);
            app(TrustedTenantContextService::class)->establishTenantLoginAssertion($request, $school, $user);
        });
        Auth::forgetUser();
    }

    private function addTenantStaff(string $database, int $userId, int $schoolId, string $role): void
    {
        Config::set('database.connections.school', $this->sqlite($database));
        DB::purge('school');
        DB::connection('school')->table('users')->insert([
            'id' => $userId, 'school_id' => $schoolId, 'status' => 1,
            'first_name' => 'Tenant', 'last_name' => 'Staff', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::connection('school')->table('roles')->insertGetId([
            'name' => $role, 'guard_name' => 'web', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('school')->table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $userId,
        ]);
    }
}

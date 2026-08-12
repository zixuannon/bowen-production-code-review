<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenantDatabase;
use App\Models\User;
use App\Support\LocalBowenQaGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InitializeTenantDatabaseTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];

    public function test_tenant_context_is_initialized_before_role_aware_web_middleware(): void
    {
        $web = app(\App\Http\Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertLessThan(
            array_search(\App\Http\Middleware\LanguageManager::class, $web, true),
            array_search(InitializeTenantDatabase::class, $web, true)
        );
        $this->assertLessThan(
            array_search(\App\Http\Middleware\WizardSettings::class, $web, true),
            array_search(InitializeTenantDatabase::class, $web, true)
        );
    }

    public function test_it_reloads_the_authenticated_user_after_establishing_the_tenant_connection(): void
    {
        $tenantDatabase = LocalBowenQaGuard::TENANT_DATABASE;
        Config::set('database.connections.school.database', $tenantDatabase);
        DB::purge('school');
        $email = 'tenant-context-' . bin2hex(random_bytes(12)) . '@local.test';
        $password = bcrypt('local-only');
        $centralId = DB::connection('mysql')->table('users')->insertGetId([
            'first_name' => 'Tenant', 'last_name' => 'Context', 'email' => $email, 'password' => $password,
            'school_id' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('school')->table('users')->insert([
            'id' => $centralId, 'first_name' => 'Tenant', 'last_name' => 'Context', 'email' => $email, 'password' => $password,
            'school_id' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['School Admin', 'HR'] as $role) {
            DB::connection('school')->table('roles')->updateOrInsert(
                ['name' => $role, 'school_id' => 1],
                ['guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]
            );
            DB::connection('school')->table('model_has_roles')->insert([
                'role_id' => DB::connection('school')->table('roles')->where('name', $role)->where('school_id', 1)->value('id'),
                'model_id' => $centralId,
                'model_type' => User::class,
            ]);
        }

        DB::setDefaultConnection('mysql');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $central = User::findOrFail($centralId);
        Auth::login($central);
        session()->put(Auth::getName(), $centralId);
        $this->assertFalse(Auth::user()->hasAnyRole(['School Admin', 'HR']));
        $this->assertTrue(Auth::user()->relationLoaded('roles'));

        session(['school_database_name' => $tenantDatabase]);
        $request = Request::create('/tenant-context-probe');
        $request->setLaravelSession(app('session.store'));

        app(InitializeTenantDatabase::class)->handle($request, function () {
            $this->assertSame('school', DB::getDefaultConnection());
            $this->assertTrue(Auth::user()->hasAllRoles(['School Admin', 'HR']));
            $this->assertFalse(Auth::user()->relationLoaded('roles') && Auth::user()->roles->isEmpty());

            return response('ok');
        });

        Auth::logout();
        DB::setDefaultConnection('mysql');
    }

    public function test_it_keeps_central_context_when_no_tenant_is_selected(): void
    {
        session()->forget('school_database_name');
        DB::setDefaultConnection('school');
        $request = Request::create('/central-context-probe');
        $request->setLaravelSession(app('session.store'));

        app(InitializeTenantDatabase::class)->handle($request, function () {
            $this->assertSame('mysql', DB::getDefaultConnection());

            return response('ok');
        });
    }
}

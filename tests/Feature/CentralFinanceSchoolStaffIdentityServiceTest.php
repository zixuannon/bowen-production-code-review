<?php

namespace Tests\Feature;

use App\Models\CentralFinanceSchoolStaffIdentity;
use App\Models\FinanceGroup;
use App\Models\School;
use App\Services\CentralFinanceSchoolStaffIdentityService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CentralFinanceSchoolStaffIdentityServiceTest extends TestCase
{
    private string $central;
    private string $zixuan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = tempnam(sys_get_temp_dir(), 'cf_staff_identity_central_');
        $this->zixuan = tempnam(sys_get_temp_dir(), 'cf_staff_identity_zixuan_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->central, 'prefix' => '', 'foreign_key_constraints' => true]);
        Config::set('database.connections.school', ['driver' => 'sqlite', 'database' => $this->zixuan, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('code'); $table->string('database_name'); $table->boolean('installed')->default(true); $table->string('status')->default('active'); $table->softDeletes(); $table->timestamps(); });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void { $table->id(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->string('email')->nullable(); $table->string('password')->nullable(); $table->unsignedBigInteger('school_id')->nullable(); $table->boolean('status')->default(true); $table->softDeletes(); $table->timestamps(); });
        Schema::connection('mysql')->create('roles', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('guard_name')->default('web'); $table->unsignedBigInteger('school_id')->nullable(); $table->timestamps(); });
        Schema::connection('mysql')->create('model_has_roles', function (Blueprint $table): void { $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id'); });
        foreach (['2026_08_18_000001_create_finance_group_scope_tables.php', '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php', '2026_08_21_000002_create_central_finance_operating_documents.php', '2026_08_21_000003_create_central_finance_internal_transfer_documents.php', '2026_08_24_000002_create_central_finance_school_staff_identities.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        DB::connection('mysql')->table('schools')->insert(['id' => 1, 'name' => 'Zixuan', 'code' => 'SCH202615', 'database_name' => $this->zixuan, 'installed' => true, 'status' => 'active']);

        Schema::connection('school')->create('users', function (Blueprint $table): void { $table->id(); $table->uuid('central_finance_source_uuid')->nullable()->unique(); $table->unsignedBigInteger('school_id'); $table->string('first_name'); $table->string('last_name'); $table->string('email')->nullable(); $table->softDeletes(); });
        Schema::connection('school')->create('staffs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('user_id'); });
        Schema::connection('school')->create('roles', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('guard_name')->default('web'); $table->unsignedBigInteger('school_id')->nullable(); });
        Schema::connection('school')->create('model_has_roles', function (Blueprint $table): void { $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id'); });
        DB::connection('school')->table('users')->insert(['id' => 7, 'school_id' => 1, 'first_name' => 'Zixuan', 'last_name' => 'Accountant', 'email' => 'zixuan.accountant@example.test']);
        DB::connection('school')->table('users')->insert([
            ['id' => 8, 'school_id' => 1, 'first_name' => 'Zixuan', 'last_name' => 'Principal', 'email' => 'zixuan.principal@example.test'],
            ['id' => 9, 'school_id' => 1, 'first_name' => 'Multi', 'last_name' => 'Role', 'email' => 'multi@example.test'],
            ['id' => 10, 'school_id' => 1, 'first_name' => 'School', 'last_name' => 'Admin', 'email' => 'admin@example.test'],
        ]);
        DB::connection('school')->table('staffs')->insert([['id' => 1, 'user_id' => 7], ['id' => 2, 'user_id' => 8], ['id' => 3, 'user_id' => 9], ['id' => 4, 'user_id' => 10]]);
        DB::connection('school')->table('roles')->insert([
            ['id' => 1, 'name' => 'Cashier', 'guard_name' => 'web', 'school_id' => 1],
            ['id' => 2, 'name' => 'Principal', 'guard_name' => 'web', 'school_id' => 1],
            ['id' => 3, 'name' => 'School Admin', 'guard_name' => 'web', 'school_id' => 1],
        ]);
        DB::connection('school')->table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => \App\Models\User::class, 'model_id' => 7],
            ['role_id' => 2, 'model_type' => \App\Models\User::class, 'model_id' => 8],
            ['role_id' => 1, 'model_type' => \App\Models\User::class, 'model_id' => 9],
            ['role_id' => 2, 'model_type' => \App\Models\User::class, 'model_id' => 9],
            ['role_id' => 3, 'model_type' => \App\Models\User::class, 'model_id' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql'); DB::purge('school'); @unlink($this->central); @unlink($this->zixuan); parent::tearDown();
    }

    public function test_existing_school_staff_receives_a_stable_uuid_backed_identity_without_a_second_login(): void
    {
        $group = app(\App\Services\FinanceGroupScopeService::class)->createGroup(['name' => 'Bowen QA', 'code' => 'BOWEN_QA', 'status' => 'active']);
        app(\App\Services\FinanceGroupScopeService::class)->addSchool($group, 1);
        $service = app(CentralFinanceSchoolStaffIdentityService::class);
        $principal = $service->grantSchoolAccountant($group, 1, 7);

        $tenantUuid = DB::connection('school')->table('users')->where('id', 7)->value('central_finance_source_uuid');
        $identity = CentralFinanceSchoolStaffIdentity::on('mysql')->sole();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $tenantUuid);
        $this->assertSame($tenantUuid, $identity->tenant_user_uuid);
        $this->assertNotSame(7, $identity->central_user_id);
        $this->assertSame([$principal->id], DB::connection('mysql')->table('central_finance_user_school_scopes')->where('school_id', 1)->where('can_view', true)->pluck('user_id')->all());
        $this->assertSame([1], app(CentralFinanceWorkspaceService::class)->accessibleSchools($principal)->pluck('id')->all());
        // A real School Login retains this tenant session key. Central
        // principal resolution must still query the central directory.
        Session::put('db_connection_name', 'school');
        $this->assertSame($principal->id, $service->resolveTrustedSession(['school_id' => 1, 'user_uuid' => $tenantUuid])->id);
        Session::forget('db_connection_name');
        Session::forget(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY);
        $this->assertSame(1, app(CentralFinanceWorkspaceService::class)->currentSchool($principal)?->id);
        Session::put(CentralFinanceWorkspaceService::SESSION_SCHOOL_KEY, 999);
        $this->assertSame(1, app(CentralFinanceWorkspaceService::class)->currentSchool($principal)?->id);

        $this->expectException(AuthorizationException::class);
        $service->resolveTrustedSession(['school_id' => 2, 'user_uuid' => $tenantUuid]);
    }

    public function test_forged_or_cross_school_staff_selection_is_rejected(): void
    {
        $group = app(\App\Services\FinanceGroupScopeService::class)->createGroup(['name' => 'Bowen QA', 'code' => 'BOWEN_QA', 'status' => 'active']);
        app(\App\Services\FinanceGroupScopeService::class)->addSchool($group, 1);
        $this->expectException(ValidationException::class);
        app(CentralFinanceSchoolStaffIdentityService::class)->grantSchoolAccountant($group, 1, 99);
    }

    public function test_principal_is_read_only_and_scope_revocation_immediately_removes_central_finance_access_without_removing_school_role(): void
    {
        $group = app(\App\Services\FinanceGroupScopeService::class)->createGroup(['name' => 'Bowen QA', 'code' => 'BOWEN_QA', 'status' => 'active']);
        app(\App\Services\FinanceGroupScopeService::class)->addSchool($group, 1);
        $service = app(CentralFinanceSchoolStaffIdentityService::class);
        $principal = $service->grantSchoolPrincipal($group, 1, 8);

        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => $principal->id, 'school_id' => 1])->first();
        $this->assertTrue((bool) $scope->can_view);
        $this->assertFalse((bool) $scope->can_operate);
        $this->assertSame([1], app(CentralFinanceWorkspaceService::class)->accessibleSchools($principal)->pluck('id')->all());
        try {
            app(CentralFinanceWorkspaceService::class)->assertCanOperateSchool($principal, 1);
            $this->fail('A Principal read scope must not become an operating scope.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => $principal->id, 'school_id' => 1])->update(['can_view' => false, 'can_operate' => false]);
        $this->assertSame(['Principal'], DB::connection('school')->table('model_has_roles as assignments')->join('roles', 'roles.id', '=', 'assignments.role_id')->where('assignments.model_id', 8)->pluck('roles.name')->all());
        $this->expectException(AuthorizationException::class);
        app(CentralFinanceWorkspaceService::class)->actor($principal->fresh());
    }

    public function test_multi_role_school_staff_uses_explicit_scope_union_while_school_admin_never_receives_finance_access_by_role_name_alone(): void
    {
        $group = app(\App\Services\FinanceGroupScopeService::class)->createGroup(['name' => 'Bowen QA', 'code' => 'BOWEN_QA', 'status' => 'active']);
        app(\App\Services\FinanceGroupScopeService::class)->addSchool($group, 1);
        $service = app(CentralFinanceSchoolStaffIdentityService::class);

        // Principal + Cashier stays read-only under an explicit Principal
        // grant; an explicit Accountant grant is needed to add operate.
        $multi = $service->grantSchoolPrincipal($group, 1, 9);
        $this->assertFalse((bool) DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => $multi->id, 'school_id' => 1])->value('can_operate'));
        $service->grantSchoolAccountant($group, 1, 9);
        $this->assertTrue((bool) DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => $multi->id, 'school_id' => 1])->value('can_operate'));

        $this->expectException(ValidationException::class);
        $service->grantSchoolPrincipal($group, 1, 10);
    }

    public function test_school_staff_principal_without_a_spatie_role_uses_the_sidebar_safe_role_value(): void
    {
        $group = app(\App\Services\FinanceGroupScopeService::class)->createGroup(['name' => 'Bowen QA', 'code' => 'BOWEN_QA', 'status' => 'active']);
        app(\App\Services\FinanceGroupScopeService::class)->addSchool($group, 1);
        $principal = app(CentralFinanceSchoolStaffIdentityService::class)->grantSchoolAccountant($group, 1, 7);

        $this->assertSame([], $principal->getRoleNames()->all());
        $sidebar = (string) file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $this->assertStringContainsString("\$sidebarRoleName = Auth::check() ? (string) Auth::user()->getRoleNames()->first() : '';", $sidebar);
        $this->assertStringContainsString('data-name="{{ $sidebarRoleName }}"', $sidebar);
        $this->assertStringNotContainsString('getRoleNames()[0]', $sidebar);
    }
}

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
        DB::connection('school')->table('users')->insert(['id' => 7, 'school_id' => 1, 'first_name' => 'Zixuan', 'last_name' => 'Accountant', 'email' => 'zixuan.accountant@example.test']);
        DB::connection('school')->table('staffs')->insert(['id' => 1, 'user_id' => 7]);
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
        $this->assertSame($principal->id, $service->resolveTrustedSession(['school_id' => 1, 'user_uuid' => $tenantUuid])->id);

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
}

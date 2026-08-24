<?php

namespace Tests\Feature;

use App\Http\Controllers\FinanceGroupController;
use App\Models\FinanceGroup;
use App\Models\User;
use App\Services\FinanceGroupScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CentralFinanceStaffConfigurationTest extends TestCase
{
    private string $database;
    private User $superAdmin;
    private FinanceGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_staff_configuration_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql'); DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('code'); $table->string('database_name'); $table->string('status')->default('active'); $table->softDeletes(); $table->timestamps(); });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void { $table->id(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->string('email')->nullable(); $table->unsignedBigInteger('school_id')->nullable(); $table->softDeletes(); $table->timestamps(); });
        Schema::connection('mysql')->create('roles', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('guard_name')->default('web'); $table->unsignedBigInteger('school_id')->nullable(); $table->timestamps(); });
        Schema::connection('mysql')->create('model_has_roles', function (Blueprint $table): void { $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id'); });
        foreach (['2026_08_18_000001_create_finance_group_scope_tables.php', '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php', '2026_08_21_000002_create_central_finance_operating_documents.php', '2026_08_21_000003_create_central_finance_internal_transfer_documents.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        DB::connection('mysql')->table('schools')->insert([['id' => 1, 'name' => 'Zixuan', 'code' => 'ZIX', 'database_name' => 'qa_zixuan', 'status' => 'active'], ['id' => 2, 'name' => 'Timecity', 'code' => 'TIM', 'database_name' => 'qa_timecity', 'status' => 'active']]);
        DB::connection('mysql')->table('users')->insert([['id' => 1, 'first_name' => 'Super', 'last_name' => 'Admin', 'school_id' => null], ['id' => 2, 'first_name' => 'Head', 'last_name' => 'Finance', 'school_id' => null], ['id' => 3, 'first_name' => 'Zixuan', 'last_name' => 'Accountant', 'school_id' => null]]);
        DB::connection('mysql')->table('roles')->insert([['id' => 1, 'name' => 'Super Admin', 'guard_name' => 'web'], ['id' => 2, 'name' => 'Head Finance', 'guard_name' => 'web']]);
        foreach ([[1, 1], [2, 2]] as [$userId, $roleId]) DB::connection('mysql')->table('model_has_roles')->insert(['model_id' => $userId, 'role_id' => $roleId, 'model_type' => User::class]);
        $scope = app(FinanceGroupScopeService::class);
        $this->group = $scope->createGroup(['name' => 'Central Staff QA', 'code' => 'CF_STAFF_QA', 'status' => 'active']);
        $scope->syncSchools($this->group, [1, 2]);
        $this->superAdmin = User::on('mysql')->findOrFail(1);
    }

    protected function tearDown(): void { DB::purge('mysql'); @unlink($this->database); parent::tearDown(); }

    public function test_head_finance_all_school_grant_creates_each_central_scope_and_school_accountant_is_single_school_only(): void
    {
        $this->actingAs($this->superAdmin);
        $controller = app(FinanceGroupController::class);
        $controller->storeCentralSchoolScope(new Request(['central_user_id' => 2, 'grant_type' => 'head_finance_all']), $this->group);
        $scopes = DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id', 2)->orderBy('school_id')->get();
        $this->assertCount(2, $scopes);
        $this->assertTrue($scopes->every(fn ($scope) => $scope->can_view && $scope->can_operate && $scope->can_approve_reimbursements && $scope->can_confirm_funding));

        $controller->storeCentralSchoolScope(new Request(['central_user_id' => 3, 'grant_type' => 'school_accountant', 'school_id' => 1]), $this->group);
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_user_school_scopes')->where('user_id', 3)->where('can_view', true)->count());
        try {
            $controller->storeCentralSchoolScope(new Request(['central_user_id' => 3, 'grant_type' => 'school_accountant', 'school_id' => 2]), $this->group);
            $this->fail('A School Accountant must remain single-School.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        try {
            $controller->storeCentralSchoolScope(new Request([
                'central_user_id' => 3, 'grant_type' => 'custom', 'school_id' => 2,
                'can_view' => true, 'can_operate' => true,
            ]), $this->group);
            $this->fail('The edit form must not bypass the one-School Accountant boundary.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_disable_preserves_scope_row_and_removes_all_central_finance_capabilities(): void
    {
        $this->actingAs($this->superAdmin);
        $controller = app(FinanceGroupController::class);
        $controller->storeCentralSchoolScope(new Request(['central_user_id' => 3, 'grant_type' => 'school_accountant', 'school_id' => 1]), $this->group);
        $controller->disableCentralSchoolScope(new Request(['central_user_id' => 3, 'school_id' => 1]), $this->group);
        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => 3, 'school_id' => 1])->first();
        $this->assertNotNull($scope);
        $this->assertFalse((bool) $scope->can_view || (bool) $scope->can_operate || (bool) $scope->can_approve_reimbursements || (bool) $scope->can_confirm_funding);
    }
}

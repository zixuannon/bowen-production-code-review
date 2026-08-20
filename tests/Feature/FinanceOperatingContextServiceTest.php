<?php

namespace Tests\Feature;

use App\Models\FinanceGroup;
use App\Models\User;
use App\Services\FinanceGroupScopeService;
use App\Services\FinanceOperatingContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceOperatingContextServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;

    /** @var array<string, mixed> */
    private array $schoolConnection;

    private string $centralDatabase;

    private string $zixuanDatabase;

    private string $timecityDatabase;

    private string $unrelatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->centralDatabase = tempnam(sys_get_temp_dir(), 'finance_operating_central_');
        $this->zixuanDatabase = tempnam(sys_get_temp_dir(), 'finance_operating_zixuan_');
        $this->timecityDatabase = tempnam(sys_get_temp_dir(), 'finance_operating_timecity_');
        $this->unrelatedDatabase = tempnam(sys_get_temp_dir(), 'finance_operating_unrelated_');

        Config::set('database.connections.mysql', $this->sqlite($this->centralDatabase));
        Config::set('database.connections.school', $this->sqlite($this->zixuanDatabase));
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');
        session()->forget(['school_database_name', 'db_connection_name', FinanceOperatingContextService::SESSION_KEY]);

        $this->createCentralSchema();
        $this->createTenantSchema($this->zixuanDatabase, 1, 101, 10, 11);
        $this->createTenantSchema($this->timecityDatabase, 2, 201, 20, 21);
        $this->seedGroupScope();
    }

    protected function tearDown(): void
    {
        session()->forget(['school_database_name', 'db_connection_name', FinanceOperatingContextService::SESSION_KEY]);
        DB::purge('mysql');
        DB::purge('school');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        DB::setDefaultConnection('mysql');
        @unlink($this->centralDatabase);
        @unlink($this->zixuanDatabase);
        @unlink($this->timecityDatabase);
        @unlink($this->unrelatedDatabase);
        parent::tearDown();
    }

    public function test_central_actor_enters_zixuan_without_impersonation_and_keeps_only_assigned_account_scope(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $this->actingAs($central);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        $service = app(FinanceOperatingContextService::class);
        $beforeDefault = DB::getDefaultConnection();
        $beforeDatabase = config('database.connections.school.database');

        $context = $service->enterSchool($central, $group->id, 1);

        $this->assertSame(1, $context->centralActorId);
        $this->assertSame(1, $context->schoolId);
        $this->assertSame(101, $context->tenantUserId);
        $this->assertSame(1, $service->centralActor($central)->id);
        $this->assertSame(1, auth()->id());
        $this->assertNull(auth()->user()?->school_id);
        $this->assertSame($group->id, $service->currentGroup($central)?->id);
        $this->assertSame(1, $service->currentSchool($central)?->id);
        $this->assertSame(101, $service->trustedTenantIdentity($central));
        $this->assertNull(session('school_database_name'));
        $this->assertSame($beforeDefault, DB::getDefaultConnection());
        $this->assertSame($beforeDatabase, config('database.connections.school.database'));

        $accounts = $service->accessibleFundAccounts($central);
        $this->assertSame([10], array_column($accounts, 'id'));
        $this->assertSame($beforeDefault, DB::getDefaultConnection());
        $this->assertSame($beforeDatabase, config('database.connections.school.database'));
        $this->assertNull(session('db_connection_name'));
    }

    public function test_central_actor_can_switch_only_between_zixuan_and_timecity_scoped_identities(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        $service = app(FinanceOperatingContextService::class);

        $service->enterSchool($central, $group->id, 1);
        $this->assertSame([10], array_column($service->accessibleFundAccounts($central), 'id'));

        $context = $service->enterSchool($central, $group->id, 2);
        $this->assertSame(2, $context->schoolId);
        $this->assertSame(201, $context->tenantUserId);
        $this->assertSame([20], array_column($service->accessibleFundAccounts($central), 'id'));

        $service->exitSchool($central);
        $this->assertNull($service->current($central));
        $this->assertNull(session('school_database_name'));
    }

    public function test_unrelated_school_tenant_login_and_forged_database_session_are_rejected(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        $service = app(FinanceOperatingContextService::class);

        try {
            $service->enterSchool($central, $group->id, 3);
            $this->fail('An unrelated School cannot be selected by ID.');
        } catch (AuthorizationException) {
            $this->assertNull($service->current($central));
        }

        session(['school_database_name' => $this->timecityDatabase]);
        $this->expectException(AuthorizationException::class);
        $service->enterSchool($central, $group->id, 1);
    }

    public function test_forged_context_payload_and_another_central_actor_are_rejected(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $otherCentral = User::on('mysql')->findOrFail(2);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        $service = app(FinanceOperatingContextService::class);

        $context = $service->enterSchool($central, $group->id, 1)->toSession();
        $context['database_name'] = $this->timecityDatabase;
        session([FinanceOperatingContextService::SESSION_KEY => $context]);

        try {
            $service->current($central);
            $this->fail('A context payload must never accept a database name.');
        } catch (AuthorizationException) {
            $this->assertNull(session(FinanceOperatingContextService::SESSION_KEY));
        }

        $service->enterSchool($central, $group->id, 1);
        session(['school_database_name' => $this->timecityDatabase]);
        try {
            $service->current($central);
            $this->fail('A Finance Operating Context cannot coexist with a tenant-login session.');
        } catch (AuthorizationException) {
            $this->assertSame($this->timecityDatabase, session('school_database_name'));
        }
        session()->forget('school_database_name');
        $this->expectException(AuthorizationException::class);
        $service->current($otherCentral);
    }

    /** @return array<string, mixed> */
    private function sqlite(string $database): array
    {
        return ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true];
    }

    private function createCentralSchema(): void
    {
        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->integer('status')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function ($table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('roles', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan QA School', 'code' => 'GROUP_QA_ZIXUAN', 'database_name' => $this->zixuanDatabase],
            ['id' => 2, 'name' => 'Timecity QA School', 'code' => 'GROUP_QA_TIMECITY', 'database_name' => $this->timecityDatabase],
            ['id' => 3, 'name' => 'Unrelated QA School', 'code' => 'GROUP_QA_UNRELATED', 'database_name' => $this->unrelatedDatabase],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 1, 'first_name' => 'Central', 'last_name' => 'Head Finance', 'email' => 'head@group-qa.test', 'school_id' => null],
            ['id' => 2, 'first_name' => 'Central', 'last_name' => 'Other', 'email' => 'other@group-qa.test', 'school_id' => null],
        ]);
        (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up();
    }

    private function createTenantSchema(string $database, int $schoolId, int $userId, int $assignedAccountId, int $unassignedAccountId): void
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        Schema::connection('school')->create('users', function ($table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('school')->create('roles', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
        });
        Schema::connection('school')->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::connection('school')->create('bank_accounts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('account_name');
            $table->string('currency')->default('MMK');
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('school')->create('bank_account_user', function ($table): void {
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        DB::connection('school')->table('users')->insert(['id' => $userId, 'first_name' => 'Accountant', 'last_name' => (string) $schoolId, 'email' => "accountant{$schoolId}@group-qa.test", 'school_id' => $schoolId]);
        DB::connection('school')->table('roles')->insert(['id' => 1, 'name' => 'Cashier', 'guard_name' => 'web', 'school_id' => $schoolId]);
        DB::connection('school')->table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);
        DB::connection('school')->table('bank_accounts')->insert([
            ['id' => $assignedAccountId, 'school_id' => $schoolId, 'account_name' => "School {$schoolId} Assigned Cash", 'currency' => 'MMK', 'is_active' => true],
            ['id' => $unassignedAccountId, 'school_id' => $schoolId, 'account_name' => "School {$schoolId} Unassigned Cash", 'currency' => 'MMK', 'is_active' => true],
        ]);
        DB::connection('school')->table('bank_account_user')->insert(['bank_account_id' => $assignedAccountId, 'user_id' => $userId]);
    }

    private function seedGroupScope(): void
    {
        $scope = app(FinanceGroupScopeService::class);
        $group = $scope->createGroup(['name' => 'Bowen Operating QA', 'code' => 'BOWEN_OPERATING_QA', 'status' => 'active']);
        $scope->addSchool($group, 1);
        $scope->addSchool($group, 2);
        $groupUser = $scope->addUser($group, 1);
        $scope->grantScope($groupUser, FinanceOperatingContextService::OPERATING_CAPABILITY, 'GROUP');
        $scope->bindTenantIdentity($groupUser, 1, 101);
        $scope->bindTenantIdentity($groupUser, 2, 201);
    }
}

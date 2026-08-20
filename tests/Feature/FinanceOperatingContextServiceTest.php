<?php

namespace Tests\Feature;

use App\Models\FinanceGroup;
use App\Models\User;
use App\Services\FinanceGroupScopeService;
use App\Services\FinanceOperatingContextService;
use App\Services\FinanceOperatingWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $this->seedTenantFinance($this->zixuanDatabase, 1, 101, 10, 'ZIXUAN');
        $this->seedTenantFinance($this->timecityDatabase, 2, 201, 20, 'TIMECITY');
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
        $this->actingAs($central);
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

    public function test_workspace_reads_only_the_current_school_with_mapped_account_scope_and_keeps_central_identity(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $this->actingAs($central);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        $context = app(FinanceOperatingContextService::class);
        $workspace = app(FinanceOperatingWorkspaceService::class);
        $before = $this->financeSourceSnapshot();

        $context->enterSchool($central, $group->id, 1);
        $zixuanAccounts = $workspace->accounts($central);
        $zixuanLedger = $workspace->ledger($central);
        $this->assertSame([10], array_column($zixuanAccounts, 'id'));
        $this->assertSame(1100.0, $zixuanAccounts[0]['current_balance']);
        $this->assertSame(['ZIXUAN_OTHER'], $zixuanLedger['rows']->pluck('reference_no')->all());
        $this->assertSame(100.0, $zixuanLedger['summary']['operating_income']);
        $this->assertSame(1, auth()->id());
        $this->assertNull(auth()->user()?->school_id);
        $this->assertNull(session('school_database_name'));

        $context->enterSchool($central, $group->id, 2);
        $timecityAccounts = $workspace->accounts($central);
        $timecityLedger = $workspace->ledger($central);
        $this->assertSame([20], array_column($timecityAccounts, 'id'));
        $this->assertSame(1200.0, $timecityAccounts[0]['current_balance']);
        $this->assertSame(['TIMECITY_OTHER'], $timecityLedger['rows']->pluck('reference_no')->all());
        $this->assertSame(200.0, $timecityLedger['summary']['operating_income']);
        $this->assertSame(1, auth()->id());
        $this->assertNull(session('school_database_name'));
        $this->assertSame($before, $this->financeSourceSnapshot());
    }

    public function test_workspace_rejects_an_unassigned_fund_account_filter_and_never_falls_into_the_peer_school(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        app(FinanceOperatingContextService::class)->enterSchool($central, $group->id, 1);

        $this->expectException(ModelNotFoundException::class);
        app(FinanceOperatingWorkspaceService::class)->ledger($central, ['bank_account_id' => 11]);
    }

    public function test_operating_executor_uses_only_the_current_trusted_school_identity_and_retains_central_authentication(): void
    {
        $central = User::on('mysql')->findOrFail(1);
        $this->actingAs($central);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_OPERATING_QA')->firstOrFail();
        $context = app(FinanceOperatingContextService::class)->enterSchool($central, $group->id, 1);
        $groupUser = \App\Models\FinanceGroupUser::query()->where('group_id', $group->id)->where('central_user_id', $central->id)->firstOrFail();

        $created = app(FinanceGroupScopeService::class)->executeOperatingFinanceAsTenantIdentity($groupUser, 1,
            function (User $tenant) use ($context): int {
                $id = DB::connection('school')->table('other_incomes')->insertGetId([
                    'school_id' => $tenant->school_id, 'bank_account_id' => 10, 'date' => '2026-08-20',
                    'payer' => 'Central QA', 'description' => 'trusted operating write', 'amount' => 25,
                    'payment_method' => 'Cash', 'reference_no' => 'OPERATING_WRITE_A', 'created_by' => $tenant->id,
                ]);
                DB::connection('school')->table('finance_operating_audits')->insert([
                    'central_actor_id' => $context->centralActorId, 'finance_group_id' => $context->groupId,
                    'school_id' => $context->schoolId, 'tenant_user_id' => $context->tenantUserId,
                    'source_type' => 'other_income', 'source_id' => $id, 'action' => 'receive_money',
                    'request_source' => 'finance_operating_context', 'created_at' => now(), 'updated_at' => now(),
                ]);
                return $id;
            });

        Config::set('database.connections.school.database', $this->zixuanDatabase); DB::purge('school');
        $this->assertSame(2, DB::connection('school')->table('other_incomes')->where('school_id', 1)->count());
        $this->assertSame(1, DB::connection('school')->table('finance_operating_audits')->where('source_id', $created)->count());
        Config::set('database.connections.school.database', $this->timecityDatabase); DB::purge('school');
        $this->assertSame(1, DB::connection('school')->table('other_incomes')->where('school_id', 2)->count());
        $this->assertSame(1, auth()->id());
        $this->assertNull(auth()->user()?->school_id);
        $this->assertNull(session('school_database_name'));
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
        DB::connection('mysql')->table('roles')->insert([
            'id' => 1, 'name' => 'Head Finance', 'guard_name' => 'web', 'school_id' => null,
        ]);
        DB::connection('mysql')->table('model_has_roles')->insert([
            'role_id' => 1, 'model_type' => User::class, 'model_id' => 1,
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
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('school')->create('bank_account_user', function ($table): void {
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
        Schema::connection('school')->create('compulsory_fees', function ($table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('student_id')->nullable(); $table->string('status')->default('Success');
            $table->string('reference_no')->nullable(); $table->string('mode')->nullable(); $table->decimal('amount', 15, 2); $table->date('date'); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('school')->create('optional_fees', function ($table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('student_id')->nullable(); $table->string('status')->default('Success');
            $table->string('mode')->nullable(); $table->decimal('amount', 15, 2); $table->date('date'); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('school')->create('other_incomes', function ($table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('bank_account_id');
            $table->date('date'); $table->string('payer'); $table->string('description'); $table->decimal('amount', 15, 2);
            $table->string('payment_method'); $table->string('reference_no')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('school')->create('expenses', function ($table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('bank_account_id');
            $table->string('ref_no')->nullable(); $table->string('title'); $table->string('description')->nullable();
            $table->decimal('amount', 15, 2); $table->decimal('amount_mmk', 15, 2)->default(0); $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('finance_category_id')->nullable(); $table->string('transaction_currency')->nullable();
            $table->decimal('original_amount', 15, 2)->nullable(); $table->decimal('exchange_rate_snapshot', 15, 4)->nullable(); $table->unsignedBigInteger('created_by')->nullable();
            $table->date('date'); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('school')->create('bank_transfers', function ($table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('from_account_id'); $table->unsignedBigInteger('to_account_id');
            $table->decimal('amount', 15, 2); $table->date('transfer_date'); $table->string('reference_no')->nullable(); $table->string('notes')->nullable(); $table->string('status')->default('completed'); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('school')->create('finance_operating_audits', function ($table): void {
            $table->id(); $table->unsignedBigInteger('central_actor_id'); $table->unsignedBigInteger('finance_group_id');
            $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('tenant_user_id');
            $table->string('source_type'); $table->unsignedBigInteger('source_id'); $table->string('action'); $table->string('request_source'); $table->timestamps();
        });

        DB::connection('school')->table('users')->insert(['id' => $userId, 'first_name' => 'Accountant', 'last_name' => (string) $schoolId, 'email' => "accountant{$schoolId}@group-qa.test", 'school_id' => $schoolId]);
        DB::connection('school')->table('roles')->insert(['id' => 1, 'name' => 'Cashier', 'guard_name' => 'web', 'school_id' => $schoolId]);
        DB::connection('school')->table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => $userId]);
        DB::connection('school')->table('bank_accounts')->insert([
            ['id' => $assignedAccountId, 'school_id' => $schoolId, 'account_name' => "School {$schoolId} Assigned Cash", 'currency' => 'MMK', 'opening_balance' => 1000, 'is_active' => true],
            ['id' => $unassignedAccountId, 'school_id' => $schoolId, 'account_name' => "School {$schoolId} Unassigned Cash", 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true],
        ]);
        DB::connection('school')->table('bank_account_user')->insert(['bank_account_id' => $assignedAccountId, 'user_id' => $userId]);
    }

    private function seedTenantFinance(string $database, int $schoolId, int $userId, int $accountId, string $prefix): void
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        DB::connection('school')->table('other_incomes')->insert([
            'school_id' => $schoolId, 'bank_account_id' => $accountId, 'date' => '2026-08-20',
            'payer' => $prefix . ' Payer', 'description' => $prefix . ' Other Income', 'amount' => $schoolId * 100,
            'payment_method' => 'Cash', 'reference_no' => $prefix . '_OTHER', 'created_by' => $userId,
        ]);
    }

    private function financeSourceSnapshot(): string
    {
        $parts = [];
        foreach ([$this->zixuanDatabase, $this->timecityDatabase] as $database) {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            foreach (['bank_accounts', 'compulsory_fees', 'optional_fees', 'other_incomes', 'expenses', 'bank_transfers'] as $table) {
                $parts[] = $database . ':' . $table . ':' . json_encode(
                    DB::connection('school')->table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all(),
                    JSON_THROW_ON_ERROR,
                );
            }
        }

        return hash('sha256', implode('|', $parts));
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

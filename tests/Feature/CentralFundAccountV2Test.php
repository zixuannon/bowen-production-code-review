<?php

namespace Tests\Feature;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountSchoolAllocation;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\School;
use App\Models\User;
use App\Services\CentralFinanceFundAccountAdministrationService;
use App\Services\CentralFinanceFundAccountAllocationCleanupService;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceFundAccountSchoolAvailabilityService;
use App\Services\CentralFinanceFundAccountV2ConversionService;
use App\Services\CentralFinanceLedgerService;
use App\Services\CentralFinancePreGoLiveResetService;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

final class CentralFundAccountV2Test extends TestCase
{
    private string $database;
    private CentralFinanceUser $head;
    private FinanceGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'fund_account_v2_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('code'); $table->string('status')->default('active');
            $table->boolean('installed')->default(true); $table->softDeletes(); $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id')->nullable();
            $table->string('central_finance_principal_type', 40)->default('central_user');
            $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->string('email')->nullable();
            $table->softDeletes(); $table->timestamps();
        });
        Schema::connection('mysql')->create('roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('guard_name'); $table->timestamps();
        });
        Schema::connection('mysql')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id');
        });
        Schema::connection('mysql')->create('migrations', function (Blueprint $table): void {
            $table->id(); $table->string('migration'); $table->unsignedInteger('batch');
        });

        foreach ([
            '2026_08_18_000001_create_finance_group_scope_tables.php',
            '2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php',
            '2026_08_26_000002_add_master_data_to_central_finance_fund_accounts.php',
            '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php',
            '2026_08_21_000002_create_central_finance_operating_documents.php',
            '2026_08_21_000003_create_central_finance_internal_transfer_documents.php',
            '2026_08_21_000006_create_central_finance_opening_balance_audits.php',
            '2026_08_24_000002_create_central_finance_school_staff_identities.php',
            '2026_09_03_000001_create_central_finance_fund_account_school_allocations.php',
            '2026_09_17_000001_add_group_context_to_central_finance_fund_account_audits.php',
            '2026_09_18_000002_add_owner_holder_to_central_finance_fund_accounts.php',
            '2026_09_18_000002_create_central_finance_pre_go_live_reset_manifests.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        Schema::connection('mysql')->create('payment_transactions', function (Blueprint $table): void {
            $table->id(); $table->string('reference')->unique(); $table->timestamps();
        });
        Schema::connection('mysql')->create('subscriptions', function (Blueprint $table): void {
            $table->id(); $table->string('reference')->unique(); $table->timestamps();
        });
        Schema::connection('mysql')->create('subscription_bills', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('payment_transaction_id')->nullable(); $table->timestamps();
        });
        Schema::connection('mysql')->create('central_finance_group_import_batches', function (Blueprint $table): void {
            $table->id(); $table->timestamps();
        });
        Schema::connection('mysql')->create('central_finance_import_batches', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('group_import_batch_id')->nullable(); $table->timestamps();
            $table->foreign('group_import_batch_id')->references('id')->on('central_finance_group_import_batches')->restrictOnDelete();
        });

        DB::connection('mysql')->table('schools')->insert(collect(range(1, 4))->map(fn (int $id): array => [
            'id' => $id, 'name' => 'School '.$id, 'code' => 'MMBOWEN0'.$id,
            'status' => 'active', 'installed' => true, 'created_at' => now(), 'updated_at' => now(),
        ])->all());
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'school_id' => null, 'central_finance_principal_type' => 'central_user', 'first_name' => 'Head', 'last_name' => 'Finance', 'email' => 'head@example.test', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 201, 'school_id' => 1, 'central_finance_principal_type' => 'school_staff_identity', 'first_name' => 'School', 'last_name' => 'Principal', 'email' => 'principal@example.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('roles')->insert(['id' => 1, 'name' => 'Head Finance', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => 100]);

        $this->group = FinanceGroup::on('mysql')->create([
            'name' => 'Bowen Group', 'code' => 'BOWEN', 'status' => 'active', 'reporting_currency' => 'MMK', 'fiscal_year_start_month' => 1,
        ]);
        foreach (range(1, 4) as $schoolId) {
            DB::connection('mysql')->table('finance_group_schools')->insert([
                'group_id' => $this->group->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::connection('mysql')->table('central_finance_user_school_scopes')->insert([
                'user_id' => 100, 'school_id' => $schoolId, 'can_view' => true, 'can_operate' => true,
                'can_approve_reimbursements' => false, 'can_confirm_funding' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $headGroupUser = DB::connection('mysql')->table('finance_group_users')->insertGetId([
            'group_id' => $this->group->id, 'central_user_id' => 100, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['view_reports', 'operate_finance', 'manage_hq_accounts'] as $capability) {
            DB::connection('mysql')->table('finance_group_user_scopes')->insert([
                'group_user_id' => $headGroupUser, 'scope_type' => 'GROUP', 'scope_key' => 'group',
                'capability' => $capability, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $principalGroupUser = DB::connection('mysql')->table('finance_group_users')->insertGetId([
            'group_id' => $this->group->id, 'central_user_id' => 201, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('finance_group_user_scopes')->insert([
            'group_user_id' => $principalGroupUser, 'school_id' => 1, 'scope_type' => 'SCHOOL', 'scope_key' => 'school:1',
            'capability' => 'view_reports', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('central_finance_user_school_scopes')->insert([
            'user_id' => 201, 'school_id' => 1, 'can_view' => true, 'can_operate' => false,
            'can_approve_reimbursements' => false, 'can_confirm_funding' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('central_finance_school_staff_identities')->insert([
            'identity_uuid' => (string) Str::uuid(), 'school_id' => 1, 'tenant_user_uuid' => (string) Str::uuid(),
            'central_user_id' => 201, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->head = CentralFinanceUser::on('mysql')->findOrFail(100);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_owner_holder_is_editable_audited_metadata_not_scope_or_balance(): void
    {
        $admin = app(CentralFinanceFundAccountAdministrationService::class);
        $account = $admin->createGroupAccount($this->head, $this->group->id, [
            'account_code' => 'HOLDER-TEST', 'account_name' => 'Central Bank', 'currency' => 'MMK',
            'opening_balance' => 123, 'opening_balance_date' => '2026-09-18', 'opening_reason' => 'QA opening',
            'account_type' => 'bank', 'owner_holder' => ' BOWEN EDUCATION COMPANY LIMITED ',
        ]);
        $this->assertSame('BOWEN EDUCATION COMPANY LIMITED', $account->owner_holder);
        $admin->updateMasterData($this->head, null, $account, [
            'account_name' => 'Central Bank', 'account_type' => 'bank',
            'owner_holder' => 'Updated legal holder', 'reason' => 'Verified legal account name',
        ]);
        $account->refresh();
        $this->assertSame('Updated legal holder', $account->owner_holder);
        $this->assertSame('hq', $account->owner_type);
        $this->assertNull($account->school_id);
        $this->assertFalse(app(CentralFinanceFundAccountSchoolAvailabilityService::class)->isAccountAvailableForSchool($account, 1));
        $this->assertSame(123.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($account));
        $this->assertSame(0, CentralFinanceLedgerEntry::on('mysql')->count());
        $audit = \App\Models\CentralFinanceDocumentAudit::on('mysql')->where('document_id', $account->id)->where('action', 'master_data_updated')->sole();
        $this->assertSame('BOWEN EDUCATION COMPANY LIMITED', $audit->before_values['owner_holder']);
        $this->assertSame('Updated legal holder', $audit->after_values['owner_holder']);
        $this->assertSame('Verified legal account name', $audit->reason);
        $admin->updateMasterData($this->head, null, $account, [
            'account_name' => 'Central Bank', 'account_type' => 'bank', 'reason' => 'Unrelated metadata update',
        ]);
        $this->assertSame('Updated legal holder', $account->fresh()->owner_holder);
        try {
            $admin->updateMasterData($this->head, null, $account, [
                'account_name' => 'Central Bank', 'account_type' => 'bank',
                'owner_holder' => str_repeat('x', 192), 'reason' => 'Invalid holder',
            ]);
            $this->fail('Overlength holder must be rejected server-side.');
        } catch (ValidationException $expected) {
            $this->assertArrayHasKey('owner_holder', $expected->errors());
            $this->assertSame('Updated legal holder', $account->fresh()->owner_holder);
        }
        (require database_path('migrations/2026_09_18_000002_add_owner_holder_to_central_finance_fund_accounts.php'))->up();
        $this->assertSame('Updated legal holder', $account->fresh()->owner_holder);
    }

    public function test_group_account_requires_allocations_and_exposes_one_physical_balance_with_school_activity_isolation(): void
    {
        $admin = app(CentralFinanceFundAccountAdministrationService::class);
        $account = $admin->createGroupAccount($this->head, $this->group->id, [
            'account_code' => 'CENTRAL-MMK', 'account_name' => 'Bowen Central MMK', 'currency' => 'MMK',
            'opening_balance' => 100, 'opening_balance_date' => '2026-09-17', 'opening_reason' => 'Reviewed central opening',
            'account_type' => CentralFinanceFundAccount::TYPE_BANK,
        ]);
        $this->assertSame(CentralFinanceFundAccount::OWNER_HQ, $account->owner_type);
        $this->assertNull($account->school_id);

        $availability = app(CentralFinanceFundAccountSchoolAvailabilityService::class);
        $this->assertFalse($availability->isAccountAvailableForSchool($account, 1));
        $admin->syncSchoolAllocations($this->head, null, $account, collect(range(1, 3))->map(fn (int $schoolId): array => [
            'school_id' => $schoolId, 'opening_allocation_amount' => 0, 'is_active' => true,
        ])->all(), 'Authorize three Bowen Schools');

        foreach (range(1, 3) as $schoolId) {
            $this->assertTrue($availability->isAccountAvailableForSchool($account, $schoolId));
        }
        $this->assertFalse($availability->isAccountAvailableForSchool($account, 4));

        $this->assertTrue(app(\App\Services\CentralFinanceFundAccountScopeService::class)->canOperate($this->head, $account, 3));

        app(CentralFinanceLedgerService::class)->recordOperatingIncome(
            $this->head, $account, 3, 'other_income', 'TIMECITY-500', 500,
            CarbonImmutable::parse('2026-09-17 10:00:00', 'Asia/Yangon'), 'TIMECITY-500'
        );
        $balances = app(CentralFinanceFundAccountBalanceService::class);
        foreach (range(1, 3) as $schoolId) {
            $this->assertSame(600.0, $balances->currentBalance($account));
        }
        $this->assertSame(500.0, $balances->schoolActivity($account, 3)['net_movement']);
        $this->assertSame(0.0, $balances->schoolActivity($account, 1)['net_movement']);
        $this->assertSame(0.0, $balances->schoolActivity($account, 2)['net_movement']);
        $this->assertDatabaseHas('central_finance_ledger_entries', ['fund_account_id' => $account->id, 'school_id' => 3, 'money_in' => 500], 'mysql');

        try {
            $admin->syncSchoolAllocations($this->head, null, $account, [[
                'school_id' => 3, 'opening_allocation_amount' => 0, 'is_active' => false,
            ]], 'Attempt to revoke historical School activity');
            $this->fail('An allocation with School Ledger history must require formal reallocation.');
        } catch (ValidationException) {
            $this->assertTrue($availability->isAccountAvailableForSchool($account, 3));
        }

        try {
            app(CentralFinanceLedgerService::class)->recordOperatingIncome(
                $this->head, $account, 4, 'other_income', 'UNALLOCATED', 1,
                CarbonImmutable::parse('2026-09-17 11:00:00', 'Asia/Yangon')
            );
            $this->fail('An unallocated School must not use a Central Fund Account.');
        } catch (InvalidArgumentException|AuthorizationException) {
            $this->assertSame(1, CentralFinanceLedgerEntry::on('mysql')->count());
        }
    }

    public function test_principal_sees_allocated_physical_account_read_only_and_only_own_school_activity(): void
    {
        $account = $this->centralAccount('PRINCIPAL-MMK');
        $this->allocate($account, 1);
        $this->allocate($account, 2);
        $this->ledger($account, 1, 'SCHOOL-1', 25);
        $this->ledger($account, 2, 'SCHOOL-2', 75);

        $principal = CentralFinanceUser::on('mysql')->findOrFail(201);
        $workspace = app(CentralFinanceWorkspaceService::class);
        $this->assertSame([$account->id], $workspace->readableAccounts($principal, 1)->pluck('id')->all());
        $this->assertSame([], $workspace->accessibleAccounts($principal, 1)->pluck('id')->all());
        $this->assertSame(200.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($account));
        $this->assertSame(25.0, app(CentralFinanceFundAccountBalanceService::class)->schoolActivity($account, 1)['net_movement']);

        $this->expectException(AuthorizationException::class);
        app(CentralFinanceLedgerService::class)->recordOperatingIncome(
            $principal, $account, 1, 'other_income', 'PRINCIPAL-WRITE', 1,
            CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Yangon')
        );
    }

    public function test_school_accountant_uses_only_allocated_accounts_in_its_own_school_without_legacy_user_pivot(): void
    {
        DB::connection('mysql')->table('users')->insert([
            'id' => 202, 'school_id' => 1, 'central_finance_principal_type' => 'school_staff_identity',
            'first_name' => 'Bahan', 'last_name' => 'Accountant', 'email' => 'bahan.accountant@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('central_finance_school_staff_identities')->insert([
            'identity_uuid' => (string) Str::uuid(), 'school_id' => 1, 'tenant_user_uuid' => (string) Str::uuid(),
            'central_user_id' => 202, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('central_finance_user_school_scopes')->insert([
            'user_id' => 202, 'school_id' => 1, 'can_view' => true, 'can_operate' => true,
            'can_approve_reimbursements' => false, 'can_confirm_funding' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupUserId = DB::connection('mysql')->table('finance_group_users')->insertGetId([
            'group_id' => $this->group->id, 'central_user_id' => 202, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['view_reports', 'operate_finance'] as $capability) {
            DB::connection('mysql')->table('finance_group_user_scopes')->insert([
                'group_user_id' => $groupUserId, 'school_id' => 1, 'scope_type' => 'SCHOOL',
                'scope_key' => 'school:1', 'capability' => $capability, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $shared = $this->centralAccount('BAHAN-SHARED');
        $this->allocate($shared, 1);
        $this->allocate($shared, 2);
        $unallocated = $this->centralAccount('BAHAN-DENIED');
        $this->allocate($unallocated, 2);
        $accountant = CentralFinanceUser::on('mysql')->findOrFail(202);
        $scope = app(\App\Services\CentralFinanceFundAccountScopeService::class);

        $this->assertSame(0, DB::connection('mysql')->table('central_finance_fund_account_users')->where('user_id', 202)->count());
        $this->assertTrue($scope->canOperate($accountant, $shared, 1));
        $this->assertFalse($scope->canOperate($accountant, $shared, 2));
        $this->assertFalse($scope->canOperate($accountant, $unallocated, 1));
        $this->assertTrue($scope->canOperate($this->head, $shared, 2));

        DB::connection('mysql')->table('users')->insert([
            'id' => 203, 'school_id' => 1, 'central_finance_principal_type' => 'central_user',
            'first_name' => 'School', 'last_name' => 'Admin', 'email' => 'admin@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('central_finance_fund_account_users')->insert([
            'fund_account_id' => $shared->id, 'user_id' => 203, 'can_view' => true, 'can_operate' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('central_finance_user_school_scopes')->insert([
            'user_id' => 203, 'school_id' => 1, 'can_view' => true, 'can_operate' => true,
            'can_approve_reimbursements' => false, 'can_confirm_funding' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $schoolAdminGroupUserId = DB::connection('mysql')->table('finance_group_users')->insertGetId([
            'group_id' => $this->group->id, 'central_user_id' => 203, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('finance_group_user_scopes')->insert([
            'group_user_id' => $schoolAdminGroupUserId, 'school_id' => 1, 'scope_type' => 'SCHOOL',
            'scope_key' => 'school:1', 'capability' => 'operate_finance', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $schoolAdmin = CentralFinanceUser::on('mysql')->findOrFail(203);
        $this->assertFalse($scope->canView($schoolAdmin, $shared, 1));
        $this->assertFalse($scope->canOperate($schoolAdmin, $shared, 1));
    }

    public function test_reviewed_b_and_m_account_conversion_is_idempotent_auditable_and_financially_immutable(): void
    {
        $b = $this->legacyAccount('B-0001', 1, 75);
        $m = $this->legacyAccount('M-0001', 3, 25);
        $this->allocate($b, 1, 75);
        $this->allocate($b, 2);
        $this->allocate($m, 3, 25);
        $this->ledger($b, 1, 'B-HISTORY', 10);
        $beforeLedger = DB::connection('mysql')->table('central_finance_ledger_entries')->get()->toArray();

        $conversion = app(CentralFinanceFundAccountV2ConversionService::class);
        $before = $conversion->preflight();
        $after = $conversion->execute($this->head, 'Approved Fund Account V2 ownership conversion');

        foreach (['B-0001', 'M-0001'] as $code) {
            $this->assertSame('already_converted', $after[$code]['status']);
            $this->assertSame($before[$code]['account_id'], $after[$code]['account_id']);
            $this->assertSame($before[$code]['opening_balance'], $after[$code]['opening_balance']);
            $this->assertSame($before[$code]['ledger_checksum'], $after[$code]['ledger_checksum']);
            $account = CentralFinanceFundAccount::on('mysql')->where('account_code', $code)->sole();
            $this->assertSame(CentralFinanceFundAccount::OWNER_HQ, $account->owner_type);
            $this->assertNull($account->school_id);
        }
        $this->assertEquals($beforeLedger, DB::connection('mysql')->table('central_finance_ledger_entries')->get()->toArray());
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_document_audits')->where('action', 'group_ownership_migrated')->count());

        $conversion->execute($this->head, 'Idempotency verification');
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_document_audits')->where('action', 'group_ownership_migrated')->count());
    }

    public function test_v2_1_cleanup_clears_only_legacy_amounts_and_preserves_financial_and_access_state(): void
    {
        $account = $this->centralAccount('V21-CENTRAL');
        $this->allocate($account, 2);
        DB::connection('mysql')->table('central_finance_fund_account_school_allocations')
            ->where(['fund_account_id' => $account->id, 'school_id' => 2])
            ->update(['opening_allocation_amount' => 496316250.6800]);
        $this->ledger($account, 2, 'V21-LEDGER', 500);

        $cleanup = app(CentralFinanceFundAccountAllocationCleanupService::class);
        $before = $cleanup->preflight();
        $this->assertSame('eligible', $before['status']);
        $this->assertSame(1, $before['legacy_amount_rows']);

        $after = $cleanup->execute($this->head, 'Approved removal of test-only legacy allocation amounts');
        $this->assertSame('complete', $after['status']);
        $this->assertSame(1, $after['rows_changed']);
        $this->assertSame($before['ledger_checksum'], $after['ledger_checksum']);
        $this->assertSame($before['account_opening_checksum'], $after['account_opening_checksum']);
        $this->assertSame($before['allocation_access_checksum'], $after['allocation_access_checksum']);
        $this->assertDatabaseHas('central_finance_fund_account_school_allocations', [
            'fund_account_id' => $account->id, 'school_id' => 2,
            'opening_allocation_amount' => 0, 'is_active' => 1, 'status' => 'active',
        ], 'mysql');
        $this->assertDatabaseHas('central_finance_document_audits', [
            'document_type' => 'fund_account', 'document_id' => $account->id,
            'action' => 'legacy_school_allocation_amounts_cleared',
        ], 'mysql');

        $repeat = $cleanup->execute($this->head, 'Idempotency verification');
        $this->assertSame('complete', $repeat['status']);
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_document_audits')
            ->where('action', 'legacy_school_allocation_amounts_cleared')->count());
    }

    public function test_school_allocation_service_ignores_forged_legacy_amount_and_grants_access_only(): void
    {
        $account = $this->centralAccount('ACCESS-ONLY');
        app(CentralFinanceFundAccountAdministrationService::class)->syncSchoolAllocations(
            $this->head,
            null,
            $account,
            [['school_id' => 2, 'is_active' => true, 'opening_allocation_amount' => 999999999]],
            'Grant access without monetary allocation'
        );

        $this->assertDatabaseHas('central_finance_fund_account_school_allocations', [
            'fund_account_id' => $account->id, 'school_id' => 2,
            'opening_allocation_amount' => 0, 'is_active' => 1,
        ], 'mysql');
        $this->assertSame(100.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($account));
        $this->assertSame(0.0, app(CentralFinanceFundAccountBalanceService::class)->schoolActivity($account, 2)['net_movement']);
    }

    public function test_fund_account_lifecycle_is_authorized_audited_and_exactly_once_on_replay(): void
    {
        $account = $this->centralAccount('LIFECYCLE-TEST');
        $account->update(['opening_balance' => 0]);
        $admin = app(CentralFinanceFundAccountAdministrationService::class);

        $admin->changeStatus($this->head, null, $account, CentralFinanceFundAccount::STATUS_INACTIVE, 'Temporarily suspend QA account');
        $admin->changeStatus($this->head, null, $account, CentralFinanceFundAccount::STATUS_INACTIVE, 'Replayed submit must be a no-op');
        $this->assertSame(CentralFinanceFundAccount::STATUS_INACTIVE, $account->fresh()->status);
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_document_audits')->where('document_id', $account->id)->where('action', 'lifecycle_inactive')->count());

        $admin->changeStatus($this->head, null, $account, CentralFinanceFundAccount::STATUS_ACTIVE, 'Reactivate reviewed QA account');
        $admin->changeStatus($this->head, null, $account, CentralFinanceFundAccount::STATUS_ARCHIVED, 'Archive zero-balance QA account');
        $this->assertSame(CentralFinanceFundAccount::STATUS_ARCHIVED, $account->fresh()->status);
        $this->assertSame(3, DB::connection('mysql')->table('central_finance_document_audits')->where('document_id', $account->id)->whereIn('action', ['lifecycle_inactive', 'lifecycle_active', 'lifecycle_archived'])->count());
        $this->assertSame(0, CentralFinanceLedgerEntry::on('mysql')->where('fund_account_id', $account->id)->count());
    }

    public function test_physical_currency_summary_counts_a_shared_account_once_not_per_school_allocation(): void
    {
        $account = $this->centralAccount('PHYSICAL-SUMMARY');
        $this->allocate($account, 1);
        $this->allocate($account, 2);
        $this->ledger($account, 1, 'physical-1', 25);

        $summary = app(CentralFinanceFundAccountBalanceService::class)
            ->physicalSummaryByCurrency(collect([$account, $account]));

        $this->assertSame(100.0, $summary['MMK']['opening_balance']);
        $this->assertSame(25.0, $summary['MMK']['money_in']);
        $this->assertSame(0.0, $summary['MMK']['money_out']);
        $this->assertSame(125.0, $summary['MMK']['closing_balance']);
    }

    public function test_pre_go_live_reset_deletes_only_reviewed_central_finance_data_and_preserves_system_billing(): void
    {
        $account = $this->centralAccount('RESET-ACCOUNT');
        $this->allocate($account, 1);
        $this->ledger($account, 1, 'reset-ledger', 30);
        DB::connection('mysql')->table('central_finance_categories')->insert([
            'category_uuid' => (string) Str::uuid(), 'school_id' => 1, 'type' => 'income', 'name' => 'Reset test category',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('payment_transactions')->insert(['id' => 700, 'reference' => 'SYSTEM-BILLING-PAYMENT', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('subscriptions')->insert(['id' => 701, 'reference' => 'SYSTEM-SUBSCRIPTION', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('subscription_bills')->insert(['id' => 702, 'payment_transaction_id' => 700, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('central_finance_group_import_batches')->insert(['id' => 801, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('central_finance_import_batches')->insert(['id' => 802, 'group_import_batch_id' => 801, 'created_at' => now(), 'updated_at' => now()]);

        $reset = app(CentralFinancePreGoLiveResetService::class);
        $preflight = $reset->preflight();
        $this->assertSame(1, $preflight['business_allowlist']['central_finance_fund_accounts']);
        $result = $reset->execute($this->head, 'TEST-PRE-GO-LIVE-RESET', 'Approved isolated reset test');

        $this->assertSame(0, CentralFinanceFundAccount::on('mysql')->count());
        $this->assertSame(0, CentralFinanceLedgerEntry::on('mysql')->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_categories')->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_import_batches')->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_group_import_batches')->count());
        $this->assertSame(1, DB::connection('mysql')->table('subscriptions')->count());
        $this->assertSame(1, DB::connection('mysql')->table('subscription_bills')->count());
        $this->assertSame(1, DB::connection('mysql')->table('payment_transactions')->count());
        $this->assertSame($preflight['protected_hash'], $result['protected_hash']);
        $this->assertDatabaseHas('central_finance_pre_go_live_reset_manifests', [
            'approval_reference' => 'TEST-PRE-GO-LIVE-RESET', 'actor_id' => $this->head->id,
        ], 'mysql');
    }

    private function centralAccount(string $code): CentralFinanceFundAccount
    {
        return CentralFinanceFundAccount::on('mysql')->create([
            'account_uuid' => (string) Str::uuid(), 'group_id' => $this->group->id, 'school_id' => null,
            'owner_type' => CentralFinanceFundAccount::OWNER_HQ, 'account_code' => $code, 'account_name' => $code,
            'currency' => 'MMK', 'opening_balance' => 100, 'is_active' => true,
            'account_type' => CentralFinanceFundAccount::TYPE_BANK, 'status' => CentralFinanceFundAccount::STATUS_ACTIVE,
        ]);
    }

    private function legacyAccount(string $code, int $schoolId, float $opening): CentralFinanceFundAccount
    {
        return CentralFinanceFundAccount::on('mysql')->create([
            'account_uuid' => (string) Str::uuid(), 'group_id' => $this->group->id, 'school_id' => $schoolId,
            'owner_type' => CentralFinanceFundAccount::OWNER_SCHOOL, 'account_code' => $code, 'account_name' => $code,
            'currency' => 'MMK', 'opening_balance' => $opening, 'is_active' => true,
            'account_type' => CentralFinanceFundAccount::TYPE_BANK, 'status' => CentralFinanceFundAccount::STATUS_ACTIVE,
        ]);
    }

    private function allocate(CentralFinanceFundAccount $account, int $schoolId, float $opening = 0): void
    {
        CentralFinanceFundAccountSchoolAllocation::on('mysql')->create([
            'fund_account_id' => $account->id, 'school_id' => $schoolId,
            'opening_allocation_amount' => $opening, 'effective_from' => '2026-09-17',
            'status' => 'active', 'is_active' => true, 'assigned_by' => $this->head->id,
            'assignment_reason' => 'Explicit test allocation',
        ]);
    }

    private function ledger(CentralFinanceFundAccount $account, int $schoolId, string $sourceId, float $amount): void
    {
        CentralFinanceLedgerEntry::on('mysql')->create([
            'entry_uuid' => (string) Str::uuid(), 'school_id' => $schoolId, 'fund_account_id' => $account->id,
            'entry_date' => '2026-09-17', 'occurred_at' => now(), 'source_type' => 'v2_test',
            'source_id' => $sourceId, 'source_line' => 'main', 'reference_no' => $sourceId,
            'transaction_type' => CentralFinanceLedgerEntry::TYPE_OPERATING_INCOME, 'currency' => 'MMK',
            'money_in' => $amount, 'money_out' => 0, 'operating_income' => $amount, 'operating_expense' => 0,
            'created_by' => $this->head->id,
        ]);
    }
}

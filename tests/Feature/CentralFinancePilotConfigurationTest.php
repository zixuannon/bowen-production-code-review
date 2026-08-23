<?php

namespace Tests\Feature;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountOpeningBalanceAudit;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\School;
use App\Services\CentralFinanceFundAccountAdministrationService;
use App\Services\CentralFinanceSchoolCutoverService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class CentralFinancePilotConfigurationTest extends TestCase
{
    private string $database;
    private CentralFinanceUser $headFinance;
    private CentralFinanceUser $accountant;
    private School $zixuan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_pilot_config_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('code')->nullable(); $table->string('status')->default('active'); $table->softDeletes(); $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id')->nullable(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->string('email')->nullable(); $table->softDeletes(); $table->timestamps();
        });
        Schema::connection('mysql')->create('roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('guard_name'); $table->unsignedBigInteger('school_id')->nullable(); $table->timestamps();
        });
        Schema::connection('mysql')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id');
        });
        foreach ([
            '2026_08_18_000001_create_finance_group_scope_tables.php',
            '2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php',
            '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php',
            '2026_08_21_000002_create_central_finance_operating_documents.php',
            '2026_08_21_000003_create_central_finance_internal_transfer_documents.php',
            '2026_08_21_000005_create_central_finance_school_cutovers.php',
            '2026_08_21_000006_create_central_finance_opening_balance_audits.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::connection('mysql')->table('schools')->insert(['id' => 1, 'name' => 'Zixuan QA', 'code' => 'SCH202615', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'first_name' => 'Head', 'last_name' => 'Finance', 'email' => 'head@example.test', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 200, 'first_name' => 'School', 'last_name' => 'Accountant', 'email' => 'accountant@example.test', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 300, 'first_name' => 'Unscoped', 'last_name' => 'User', 'email' => 'unscoped@example.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('roles')->insert([
            ['id' => 1, 'name' => 'Head Finance', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Cashier', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => \App\Models\User::class, 'model_id' => 100],
            ['role_id' => 2, 'model_type' => \App\Models\User::class, 'model_id' => 200],
        ]);
        $group = FinanceGroup::on('mysql')->create(['name' => 'Bowen QA Group', 'code' => 'BOWEN_QA', 'status' => 'active', 'reporting_currency' => 'MMK', 'fiscal_year_start_month' => 1]);
        DB::connection('mysql')->table('finance_group_schools')->insert(['group_id' => $group->id, 'school_id' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->groupUser($group->id, 100, 1, 'GROUP', null, 'view_reports');
        $this->groupUser($group->id, 100, 1, 'GROUP', null, 'operate_finance');
        $this->groupUser($group->id, 100, 1, 'GROUP', null, 'manage_hq_accounts');
        $this->groupUser($group->id, 200, 2, 'SCHOOL', 1, 'view_reports');
        $this->groupUser($group->id, 200, 2, 'SCHOOL', 1, 'operate_finance');
        foreach ([[100, true], [200, true]] as [$userId, $canOperate]) {
            DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id' => $userId, 'school_id' => 1, 'can_view' => true, 'can_operate' => $canOperate, 'can_approve_reimbursements' => false, 'can_confirm_funding' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headFinance = CentralFinanceUser::on('mysql')->findOrFail(100);
        $this->accountant = CentralFinanceUser::on('mysql')->findOrFail(200);
        $this->zixuan = School::on('mysql')->findOrFail(1);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql'); @unlink($this->database); parent::tearDown();
    }

    public function test_ready_to_central_requires_signed_opening_and_assigned_head_finance_without_ledger_write(): void
    {
        $cutover = app(CentralFinanceSchoolCutoverService::class);
        $cutover->transition($this->headFinance, $this->zixuan, 'ready');
        try { $cutover->transition($this->headFinance, $this->zixuan, 'central'); $this->fail('Incomplete configuration must not cut over.'); } catch (LogicException) {}

        $account = app(CentralFinanceFundAccountAdministrationService::class)->createSchoolAccount($this->headFinance, $this->zixuan, [
            'account_code' => 'ZIX-CASH', 'account_name' => 'Zixuan Cash', 'currency' => 'MMK',
            'opening_balance' => 50000000, 'opening_balance_date' => '2026-08-21', 'opening_reason' => 'Signed Zixuan cutover balance sheet',
        ], [$this->accountant->id]);
        $this->assertSame(50000000.0, (float) $account->opening_balance);
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_fund_account_users')->where('fund_account_id', $account->id)->count());
        $this->assertSame(1, CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where(['fund_account_id' => $account->id, 'change_type' => 'initial'])->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());

        $this->assertSame('central', $cutover->transition($this->headFinance, $this->zixuan, 'central')->status);
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_only_head_finance_can_create_or_adjust_and_adjustments_are_audited_without_income_or_ledger(): void
    {
        $admin = app(CentralFinanceFundAccountAdministrationService::class);
        $account = $admin->createSchoolAccount($this->headFinance, $this->zixuan, [
            'account_code' => 'ZIX-BANK', 'account_name' => 'Zixuan Bank', 'currency' => 'MMK',
            'opening_balance' => 100, 'opening_balance_date' => '2026-08-21', 'opening_reason' => 'Signed opening',
        ], []);
        try {
            $admin->adjustOpeningBalance($this->accountant, $this->zixuan, $account, 5, '2026-08-22', 'Not permitted');
            $this->fail('School Accountant cannot adjust an opening balance.');
        } catch (AuthorizationException) {}
        $admin->adjustOpeningBalance($this->headFinance, $this->zixuan, $account, -10, '2026-08-22', 'Counted cash correction');
        $this->assertSame(90.0, (float) $account->fresh()->opening_balance);
        $this->assertSame(2, CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where('fund_account_id', $account->id)->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_head_finance_can_create_and_audit_an_hq_central_fund_account_for_the_authorized_group(): void
    {
        $admin = app(CentralFinanceFundAccountAdministrationService::class);
        $account = $admin->createHqAccount($this->headFinance, $this->zixuan, [
            'account_code' => 'HQ-MMK', 'account_name' => 'Bowen HQ MMK', 'currency' => 'MMK',
            'opening_balance' => 200, 'opening_balance_date' => '2026-08-21', 'opening_reason' => 'Signed HQ opening',
        ], [$this->accountant->id]);

        $this->assertSame(CentralFinanceFundAccount::OWNER_HQ, $account->owner_type);
        $this->assertNull($account->school_id);
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_fund_account_users')->where('fund_account_id', $account->id)->count());
        $this->assertSame(1, CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where(['fund_account_id' => $account->id, 'change_type' => 'initial'])->count());

        $admin->adjustOpeningBalance($this->headFinance, $this->zixuan, $account, 25, '2026-08-22', 'Signed HQ correction');
        $this->assertSame(225.0, (float) $account->fresh()->opening_balance);
        $this->assertSame(2, CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where('fund_account_id', $account->id)->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_fund_account_assignment_rejects_unscoped_central_users(): void
    {
        try {
            app(CentralFinanceFundAccountAdministrationService::class)->createSchoolAccount($this->headFinance, $this->zixuan, [
                'account_code' => 'ZIX-REJECT', 'account_name' => 'Zixuan Reject', 'currency' => 'MMK',
                'opening_balance' => 0, 'opening_balance_date' => '2026-08-21', 'opening_reason' => 'Signed zero balance',
            ], [300]);
            $this->fail('Unscoped central users cannot be assigned.');
        } catch (AuthorizationException) {}
        $this->assertSame(0, CentralFinanceFundAccount::on('mysql')->count());
        $this->assertSame(0, CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->count());
    }

    public function test_opening_balance_audit_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_08_21_000006_create_central_finance_opening_balance_audits.php');
        $migration->down();
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_fund_account_opening_balance_audits'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_fund_accounts'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('schools'));
        $migration->up();
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_fund_account_opening_balance_audits'));
    }

    private function groupUser(int $groupId, int $centralUserId, int $id, string $scopeType, ?int $schoolId, string $capability): void
    {
        DB::connection('mysql')->table('finance_group_users')->updateOrInsert(['id' => $id], ['group_id' => $groupId, 'central_user_id' => $centralUserId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id' => $id, 'school_id' => $schoolId, 'scope_type' => $scopeType, 'scope_key' => $scopeType === 'GROUP' ? 'group' : 'school:'.$schoolId, 'capability' => $capability, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
}

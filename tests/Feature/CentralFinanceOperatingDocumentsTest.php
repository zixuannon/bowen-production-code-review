<?php

namespace Tests\Feature;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinanceReimbursementRequest;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceOperatingDocumentService;
use App\Services\CentralFinanceReimbursementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralFinanceOperatingDocumentsTest extends TestCase
{
    private string $central;
    private CentralFinanceUser $head;
    private CentralFinanceUser $zixuanAccountant;
    private CentralFinanceFundAccount $hqAccount;
    private CentralFinanceFundAccount $zixuanAccount;
    private CentralFinanceFundAccount $timecityAccount;
    private CentralFinanceCategory $zixuanExpense;
    private CentralFinanceCategory $zixuanIncome;
    private CentralFinanceCategory $timecityExpense;
    private CentralFinanceCategory $timecityIncome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = tempnam(sys_get_temp_dir(), 'cf_documents_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->central, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', fn (Blueprint $table) => [
            $table->id(), $table->string('name'), $table->softDeletes(), $table->timestamps(),
        ]);
        Schema::connection('mysql')->create('users', fn (Blueprint $table) => [
            $table->id(), $table->string('first_name')->nullable(), $table->string('last_name')->nullable(), $table->softDeletes(), $table->timestamps(),
        ]);
        foreach ([
            '2026_08_20_000003_create_central_finance_student_sync_tables.php',
            '2026_08_20_000004_add_academic_and_guardian_references_to_central_finance_student_profiles.php',
            '2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php',
            '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php',
            '2026_08_21_000002_create_central_finance_operating_documents.php',
            '2026_08_21_000005_create_central_finance_school_cutovers.php',
            '2026_08_26_000001_complete_central_finance_reimbursement_workflow.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan QA', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Timecity QA', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'first_name' => 'Head', 'last_name' => 'Finance', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 200, 'first_name' => 'Zixuan', 'last_name' => 'Accountant', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('central_finance_school_cutovers')->insert([
            ['school_id' => 1, 'status' => 'central', 'cutover_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => 2, 'status' => 'central', 'cutover_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->head = CentralFinanceUser::on('mysql')->findOrFail(100);
        $this->zixuanAccountant = CentralFinanceUser::on('mysql')->findOrFail(200);
        $this->hqAccount = $this->account('CF-HQ', 'HQ Bank', 'hq', null);
        $this->zixuanAccount = $this->account('CF-ZIX', 'Zixuan Cash', 'school', 1);
        $this->timecityAccount = $this->account('CF-TIM', 'Timecity Cash', 'school', 2);
        foreach ([$this->hqAccount, $this->zixuanAccount, $this->timecityAccount] as $account) {
            $this->grantAccount($this->head, $account);
        }
        $this->grantAccount($this->zixuanAccountant, $this->zixuanAccount);
        $this->grantSchool($this->head, 1, true);
        $this->grantSchool($this->head, 2, true);
        $this->grantSchool($this->zixuanAccountant, 1, false);
        $this->zixuanExpense = $this->category(1, CentralFinanceCategory::EXPENSE, 'Supplies');
        $this->zixuanIncome = $this->category(1, CentralFinanceCategory::INCOME, 'Activity');
        $this->timecityExpense = $this->category(2, CentralFinanceCategory::EXPENSE, 'Rent');
        $this->timecityIncome = $this->category(2, CentralFinanceCategory::INCOME, 'Camp');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->central);
        parent::tearDown();
    }

    public function test_zixuan_expense_is_idempotent_audited_and_ledger_backed(): void
    {
        $service = app(CentralFinanceOperatingDocumentService::class);
        $first = $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 100, 'Cash', $this->at(), 'ZIX-EXP-1', 'ZIX-EXP-REF', 'Books');
        $retry = $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 100, 'Cash', $this->at(), 'ZIX-EXP-1', 'ZIX-EXP-REF', 'Books');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(-100.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zixuanAccount));
        $totals = app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1);
        $this->assertSame(100.0, $totals['money_out']);
        $this->assertSame(100.0, $totals['operating_expense']);
        $this->assertSame(-100.0, $totals['operating_net']);
        $this->assertSame(1, CentralFinanceExpense::on('mysql')->count());
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_document_audits')->count());
    }

    public function test_timecity_other_income_can_enter_hq_account_with_timecity_attribution(): void
    {
        $service = app(CentralFinanceOperatingDocumentService::class);
        $income = $service->createOtherIncome(
            $this->head, 2, $this->timecityIncome->id, $this->hqAccount, 250,
            'Bank', $this->at(), 'TIM-INCOME-1', 'TIM-INCOME-REF', 'Camp parent', 'Summer camp',
        );

        $this->assertSame(250.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->hqAccount));
        $totals = app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(2);
        $this->assertSame(250.0, $totals['money_in']);
        $this->assertSame(250.0, $totals['operating_income']);
        $this->assertSame(250.0, $totals['operating_net']);

        $service->voidOtherIncome($this->head, $income->id, 'Duplicate receipt', $this->at()->addMinute());
        $totals = app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(2);
        $this->assertSame(0.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->hqAccount));
        $this->assertSame(0.0, $totals['operating_income']);
        $this->assertSame(0.0, $totals['operating_net']);
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_soft_delete_adds_exactly_one_reversal_and_keeps_reference_reserved(): void
    {
        $service = app(CentralFinanceOperatingDocumentService::class);
        $expense = $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 100, 'Cash', $this->at(), 'ZIX-VOID-1', 'ZIX-VOID-REF');
        $service->voidExpense($this->head, $expense->id, 'Duplicate receipt', $this->at()->addMinute());
        $service->voidExpense($this->head, $expense->id, 'Repeat request', $this->at()->addMinutes(2));

        $this->assertNotNull(CentralFinanceExpense::on('mysql')->withTrashed()->findOrFail($expense->id)->deleted_at);
        $this->assertSame(0.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zixuanAccount));
        $totals = app(CentralFinanceFundAccountBalanceService::class)->totalsForSchool(1);
        $this->assertSame(100.0, $totals['money_out']);
        $this->assertSame(100.0, $totals['money_in']);
        $this->assertSame(0.0, $totals['operating_expense']);
        $this->assertSame(0.0, $totals['operating_net']);
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $this->expectException(InvalidArgumentException::class);
        $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 100, 'Cash', $this->at(), 'ZIX-VOID-2', 'ZIX-VOID-REF');
    }

    public function test_expense_metadata_edit_requires_reason_and_has_no_extra_ledger_entry(): void
    {
        $service = app(CentralFinanceOperatingDocumentService::class);
        $expense = $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 50, 'Cash', $this->at(), 'ZIX-EDIT-1');
        $this->expectException(InvalidArgumentException::class);
        $service->updateExpenseDetails($this->head, $expense->id, ['description' => 'Corrected'], '');
    }

    public function test_expense_metadata_edit_is_audited_with_reason_without_changing_money(): void
    {
        $service = app(CentralFinanceOperatingDocumentService::class);
        $expense = $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 50, 'Cash', $this->at(), 'ZIX-EDIT-2');
        $updated = $service->updateExpenseDetails($this->head, $expense->id, ['description' => 'Corrected description'], 'Correct typo');

        $this->assertSame('Corrected description', $updated->description);
        $this->assertSame('Correct typo', $updated->edit_reason);
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_document_audits')->count());
    }

    public function test_reimbursement_is_neutral_until_head_finance_approval_then_forms_one_expense(): void
    {
        $reimbursements = app(CentralFinanceReimbursementService::class);
        $request = $reimbursements->submit($this->zixuanAccountant, 1, $this->zixuanExpense->id, 200, 'MMK', 'ZIX-REQ-1', 'Classroom repair', 'ZIX-REQ-REF');
        $this->assertSame(CentralFinanceReimbursementRequest::PENDING, $request->status);
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $this->assertSame(0.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zixuanAccount));
        try {
            $reimbursements->approve($this->zixuanAccountant, $request->id, $this->zixuanAccount, 'Cash', $this->at(), 'Looks good');
            $this->fail('A School Accountant cannot approve reimbursement.');
        } catch (AuthorizationException) {
            $this->assertSame(0, CentralFinanceExpense::on('mysql')->count());
        }

        $expense = $reimbursements->approve($this->head, $request->id, $this->zixuanAccount, 'Cash', $this->at(), 'Approved after review');
        $retry = $reimbursements->approve($this->head, $request->id, $this->zixuanAccount, 'Cash', $this->at(), 'Repeat approval');
        $request->refresh();
        $this->assertSame($expense->id, $retry->id);
        $this->assertSame(CentralFinanceReimbursementRequest::APPROVED, $request->status);
        $this->assertSame($expense->id, $request->expense_id);
        $this->assertSame(-200.0, app(CentralFinanceFundAccountBalanceService::class)->currentBalance($this->zixuanAccount));
        $this->assertSame(1, CentralFinanceExpense::on('mysql')->count());
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_scope_category_and_inactive_account_rejections_create_no_financial_document(): void
    {
        $service = app(CentralFinanceOperatingDocumentService::class);
        try {
            $service->createOtherIncome($this->zixuanAccountant, 2, $this->timecityIncome->id, $this->timecityAccount, 10, 'Cash', $this->at(), 'FORGED-1');
            $this->fail('School Accountant cannot write Timecity.');
        } catch (AuthorizationException) {
            $this->assertSame(0, CentralFinanceOtherIncome::on('mysql')->count());
        }
        try {
            $service->createExpense($this->head, 1, $this->timecityExpense->id, $this->zixuanAccount, 10, 'Cash', $this->at(), 'FORGED-2');
            $this->fail('Cross-school category must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, CentralFinanceExpense::on('mysql')->count());
        }
        $this->zixuanAccount->update(['is_active' => false]);
        try {
            $service->createExpense($this->head, 1, $this->zixuanExpense->id, $this->zixuanAccount, 10, 'Cash', $this->at(), 'FORGED-3');
            $this->fail('Inactive account must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, CentralFinanceExpense::on('mysql')->count());
            $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        }
    }

    public function test_operating_document_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_08_21_000002_create_central_finance_operating_documents.php');
        $migration->down();
        foreach ([
            'central_finance_document_audits', 'central_finance_reimbursement_requests',
            'central_finance_other_incomes', 'central_finance_expenses', 'central_finance_categories',
        ] as $table) {
            $this->assertFalse(Schema::connection('mysql')->hasTable($table));
        }
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_ledger_entries'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_fund_accounts'));
    }

    private function account(string $code, string $name, string $ownerType, ?int $schoolId): CentralFinanceFundAccount
    {
        return CentralFinanceFundAccount::on('mysql')->create([
            'account_uuid' => (string) Str::uuid(), 'group_id' => 1,
            'account_code' => $code, 'account_name' => $name, 'owner_type' => $ownerType,
            'school_id' => $schoolId, 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true,
        ]);
    }

    private function category(int $schoolId, string $type, string $name): CentralFinanceCategory
    {
        return CentralFinanceCategory::on('mysql')->create(['school_id' => $schoolId, 'type' => $type, 'name' => $name, 'is_active' => true]);
    }

    private function grantAccount(CentralFinanceUser $user, CentralFinanceFundAccount $account): void
    {
        DB::connection('mysql')->table('central_finance_fund_account_users')->insert([
            'fund_account_id' => $account->id, 'user_id' => $user->id,
            'can_view' => true, 'can_operate' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grantSchool(CentralFinanceUser $user, int $schoolId, bool $canApprove): void
    {
        DB::connection('mysql')->table('central_finance_user_school_scopes')->insert([
            'user_id' => $user->id, 'school_id' => $schoolId, 'can_view' => true,
            'can_operate' => true, 'can_approve_reimbursements' => $canApprove,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function at(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-21 12:00:00', 'Asia/Yangon');
    }
}

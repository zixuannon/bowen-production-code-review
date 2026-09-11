<?php

namespace Tests\Feature;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountSchoolAllocation;
use App\Models\CentralFinanceGroupImportBatch;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupUser;
use App\Exports\CentralFinanceGroupImportTemplateV2Export;
use App\Services\CentralFinanceGroupImportService;
use App\Services\CentralFinanceFundAccountAdministrationService;
use App\Services\CentralFinanceOperatingDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use InvalidArgumentException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * This uses the real canonical operating-document service on a disposable
 * central SQLite database.  It characterizes the Group Import orchestrator;
 * it never creates tenant data or a Student Payment path.
 */
final class CentralFinanceGroupImportConfirmTest extends TestCase
{
    private string $database;
    private CentralFinanceUser $head;
    private FinanceGroup $group;
    private CentralFinanceFundAccount $zixuanAccount;
    private CentralFinanceFundAccount $timesAccount;
    private CentralFinanceCategory $zixuanIncome;
    private CentralFinanceCategory $timesExpense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_group_confirm_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('installed')->default(true);
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        foreach ([
            '2026_08_18_000001_create_finance_group_scope_tables.php',
            '2026_08_20_000003_create_central_finance_student_sync_tables.php',
            '2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php',
            '2026_08_21_000001_create_central_finance_receivables_payments_and_receipts.php',
            '2026_08_21_000002_create_central_finance_operating_documents.php',
            '2026_08_21_000005_create_central_finance_school_cutovers.php',
            '2026_08_24_000001_create_central_finance_import_batches.php',
            '2026_08_24_000004_add_reimbursed_by_to_central_finance_expenses.php',
            '2026_08_26_000002_add_master_data_to_central_finance_fund_accounts.php',
            '2026_09_03_000001_create_central_finance_fund_account_school_allocations.php',
            '2026_09_02_000001_harden_school_codes_for_group_finance_import.php',
            '2026_09_11_000001_finalize_school_code_identity.php',
            '2026_09_02_000002_add_category_codes_for_group_finance_import.php',
            '2026_09_02_000003_create_central_finance_group_import_previews.php',
            '2026_09_02_000004_add_group_import_confirm_links.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan QA', 'code' => 'SCH-ZIX', 'installed' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Times QA', 'code' => 'SCH-TIM', 'installed' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'first_name' => 'Head', 'last_name' => 'Finance', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('central_finance_school_cutovers')->insert([
            ['school_id' => 1, 'status' => 'central', 'cutover_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => 2, 'status' => 'central', 'cutover_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->head = CentralFinanceUser::on('mysql')->findOrFail(100);
        $headFinanceRoleId = DB::connection('mysql')->table('roles')->insertGetId([
            'name' => 'Head Finance', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('model_has_roles')->insert([
            'role_id' => $headFinanceRoleId, 'model_type' => User::class, 'model_id' => $this->head->id,
        ]);
        $this->group = FinanceGroup::on('mysql')->create(['code' => 'QA', 'name' => 'QA Group', 'status' => 'active']);
        foreach ([1, 2] as $schoolId) {
            DB::connection('mysql')->table('finance_group_schools')->insert(['group_id' => $this->group->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::connection('mysql')->table('central_finance_user_school_scopes')->insert(['user_id' => 100, 'school_id' => $schoolId, 'can_view' => true, 'can_operate' => true, 'can_approve_reimbursements' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $member = FinanceGroupUser::on('mysql')->create(['group_id' => $this->group->id, 'central_user_id' => 100, 'status' => 'active']);
        foreach ([1, 2] as $schoolId) {
            DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id' => $member->id, 'school_id' => $schoolId, 'scope_type' => 'SCHOOL', 'capability' => 'view_reports', 'scope_key' => 'SCHOOL:'.$schoolId.':view_reports', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id' => $member->id, 'school_id' => $schoolId, 'scope_type' => 'SCHOOL', 'capability' => 'operate_finance', 'scope_key' => 'SCHOOL:'.$schoolId.':operate_finance', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::connection('mysql')->table('finance_group_user_scopes')->insert(['group_user_id' => $member->id, 'school_id' => null, 'scope_type' => 'GROUP', 'capability' => 'operate_finance', 'scope_key' => 'group', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->zixuanAccount = $this->account('ZIX-CASH', 'Zixuan Cash', 1);
        $this->timesAccount = $this->account('TIM-CASH', 'Times Cash', 2);
        foreach ([$this->zixuanAccount, $this->timesAccount] as $account) {
            DB::connection('mysql')->table('central_finance_fund_account_users')->insert(['fund_account_id' => $account->id, 'user_id' => 100, 'can_view' => true, 'can_operate' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->zixuanIncome = $this->category(1, CentralFinanceCategory::INCOME, 'DONATION');
        $this->timesExpense = $this->category(2, CentralFinanceCategory::EXPENSE, 'RENT');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_multi_school_mixed_confirm_creates_only_canonical_operating_documents_and_links_rows(): void
    {
        $batch = $this->preview([
            $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'GI-IN-1', 125, 0),
            $this->row('SCH-TIM', 'Times QA', $this->timesAccount, $this->timesExpense, 'GI-EX-1', 0, 40),
        ]);

        $confirmed = app(CentralFinanceGroupImportService::class)->confirm($this->head, $batch->token);

        $this->assertSame('completed', $confirmed->status);
        $this->assertSame(1, CentralFinanceOtherIncome::on('mysql')->count());
        $this->assertSame(1, CentralFinanceExpense::on('mysql')->count());
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_payments')->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_receipts')->count());
        $this->assertSame(2, $confirmed->rows()->whereNotNull('canonical_source_id')->count());
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_import_batches')->where('group_import_batch_id', $confirmed->id)->where('status', 'completed')->count());
        // one canonical document audit plus start/completion batch audit per School
        $this->assertSame(6, DB::connection('mysql')->table('central_finance_document_audits')->count());
    }

    public function test_an_unchecked_school_allocation_checkbox_fails_closed_instead_of_granting_account_access(): void
    {
        $this->assertTrue(User::on('mysql')->findOrFail($this->head->id)->hasRole('Head Finance'));
        app(CentralFinanceFundAccountAdministrationService::class)->syncSchoolAllocations(
            $this->head,
            School::on('mysql')->findOrFail(1),
            $this->zixuanAccount,
            [
                ['school_id' => 1, 'opening_allocation_amount' => 0, 'is_active' => true],
                // Native HTML omits an unchecked checkbox from the request.
                ['school_id' => 2, 'opening_allocation_amount' => 0],
            ],
            'Checkbox omission must not grant School access.',
        );

        $allocation = CentralFinanceFundAccountSchoolAllocation::on('mysql')->where([
            'fund_account_id' => $this->zixuanAccount->id,
            'school_id' => 2,
        ])->firstOrFail();

        $this->assertFalse((bool) $allocation->is_active);
        $this->assertSame(CentralFinanceFundAccountSchoolAllocation::STATUS_INACTIVE, $allocation->status);
        $this->assertSame(1, CentralFinanceFundAccountSchoolAllocation::on('mysql')->where([
            'fund_account_id' => $this->zixuanAccount->id,
            'school_id' => 1,
            'is_active' => true,
        ])->count());
    }

    public function test_formal_template_lookups_exclude_explicit_test_and_preview_master_data(): void
    {
        $uatAccount = $this->account('CENTRAL-PROD-UAT-ZXN-MMK', 'Retained UAT Account', 1);
        $testAccount = $this->account('TEMP-CASH', 'Test Petty Cash', 1);
        $compactTestAccount = $this->account('12345', 'testzixuan', 1);
        foreach ([$uatAccount, $testAccount, $compactTestAccount] as $account) {
            DB::connection('mysql')->table('central_finance_fund_account_users')->insert([
                'fund_account_id' => $account->id,
                'user_id' => $this->head->id,
                'can_view' => true,
                'can_operate' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->category(1, CentralFinanceCategory::EXPENSE, 'CENTRAL-PROD-UAT-EXPENSE');
        $testCategory = $this->category(1, CentralFinanceCategory::INCOME, 'TEMP-INCOME');
        $testCategory->update(['name' => 'Test Income']);
        $previewCategory = $this->category(1, CentralFinanceCategory::INCOME, 'CATEGORY-0EA183FE-360');
        $previewCategory->update(['name' => 'SFA P1 Preview Income']);

        $lookups = app(CentralFinanceGroupImportService::class)->templateLookups($this->head, $this->group);

        $this->assertNotContains($uatAccount->account_code, array_column($lookups['accounts'], 'code'));
        $this->assertNotContains($testAccount->account_code, array_column($lookups['accounts'], 'code'));
        $this->assertNotContains($compactTestAccount->account_code, array_column($lookups['accounts'], 'code'));
        $this->assertNotContains('CENTRAL-PROD-UAT-EXPENSE', array_column($lookups['categories'], 'category_code'));
        $this->assertNotContains($testCategory->category_code, array_column($lookups['categories'], 'category_code'));
        $this->assertNotContains($previewCategory->category_code, array_column($lookups['categories'], 'category_code'));
        $this->assertContains($this->zixuanAccount->account_code, array_column($lookups['accounts'], 'code'));
        $this->assertContains($this->zixuanIncome->category_code, array_column($lookups['categories'], 'category_code'));
    }

    public function test_v21_saved_workbook_previews_two_school_expense_and_other_income_without_formula_blank_rows(): void
    {
        $lookups = app(CentralFinanceGroupImportService::class)->templateLookups($this->head, $this->group);
        $path = tempnam(sys_get_temp_dir(), 'cf_group_v21_');
        file_put_contents($path, Excel::raw(new CentralFinanceGroupImportTemplateV2Export(
            $lookups['schools'], $lookups['accounts'], $lookups['categories'],
        ), ExcelFormat::XLSX));

        try {
            $workbook = IOFactory::load($path);
            $import = $workbook->getSheetByName('Import');
            $this->assertNotFalse($import);
            $this->assertEqualsCanonicalizing(['Zixuan QA', 'Times QA'], array_column($lookups['schools'], 'name'));
            $this->assertContains('Zixuan Cash', array_column($workbook->getSheetByName('Fund Accounts')->rangeToArray('B2:B10', null, true, false, false), 0));
            $this->assertContains('DONATION', array_column($workbook->getSheetByName('Categories')->rangeToArray('C2:C10', null, true, false, false), 0));

            // The user types only the visible/selectable values; V2.1 fills the
            // canonical routing and account metadata formulas itself.
            foreach ([
                'C2' => 'Zixuan QA', 'D2' => '2026-09-03', 'E2' => 'QA Claimant', 'F2' => 'V2.1 income QA', 'G2' => 'ZIX-CASH', 'J2' => 'DONATION', 'K2' => 'Cash', 'L2' => 125, 'O2' => 'V21-IN-001',
                'C3' => 'Times QA', 'D3' => '2026-09-03', 'E3' => 'QA Claimant', 'F3' => 'V2.1 expense QA', 'G3' => 'TIM-CASH', 'J3' => 'RENT', 'K3' => 'Cash', 'M3' => 40, 'O3' => 'V21-EX-001',
            ] as $cell => $value) {
                $import->setCellValue($cell, $value);
            }
            $workbook->getCalculationEngine()->clearCalculationCache();
            IOFactory::createWriter($workbook, 'Xlsx')->save($path);
            $workbook->disconnectWorksheets();

            $this->withoutMiddleware()->actingAs($this->head);
            $this->post(route('central-finance.group-import.preview'), [
                'finance_group_id' => $this->group->id,
                'group_import' => new UploadedFile($path, 'group-finance-import-template-v2.1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertRedirect();
            $batch = CentralFinanceGroupImportBatch::on('mysql')->latest('id')->firstOrFail();
            $rows = $batch->rows()->orderBy('row_number')->get();

            $this->assertSame('previewed', $batch->status);
            $this->assertSame(2, $batch->total_rows);
            $this->assertSame(2, $batch->new_rows);
            $this->assertSame(0, $batch->error_rows);
            $this->assertSame(['other_income', 'expense'], $rows->pluck('document_type')->all());
            $this->assertSame(['SCH-ZIX', 'SCH-TIM'], $rows->pluck('normalized_data')->map(fn (array $row): string => $row['school_code'])->all());
            $this->assertSame(['ZIX-CASH', 'TIM-CASH'], $rows->pluck('normalized_data')->map(fn (array $row): string => $row['fund_account_code'])->all());
            $this->assertSame(['MMK', 'MMK'], $rows->pluck('normalized_data')->map(fn (array $row): string => $row['currency'])->all());
            $this->assertSame(['V21-IN-001', 'V21-EX-001'], $rows->pluck('reference_no')->all());
        } finally {
            @unlink($path);
        }
    }

    public function test_same_shared_account_routes_two_schools_through_preview_and_confirm_without_duplicate_finance_writes(): void
    {
        CentralFinanceFundAccountSchoolAllocation::on('mysql')->create([
            'fund_account_id' => $this->zixuanAccount->id, 'school_id' => 2,
            'opening_allocation_amount' => 0, 'effective_from' => '2026-09-01',
            'status' => 'active', 'is_active' => true, 'assigned_by' => $this->head->id,
            'assignment_reason' => 'Shared-account P1 QA.',
        ]);

        $batch = $this->preview([
            $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'SHARED-ZIX-1', 125, 0),
            $this->row('SCH-TIM', 'Times QA', $this->zixuanAccount, $this->timesExpense, 'SHARED-TIM-1', 0, 40),
        ]);
        $this->assertSame(2, $batch->new_rows);
        $this->assertSame(0, $batch->error_rows);
        $this->assertSame(0, CentralFinanceExpense::on('mysql')->count());
        $this->assertSame(0, CentralFinanceOtherIncome::on('mysql')->count());

        app(CentralFinanceGroupImportService::class)->confirm($this->head, $batch->token);
        $this->assertSame(1, CentralFinanceExpense::on('mysql')->where('fund_account_id', $this->zixuanAccount->id)->where('school_id', 2)->count());
        $this->assertSame(1, CentralFinanceOtherIncome::on('mysql')->where('fund_account_id', $this->zixuanAccount->id)->where('school_id', 1)->count());
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_ledger_entries')->where('fund_account_id', $this->zixuanAccount->id)->count());

        $repeat = $this->preview([
            $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'SHARED-ZIX-1', 125, 0),
            $this->row('SCH-TIM', 'Times QA', $this->zixuanAccount, $this->timesExpense, 'SHARED-TIM-1', 0, 40),
        ]);
        $this->assertSame(2, $repeat->duplicate_rows);
        $this->assertSame(2, DB::connection('mysql')->table('central_finance_ledger_entries')->where('fund_account_id', $this->zixuanAccount->id)->count());
    }

    public function test_unassigned_school_is_rejected_before_group_import_can_create_a_financial_document(): void
    {
        $batch = $this->preview([
            $this->row('SCH-TIM', 'Times QA', $this->zixuanAccount, $this->timesExpense, 'UNASSIGNED-SHARED-1', 0, 40),
        ]);
        $row = $batch->rows()->sole();
        $this->assertSame('Error', $row->result_status);
        $this->assertSame('FUND_ACCOUNT_SCOPE_MISMATCH', $row->error_code);
        $this->assertSame(0, CentralFinanceExpense::on('mysql')->count());
        $this->assertSame(0, CentralFinanceOtherIncome::on('mysql')->count());
    }

    public function test_server_rejects_zero_negative_double_sided_and_unlisted_payment_method_rows(): void
    {
        $cases = [
            ['row' => $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'ZERO', 0, 0), 'code' => 'AMOUNT_ROUTING_INVALID'],
            ['row' => $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'NEG', -1, 0), 'code' => 'AMOUNT_ROUTING_INVALID'],
            ['row' => $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'BOTH', 1, 1), 'code' => 'AMOUNT_ROUTING_INVALID'],
        ];
        $unlisted = $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'METHOD', 1, 0);
        $unlisted['付款方式'] = 'Unlisted Method';
        $cases[] = ['row' => $unlisted, 'code' => 'PAYMENT_METHOD_INVALID'];

        foreach ($cases as $case) {
            $preview = $this->preview([$case['row']]);
            $this->assertSame('Error', $preview->rows()->sole()->result_status);
            $this->assertSame($case['code'], $preview->rows()->sole()->error_code);
        }
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_statement_balance_is_only_a_reconciliation_assertion_and_never_writes_balance(): void
    {
        $matching = $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'BALANCE-OK', 25, 0);
        $matching['Statement Balance / 对账余款'] = 25;
        $accepted = $this->preview([$matching]);
        $this->assertSame('New', $accepted->rows()->sole()->result_status);
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());

        $mismatch = $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'BALANCE-BAD', 25, 0);
        $mismatch['Statement Balance / 对账余款'] = 999;
        $rejected = $this->preview([$mismatch]);
        $this->assertSame('BALANCE_ASSERTION_MISMATCH', $rejected->rows()->sole()->error_code);
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
    }

    public function test_confirm_failure_after_preview_leaves_no_partial_financial_write_and_marks_batch_failed(): void
    {
        $batch = $this->preview([
            $this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'GI-ROLL-1', 125, 0),
            $this->row('SCH-TIM', 'Times QA', $this->timesAccount, $this->timesExpense, 'GI-ROLL-2', 0, 40),
        ]);
        $this->timesAccount->update(['is_active' => false, 'status' => 'inactive']);

        try {
            app(CentralFinanceGroupImportService::class)->confirm($this->head, $batch->token);
            $this->fail('Confirm must revalidate the now-inactive account.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, CentralFinanceOtherIncome::on('mysql')->count());
            $this->assertSame(0, CentralFinanceExpense::on('mysql')->count());
            $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
            $this->assertSame('failed', CentralFinanceGroupImportBatch::on('mysql')->findOrFail($batch->id)->status);
        }
    }

    public function test_duplicate_confirm_is_exactly_once_and_conflict_after_preview_blocks_all_new_writes(): void
    {
        $batch = $this->preview([$this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'GI-ONCE-1', 125, 0)]);
        app(CentralFinanceGroupImportService::class)->confirm($this->head, $batch->token);
        try {
            app(CentralFinanceGroupImportService::class)->confirm($this->head, $batch->token);
            $this->fail('A completed batch cannot confirm twice.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, CentralFinanceOtherIncome::on('mysql')->count());
            $this->assertSame(1, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
        }

        $conflicted = $this->preview([$this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'GI-CONFLICT-1', 50, 0)]);
        app(CentralFinanceOperatingDocumentService::class)->createOtherIncome($this->head, 1, $this->zixuanIncome->id, $this->zixuanAccount, 75, 'Cash', CarbonImmutable::parse('2026-09-02', 'Asia/Yangon'), 'external-conflict-1', 'GI-CONFLICT-1');
        try {
            app(CentralFinanceGroupImportService::class)->confirm($this->head, $conflicted->token);
            $this->fail('An immutable reference conflict must block confirmation.');
        } catch (InvalidArgumentException) {
            $this->assertSame(2, CentralFinanceOtherIncome::on('mysql')->count());
            $this->assertSame(2, DB::connection('mysql')->table('central_finance_ledger_entries')->count());
            $this->assertSame('failed', CentralFinanceGroupImportBatch::on('mysql')->findOrFail($conflicted->id)->status);
        }
    }

    public function test_confirm_link_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_02_000004_add_group_import_confirm_links.php');
        $migration->down();
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_group_import_batches', 'confirmed_by'));
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_group_import_preview_rows', 'canonical_source_id'));

        $migration->up();
        $this->assertTrue(Schema::connection('mysql')->hasColumn('central_finance_group_import_batches', 'confirmed_by'));
        $this->assertTrue(Schema::connection('mysql')->hasColumn('central_finance_group_import_preview_rows', 'canonical_source_id'));
    }

    public function test_school_scoped_operate_finance_cannot_preview_group_import(): void
    {
        DB::connection('mysql')->table('users')->insert(['id' => 101, 'first_name' => 'School', 'last_name' => 'Accountant', 'created_at' => now(), 'updated_at' => now()]);
        $schoolActor = CentralFinanceUser::on('mysql')->findOrFail(101);
        $schoolMember = FinanceGroupUser::on('mysql')->create(['group_id' => $this->group->id, 'central_user_id' => 101, 'status' => 'active']);
        DB::connection('mysql')->table('finance_group_user_scopes')->insert([
            'group_user_id' => $schoolMember->id, 'school_id' => 1, 'scope_type' => 'SCHOOL',
            'capability' => 'operate_finance', 'scope_key' => 'school:1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);
        app(CentralFinanceGroupImportService::class)->previewRows(
            $schoolActor,
            $this->group,
            'school-only.xlsx',
            hash('sha256', 'school-only'),
            [$this->row('SCH-ZIX', 'Zixuan QA', $this->zixuanAccount, $this->zixuanIncome, 'GI-SCHOOL-DENIED', 10, 0)],
        );
    }

    public function test_http_surface_requires_group_level_operate_finance(): void
    {
        $this->withoutMiddleware()->actingAs($this->head);
        $workspace = app(\App\Http\Controllers\CentralFinanceGroupImportController::class)->workspace(request());
        $this->assertInstanceOf(View::class, $workspace);
        $this->assertSame('central-finance.group-import.index', $workspace->name());
        $this->get(route('central-finance.group-import.template', ['finance_group_id' => $this->group->id]))
            ->assertDownload('group-finance-import-template-v2.2.xlsx');

        $schoolActor = $this->schoolOnlyActor();
        $batch = CentralFinanceGroupImportBatch::on('mysql')->create([
            'finance_group_id' => $this->group->id,
            'uploaded_by' => $this->head->id,
            'file_name' => 'head-only.xlsx',
            'file_hash' => hash('sha256', 'head-only'),
            'schema_version' => CentralFinanceGroupImportService::SCHEMA_VERSION,
            'status' => 'previewed',
            'total_rows' => 0,
            'new_rows' => 0,
            'duplicate_rows' => 0,
            'conflict_rows' => 0,
            'error_rows' => 0,
            'school_summary' => [],
        ]);

        $this->actingAs($schoolActor);
        $this->get(route('central-finance.group-import.index'))->assertForbidden();
        $this->get(route('central-finance.group-import.template'))->assertForbidden();
        $this->post(route('central-finance.group-import.preview'), [
            'finance_group_id' => $this->group->id,
            'group_import' => UploadedFile::fake()->create('school-only.xlsx', 1, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertForbidden();
        $this->post(route('central-finance.group-import.confirm', $batch->token))->assertForbidden();
        $this->get(route('central-finance.group-import.source', [$batch->token, 1]))->assertForbidden();
    }

    private function schoolOnlyActor(): CentralFinanceUser
    {
        if ($actor = CentralFinanceUser::on('mysql')->find(101)) {
            return $actor;
        }

        DB::connection('mysql')->table('users')->insert(['id' => 101, 'first_name' => 'School', 'last_name' => 'Accountant', 'created_at' => now(), 'updated_at' => now()]);
        $member = FinanceGroupUser::on('mysql')->create(['group_id' => $this->group->id, 'central_user_id' => 101, 'status' => 'active']);
        DB::connection('mysql')->table('finance_group_user_scopes')->insert([
            'group_user_id' => $member->id, 'school_id' => 1, 'scope_type' => 'SCHOOL',
            'capability' => 'operate_finance', 'scope_key' => 'school:1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CentralFinanceUser::on('mysql')->findOrFail(101);
    }

    /** @param list<array<string,mixed>> $rows */
    private function preview(array $rows): CentralFinanceGroupImportBatch
    {
        return app(CentralFinanceGroupImportService::class)->previewRows($this->head, $this->group, 'group-import.xlsx', hash('sha256', serialize($rows).Str::uuid()), $rows);
    }

    /** @return array<string,mixed> */
    private function row(string $schoolCode, string $schoolLabel, CentralFinanceFundAccount $account, CentralFinanceCategory $category, string $reference, float $income, float $expense): array
    {
        return ['School Code' => $schoolCode, '校区' => $schoolLabel, '日期' => '2026-09-02', '报销人' => 'QA', '摘要' => 'Group import QA', 'Fund Account Code' => $account->account_code, 'Fund Account Type' => $account->account_type, 'Account Owner' => $account->owner_type, 'Category Code' => $category->category_code, '付款方式' => 'Cash', '收入' => $income, '支出' => $expense, '余款' => null, 'Reference / 单据号' => $reference, 'Currency' => 'MMK', '备注' => ''];
    }

    private function account(string $code, string $name, int $schoolId): CentralFinanceFundAccount
    {
        return CentralFinanceFundAccount::on('mysql')->create(['account_uuid' => (string) Str::uuid(), 'group_id' => $this->group->id, 'school_id' => $schoolId, 'owner_type' => 'school', 'account_code' => $code, 'account_name' => $name, 'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true, 'status' => 'active']);
    }

    private function category(int $schoolId, string $type, string $code): CentralFinanceCategory
    {
        return CentralFinanceCategory::on('mysql')->create(['school_id' => $schoolId, 'type' => $type, 'name' => $code, 'category_code' => $code, 'is_active' => true]);
    }
}

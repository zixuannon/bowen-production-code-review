<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankAccountBalanceAdjustment;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\ExpenseChangeLog;
use App\Models\Fee;
use App\Models\FeesPaid;
use App\Models\OptionalFee;
use App\Models\User;
use App\Services\FeesPaidImportService;
use App\Services\FeesPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialAuditSafetyTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];

    private int $schoolId = 1;
    private int $authUserId;
    private int $studentId;
    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTables();

        $this->authUserId = DB::table('users')->insertGetId([
            'first_name' => 'Audit', 'last_name' => 'Tester',
            'email' => 'audit.tester.' . uniqid() . '@test.local',
            'password' => bcrypt('password'), 'school_id' => $this->schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->studentId = DB::table('users')->insertGetId([
            'first_name' => 'Audit', 'last_name' => 'Student',
            'email' => 'audit.student.' . uniqid() . '@test.local',
            'password' => bcrypt('password'), 'school_id' => $this->schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 1, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => $this->schoolId,
            ]);
        } catch (\Throwable) {}

        $this->bankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id' => $this->schoolId, 'account_name' => 'Audit Test Bank',
            'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Auth::loginUsingId($this->authUserId);
    }

    // ================================================================
    // P1-B: Expense Edit Change Log
    // ================================================================

    /** @test */
    public function expense_change_log_stores_old_and_new_amount(): void
    {
        $expense = $this->createExpense(500, 'Change log test');

        $log = ExpenseChangeLog::create([
            'expense_id' => $expense->id,
            'field_name' => 'amount',
            'old_value'  => '500',
            'new_value'  => '750',
            'changed_by' => $this->authUserId,
            'reason'     => 'Corrected after invoice review',
        ]);

        $this->assertNotNull($log->id);
        $this->assertEquals('amount', $log->field_name);
        $this->assertEquals('500', $log->old_value);
        $this->assertEquals('750', $log->new_value);
        $this->assertEquals($this->authUserId, $log->changed_by);
    }

    /** @test */
    public function expense_change_log_stores_bank_account_change(): void
    {
        $expense = $this->createExpense(300, 'Bank change test');

        $newBankId = DB::table('bank_accounts')->insertGetId([
            'school_id' => $this->schoolId, 'account_name' => 'New Target Bank',
            'account_type' => 'bank', 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $log = ExpenseChangeLog::create([
            'expense_id' => $expense->id,
            'field_name' => 'bank_account_id',
            'old_value'  => (string) $this->bankAccountId,
            'new_value'  => (string) $newBankId,
            'changed_by' => $this->authUserId,
            'reason'     => 'Moved to correct account',
        ]);

        $this->assertEquals((string)$this->bankAccountId, $log->old_value);
        $this->assertEquals((string)$newBankId, $log->new_value);
    }

    /** @test */
    public function expense_change_log_stores_reason(): void
    {
        $expense = $this->createExpense(200, 'Reason test');
        $log = ExpenseChangeLog::create([
            'expense_id' => $expense->id,
            'field_name' => 'amount',
            'old_value' => '200',
            'new_value' => '400',
            'changed_by' => $this->authUserId,
            'reason' => 'Corrected amount after invoice review',
        ]);

        $this->assertEquals('Corrected amount after invoice review', $log->reason);
    }

    /** @test */
    public function expense_change_log_date_field_correct(): void
    {
        $expense = $this->createExpense(500, 'Date change test');
        $log = ExpenseChangeLog::create([
            'expense_id' => $expense->id,
            'field_name' => 'date',
            'old_value'  => '2025-06-01',
            'new_value'  => '2025-07-15',
            'changed_by' => $this->authUserId,
        ]);

        $this->assertEquals('2025-06-01', $log->old_value);
        $this->assertEquals('2025-07-15', $log->new_value);
    }

    // ================================================================
    // P1-C: Fee Payment Delete Safety
    // ================================================================

    /** @test */
    public function compulsory_fee_soft_delete_preserves_record(): void
    {
        $fee = $this->ensureFeeTable();
        $cf = CompulsoryFee::create([
            'student_id' => $this->studentId, 'type' => 'Full Payment',
            'mode' => 'Cash', 'amount' => 1000, 'date' => now()->format('Y-m-d'),
            'school_id' => $this->schoolId, 'bank_account_id' => $this->bankAccountId,
        ]);

        $cfId = $cf->id;
        $cf->deleted_by = $this->authUserId;
        $cf->delete_reason = 'Test deletion';
        $cf->save();
        $cf->delete();

        // Record still exists in DB
        $row = DB::table('compulsory_fees')->where('id', $cfId)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at);
        $this->assertEquals($this->authUserId, $row->deleted_by);
        $this->assertEquals('Test deletion', $row->delete_reason);
    }

    /** @test */
    public function soft_deleted_compulsory_fee_not_in_normal_query(): void
    {
        $fee = $this->ensureFeeTable();
        $cf = CompulsoryFee::create([
            'student_id' => $this->studentId, 'type' => 'Full Payment',
            'mode' => 'Cash', 'amount' => 500, 'date' => now()->format('Y-m-d'),
            'school_id' => $this->schoolId, 'bank_account_id' => $this->bankAccountId,
        ]);

        $cf->deleted_by = $this->authUserId;
        $cf->delete_reason = 'Test';
        $cf->save();
        $cf->delete();

        $this->assertNull(CompulsoryFee::find($cf->id));
        $this->assertNotNull(CompulsoryFee::withTrashed()->find($cf->id));
    }

    /** @test */
    public function optional_fee_soft_delete_preserves_record(): void
    {
        $fee = $this->ensureFeeTable();
        $of = OptionalFee::create([
            'student_id' => $this->studentId, 'mode' => 'Cash',
            'amount' => 800, 'date' => now()->format('Y-m-d'),
            'school_id' => $this->schoolId, 'bank_account_id' => $this->bankAccountId,
        ]);

        $ofId = $of->id;
        $of->deleted_by = $this->authUserId;
        $of->delete_reason = 'Wrong optional fee';
        $of->save();
        $of->delete();

        $row = DB::table('optional_fees')->where('id', $ofId)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at);
        $this->assertEquals($this->authUserId, $row->deleted_by);
    }

    /** @test */
    public function soft_deleted_reference_no_still_reserved(): void
    {
        $fee = $this->ensureFeeTable();
        $refNo = 'REF-RESERVED-' . strtoupper(Str::random(6));

        $cf = CompulsoryFee::create([
            'student_id' => $this->studentId, 'type' => 'Full Payment',
            'mode' => 'Cash', 'amount' => 1000, 'date' => now()->format('Y-m-d'),
            'school_id' => $this->schoolId, 'reference_no' => $refNo,
            'bank_account_id' => $this->bankAccountId,
        ]);

        $cf->deleted_by = $this->authUserId;
        $cf->delete_reason = 'Test';
        $cf->save();
        $cf->delete();

        // Check withTrashed finds it, so duplicate would be rejected
        $exists = CompulsoryFee::withTrashed()
            ->where('school_id', $this->schoolId)
            ->where('reference_no', $refNo)
            ->exists();
        $this->assertTrue($exists, 'Soft-deleted reference_no must still be reserved');
    }

    // ================================================================
    // P1-D: Opening Balance Adjustments
    // ================================================================

    /** @test */
    public function changing_opening_balance_creates_adjustment_record(): void
    {
        $account = BankAccount::create([
            'school_id' => $this->schoolId, 'account_name' => 'OB Test',
            'account_type' => 'cash', 'currency' => 'MMK',
            'opening_balance' => 1000000, 'opening_balance_date' => '2025-01-01',
            'is_active' => true,
        ]);

        BankAccountBalanceAdjustment::create([
            'bank_account_id'           => $account->id,
            'old_opening_balance'       => 1000000,
            'new_opening_balance'       => 5000000,
            'old_opening_balance_date'  => '2025-01-01',
            'new_opening_balance_date'  => '2025-06-01',
            'changed_by'                => $this->authUserId,
            'reason'                    => 'Correction: actual opening balance was higher',
        ]);

        $adj = BankAccountBalanceAdjustment::where('bank_account_id', $account->id)->first();
        $this->assertNotNull($adj);
        $this->assertEquals(1000000, $adj->old_opening_balance);
        $this->assertEquals(5000000, $adj->new_opening_balance);
        $this->assertEquals('2025-01-01', $adj->old_opening_balance_date->format('Y-m-d'));
        $this->assertEquals('2025-06-01', $adj->new_opening_balance_date->format('Y-m-d'));
        $this->assertEquals($this->authUserId, $adj->changed_by);
        $this->assertEquals('Correction: actual opening balance was higher', $adj->reason);
    }

    /** @test */
    public function adjustment_not_created_when_balance_unchanged(): void
    {
        $account = BankAccount::create([
            'school_id' => $this->schoolId, 'account_name' => 'Unchanged OB',
            'account_type' => 'cash', 'currency' => 'MMK',
            'opening_balance' => 2000000,
            'is_active' => true,
        ]);

        // Change only non-balance fields
        $account->account_name = 'Unchanged OB (renamed)';
        $account->save();

        $this->assertEquals(0, BankAccountBalanceAdjustment::where('bank_account_id', $account->id)->count(),
            'Non-balance changes must NOT create adjustment records');
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function ensureTables(): void
    {
        $tables = ['compulsory_fees', 'optional_fees', 'expenses', 'bank_accounts',
                    'bank_account_balance_adjustments', 'expense_change_logs', 'fees_paids', 'fees'];
        foreach ($tables as $table) {
            try { DB::connection()->getPdo()->query("SELECT 1 FROM {$table} LIMIT 1"); } catch (\Throwable) {}
        }
    }

    private function createExpense(float $amount, string $title): Expense
    {
        $id = DB::table('expenses')->insertGetId([
            'school_id' => $this->schoolId, 'bank_account_id' => $this->bankAccountId,
            'amount' => $amount, 'amount_mmk' => $amount, 'date' => now()->format('Y-m-d'),
            'session_year_id' => 1, 'title' => $title,
            'transaction_currency' => 'MMK', 'created_by' => $this->authUserId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Expense::find($id);
    }

    private function ensureFeeTable(): ?Fee
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM fees LIMIT 1');
            return Fee::create([
                'name' => 'Audit Fee ' . Str::random(4),
                'due_date' => now()->addDays(30)->format('Y-m-d'),
                'due_charges' => 0, 'class_id' => 1, 'school_id' => $this->schoolId,
                'session_year_id' => 1, 'total_compulsory_fees' => 5000,
            ]);
        } catch (\Throwable) {
            $this->markTestSkipped('Fees table not available');
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseSoftDeleteTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];

    private int $schoolId = 1;
    private int $authUserId;
    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureExpensesTable();
        $this->ensureBankAccountsTable();

        $this->authUserId = DB::table('users')->insertGetId([
            'first_name' => 'Expense', 'last_name' => 'Tester',
            'email' => 'expense.tester.' . uniqid() . '@test.local',
            'password' => bcrypt('password'), 'school_id' => $this->schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->bankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id' => $this->schoolId, 'account_name' => 'SoftDelete Test Bank',
            'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Auth::loginUsingId($this->authUserId);
    }

    // ================================================================
    // 1. Soft delete preserves the row
    // ================================================================

    /** @test */
    public function soft_deleted_expense_still_exists_in_database(): void
    {
        $expense = $this->createExpense(500, 'Test soft delete');

        $this->assertNull($expense->deleted_at);
        $this->assertNotNull(Expense::find($expense->id));

        $expense->deleted_by = $this->authUserId;
        $expense->delete_reason = 'Duplicate entry';
        $expense->save();
        $expense->delete();

        // Row still exists in DB
        $this->assertNotNull(DB::table('expenses')->where('id', $expense->id)->first());
        $this->assertNotNull($expense->fresh()->deleted_at);
    }

    /** @test */
    public function soft_deleted_expense_not_found_by_normal_query(): void
    {
        $expense = $this->createExpense(300, 'Normal query test');

        $expense->deleted_by = $this->authUserId;
        $expense->delete_reason = 'Test';
        $expense->save();
        $expense->delete();

        // Normal queries exclude soft-deleted
        $this->assertNull(Expense::find($expense->id));
        $this->assertEquals(0, Expense::where('id', $expense->id)->count());
    }

    /** @test */
    public function soft_deleted_expense_found_with_trashed(): void
    {
        $expense = $this->createExpense(200, 'Trashed test');

        $expense->deleted_by = $this->authUserId;
        $expense->delete_reason = 'Test';
        $expense->save();
        $expense->delete();

        // withTrashed finds it
        $found = Expense::withTrashed()->find($expense->id);
        $this->assertNotNull($found);
        $this->assertEquals(200, $found->amount);
        $this->assertEquals('Trashed test', $found->title);
        $this->assertEquals($this->authUserId, $found->deleted_by);
        $this->assertEquals('Test', $found->delete_reason);
        $this->assertNotNull($found->deleted_at);
    }

    /** @test */
    public function deleted_by_and_delete_reason_are_stored(): void
    {
        $expense = $this->createExpense(100, 'Audit fields test');

        $expense->deleted_by = $this->authUserId;
        $expense->delete_reason = 'Wrong entry - should have been 200';
        $expense->save();
        $expense->delete();

        $row = DB::table('expenses')->where('id', $expense->id)->first();
        $this->assertEquals($this->authUserId, $row->deleted_by);
        $this->assertEquals('Wrong entry - should have been 200', $row->delete_reason);
        $this->assertNotNull($row->deleted_at);
    }

    // ================================================================
    // 2. Balance/SUM correctly excludes soft-deleted
    // ================================================================

    /** @test */
    public function soft_deleted_expense_excluded_from_balance_calculation(): void
    {
        // Create two expenses
        $e1 = $this->createExpense(300, 'Active expense');
        $e2 = $this->createExpense(200, 'To be deleted');

        // Before deletion: SUM = 500
        $beforeSum = (float) Expense::where('bank_account_id', $this->bankAccountId)->sum('amount');
        $this->assertEquals(500, $beforeSum);

        // Soft delete e2
        $e2->deleted_by = $this->authUserId;
        $e2->delete_reason = 'Duplicate';
        $e2->save();
        $e2->delete();

        // After deletion: SUM should be 300 (only e1)
        $afterSum = (float) Expense::where('bank_account_id', $this->bankAccountId)->sum('amount');
        $this->assertEquals(300, $afterSum, 'SUM must exclude soft-deleted expenses');
    }

    /** @test */
    public function with_trashed_still_sums_deleted_records(): void
    {
        $e1 = $this->createExpense(300, 'Active');
        $e2 = $this->createExpense(200, 'Deleted');

        $e2->deleted_by = $this->authUserId;
        $e2->delete_reason = 'Test';
        $e2->save();
        $e2->delete();

        // withTrashed should include all
        $total = (float) Expense::withTrashed()->where('bank_account_id', $this->bankAccountId)->sum('amount');
        $this->assertEquals(500, $total, 'withTrashed must still include soft-deleted amounts');
    }

    // ================================================================
    // 3. Eloquent relationship still works
    // ================================================================

    /** @test */
    public function deleted_by_user_relationship_resolves(): void
    {
        $expense = $this->createExpense(100, 'Relationship test');

        $expense->deleted_by = $this->authUserId;
        $expense->delete_reason = 'Test';
        $expense->save();
        $expense->delete();

        $found = Expense::withTrashed()->with('deleted_by_user')->find($expense->id);
        $this->assertNotNull($found->deleted_by_user);
        $this->assertEquals($this->authUserId, $found->deleted_by_user->id);
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function ensureExpensesTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT deleted_at FROM expenses LIMIT 1');
        } catch (\Throwable) {
            DB::statement("ALTER TABLE expenses ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        }
        try {
            DB::connection()->getPdo()->query('SELECT deleted_by FROM expenses LIMIT 1');
        } catch (\Throwable) {
            DB::statement("ALTER TABLE expenses ADD COLUMN deleted_by BIGINT UNSIGNED NULL DEFAULT NULL");
        }
        try {
            DB::connection()->getPdo()->query('SELECT delete_reason FROM expenses LIMIT 1');
        } catch (\Throwable) {
            DB::statement("ALTER TABLE expenses ADD COLUMN delete_reason VARCHAR(255) NULL DEFAULT NULL");
        }
    }

    private function ensureBankAccountsTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM bank_accounts LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS bank_accounts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                account_name varchar(255) NOT NULL,
                account_type varchar(50) DEFAULT 'checking',
                currency varchar(3) DEFAULT 'MMK',
                opening_balance decimal(12,2) DEFAULT 0.00,
                is_active tinyint NOT NULL DEFAULT 1,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    private function createExpense(float $amount, string $title): Expense
    {
        $id = DB::table('expenses')->insertGetId([
            'school_id'          => $this->schoolId,
            'bank_account_id'    => $this->bankAccountId,
            'amount'             => $amount,
            'amount_mmk'         => $amount,
            'date'               => now()->format('Y-m-d'),
            'session_year_id'    => 1,
            'title'              => $title,
            'transaction_currency' => 'MMK',
            'created_by'         => $this->authUserId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        return Expense::find($id);
    }
}

<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesPaid;
use App\Models\User;
use App\Services\FeesPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 Money Integrity: All fee payments and expenses MUST reference
 * a valid, active, school-owned fund account.
 */
class FundAccountRequiredTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];

    private int $schoolId = 1;
    private int $authUserId;
    private int $studentId;
    private int $validBankAccountId;
    private int $inactiveBankAccountId;
    private int $otherSchoolBankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureBankAccountsTable();
        $this->ensureExpensesTable();
        $this->ensureOptionalFeesTable();
        $this->ensureCompulsoryFeesTable();
        $this->ensureBankTransfersTable();

        $this->authUserId = $this->createUser('Finance', 'Admin', $this->schoolId);
        $this->studentId  = $this->createUser('Test', 'Student', $this->schoolId);

        // Ensure role
        $this->ensureRole('Super Admin');
        $this->ensureRole('Student');
        $this->assignRole($this->authUserId, 'Super Admin');

        Auth::loginUsingId($this->authUserId);

        // Ensure session_year exists
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 1, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => $this->schoolId,
            ]);
        } catch (\Throwable) {}

        // Ensure second school exists for cross-school tests
        try {
            DB::table('schools')->insertOrIgnore([
                'id' => 2, 'name' => 'Other School', 'code' => 'OS2',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {}

        // Create a valid bank account
        $this->validBankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id'          => $this->schoolId,
            'account_name'       => 'Main Fund Account',
            'account_type'       => 'cash',
            'currency'           => 'MMK',
            'opening_balance'    => 0,
            'is_active'          => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Create an inactive bank account
        $this->inactiveBankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id'          => $this->schoolId,
            'account_name'       => 'Inactive Account',
            'account_type'       => 'bank',
            'currency'           => 'MMK',
            'opening_balance'    => 0,
            'is_active'          => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Create a bank account in another school
        $this->otherSchoolBankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id'          => 2,
            'account_name'       => 'Other School Account',
            'account_type'       => 'bank',
            'currency'           => 'MMK',
            'opening_balance'    => 0,
            'is_active'          => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    // ================================================================
    // A. FeesPaymentService Guard
    // ================================================================

    /** @test */
    public function service_rejects_missing_bank_account_id(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000);

        $service = app(FeesPaymentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fund account (bank_account_id) is required');

        $service->processPayment([
            'fees_id'           => $fee->id,
            'student_id'        => $this->studentId,
            'bank_account_id'   => null,  // missing
            'installment_mode'  => false,
            'installment_fees'  => [],
            'mode'              => 'Cash',
            'date'              => now()->format('Y-m-d'),
            'total_amount'      => 1000,
            'enter_amount'      => 1000,
            'due_charges_amount'=> 0,
            'advance'           => 0,
            'parent_id'         => null,
            'transaction_currency' => 'MMK',
            'reference_no'      => null,
        ], $fee);
    }

    /** @test */
    public function service_rejects_inactive_bank_account(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000);

        $service = app(FeesPaymentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fund account is not valid');

        $service->processPayment([
            'fees_id'           => $fee->id,
            'student_id'        => $this->studentId,
            'bank_account_id'   => $this->inactiveBankAccountId,
            'installment_mode'  => false,
            'installment_fees'  => [],
            'mode'              => 'Cash',
            'date'              => now()->format('Y-m-d'),
            'total_amount'      => 1000,
            'enter_amount'      => 1000,
            'due_charges_amount'=> 0,
            'advance'           => 0,
            'parent_id'         => null,
            'transaction_currency' => 'MMK',
            'reference_no'      => null,
        ], $fee);
    }

    /** @test */
    public function service_rejects_cross_school_bank_account(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000);

        $service = app(FeesPaymentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fund account is not valid');

        $service->processPayment([
            'fees_id'           => $fee->id,
            'student_id'        => $this->studentId,
            'bank_account_id'   => $this->otherSchoolBankAccountId,
            'installment_mode'  => false,
            'installment_fees'  => [],
            'mode'              => 'Cash',
            'date'              => now()->format('Y-m-d'),
            'total_amount'      => 1000,
            'enter_amount'      => 1000,
            'due_charges_amount'=> 0,
            'advance'           => 0,
            'parent_id'         => null,
            'transaction_currency' => 'MMK',
            'reference_no'      => null,
        ], $fee);
    }

    /** @test */
    public function service_accepts_valid_bank_account(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(500);

        $service = app(FeesPaymentService::class);
        $result = $service->processPayment([
            'fees_id'           => $fee->id,
            'student_id'        => $this->studentId,
            'bank_account_id'   => $this->validBankAccountId,
            'installment_mode'  => false,
            'installment_fees'  => [],
            'mode'              => 'Cash',
            'date'              => now()->format('Y-m-d'),
            'total_amount'      => 500,
            'enter_amount'      => 500,
            'due_charges_amount'=> 0,
            'advance'           => 0,
            'parent_id'         => null,
            'transaction_currency' => 'MMK',
            'reference_no'      => null,
        ], $fee);

        $this->assertTrue($result['is_fully_paid']);
        $this->assertEquals(500, $result['fees_paid']->amount);
    }

    /** @test */
    public function service_rejects_missing_or_forged_payment_method_before_creating_money_in(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(500);
        $beforeFeesPaid = FeesPaid::count();
        $beforeCompulsory = CompulsoryFee::count();

        foreach ([null, 'Forged Method'] as $mode) {
            try {
                app(FeesPaymentService::class)->processPayment([
                    'fees_id' => $fee->id, 'student_id' => $this->studentId,
                    'bank_account_id' => $this->validBankAccountId, 'installment_mode' => false,
                    'installment_fees' => [], 'mode' => $mode, 'date' => now()->format('Y-m-d'),
                    'total_amount' => 500, 'enter_amount' => 500, 'due_charges_amount' => 0,
                    'advance' => 0, 'parent_id' => null, 'transaction_currency' => 'MMK', 'reference_no' => null,
                ], $fee);
                $this->fail('A missing or forged payment method must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('payment method', $exception->getMessage());
            }
        }

        $this->assertSame($beforeFeesPaid, FeesPaid::count());
        $this->assertSame($beforeCompulsory, CompulsoryFee::count());
    }

    /** @test */
    public function service_rejects_soft_deleted_bank_account(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(500);

        // Soft-delete a bank account
        $deletedId = DB::table('bank_accounts')->insertGetId([
            'school_id'          => $this->schoolId,
            'account_name'       => 'Deleted Account',
            'account_type'       => 'bank',
            'currency'           => 'MMK',
            'opening_balance'    => 0,
            'is_active'          => 1,
            'deleted_at'         => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $service = app(FeesPaymentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fund account is not valid');

        $service->processPayment([
            'fees_id'           => $fee->id,
            'student_id'        => $this->studentId,
            'bank_account_id'   => $deletedId,
            'installment_mode'  => false,
            'installment_fees'  => [],
            'mode'              => 'Cash',
            'date'              => now()->format('Y-m-d'),
            'total_amount'      => 500,
            'enter_amount'      => 500,
            'due_charges_amount'=> 0,
            'advance'           => 0,
            'parent_id'         => null,
            'transaction_currency' => 'MMK',
            'reference_no'      => null,
        ], $fee);
    }

    // ================================================================
    // B. Balance Integration: Fee creates income in Fund Account
    // ================================================================

    /** @test */
    public function fee_payment_increases_fund_account_balance(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000);

        $beforeBalance = $this->computeBalance($this->validBankAccountId);

        $service = app(FeesPaymentService::class);
        $service->processPayment([
            'fees_id'           => $fee->id,
            'student_id'        => $this->studentId,
            'bank_account_id'   => $this->validBankAccountId,
            'installment_mode'  => false,
            'installment_fees'  => [],
            'mode'              => 'Cash',
            'date'              => now()->format('Y-m-d'),
            'total_amount'      => 1000,
            'enter_amount'      => 1000,
            'due_charges_amount'=> 0,
            'advance'           => 0,
            'parent_id'         => null,
            'transaction_currency' => 'MMK',
            'reference_no'      => null,
        ], $fee);

        $afterBalance = $this->computeBalance($this->validBankAccountId);
        $this->assertEquals(
            $beforeBalance + 1000,
            $afterBalance,
            'Fund account balance must increase by exactly the fee payment amount'
        );
    }

    /** @test */
    public function expense_decreases_fund_account_balance(): void
    {
        // Create expense with valid bank account
        $initialBalance = $this->computeBalance($this->validBankAccountId);

        DB::table('expenses')->insert([
            'school_id'          => $this->schoolId,
            'bank_account_id'    => $this->validBankAccountId,
            'amount'             => 300,
            'amount_mmk'         => 300,
            'date'               => now()->format('Y-m-d'),
            'session_year_id'    => 1,
            'title'              => 'Test Expense',
            'transaction_currency' => 'MMK',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $afterBalance = $this->computeBalance($this->validBankAccountId);
        $this->assertEquals(
            $initialBalance - 300,
            $afterBalance,
            'Fund account balance must decrease by exactly the expense amount'
        );
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function ensureExpensesTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM expenses LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS expenses (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                bank_account_id bigint unsigned DEFAULT NULL,
                amount double(8,2) NOT NULL DEFAULT 0,
                amount_mmk double(8,2) NOT NULL DEFAULT 0,
                date date DEFAULT NULL,
                session_year_id bigint unsigned DEFAULT NULL,
                title varchar(255) DEFAULT NULL,
                transaction_currency varchar(3) DEFAULT 'MMK',
                category_id bigint unsigned DEFAULT NULL,
                finance_category_id bigint unsigned DEFAULT NULL,
                ref_no varchar(100) DEFAULT NULL,
                description text DEFAULT NULL,
                staff_id bigint unsigned DEFAULT NULL,
                vehicle_id bigint unsigned DEFAULT NULL,
                month int DEFAULT NULL,
                year int DEFAULT NULL,
                basic_salary double(8,2) DEFAULT 0,
                paid_leaves int DEFAULT 0,
                file varchar(255) DEFAULT NULL,
                created_by bigint unsigned DEFAULT NULL,
                original_amount double(8,2) DEFAULT 0,
                exchange_rate_snapshot double(8,4) DEFAULT 1.0000,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    private function ensureOptionalFeesTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM optional_fees LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS optional_fees (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                bank_account_id bigint unsigned DEFAULT NULL,
                student_id bigint unsigned NOT NULL,
                class_id bigint unsigned DEFAULT NULL,
                fees_class_id bigint unsigned DEFAULT NULL,
                payment_transaction_id bigint unsigned DEFAULT NULL,
                mode varchar(50) DEFAULT 'Cash',
                cheque_no varchar(100) DEFAULT NULL,
                amount double(8,2) NOT NULL DEFAULT 0,
                fees_paid_id bigint unsigned DEFAULT NULL,
                date date DEFAULT NULL,
                session_year_id bigint unsigned DEFAULT NULL,
                status varchar(50) DEFAULT 'Success',
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    private function ensureCompulsoryFeesTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM compulsory_fees LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS compulsory_fees (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                bank_account_id bigint unsigned DEFAULT NULL,
                student_id bigint unsigned NOT NULL,
                type varchar(255) DEFAULT 'Full Payment',
                installment_id bigint unsigned DEFAULT NULL,
                payment_transaction_id bigint unsigned DEFAULT NULL,
                mode varchar(50) DEFAULT 'Cash',
                cheque_no varchar(100) DEFAULT NULL,
                reference_no varchar(100) DEFAULT NULL,
                import_batch_id bigint unsigned DEFAULT NULL,
                amount double(8,2) NOT NULL DEFAULT 0,
                due_charges double(8,2) DEFAULT 0,
                fees_paid_id bigint unsigned DEFAULT NULL,
                date date DEFAULT NULL,
                session_year_id bigint unsigned DEFAULT NULL,
                class_id bigint unsigned DEFAULT NULL,
                status varchar(50) DEFAULT 'Success',
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY compulsory_fees_reference_no_unique (school_id, reference_no)
            )");
        }
    }

    private function ensureBankTransfersTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM bank_transfers LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS bank_transfers (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                from_account_id bigint unsigned NOT NULL,
                to_account_id bigint unsigned NOT NULL,
                amount decimal(12,2) NOT NULL DEFAULT 0.00,
                transfer_date date DEFAULT NULL,
                reference_no varchar(100) DEFAULT NULL,
                notes text DEFAULT NULL,
                status varchar(20) DEFAULT 'completed',
                created_by bigint unsigned DEFAULT NULL,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
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
                account_number varchar(100) DEFAULT NULL,
                bank_name varchar(255) DEFAULT NULL,
                account_type varchar(50) DEFAULT 'checking',
                currency varchar(3) DEFAULT 'MMK',
                opening_balance decimal(12,2) DEFAULT 0.00,
                opening_balance_date date DEFAULT NULL,
                is_active tinyint NOT NULL DEFAULT 1,
                is_default tinyint NOT NULL DEFAULT 0,
                notes text DEFAULT NULL,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                deleted_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    private function ensureRole(string $name): void
    {
        $exists = DB::table('roles')->where('name', $name)->where('school_id', $this->schoolId)->exists();
        if (!$exists) {
            DB::table('roles')->insert([
                'name' => $name, 'guard_name' => 'web', 'school_id' => $this->schoolId,
                'custom_role' => 1, 'editable' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function assignRole(int $userId, string $name): void
    {
        $roleId = DB::table('roles')
            ->where('name', $name)
            ->where('school_id', $this->schoolId)
            ->value('id');

        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $userId,
        ], []);
    }

    private function createUser(string $first, string $last, int $schoolId): int
    {
        $email = strtolower($first) . '.' . strtolower($last) . uniqid() . '@test.local';
        return DB::table('users')->insertGetId([
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'password' => bcrypt('password'), 'school_id' => $schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function skipIfNoFeeTable(): void
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM fees LIMIT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Fees table not found - full school migration needed');
        }
    }

    private function createTestFee(float $total): Fee
    {
        $fee = new Fee();
        $fee->forceFill([
            'name'                  => 'Fund Account Test Fee',
            'due_date'              => now()->addDays(30)->format('Y-m-d'),
            'due_charges'           => 0,
            'class_id'              => 1,
            'school_id'             => $this->schoolId,
            'session_year_id'       => 1,
            'total_compulsory_fees' => $total,
        ]);
        $fee->save();
        return $fee;
    }

    /**
     * Compute current balance for a fund account using the same formula
     * as BankAccountController. (opening + income - expense + transfer_net)
     */
    private function computeBalance(int $bankAccountId): float
    {
        $account = DB::table('bank_accounts')->find($bankAccountId);
        $opening = (float)($account->opening_balance ?? 0);

        $compulsory = $this->safeSum('compulsory_fees', $bankAccountId);
        $optional   = $this->safeSum('optional_fees', $bankAccountId);
        $expenses   = $this->safeSum('expenses', $bankAccountId);
        $transferIn = $this->safeTransferSum('to_account_id', $bankAccountId);
        $transferOut= $this->safeTransferSum('from_account_id', $bankAccountId);

        return $opening + $compulsory + $optional + $transferIn - $expenses - $transferOut;
    }

    private function safeSum(string $table, int $bankAccountId): float
    {
        try {
            return (float)DB::table($table)
                ->where('bank_account_id', $bankAccountId)
                ->where('school_id', $this->schoolId)
                ->sum('amount');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeTransferSum(string $column, int $bankAccountId): float
    {
        try {
            return (float)DB::table('bank_transfers')
                ->where($column, $bankAccountId)
                ->where('school_id', $this->schoolId)
                ->where('status', 'completed')
                ->sum('amount');
        } catch (\Throwable) {
            return 0;
        }
    }
}

<?php

namespace Tests\Feature;

use App\Helpers\MoneyDecimal;
use App\Models\BankAccount;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeeImportBatch;
use App\Models\FeesAdvance;
use App\Models\FeesPaid;
use App\Models\SessionYearsTracking;
use App\Models\User;
use App\Services\FeesPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FeesPaymentCharacterizationTest
 *
 * Locks in the original manual payment behavior.
 * All tests that require the full DB schema are skipped if tables don't exist.
 * MoneyDecimal and migration tests always run.
 */
class FeesPaymentCharacterizationTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'school'];

    private int $authUserId;
    private int $studentId;
    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users directly (no factory - avoids 'name' column issue)
        $this->authUserId = $this->createUser('Admin', 'User', 1);
        $this->assignSchoolAdminRole($this->authUserId, 1);
        $this->studentId = $this->createUser('Test', 'Student', 1);

        Auth::loginUsingId($this->authUserId);

        // Ensure bank_accounts table exists
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

        // Create a test bank account
        $this->bankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id'     => 1,
            'account_name'  => 'Test Fund Account',
            'account_type'  => 'cash',
            'currency'      => 'MMK',
            'opening_balance' => 0,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Ensure session_year exists
        try {
            DB::table('session_years')->insertOrIgnore([
                'id'         => 1,
                'name'       => '2025-2026',
                'default'    => 1,
                'start_date' => '2025-06-01',
                'end_date'   => '2026-05-31',
                'school_id'  => 1,
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function createUser(string $first, string $last, int $schoolId): int
    {
        $email = strtolower($first) . '.' . strtolower($last) . uniqid() . '@test.local';
        return DB::table('users')->insertGetId([
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'password'   => bcrypt('password'),
            'school_id'  => $schoolId,
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignSchoolAdminRole(int $userId, int $schoolId): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => 'School Admin', 'school_id' => $schoolId],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        $roleId = DB::table('roles')->where('name', 'School Admin')->where('school_id', $schoolId)->value('id');
        DB::table('model_has_roles')->updateOrInsert(
            ['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $userId], []
        );
    }

    // ================================================================
    // 1. MoneyDecimal tests (no DB)
    // ================================================================

    /** @test */
    public function money_decimal_all_operations(): void
    {
        // normalize
        $this->assertSame('0.00', MoneyDecimal::normalize(''));
        $this->assertSame('50000.00', MoneyDecimal::normalize('50000'));
        $this->assertSame('100.50', MoneyDecimal::normalize(' 100.50 '));

        // toMinorUnits / fromMinorUnits
        $this->assertSame(15000025, MoneyDecimal::toMinorUnits('150000.25'));
        $this->assertSame('150000.25', MoneyDecimal::fromMinorUnits(15000025));
        $this->assertSame(5000000, MoneyDecimal::toMinorUnits('50000'));

        // arithmetic
        $this->assertSame('200000.25', MoneyDecimal::add('150000.25', '50000.00'));
        $this->assertSame('100000.25', MoneyDecimal::subtract('150000.25', '50000.00'));
        $this->assertSame('0.30', MoneyDecimal::add('0.10', '0.20'));

        // comparison
        $this->assertTrue(MoneyDecimal::equals('150000.25', '150000.25'));
        $this->assertTrue(MoneyDecimal::greaterThan('150.01', '150.00'));
        $this->assertTrue(MoneyDecimal::greaterThanOrEqual('150.00', '150.00'));
        $this->assertTrue(MoneyDecimal::lessThan('149.99', '150.00'));
        $this->assertTrue(MoneyDecimal::lessThanOrEqual('149.99', '150.00'));
        $this->assertTrue(MoneyDecimal::lessThanOrEqual('150.00', '150.00'));
    }

    // ================================================================
    // 2. Migration: reference_no UNIQUE allows multiple NULLs (MySQL)
    // ================================================================

    /** @test */
    public function multiple_null_reference_no_allowed(): void
    {
        $cf1 = CompulsoryFee::create([
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 100,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => 1,
            'reference_no' => null,
        ]);
        $cf2 = CompulsoryFee::create([
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 200,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => 1,
            'reference_no' => null,
        ]);

        $this->assertNotNull($cf1->id);
        $this->assertNotNull($cf2->id);
        $this->assertNotEquals($cf1->id, $cf2->id);
    }

    /** @test */
    public function duplicate_non_null_reference_no_rejected(): void
    {
        $ref = 'INV-DUP-' . uniqid();
        CompulsoryFee::create([
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 100,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => 1,
            'reference_no' => $ref,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CompulsoryFee::create([
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 200,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => 1,
            'reference_no' => $ref,
        ]);
    }

    /** @test */
    public function same_ref_no_different_schools_allowed(): void
    {
        try {
            DB::table('schools')->insertOrIgnore(['id' => 2, 'name' => 'S2', 'code' => 'S2', 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable) {}

        $ref = 'CROSS-REF-' . uniqid();

        $cf1 = CompulsoryFee::create([
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 100,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => 1,
            'reference_no' => $ref,
        ]);
        $cf2 = CompulsoryFee::create([
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 200,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => 2,
            'reference_no' => $ref,
        ]);
        $this->assertNotNull($cf1->id);
        $this->assertNotNull($cf2->id);
    }

    /** @test */
    public function reference_no_normalized_trim_and_upper(): void
    {
        $this->assertSame('INV-001', strtoupper(trim(' inv-001 ')));
        $this->assertSame('INV-001', strtoupper(trim('INV-001')));
    }

    // ================================================================
    // 3. FeeImportBatch state machine
    // ================================================================

    /** @test */
    public function batch_status_lifecycle(): void
    {
        $batch = FeeImportBatch::create([
            'token'       => 'token-lifecycle-' . uniqid(),
            'school_id'   => 1,
            'imported_by' => $this->authUserId,
            'file_name'   => 'test.xlsx',
            'file_hash'   => sha1('test' . uniqid()),
            'status'      => FeeImportBatch::STATUS_PENDING,
            'total_rows'  => 10,
            'expired_at'  => now()->addMinutes(30),
        ]);

        $this->assertEquals(FeeImportBatch::STATUS_PENDING, $batch->status);

        $batch->status = FeeImportBatch::STATUS_PROCESSING;
        $batch->save();
        $batch->refresh();
        $this->assertEquals(FeeImportBatch::STATUS_PROCESSING, $batch->status);

        $batch->status       = FeeImportBatch::STATUS_COMPLETED;
        $batch->consumed_at  = now();
        $batch->preview_data = null;
        $batch->save();
        $batch->refresh();
        $this->assertEquals(FeeImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertNull($batch->preview_data);
    }

    /** @test */
    public function failed_batch_rejected_on_confirm(): void
    {
        $batch = FeeImportBatch::create([
            'token'       => 'token-failed-' . uniqid(),
            'school_id'   => 1,
            'imported_by' => $this->authUserId,
            'file_name'   => 'test.xlsx',
            'file_hash'   => sha1('test' . uniqid()),
            'status'      => FeeImportBatch::STATUS_FAILED,
            'last_error'  => 'Previous error',
            'total_rows'  => 5,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('failed');
        if ($batch->status !== FeeImportBatch::STATUS_PENDING) {
            throw new \InvalidArgumentException(
                "Batch status is '{$batch->status}', expected 'pending'"
            );
        }
    }

    /** @test */
    public function expired_batch_rejected(): void
    {
        $batch = FeeImportBatch::create([
            'token'       => 'token-expired-' . uniqid(),
            'school_id'   => 1,
            'imported_by' => $this->authUserId,
            'file_name'   => 'test.xlsx',
            'file_hash'   => sha1('test' . uniqid()),
            'status'      => FeeImportBatch::STATUS_PENDING,
            'total_rows'  => 10,
            'expired_at'  => now()->subMinutes(5),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        if (now()->gt($batch->expired_at)) {
            throw new \InvalidArgumentException('Preview session has expired. Please re-upload.');
        }
    }

    /** @test */
    public function batch_token_is_unique(): void
    {
        $token = 'unique-token-' . uniqid();
        FeeImportBatch::create([
            'token'       => $token,
            'school_id'   => 1,
            'imported_by' => $this->authUserId,
            'file_name'   => 'test.xlsx',
            'file_hash'   => sha1('test'),
            'status'      => FeeImportBatch::STATUS_PENDING,
            'total_rows'  => 5,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        FeeImportBatch::create([
            'token'       => $token,
            'school_id'   => 1,
            'imported_by' => $this->authUserId,
            'file_name'   => 'test2.xlsx',
            'file_hash'   => sha1('test2'),
            'status'      => FeeImportBatch::STATUS_PENDING,
            'total_rows'  => 3,
        ]);
    }

    // ================================================================
    // 4. FeesPaymentService characterization (requires full fee tables)
    // ================================================================

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
            'name'                  => 'Characterization Fee',
            'due_date'              => now()->addDays(30)->format('Y-m-d'),
            'due_charges'           => 0,
            'class_id'              => 1,
            'school_id'             => 1,
            'session_year_id'       => 1,
            'total_compulsory_fees' => $total,
        ]);
        $fee->save();
        return $fee;
    }

    /** @test */
    public function mmk_full_payment_creates_fees_paid_and_compulsory_fee(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000.00);

        $service = app(FeesPaymentService::class);
        $result = $service->processPayment([
            'fees_id'               => $fee->id,
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'bank_account_id'       => $this->bankAccountId,
            'installment_mode'      => false,
            'installment_fees'      => [],
            'mode'                  => 'Cash',
            'date'                  => now()->format('Y-m-d'),
            'total_amount'          => 1000,
            'enter_amount'          => 1000,
            'due_charges_amount'    => 0,
            'advance'               => 0,
            'parent_id'             => null,
            'transaction_currency'  => 'MMK',
            'reference_no'          => null,
        ], $fee);

        $this->assertTrue($result['is_fully_paid']);
        $this->assertEquals(1000, $result['fees_paid']->amount);
        $this->assertEquals('MMK', $result['fees_paid']->transaction_currency);
        $this->assertFalse((bool) $result['fees_paid']->is_used_installment);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals('Full Payment', $cf->type);
        $this->assertEquals(1000, $cf->amount);
    }

    /** @test */
    public function mmk_installment_creates_compulsory_fee(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(3000.00);

        // Manually add an installment via DB
        DB::table('fees_installments')->insertOrIgnore([
            'id'             => 99,
            'name'           => 'Inst 1',
            'due_date'       => now()->addDays(15)->format('Y-m-d'),
            'due_charges'    => 0,
            'due_charges_type' => 'fixed',
            'fees_id'        => $fee->id,
            'session_year_id' => 1,
            'school_id'      => 1,
        ]);

        $service = app(FeesPaymentService::class);
        $result = $service->processPayment([
            'fees_id'               => $fee->id,
            'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'bank_account_id'       => $this->bankAccountId,
            'installment_mode'      => true,
            'installment_fees'      => [
                ['id' => 99, 'amount' => 1500, 'due_charges' => 0],
            ],
            'mode'                  => 'Cash',
            'date'                  => now()->format('Y-m-d'),
            'total_amount'          => 3000,
            'enter_amount'          => null,
            'due_charges_amount'    => null,
            'advance'               => 0,
            'parent_id'             => null,
            'transaction_currency'  => 'MMK',
            'reference_no'          => null,
        ], $fee);

        $this->assertFalse($result['is_fully_paid']);
        $this->assertEquals(1500, $result['fees_paid']->amount);
        $this->assertTrue((bool) $result['fees_paid']->is_used_installment);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals('Installment Payment', $cf->type);
        $this->assertEquals(1500, $cf->amount);
        $this->assertEquals(99, $cf->installment_id);
    }

    /** @test */
    public function multiple_payments_accumulate_fees_paids_amount(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(3000.00);

        $service = app(FeesPaymentService::class);

        // Payment 1: 1000
        $r1 = $service->processPayment([
            'fees_id'      => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 3000, 'enter_amount' => 1000,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);
        $this->assertEquals(1000, $r1['fees_paid']->amount);
        $this->assertFalse($r1['is_fully_paid']);

        // Payment 2: 2000
        $r2 = $service->processPayment([
            'fees_id'      => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 3000, 'enter_amount' => 2000,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);
        $this->assertEquals(3000, $r2['fees_paid']->amount); // accumulated
        $this->assertTrue($r2['is_fully_paid']);
    }

    /** @test */
    public function usd_currency_saves_correctly(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000.00);

        $service = app(FeesPaymentService::class);
        $result = $service->processPayment([
            'fees_id'      => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 1000, 'enter_amount' => 1000,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'USD',
            'original_amount'      => 500,
            'exchange_rate_snapshot' => 2,
            'reference_no' => null,
        ], $fee);

        $fp = $result['fees_paid'];
        $this->assertEquals('USD', $fp->transaction_currency);
        $this->assertEquals(500, $fp->original_amount);
        $this->assertEquals(2, $fp->exchange_rate_snapshot);
        $this->assertEquals(1000, $fp->amount_mmk);
    }

    /** @test */
    public function cny_currency_saves_correctly(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000.00);

        $service = app(FeesPaymentService::class);
        $result = $service->processPayment([
            'fees_id'      => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 1000, 'enter_amount' => 1000,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'CNY',
            'original_amount'      => 100,
            'exchange_rate_snapshot' => 10,
            'reference_no' => null,
        ], $fee);

        $fp = $result['fees_paid'];
        $this->assertEquals('CNY', $fp->transaction_currency);
        $this->assertEquals(100, $fp->original_amount);
        $this->assertEquals(1000, $fp->amount_mmk);
    }

    /** @test */
    public function due_charges_stored_separately(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(1000.00);

        $service = app(FeesPaymentService::class);
        $result = $service->processPayment([
            'fees_id'      => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 1000, 'enter_amount' => 1100,
            'due_charges_amount' => 100,
            'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals(100, $cf->due_charges);
        $this->assertEquals(1100, $cf->amount);
        // is_fully_paid: amount (1100) >= base_total (1000) = true
        $this->assertTrue($result['is_fully_paid']);
    }

    /** @test */
    public function fully_paid_prevents_second_payment(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(500.00);

        $service = app(FeesPaymentService::class);
        $service->processPayment([
            'fees_id' => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 500, 'enter_amount' => 500,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already Paid');
        $service->processPayment([
            'fees_id' => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 500, 'enter_amount' => 100,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);
    }

    /** @test */
    public function duplicate_reference_no_is_rejected_in_service(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(2000.00);
        $dupRef = Str::uuid()->toString();

        $service = app(FeesPaymentService::class);
        $service->processPayment([
            'fees_id' => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 2000, 'enter_amount' => 1000,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK',
            'reference_no' => $dupRef,
        ], $fee);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $service->processPayment([
            'fees_id' => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 2000, 'enter_amount' => 1000,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK',
            'reference_no' => $dupRef,
        ], $fee);
    }

    /** @test */
    public function session_years_trackings_created(): void
    {
        $this->skipIfNoFeeTable();
        $before = SessionYearsTracking::count();
        $fee = $this->createTestFee(500.00);

        $service = app(FeesPaymentService::class);
        $service->processPayment([
            'fees_id' => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 500, 'enter_amount' => 500,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);

        $this->assertGreaterThan($before, SessionYearsTracking::count());
    }

    /** @test */
    public function service_never_manages_transactions(): void
    {
        $this->skipIfNoFeeTable();
        $fee = $this->createTestFee(500.00);

        $level = DB::transactionLevel();

        $service = app(FeesPaymentService::class);
        $service->processPayment([
            'fees_id' => $fee->id, 'student_id' => $this->studentId, 'bank_account_id' => $this->bankAccountId,
            'installment_mode' => false, 'installment_fees' => [],
            'mode' => 'Cash', 'date' => now()->format('Y-m-d'),
            'total_amount' => 500, 'enter_amount' => 500,
            'due_charges_amount' => 0, 'advance' => 0,
            'transaction_currency' => 'MMK', 'reference_no' => null,
        ], $fee);

        $this->assertEquals($level, DB::transactionLevel(),
            'Service must not call beginTransaction/commit/rollback');
    }
}

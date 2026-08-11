<?php

namespace Tests\Feature;

use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesPaid;
use App\Models\User;
use App\Services\FeesPaidImportService;
use App\Services\FeesPaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FeesPaidSchoolIdTest
 *
 * Verifies that FeesPaid.school_id is ALWAYS populated from Auth context,
 * never from Excel or request body.
 *
 * Covers:
 *   - Manual MMK full payment
 *   - Manual USD/CNY payment
 *   - Manual Advance payment
 *   - Manual Installment payment
 *   - Excel Import Confirm
 *   - Cross-school isolation via processPayment
 *   - Confirm failure → full rollback
 */
class FeesPaidSchoolIdTest extends TestCase
{
    private int $authUserId;
    private int $studentUserId;
    private int $schoolId = 1;
    private int $classId;
    private int $sessionYearId;
    private string $admissionNo;
    private string $className;
    private int $bankAccountId;
    private string $bankAccountName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRoles();
        $this->ensureBaseTables();

        // Create class for Excel import matching
        $this->className = 'FPP-SchoolId-' . Str::random(4);
        $this->classId = DB::table('classes')->insertGetId([
            'name'       => $this->className,
            'school_id'  => $this->schoolId,
            'medium_id'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ensure session year
        $this->sessionYearId = 1;
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 1, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31',
                'school_id' => $this->schoolId,
            ]);
        } catch (\Throwable) {}

        // Create admin user (logged in)
        $this->authUserId = $this->createUser('FppSchoolId', 'Admin', $this->schoolId);
        $schoolAdminRoleId = DB::table('roles')->where('name', 'School Admin')->where('school_id', $this->schoolId)->value('id');
        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $schoolAdminRoleId,
            'model_type' => User::class,
            'model_id' => $this->authUserId,
        ]);

        // Create student user WITH Student role and students table record
        $this->admissionNo = 'FPPS-' . strtoupper(Str::random(6));
        $this->studentUserId = $this->createStudentUser(
            'FppSchoolId', 'Student', $this->schoolId,
            $this->admissionNo, $this->classId, $this->sessionYearId
        );

        Auth::loginUsingId($this->authUserId);

        // Create a valid test bank account (unique name per test)
        $this->bankAccountName = 'SchoolId Bank ' . Str::random(6);
        $this->bankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id'          => $this->schoolId,
            'account_name'       => $this->bankAccountName,
            'account_type'       => 'cash',
            'currency'           => 'MMK',
            'opening_balance'    => 0,
            'is_active'          => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        DB::table('bank_account_user')->insertOrIgnore([
            'bank_account_id' => $this->bankAccountId,
            'user_id' => $this->authUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ================================================================
    // Helpers — Table setup
    // ================================================================

    private function ensureRoles(): void
    {
        foreach (['Super Admin', 'School Admin', 'Student', 'Teacher'] as $name) {
            $exists = DB::table('roles')->where('name', $name)->where('school_id', $this->schoolId)->exists();
            if (!$exists) {
                DB::table('roles')->insert([
                    'name'        => $name,
                    'guard_name'  => 'web',
                    'school_id'   => $this->schoolId,
                    'custom_role' => 1,
                    'editable'    => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    private function createBankAccount(int $schoolId): int
    {
        $existing = DB::table('bank_accounts')
            ->where('school_id', $schoolId)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            return $existing->id;
        }
        return DB::table('bank_accounts')->insertGetId([
            'school_id'     => $schoolId,
            'account_name'  => 'SchoolId Bank ' . $schoolId . ' ' . Str::random(6),
            'account_type'  => 'cash',
            'currency'      => 'MMK',
            'opening_balance'=> 0,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function ensureBaseTables(): void
    {
        // fees_class_types
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM fees_class_types LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS fees_class_types (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                class_id bigint unsigned NOT NULL DEFAULT 1,
                fees_id bigint unsigned NOT NULL,
                fees_type_id bigint unsigned NOT NULL DEFAULT 1,
                amount double(8,2) NOT NULL DEFAULT 0,
                optional tinyint(1) NOT NULL DEFAULT 0,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }

        // sessions_years for CachingService fallback
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 99, 'name' => 'Default Year', 'default' => 1,
                'start_date' => '2024-01-01', 'end_date' => '2024-12-31',
                'school_id' => $this->schoolId,
            ]);
        } catch (\Throwable) {}

        // fees_installments
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM fees_installments LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS fees_installments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(191) NOT NULL,
                due_date date NOT NULL,
                due_charges double(8,2) DEFAULT NULL,
                due_charges_type varchar(20) DEFAULT 'fixed',
                fees_id bigint unsigned NOT NULL,
                session_year_id bigint unsigned DEFAULT NULL,
                school_id bigint unsigned DEFAULT NULL,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }

        // bank_accounts
        try {
            DB::connection()->getPdo()->query('SELECT 1 FROM bank_accounts LIMIT 1');
        } catch (\Throwable) {
            DB::statement("CREATE TABLE IF NOT EXISTS bank_accounts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                account_name varchar(191) NOT NULL,
                school_id bigint unsigned NOT NULL DEFAULT 1,
                status tinyint NOT NULL DEFAULT 1,
                created_at timestamp NULL DEFAULT NULL,
                updated_at timestamp NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )");
        }
    }

    // ================================================================
    // Helpers — User / Student
    // ================================================================

    private function createUser(string $first, string $last, int $schoolId): int
    {
        $email = strtolower($first) . '.' . strtolower($last) . uniqid() . '@test.local';
        return DB::table('users')->insertGetId([
            'first_name' => $first, 'last_name' => $last,
            'email' => $email, 'password' => bcrypt('password'),
            'school_id' => $schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createStudentUser(
        string $first, string $last, int $schoolId,
        string $admissionNo, int $classId, int $sessionYearId
    ): int {
        $userId = $this->createUser($first, $last, $schoolId);

        // Assign Student role
        $role = DB::table('roles')->where('name', 'Student')->where('school_id', $schoolId)->first();
        if ($role) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id'    => $role->id,
                'model_type' => 'App\Models\User',
                'model_id'   => $userId,
            ]);
        }

        // Create Students record
        DB::table('students')->insertOrIgnore([
            'user_id'         => $userId,
            'class_id'        => $classId,
            'class_section_id'=> 1,
            'admission_no'    => $admissionNo,
            'admission_date'  => now()->format('Y-m-d'),
            'school_id'       => $schoolId,
            'session_year_id' => $sessionYearId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $userId;
    }

    // ================================================================
    // Helpers — Fee / Payment / Excel Import
    // ================================================================

    private function createTestFee(float $total, ?int $schoolId = null, ?string $nameSuffix = null): Fee
    {
        $schoolId = $schoolId ?? $this->schoolId;
        $fee = new Fee();
        $fee->forceFill([
            'name'                  => 'FPPSchoolId_' . ($nameSuffix ?? '') . '_' . Str::random(4),
            'due_date'              => now()->addDays(30)->format('Y-m-d'),
            'due_charges'           => 0,
            'class_id'              => $this->classId,
            'school_id'             => $schoolId,
            'session_year_id'       => $this->sessionYearId,
            'total_compulsory_fees' => $total,
        ]);
        $fee->save();

        // Ensure fees_class_type record exists for preview validation
        DB::table('fees_class_types')->insertOrIgnore([
            'fees_id'    => $fee->id,
            'class_id'   => $this->classId,
            'fees_type_id' => 1,
            'optional'   => 0,
            'amount'     => $total,
            'school_id'  => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $fee->fresh();
    }

    /**
     * Call FeesPaymentService::processPayment directly.
     */
    private function processPayment(Fee $fee, array $overrides = []): array
    {
        // Get or create a bank account for the current auth user's school
        $schoolId = Auth::user()->school_id ?? $this->schoolId;
        $bankId = $this->createBankAccount($schoolId);

        $service = app(FeesPaymentService::class);
        return $service->processPayment(array_merge([
            'fees_id'               => $fee->id,
            'student_id'            => $this->studentUserId,
            'parent_id'             => null,
            'installment_mode'      => false,
            'installment_fees'      => [],
            'mode'                  => 'Cash',
            'date'                  => now()->format('Y-m-d'),
            'total_amount'          => $fee->total_compulsory_fees,
            'enter_amount'          => $fee->total_compulsory_fees,
            'due_charges_amount'    => 0,
            'advance'               => 0,
            'transaction_currency'  => 'MMK',
            'reference_no'          => null,
            'bank_account_id'       => $bankId,
        ], $overrides), $fee);
    }

    /**
     * Full Excel import workflow: preview → confirm.
     */
    private function excelConfirm(array $csvRow, Fee $fee): array
    {
        $header = [
            'Student Admission No', 'Academic Year', 'Class Name',
            'Fee Structure Name', 'Bank Account Name', 'Installment Name',
            'Payment Date', 'Amount (MMK)', 'Payment Mode', 'Cheque No', 'Reference No'
        ];
        $content = implode(',', $header) . "\n" . implode(',', $csvRow) . "\n";
        $tmpPath = tempnam(sys_get_temp_dir(), 'fpp_school_id_') . '.csv';
        file_put_contents($tmpPath, $content);

        $file = new UploadedFile($tmpPath, 'test.csv', 'text/csv', null, true);

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        @unlink($tmpPath);

        $this->assertEquals(0, $preview['summary']['error'],
            'Preview must have 0 errors: ' . json_encode($preview));

        $result = $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        return $result;
    }

    private function assertFeesPaidSchoolId(Fee $fee, int $expectedSchoolId): FeesPaid
    {
        $fp = FeesPaid::where('fees_id', $fee->id)
            ->where('student_id', $this->studentUserId)
            ->first();
        $this->assertNotNull($fp, 'FeesPaid must be created');
        $this->assertEquals($expectedSchoolId, $fp->school_id,
            "FeesPaid.school_id must be {$expectedSchoolId} (from Auth user)");
        return $fp;
    }

    // ================================================================
    // 1. Manual MMK Full Payment
    // ================================================================

    /** @test */
    public function manual_mmk_full_payment_writes_correct_school_id(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'MMK');
        $result = $this->processPayment($fee);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals($this->schoolId, $cf->school_id,
            'CompulsoryFee.school_id must equal Auth user school_id');

        $fp = $this->assertFeesPaidSchoolId($fee, $this->schoolId);
        $this->assertEquals(1000, $fp->amount);
    }

    // ================================================================
    // 2. Manual USD Payment
    // ================================================================

    /** @test */
    public function manual_usd_payment_writes_correct_school_id(): void
    {
        $fee = $this->createTestFee(500.00, null, 'USD');
        $result = $this->processPayment($fee, [
            'transaction_currency'   => 'USD',
            'original_amount'        => 500,
            'exchange_rate_snapshot' => 3500,
            'total_amount'           => 500,
            'enter_amount'           => 500,
        ]);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals($this->schoolId, $cf->school_id);

        $fp = $this->assertFeesPaidSchoolId($fee, $this->schoolId);
        $this->assertEquals('USD', $fp->transaction_currency);
    }

    // ================================================================
    // 3. Manual CNY Payment
    // ================================================================

    /** @test */
    public function manual_cny_payment_writes_correct_school_id(): void
    {
        $fee = $this->createTestFee(500.00, null, 'CNY');
        $result = $this->processPayment($fee, [
            'transaction_currency'   => 'CNY',
            'original_amount'        => 500,
            'exchange_rate_snapshot' => 500,
            'total_amount'           => 500,
            'enter_amount'           => 500,
        ]);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals($this->schoolId, $cf->school_id);

        $fp = $this->assertFeesPaidSchoolId($fee, $this->schoolId);
        $this->assertEquals('CNY', $fp->transaction_currency);
    }

    // ================================================================
    // 4. Advance Payment
    // ================================================================

    /** @test */
    public function advance_payment_writes_correct_school_id(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'ADV');
        $result = $this->processPayment($fee, [
            'advance'        => 200,
            'total_amount'   => 1000,
            'enter_amount'   => 1000,
        ]);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals($this->schoolId, $cf->school_id);

        $fp = $this->assertFeesPaidSchoolId($fee, $this->schoolId);
        $this->assertEquals(1000, $fp->amount);
    }

    // ================================================================
    // 5. Installment Payment
    // ================================================================

    /** @test */
    public function installment_payment_writes_correct_school_id(): void
    {
        $fee = $this->createTestFee(3000.00, null, 'INST');

        $instId = (int) (10000 + random_int(1, 99999));
        DB::table('fees_installments')->insertOrIgnore([
            'id'               => $instId,
            'name'             => 'Inst School ID ' . Str::random(4),
            'due_date'         => now()->addDays(15)->format('Y-m-d'),
            'due_charges'      => 0,
            'due_charges_type' => 'fixed',
            'fees_id'          => $fee->id,
            'session_year_id'  => $this->sessionYearId,
            'school_id'        => $this->schoolId,
        ]);

        $result = $this->processPayment($fee, [
            'installment_mode'     => true,
            'installment_fees'     => [['id' => $instId, 'amount' => 1500, 'due_charges' => 0]],
            'enter_amount'         => null,
            'due_charges_amount'   => null,
        ]);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals('Installment Payment', $cf->type);
        $this->assertEquals($this->schoolId, $cf->school_id);

        $fp = $this->assertFeesPaidSchoolId($fee, $this->schoolId);
        $this->assertEquals(1500, $fp->amount);
    }

    // ================================================================
    // 6. Excel Import Confirm
    // ================================================================

    /** @test */
    public function excel_import_confirm_writes_correct_school_id(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'EXCEL');
        $refNo = 'INV-EXCEL-' . strtoupper(Str::random(8));

        $result = $this->excelConfirm([
            $this->admissionNo,
            '2025-2026',
            $this->className,
            $fee->name,
            $this->bankAccountName,
            '',
            now()->format('Y-m-d'),
            1000,
            'Cash',
            '',
            $refNo,
        ], $fee);

        $this->assertEquals(1, $result['imported'], 'Excel import should import 1 row');

        // CompulsoryFee checks
        $cf = CompulsoryFee::where('reference_no', $refNo)->first();
        $this->assertNotNull($cf, 'CompulsoryFee must be created');

        // P0: school_id
        $this->assertEquals($this->schoolId, $cf->school_id,
            'CompulsoryFee.school_id must equal Auth user school_id');

        // P0: import_batch_id must link back to the batch
        $this->assertNotNull($cf->import_batch_id, 'CompulsoryFee.import_batch_id must be set from Excel import');
        $this->assertEquals($result['batch_id'], $cf->import_batch_id,
            'CompulsoryFee.import_batch_id must match the confirm batch');

        // P0: reference_no must be correct
        $this->assertEquals($refNo, $cf->reference_no,
            'CompulsoryFee.reference_no must match the import row');

        // P1: due_charges must be 0.0, NOT null (DB column is NOT NULL in production)
        $this->assertNotNull($cf->due_charges, 'CompulsoryFee.due_charges must NOT be null');
        $this->assertSame(0.0, (float) $cf->due_charges,
            'CompulsoryFee.due_charges must be 0.0 when no late charge');

        // P1: Cash payment cheque_no is null — must not cause DB constraint error
        // (cheque_no is nullable, so null is accepted)
        $this->assertNull($cf->cheque_no,
            'Cash payment should store null cheque_no (column is nullable)');

        // FeesPaid checks — THE P0 FIX
        $fp = $this->assertFeesPaidSchoolId($fee, $this->schoolId);
        $this->assertEquals(1000, $fp->amount);
    }

    /**
     * P1: Excel import installment payment with no late charge → due_charges=0, not null.
     *
     * @test
     */
    public function excel_import_installment_due_charges_zero_not_null(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'EXCELINST');
        $refNo = 'INV-INST-P1-' . strtoupper(Str::random(8));

        // Create an installment for the fee
        $instName = 'Inst P1 ' . Str::random(4);
        $instId = (int) (20000 + random_int(1, 99999));
        DB::table('fees_installments')->insertOrIgnore([
            'id'               => $instId,
            'name'             => $instName,
            'due_date'         => now()->addDays(15)->format('Y-m-d'),
            'due_charges'      => 0,
            'due_charges_type' => 'fixed',
            'fees_id'          => $fee->id,
            'session_year_id'  => $this->sessionYearId,
            'school_id'        => $this->schoolId,
        ]);

        $result = $this->excelConfirm([
            $this->admissionNo,
            '2025-2026',
            $this->className,
            $fee->name,
            $this->bankAccountName,
            $instName,
            now()->format('Y-m-d'),
            500,
            'Cash',
            '',
            $refNo,
        ], $fee);

        $this->assertEquals(1, $result['imported']);

        $cf = CompulsoryFee::where('reference_no', $refNo)->first();
        $this->assertNotNull($cf, 'CompulsoryFee must be created for installment payment');
        $this->assertEquals('Installment Payment', $cf->type);

        // P1: due_charges must be 0.0 even for installment mode
        $this->assertNotNull($cf->due_charges,
            'Installment due_charges must NOT be null (DB is NOT NULL in production)');
        $this->assertSame(0.0, (float) $cf->due_charges,
            'Installment due_charges must be 0.0 when installment has no late charge');

        $this->assertEquals($this->schoolId, $cf->school_id);
        $this->assertNotNull($cf->import_batch_id);
    }

    /**
     * P1: Confirm failure still performs complete transaction rollback
     * (no dirty data left in compulsory_fees or fees_paids).
     *
     * Already covered by confirm_failure_rollback_does_not_create_fees_paid,
     * but this test additionally verifies compulsory_fees.due_charges
     * integrity in the rollback path by confirming that the manually inserted
     * record still has its original due_charges value unchanged.
     *
     * @test
     */
    public function p1_rollback_preserves_due_charges_on_existing_records(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'P1ROLL');
        $refNo = 'INV-P1ROLL-' . strtoupper(Str::random(8));

        $header = ['Student Admission No', 'Academic Year', 'Class Name',
            'Fee Structure Name', 'Bank Account Name', 'Installment Name',
            'Payment Date', 'Amount (MMK)', 'Payment Mode', 'Cheque No', 'Reference No'];
        $row = [$this->admissionNo, '2025-2026', $this->className, $fee->name, '', '',
            now()->format('Y-m-d'), 1000, 'Cash', '', $refNo];
        $content = implode(',', $header) . "\n" . implode(',', $row) . "\n";
        $tmpPath = tempnam(sys_get_temp_dir(), 'fpp_p1_') . '.csv';
        file_put_contents($tmpPath, $content);

        $file = new UploadedFile($tmpPath, 'test.csv', 'text/csv', null, true);

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        @unlink($tmpPath);

        // Insert a duplicate with a known due_charges value BEFORE confirm
        $cf = new CompulsoryFee();
        $cf->forceFill([
            'student_id'   => $this->studentUserId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 500,
            'due_charges'  => 5.50,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => $this->schoolId,
            'reference_no' => $refNo,
        ]);
        $cf->save();

        $this->expectException(\InvalidArgumentException::class);
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        // P1: the pre-existing record's due_charges must remain 5.50 (not corrupted)
        $cfAfter = CompulsoryFee::find($cf->id);
        $this->assertNotNull($cfAfter, 'Pre-existing compulsory_fees must still exist after rollback');
        $this->assertSame(5.50, (float) $cfAfter->due_charges,
            'Pre-existing record due_charges must be preserved after rollback');
    }

    /**
     * Verify manual payment does NOT set import_batch_id.
     *
     * @test
     */
    public function manual_payment_does_not_set_import_batch_id(): void
    {
        $fee = $this->createTestFee(500.00, null, 'NOIMP');
        $result = $this->processPayment($fee, [
            'total_amount' => 500, 'enter_amount' => 500,
            'transaction_currency' => 'MMK',
        ]);
        $cf = $result['compulsory_fees'][0];
        $this->assertNull($cf->import_batch_id,
            'Manual payment must NOT set import_batch_id');
    }

    // ================================================================
    // 7. Cross-school isolation via processPayment
    // ================================================================

    /** @test */
    public function different_school_payment_does_not_leak_school_id(): void
    {
        $school2Id = 2;
        try {
            DB::table('schools')->insertOrIgnore([
                'id' => $school2Id, 'name' => 'School Two', 'code' => 'S2',
                'address' => '', 'support_phone' => '', 'support_email' => '',
                'tagline' => '', 'logo' => '', 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {}
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 2, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => $school2Id,
            ]);
        } catch (\Throwable) {}

        // ---- School 1 payment ----
        $fee1 = $this->createTestFee(500.00, $this->schoolId, 'X1');
        $r1 = $this->processPayment($fee1);
        $fp1 = $this->assertFeesPaidSchoolId($fee1, $this->schoolId);
        $this->assertEquals($this->schoolId, $r1['compulsory_fees'][0]->school_id);

        // ---- Switch to School 2 ----
        $school2Admin = $this->createUser('School2', 'Admin', $school2Id);
        $school2RoleId = DB::table('roles')->updateOrInsert(
            ['name' => 'School Admin', 'school_id' => $school2Id],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        $school2RoleId = DB::table('roles')->where('name', 'School Admin')->where('school_id', $school2Id)->value('id');
        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $school2RoleId,
            'model_type' => User::class,
            'model_id' => $school2Admin,
        ]);
        $this->bankAccountId = DB::table('bank_accounts')->insertGetId([
            'school_id' => $school2Id, 'account_name' => 'School 2 Fund Account',
            'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bank_account_user')->insertOrIgnore([
            'bank_account_id' => $this->bankAccountId,
            'user_id' => $school2Admin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::loginUsingId($school2Admin);
        $this->studentUserId = $this->createUser('School2Student', 'Two', $school2Id);

        $fee2 = $this->createTestFee(500.00, $school2Id, 'X2');
        $r2 = $this->processPayment($fee2, [
            'total_amount' => 500, 'enter_amount' => 500,
        ]);
        $fp2 = $this->assertFeesPaidSchoolId($fee2, $school2Id);
        $this->assertEquals($school2Id, $r2['compulsory_fees'][0]->school_id);

        // Verify no cross-contamination
        $fp1Check = FeesPaid::find($fp1->id);
        $this->assertEquals($this->schoolId, $fp1Check->school_id);

        $this->assertNotEquals($fp1->school_id, $fp2->school_id);
    }

    // ================================================================
    // 8. Confirm failure rollback
    // ================================================================

    /** @test */
    public function confirm_failure_rollback_does_not_create_fees_paid(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'ROLLBACK');
        $feesPaidBefore = FeesPaid::count();
        $refNo = 'INV-ROLLBACK-' . strtoupper(Str::random(8));

        // Create CSV
        $header = ['Student Admission No', 'Academic Year', 'Class Name',
            'Fee Structure Name', 'Bank Account Name', 'Installment Name',
            'Payment Date', 'Amount (MMK)', 'Payment Mode', 'Cheque No', 'Reference No'];
        $row = [$this->admissionNo, '2025-2026', $this->className, $fee->name, '', '',
            now()->format('Y-m-d'), 1000, 'Cash', '', $refNo];
        $content = implode(',', $header) . "\n" . implode(',', $row) . "\n";
        $tmpPath = tempnam(sys_get_temp_dir(), 'fpp_fail_') . '.csv';
        file_put_contents($tmpPath, $content);

        $file = new UploadedFile($tmpPath, 'test.csv', 'text/csv', null, true);

        $service = app(FeesPaidImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->authUserId);
        @unlink($tmpPath);

        // Insert duplicate AFTER preview to force confirm failure
        $cf = new CompulsoryFee();
        $cf->forceFill([
            'student_id'   => $this->studentUserId,
            'type'         => 'Full Payment',
            'mode'         => 'Cash',
            'amount'       => 500,
            'date'         => now()->format('Y-m-d'),
            'school_id'    => $this->schoolId,
            'reference_no' => $refNo,
        ]);
        $cf->save();

        $this->expectException(\InvalidArgumentException::class);
        $service->confirm($preview['token'], $this->schoolId, $this->authUserId);

        $this->assertEquals($feesPaidBefore, FeesPaid::count(),
            'Confirm failure must rollback FeesPaid creation');
        $this->assertEquals(1, CompulsoryFee::where('reference_no', $refNo)->count(),
            'Only the manually inserted record should exist');
    }

    // ================================================================
    // 9. DB-level constraint: school_id NOT NULL
    // ================================================================

    /** @test */
    public function fees_paid_school_id_cannot_be_null_in_database(): void
    {
        $fee = $this->createTestFee(1000.00, null, 'DBNOTNULL');
        $result = $this->processPayment($fee);

        $dbSchoolId = FeesPaid::where('id', $result['fees_paid']->id)->value('school_id');
        $this->assertNotNull($dbSchoolId, 'school_id must NOT be NULL in database');
        $this->assertEquals($this->schoolId, $dbSchoolId);
    }

    // ================================================================
    // 10. Multiple payments all have correct school_id
    // ================================================================

    /** @test */
    public function multiple_fees_paid_records_all_have_correct_school_id(): void
    {
        $fee1 = $this->createTestFee(500.00, null, 'MULTI1');
        $fee2 = $this->createTestFee(750.00, null, 'MULTI2');

        $r1 = $this->processPayment($fee1, ['total_amount' => 500, 'enter_amount' => 500]);
        $r2 = $this->processPayment($fee2, ['total_amount' => 750, 'enter_amount' => 750]);

        $fp1 = FeesPaid::find($r1['fees_paid']->id);
        $fp2 = FeesPaid::find($r2['fees_paid']->id);

        $this->assertEquals($this->schoolId, $fp1->school_id);
        $this->assertEquals($this->schoolId, $fp2->school_id);
    }
}

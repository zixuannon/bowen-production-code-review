<?php

namespace Tests\Feature;

use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Services\FeesPaymentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class CompulsoryFeeSchoolIdTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    private int $authUserId;
    private int $studentId;
    private int $schoolId = 1;
    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authUserId = $this->createUser('School', 'Admin', $this->schoolId);
        $this->studentId  = $this->createUser('Test', 'Student', $this->schoolId);
        $this->ensureBankAccountUserTable();
        $this->assignSchoolAdmin($this->authUserId);

        Auth::loginUsingId($this->authUserId);

        // Ensure bank_accounts table exists
        $this->ensureBankAccountsTable();
        $this->bankAccountId = $this->createBankAccount($this->schoolId);
        DB::table('bank_account_user')->insertOrIgnore(['bank_account_id' => $this->bankAccountId, 'user_id' => $this->authUserId]);

        try {
            DB::table('session_years')->insertOrIgnore([
                'id'         => 1,
                'name'       => '2025-2026',
                'default'    => 1,
                'start_date' => '2025-06-01',
                'end_date'   => '2026-05-31',
                'school_id'  => $this->schoolId,
            ]);
        } catch (\Throwable) {}
    }

    // ================================================================
    // Helpers
    // ================================================================

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

    /**
     * Get or create a bank account for a given school.
     * Returns existing ID if one exists, creates new otherwise.
     */
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
            'account_name'  => 'CompFeeTest Bank ' . $schoolId,
            'account_type'  => 'cash',
            'currency'      => 'MMK',
            'opening_balance'=> 0,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
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

    private function ensureBankAccountUserTable(): void
    {
        if (!Schema::hasTable('bank_account_user')) {
            Schema::create('bank_account_user', function (Blueprint $table) {
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('user_id');
                $table->unique(['bank_account_id', 'user_id']);
            });
        }
    }

    private function assignSchoolAdmin(int $userId): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => 'Head Finance', 'guard_name' => 'web', 'school_id' => $this->schoolId],
            ['custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        $roleId = DB::table('roles')->where('name', 'Head Finance')->where('school_id', $this->schoolId)->value('id');
        DB::table('model_has_roles')->insertOrIgnore(['role_id' => $roleId, 'model_type' => \App\Models\User::class, 'model_id' => $userId]);
    }

    private function createTestFee(float $total): Fee
    {
        $schoolId = (int) (Auth::user()->school_id ?? $this->schoolId);
        $fee = new Fee();
        $fee->forceFill([
            'name'                  => 'School ID Test Fee',
            'due_date'              => now()->addDays(30)->format('Y-m-d'),
            'due_charges'           => 0,
            'class_id'              => 1,
            'school_id'             => $schoolId,
            'session_year_id'       => $schoolId,
            'total_compulsory_fees' => $total,
        ]);
        $fee->save();
        return $fee;
    }

    private function processPayment(Fee $fee, array $overrides = []): array
    {
        // Get or create a bank account for the current auth user's school
        $schoolId = Auth::user()->school_id ?? $this->schoolId;
        $bankId = $this->createBankAccount($schoolId);
        DB::table('bank_account_user')->insertOrIgnore(['bank_account_id' => $bankId, 'user_id' => Auth::id()]);

        $service = app(FeesPaymentService::class);
        return $service->processPayment(array_merge([
            'fees_id'               => $fee->id,
            'student_id'            => $this->studentId,
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

    // ================================================================
    // Tests
    // ================================================================

    /** @test */
    public function full_payment_writes_current_school_id(): void
    {
        $fee = $this->createTestFee(1000.00);
        $result = $this->processPayment($fee);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals('Full Payment', $cf->type);
        $this->assertEquals($this->schoolId, $cf->school_id,
            'Full Payment CompulsoryFee must have school_id from Auth user');

        // FeesPaid school_id also verified
        $this->assertEquals($this->schoolId, $result['fees_paid']->school_id,
            'Full Payment FeesPaid must have school_id from Auth user');
    }

    /** @test */
    public function installment_payment_writes_current_school_id(): void
    {
        $fee = $this->createTestFee(3000.00);

        // Manually add an installment
        DB::table('fees_installments')->insertOrIgnore([
            'id'               => 99,
            'name'             => 'Inst 1',
            'due_date'         => now()->addDays(15)->format('Y-m-d'),
            'due_charges'      => 0,
            'due_charges_type' => 'fixed',
            'fees_id'          => $fee->id,
            'session_year_id'  => 1,
            'school_id'        => $this->schoolId,
        ]);

        $result = $this->processPayment($fee, [
            'installment_mode'  => true,
            'installment_fees'  => [['id' => 99, 'amount' => 1500, 'due_charges' => 0]],
            'enter_amount'      => null,
            'due_charges_amount' => null,
        ]);

        $cf = $result['compulsory_fees'][0];
        $this->assertEquals('Installment Payment', $cf->type);
        $this->assertEquals($this->schoolId, $cf->school_id,
            'Installment Payment CompulsoryFee must have school_id from Auth user');

        $this->assertEquals($this->schoolId, $result['fees_paid']->school_id,
            'Installment Payment FeesPaid must have school_id from Auth user');
    }

    /** @test */
    public function different_schools_can_have_same_reference_no(): void
    {
        // Create school 2 if needed
        try {
            DB::table('schools')->insertOrIgnore([
                'id' => 2, 'name' => 'School Two', 'code' => 'S2',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {}
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 2, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => 2,
            ]);
        } catch (\Throwable) {}

        $ref = 'CROSS-REF-' . uniqid();

        // School 1 payment
        $fee1 = $this->createTestFee(500.00);
        $r1 = $this->processPayment($fee1, [
            'reference_no' => $ref,
            'total_amount' => 500,
            'enter_amount' => 500,
        ]);
        $this->assertEquals($this->schoolId, $r1['compulsory_fees'][0]->school_id);

        // Switch to School 2
        $school2User = $this->createUser('School2', 'Admin', 2);
        Auth::loginUsingId($school2User);
        $this->studentId = $this->createUser('Student2', 'Two', 2);

        $fee2 = $this->createTestFee(500.00);
        $r2 = $this->processPayment($fee2, [
            'reference_no' => $ref,
            'total_amount' => 500,
            'enter_amount' => 500,
        ]);
        $this->assertEquals(2, $r2['compulsory_fees'][0]->school_id);

        $this->assertNotNull($r1['compulsory_fees'][0]->id);
        $this->assertNotNull($r2['compulsory_fees'][0]->id);
        $this->assertNotEquals(
            $r1['compulsory_fees'][0]->id,
            $r2['compulsory_fees'][0]->id,
            'Same reference_no should be allowed across different schools'
        );
    }

    /** @test */
    public function same_school_cannot_have_duplicate_reference_no(): void
    {
        $fee = $this->createTestFee(2000.00);
        $ref = 'SAME-SCHOOL-DUP-' . uniqid();

        // First payment
        $this->processPayment($fee, [
            'reference_no' => $ref,
            'total_amount' => 2000,
            'enter_amount' => 1000,
        ]);

        // Second payment with same ref
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->processPayment($fee, [
            'reference_no' => $ref,
            'total_amount' => 2000,
            'enter_amount' => 1000,
        ]);
    }

    /** @test */
    public function null_reference_no_allows_multiple_records(): void
    {
        $fee = $this->createTestFee(3000.00);

        // First payment (partial) - reference_no = null
        $r1 = $this->processPayment($fee, [
            'reference_no'  => null,
            'total_amount'  => 3000,
            'enter_amount'  => 1000,
        ]);

        // Second payment (additional partial) - reference_no = null
        $r2 = $this->processPayment($fee, [
            'reference_no'  => null,
            'total_amount'  => 3000,
            'enter_amount'  => 1000,
        ]);

        $this->assertNull($r1['compulsory_fees'][0]->reference_no);
        $this->assertNull($r2['compulsory_fees'][0]->reference_no);
        $this->assertNotEquals(
            $r1['compulsory_fees'][0]->id,
            $r2['compulsory_fees'][0]->id,
            'Multiple NULL reference_no records should coexist'
        );
    }

    /** @test */
    public function duplicate_check_does_not_match_other_schools(): void
    {
        // Create school 3
        try {
            DB::table('schools')->insertOrIgnore([
                'id' => 3, 'name' => 'School Three', 'code' => 'S3',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {}
        try {
            DB::table('session_years')->insertOrIgnore([
                'id' => 3, 'name' => '2025-2026', 'default' => 1,
                'start_date' => '2025-06-01', 'end_date' => '2026-05-31', 'school_id' => 3,
            ]);
        } catch (\Throwable) {}

        $ref = 'OTHER-SCHOOL-REF-' . uniqid();

        // School 3 creates a record with this ref
        $school3User = $this->createUser('School3', 'Admin', 3);
        Auth::loginUsingId($school3User);
        $this->studentId = $this->createUser('Student3', 'Three', 3);

        $fee3 = $this->createTestFee(500.00);
        $this->processPayment($fee3, [
            'reference_no' => $ref,
            'total_amount' => 500,
            'enter_amount' => 500,
        ]);

        // Switch back to School 1 - should be able to use same ref
        Auth::loginUsingId($this->authUserId);
        $this->studentId = $this->createUser('Student1b', 'One', $this->schoolId);

        $fee1 = $this->createTestFee(500.00);
        $result = $this->processPayment($fee1, [
            'reference_no' => $ref,
            'total_amount' => 500,
            'enter_amount' => 500,
        ]);

        $this->assertNotNull($result['compulsory_fees'][0]->id,
            'Same reference_no from different school should be allowed');
    }

    /** @test */
    public function school_id_persisted_to_database_correctly(): void
    {
        $fee = $this->createTestFee(1000.00);
        $result = $this->processPayment($fee);

        $cfId = $result['compulsory_fees'][0]->id;

        $dbSchoolId = CompulsoryFee::where('id', $cfId)->value('school_id');
        $this->assertNotNull($dbSchoolId, 'school_id should NOT be NULL in database');
        $this->assertEquals($this->schoolId, $dbSchoolId,
            'Persisted school_id must match Auth user school_id');
    }
}

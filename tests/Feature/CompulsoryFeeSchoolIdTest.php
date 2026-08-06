<?php

namespace Tests\Feature;

use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Services\FeesPaymentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompulsoryFeeSchoolIdTest extends TestCase
{
    private int $authUserId;
    private int $studentId;
    private int $schoolId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authUserId = $this->createUser('School', 'Admin', $this->schoolId);
        $this->studentId  = $this->createUser('Test', 'Student', $this->schoolId);

        Auth::loginUsingId($this->authUserId);

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

    private function createTestFee(float $total): Fee
    {
        $fee = new Fee();
        $fee->forceFill([
            'name'                  => 'School ID Test Fee',
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

    private function processPayment(Fee $fee, array $overrides = []): array
    {
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

<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\FeesPaid;
use App\Models\OptionalFee;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Services\OfflineFeePaymentAuthorityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class OfflineFeePaymentAuthorityTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $tenantDbAsDefault = true;
    protected $connectionsToTransact = ['mysql', 'school'];

    private int $schoolId;
    private int $studentUserId;
    private int $classId;
    private Fee $fee;
    private FeesClassType $compulsory;
    private FeesClassType $optional;

    protected function setUp(): void
    {
        parent::setUp();
        $classSection = DB::table('class_sections')->whereNull('deleted_at')->first();
        if (!$classSection) {
            $this->markTestSkipped('A local class section is required.');
        }
        $this->schoolId = (int) $classSection->school_id;
        $this->classId = (int) $classSection->class_id;
        $sessionYearId = (int) DB::table('session_years')->where('school_id', $this->schoolId)->value('id');
        $guardianId = (int) DB::table('users')->where('school_id', $this->schoolId)->value('id');
        $this->assertGreaterThan(0, $sessionYearId);
        $this->assertGreaterThan(0, $guardianId);

        $this->studentUserId = DB::table('users')->insertGetId([
            'first_name' => 'P0B',
            'last_name' => 'Student',
            'email' => Str::uuid().'@test.local',
            'password' => bcrypt('password'),
            'school_id' => $this->schoolId,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'user_id' => $this->studentUserId,
            'class_section_id' => $classSection->id,
            'admission_no' => 'P0B-'.Str::random(8),
            'admission_date' => now()->format('Y-m-d'),
            'school_id' => $this->schoolId,
            'guardian_id' => $guardianId,
            'session_year_id' => $sessionYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fee = Fee::query()->create([
            'name' => 'P0B authoritative fee',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'due_charges' => 0,
            'due_charges_amount' => 0,
            'class_id' => $this->classId,
            'school_id' => $this->schoolId,
            'session_year_id' => $sessionYearId,
        ]);
        $feeTypes = DB::table('fees_types')->where('school_id', $this->schoolId)->limit(2)->pluck('id');
        if ($feeTypes->count() < 2) {
            while ($feeTypes->count() < 2) {
                $feeTypes->push(DB::table('fees_types')->insertGetId([
                    'name' => 'P0B '.Str::random(8),
                    'school_id' => $this->schoolId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
        $this->compulsory = FeesClassType::query()->create($this->setupRow((int) $feeTypes[0], 1000, false));
        $this->optional = FeesClassType::query()->create($this->setupRow((int) $feeTypes[1], 250, true));
    }

    public function test_compulsory_amount_and_currency_are_rebuilt_from_fee_setup(): void
    {
        $result = app(OfflineFeePaymentAuthorityService::class)->compulsory($this->compulsoryInput(), $this->schoolId);

        $this->assertSame('1000.00', $result['data']['enter_amount']);
        $this->assertSame('1000.00', $result['data']['total_amount']);
        $this->assertSame('MMK', $result['data']['transaction_currency']);
        $this->assertSame('1000.00', $result['data']['original_amount']);
    }

    /** @dataProvider compulsoryTampering */
    public function test_compulsory_negative_zero_overpay_and_tamper_fail_closed(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OfflineFeePaymentAuthorityService::class)->compulsory(
            array_replace($this->compulsoryInput(), $changes),
            $this->schoolId
        );
    }

    public static function compulsoryTampering(): array
    {
        return [
            'negative' => [['enter_amount' => -1]],
            'zero' => [['enter_amount' => 0]],
            'overpayment' => [['enter_amount' => 1000.01]],
            'tampered configured total' => [['total_amount' => 1]],
            'currency mismatch' => [['transaction_currency' => 'USD']],
            'exchange-rate tamper' => [['exchange_rate_snapshot' => 2]],
        ];
    }

    public function test_optional_item_amount_and_total_are_rebuilt_from_fee_setup(): void
    {
        $result = app(OfflineFeePaymentAuthorityService::class)->optional($this->optionalInput(), $this->schoolId);

        $this->assertSame('250.00', $result['data']['total_amount']);
        $this->assertSame('250.00', $result['data']['fees_class_type'][0]['amount']);
        $this->assertSame($this->classId, $result['data']['class_id']);
        $this->assertSame('MMK', $result['data']['transaction_currency']);
    }

    public function test_optional_payment_replay_is_rejected_without_a_second_write(): void
    {
        $feesPaid = FeesPaid::query()->create([
            'date' => now()->format('Y-m-d'), 'is_fully_paid' => false,
            'is_used_installment' => false, 'fees_id' => $this->fee->id,
            'student_id' => $this->studentUserId, 'amount' => 250,
            'school_id' => $this->schoolId,
        ]);
        OptionalFee::query()->create([
            'student_id' => $this->studentUserId, 'class_id' => $this->classId,
            'fees_class_id' => $this->optional->id, 'mode' => 'Cash',
            'amount' => 250, 'fees_paid_id' => $feesPaid->id,
            'date' => now()->format('Y-m-d'), 'status' => 'Success',
            'school_id' => $this->schoolId,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already paid');
        app(OfflineFeePaymentAuthorityService::class)->optional($this->optionalInput(), $this->schoolId);
    }

    public function test_optional_payment_uses_confirmed_snapshot_without_replacing_the_fee_item_identity(): void
    {
        $studentId = (int) DB::table('students')->where('user_id', $this->studentUserId)->value('id');
        $assignment = StudentFeeAssignment::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $this->schoolId,
            'student_id' => $studentId,
            'academic_year_id' => $this->fee->session_year_id,
            'class_id' => $this->classId,
            'assignment_type' => StudentFeeAssignment::INITIAL,
            'status' => StudentFeeAssignment::CONFIRMED,
            'confirmed_at' => now(),
        ]);
        StudentFeeAssignmentItem::create([
            'uuid' => (string) Str::uuid(),
            'student_fee_assignment_id' => $assignment->id,
            'fee_id' => $this->fee->id,
            'fees_class_type_id' => $this->optional->id,
            'fees_type_id' => $this->optional->fees_type_id,
            'description_snapshot' => 'Historical optional item',
            'amount_snapshot' => 250,
            'currency_snapshot' => 'MMK',
            'exchange_rate_snapshot' => 1,
            'amount_mmk_snapshot' => 250,
            'optional_snapshot' => true,
            'source_type' => StudentFeeAssignmentItem::FEES_CLASS_TYPE,
            'source_id' => (string) $this->optional->id,
            'status' => StudentFeeAssignmentItem::ACTIVE,
        ]);
        DB::table('fees_class_types')->where('id', $this->optional->id)->update([
            'amount' => 999,
            'fee_original_amount' => 999,
            'fee_amount_mmk' => 999,
        ]);

        $result = app(OfflineFeePaymentAuthorityService::class)->optional($this->optionalInput(), $this->schoolId);

        $this->assertSame('250.00', $result['data']['total_amount']);
        $this->assertSame($this->optional->id, $result['data']['fees_class_type'][0]['id']);
        $this->assertSame('250.00', $result['data']['fees_class_type'][0]['amount']);
    }

    public function test_installment_id_amount_and_due_charge_are_rebuilt_from_fee_setup(): void
    {
        $installmentId = DB::table('fees_installments')->insertGetId([
            'name' => 'P0B installment',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'due_charges' => 0,
            'due_charges_type' => 'fixed',
            'installment_amount' => 1000,
            'fees_id' => $this->fee->id,
            'session_year_id' => $this->fee->session_year_id,
            'school_id' => $this->schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $input = $this->compulsoryInput();
        $input['installment_mode'] = true;
        $input['installment_fees'] = [['id' => $installmentId, 'amount' => 1000, 'due_charges' => 0]];
        $input['enter_amount'] = null;

        $result = app(OfflineFeePaymentAuthorityService::class)->compulsory($input, $this->schoolId);
        $this->assertSame('1000.00', $result['data']['installment_fees'][0]['amount']);
        $this->assertSame('0.00', $result['data']['installment_fees'][0]['due_charges']);

        $input['installment_fees'][0]['amount'] = 999;
        $this->expectException(InvalidArgumentException::class);
        app(OfflineFeePaymentAuthorityService::class)->compulsory($input, $this->schoolId);
    }

    /** @dataProvider optionalTampering */
    public function test_optional_amount_tampering_fails_closed(string $field, mixed $value): void
    {
        $input = $this->optionalInput();
        if ($field === 'item') {
            $input['fees_class_type'][0]['amount'] = $value;
        } else {
            $input[$field] = $value;
        }

        $this->expectException(InvalidArgumentException::class);
        app(OfflineFeePaymentAuthorityService::class)->optional($input, $this->schoolId);
    }

    public static function optionalTampering(): array
    {
        return [
            'item zero' => ['item', 0],
            'item negative' => ['item', -1],
            'item overpay' => ['item', 251],
            'total tamper' => ['total_amount', 249],
            'currency mismatch' => ['transaction_currency', 'CNY'],
        ];
    }

    private function setupRow(int $feeTypeId, float $amount, bool $optional): array
    {
        return [
            'class_id' => $this->classId,
            'fees_id' => $this->fee->id,
            'fees_type_id' => $feeTypeId,
            'amount' => $amount,
            'optional' => $optional,
            'school_id' => $this->schoolId,
            'fee_currency' => 'MMK',
            'fee_original_amount' => $amount,
            'fee_exchange_rate_snapshot' => 1,
            'fee_amount_mmk' => $amount,
        ];
    }

    private function compulsoryInput(): array
    {
        return [
            'fees_id' => $this->fee->id,
            'student_id' => $this->studentUserId,
            'installment_mode' => false,
            'installment_fees' => [],
            'total_amount' => 1000,
            'enter_amount' => 1000,
            'due_charges_amount' => 0,
            'advance' => 0,
            'transaction_currency' => 'MMK',
            'original_amount' => 1000,
            'exchange_rate_snapshot' => 1,
        ];
    }

    private function optionalInput(): array
    {
        return [
            'fees_id' => $this->fee->id,
            'student_id' => $this->studentUserId,
            'class_id' => $this->classId,
            'fees_class_type' => [['id' => $this->optional->id, 'amount' => 250]],
            'total_amount' => 250,
            'transaction_currency' => 'MMK',
            'original_amount' => 250,
            'exchange_rate_snapshot' => 1,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\FeesPaid;
use App\Models\User;
use App\Services\FeesPaymentService;
use App\Services\OfflineFeePaymentAuthorityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyPaymentConcurrencyTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    public function test_concurrent_full_payment_replay_creates_one_payment_and_one_ledger_row(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the database race test.');
        }

        $section = DB::table('class_sections')->whereNull('deleted_at')->first();
        $this->assertNotNull($section, 'Local test database needs one class section.');
        $schoolId = (int) $section->school_id;
        $sessionYearId = (int) DB::table('session_years')->where('school_id', $schoolId)->value('id');
        $guardianId = (int) DB::table('users')->where('school_id', $schoolId)->value('id');
        $prefix = 'P1A-PAY-'.Str::uuid();

        $actor = $this->user($schoolId, 'Head Finance');
        $studentId = DB::table('users')->insertGetId([
            'first_name' => 'P1A', 'last_name' => 'Student', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('local-only'), 'school_id' => $schoolId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'user_id' => $studentId, 'class_section_id' => $section->id,
            'admission_no' => $prefix, 'admission_date' => '2026-09-11',
            'school_id' => $schoolId, 'guardian_id' => $guardianId,
            'session_year_id' => $sessionYearId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fee = Fee::query()->create([
            'name' => $prefix, 'due_date' => now()->addDay()->format('Y-m-d'),
            'due_charges' => 0, 'due_charges_amount' => 0,
            'class_id' => $section->class_id, 'school_id' => $schoolId,
            'session_year_id' => $sessionYearId,
        ]);
        $feeTypeId = (int) DB::table('fees_types')->where('school_id', $schoolId)->value('id');
        $createdFeeType = false;
        if ($feeTypeId < 1) {
            $feeTypeId = DB::table('fees_types')->insertGetId([
                'name' => $prefix, 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $createdFeeType = true;
        }
        FeesClassType::query()->create([
            'class_id' => $section->class_id, 'fees_id' => $fee->id, 'fees_type_id' => $feeTypeId,
            'amount' => 1000, 'optional' => false, 'school_id' => $schoolId,
            'fee_currency' => 'MMK', 'fee_original_amount' => 1000,
            'fee_exchange_rate_snapshot' => 1, 'fee_amount_mmk' => 1000,
        ]);
        $account = BankAccount::query()->create([
            'school_id' => $schoolId, 'account_name' => $prefix, 'account_type' => 'cash',
            'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true,
        ]);
        $input = [
            'fees_id' => $fee->id, 'student_id' => $studentId, 'installment_mode' => false,
            'installment_fees' => [], 'total_amount' => 1000, 'enter_amount' => 1000,
            'due_charges_amount' => 0, 'advance' => 0, 'transaction_currency' => 'MMK',
            'original_amount' => 1000, 'exchange_rate_snapshot' => 1,
            'bank_account_id' => $account->id, 'mode' => 'Cash', 'date' => '2026-09-11',
        ];

        try {
            DB::disconnect('school');
            DB::disconnect('mysql');
            $children = [];
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        DB::purge('school');
                        DB::purge('mysql');
                        DB::setDefaultConnection('school');
                        session(['db_connection_name' => 'school']);
                        Auth::login(User::query()->findOrFail($actor->id));
                        usleep(200000);
                        DB::connection('school')->transaction(function () use ($input, $schoolId, $actor): void {
                            $authorized = app(OfflineFeePaymentAuthorityService::class)->compulsory($input, $schoolId);
                            usleep(300000);
                            app(FeesPaymentService::class)->processPayment($authorized['data'], $authorized['fee'], $actor);
                        });
                        exit(0);
                    } catch (InvalidArgumentException) {
                        exit(2);
                    } catch (\Throwable $exception) {
                        fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            $statuses = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $statuses[] = pcntl_wexitstatus($status);
            }
            sort($statuses);
            $this->assertSame([0, 2], $statuses);
            DB::purge('school');
            DB::setDefaultConnection('school');
            $feesPaid = FeesPaid::withTrashed()->where('fees_id', $fee->id)->where('student_id', $studentId)->get();
            $this->assertCount(1, $feesPaid);
            $this->assertCount(1, CompulsoryFee::withTrashed()->where('fees_paid_id', $feesPaid->first()->id)->get());
        } finally {
            DB::purge('school');
            DB::setDefaultConnection('school');
            $feesPaidIds = FeesPaid::withTrashed()->where('fees_id', $fee->id)->where('student_id', $studentId)->pluck('id');
            $compulsoryIds = CompulsoryFee::withTrashed()->whereIn('fees_paid_id', $feesPaidIds)->pluck('id');
            DB::table('session_years_trackings')->where('modal_type', CompulsoryFee::class)->whereIn('modal_id', $compulsoryIds)->delete();
            CompulsoryFee::withTrashed()->whereIn('id', $compulsoryIds)->forceDelete();
            DB::table('fee_payment_fx_snapshots')->whereIn('fees_paid_id', $feesPaidIds)->delete();
            FeesPaid::withTrashed()->whereIn('id', $feesPaidIds)->forceDelete();
            FeesClassType::query()->where('fees_id', $fee->id)->delete();
            Fee::withTrashed()->whereKey($fee->id)->forceDelete();
            BankAccount::withTrashed()->whereKey($account->id)->forceDelete();
            DB::table('students')->where('user_id', $studentId)->delete();
            DB::table('model_has_roles')->where('model_id', $actor->id)->where('model_type', User::class)->delete();
            User::query()->whereIn('id', [$studentId, $actor->id])->forceDelete();
            if ($createdFeeType) {
                DB::table('fees_types')->where('id', $feeTypeId)->delete();
            }
        }
    }

    private function user(int $schoolId, string $role): User
    {
        $user = User::query()->create([
            'first_name' => 'P1A', 'last_name' => $role, 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('local-only'), 'school_id' => $schoolId, 'status' => 1,
        ]);
        DB::table('roles')->updateOrInsert(
            ['name' => $role, 'school_id' => $schoolId],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', $role)->where('school_id', $schoolId)->value('id'),
            'model_id' => $user->id, 'model_type' => User::class,
        ]);
        return $user;
    }
}

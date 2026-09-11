<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Services\FundAccountBalanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Round4FinancialCurrencyIntegrityTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;
    use DatabaseTransactions;
    protected $connectionsToTransact = ['mysql', 'school'];

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('session_years')->insertOrIgnore([
            'id' => 1, 'name' => '2026', 'default' => 1, 'start_date' => '2026-01-01',
            'end_date' => '2026-12-31', 'school_id' => 1,
        ]);
        $this->userId = DB::table('users')->insertGetId([
            'first_name' => 'Round4', 'last_name' => 'Student', 'email' => 'round4.'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'school_id' => 1, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_balance_counts_only_success_and_uses_account_currency_amount(): void
    {
        $mmk = $this->account('MMK', 100);
        $this->fee($mmk, 'Success', 50, 'MMK', 50);
        $this->fee($mmk, 'Pending', 900, 'MMK', 900);
        $this->fee($mmk, 'Failed', 800, 'MMK', 800);
        $this->assertSame(150.0, app(FundAccountBalanceService::class)->currentBalance($mmk));

        $usd = $this->account('USD', 10);
        $this->fee($usd, 'Success', 30000, 'USD', 10, 3000);
        $this->assertSame(20.0, app(FundAccountBalanceService::class)->currentBalance($usd));
    }

    public function test_foreign_account_with_legacy_unsnapshotted_history_fails_closed(): void
    {
        $usd = $this->account('USD', 0);
        $this->fee($usd, 'Success', 30000, null, null, null);
        $this->expectException(\DomainException::class);
        app(FundAccountBalanceService::class)->currentBalance($usd);
    }

    public function test_fund_account_currency_is_immutable_after_history_exists(): void
    {
        $account = $this->account('MMK', 0);
        $this->fee($account, 'Success', 10, 'MMK', 10);
        $this->expectException(\DomainException::class);
        $account->update(['currency' => 'USD']);
    }

    public function test_empty_account_can_set_initial_currency_and_opening_balance_together(): void
    {
        $account = $this->account('MMK', 0);

        $account->update(['currency' => 'USD', 'opening_balance' => 10]);

        $this->assertSame('USD', $account->fresh()->currency);
        $this->assertSame(10.0, (float) $account->fresh()->opening_balance);
    }

    public function test_expense_fx_history_is_immutable(): void
    {
        $account = $this->account('USD', 0);
        $expense = Expense::create([
            'school_id' => 1, 'bank_account_id' => $account->id, 'title' => 'Immutable FX',
            'amount' => 30000, 'amount_mmk' => 30000, 'transaction_currency' => 'USD',
            'original_amount' => 10, 'exchange_rate_snapshot' => 3000, 'date' => '2026-09-11',
            'session_year_id' => 1,
        ]);
        $this->expectException(\DomainException::class);
        $expense->update(['exchange_rate_snapshot' => 3100]);
    }

    public function test_confirmed_receivable_snapshot_requires_explicit_adjustment(): void
    {
        $assignment = StudentFeeAssignment::create([
            'uuid' => (string) Str::uuid(), 'school_id' => 1, 'student_id' => 999001,
            'academic_year_id' => 1, 'class_id' => 1, 'assignment_type' => StudentFeeAssignment::INITIAL,
            'status' => StudentFeeAssignment::CONFIRMED, 'confirmed_at' => now(),
        ]);
        $item = StudentFeeAssignmentItem::create([
            'uuid' => (string) Str::uuid(), 'student_fee_assignment_id' => $assignment->id,
            'description_snapshot' => 'Historical tuition', 'amount_snapshot' => 10,
            'currency_snapshot' => 'USD', 'exchange_rate_snapshot' => 3000,
            'amount_mmk_snapshot' => 30000, 'optional_snapshot' => false,
            'source_type' => StudentFeeAssignmentItem::FEES_CLASS_TYPE, 'source_id' => '991',
            'status' => StudentFeeAssignmentItem::ACTIVE,
        ]);
        $this->expectException(\DomainException::class);
        $item->update(['amount_snapshot' => 11]);
    }

    public function test_confirmed_receivable_parent_cannot_be_cancelled_or_hidden(): void
    {
        $assignment = StudentFeeAssignment::create([
            'uuid' => (string) Str::uuid(), 'school_id' => 1, 'student_id' => 999002,
            'academic_year_id' => 1, 'class_id' => 1, 'assignment_type' => StudentFeeAssignment::INITIAL,
            'status' => StudentFeeAssignment::CONFIRMED, 'confirmed_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $assignment->update(['status' => StudentFeeAssignment::CANCELLED]);
    }

    public function test_confirmed_receivable_parent_cannot_be_deleted(): void
    {
        $assignment = StudentFeeAssignment::create([
            'uuid' => (string) Str::uuid(), 'school_id' => 1, 'student_id' => 999003,
            'academic_year_id' => 1, 'class_id' => 1, 'assignment_type' => StudentFeeAssignment::INITIAL,
            'status' => StudentFeeAssignment::CONFIRMED, 'confirmed_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $assignment->delete();
    }

    private function account(string $currency, float $opening): BankAccount
    {
        return BankAccount::create([
            'school_id' => 1, 'account_name' => 'Round4 '.$currency.' '.uniqid(), 'account_type' => 'cash',
            'currency' => $currency, 'opening_balance' => $opening, 'is_active' => true,
        ]);
    }

    private function fee(BankAccount $account, string $status, float $amount, ?string $currency, ?float $original, ?float $rate = 1): CompulsoryFee
    {
        return CompulsoryFee::create([
            'student_id' => $this->userId, 'type' => 'Full Payment', 'mode' => 'Cash',
            'amount' => $amount, 'amount_mmk' => $currency === null ? null : $amount,
            'transaction_currency' => $currency, 'original_amount' => $original,
            'exchange_rate_snapshot' => $rate, 'status' => $status, 'date' => '2026-09-11',
            'school_id' => 1, 'bank_account_id' => $account->id,
        ]);
    }
}

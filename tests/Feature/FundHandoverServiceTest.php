<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\FundHandover;
use App\Models\User;
use App\Services\FundAccountBalanceService;
use App\Services\FundHandoverService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class FundHandoverServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['school'];

    private User $head;
    private User $schoolAdmin;
    private User $cashierA;
    private User $cashierB;
    private BankAccount $headAccount;
    private BankAccount $cashierAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureHandoverTable();
        $this->ensurePivotTable();
        $this->head = $this->user('head', 1, 'Head Finance');
        $this->schoolAdmin = $this->user('school-admin', 1, 'School Admin');
        $this->cashierA = $this->user('cashier-a', 1, 'Cashier');
        $this->cashierB = $this->user('cashier-b', 1, 'Cashier');
        $this->headAccount = $this->account('Head source', 1, 1000);
        $this->cashierAccount = $this->account('Cashier destination', 1, 100);
        $this->cashierA->authorized_bank_accounts()->sync([$this->cashierAccount->id]);
    }

    public function test_pending_handover_has_no_balance_or_ledger_effect_until_receiver_confirms(): void
    {
        $service = app(FundHandoverService::class);
        $balances = app(FundAccountBalanceService::class);
        $fromBefore = $balances->currentBalance($this->headAccount);
        $toBefore = $balances->currentBalance($this->cashierAccount);

        $transferCount = BankTransfer::where('school_id', 1)->count();
        $handover = $service->create($this->head, $this->payload($this->cashierA));

        $this->assertSame(FundHandover::STATUS_PENDING, $handover->status);
        $this->assertNull($handover->bank_transfer_id);
        $this->assertSame($transferCount, BankTransfer::where('school_id', 1)->count());
        $this->assertSame($fromBefore, $balances->currentBalance($this->headAccount));
        $this->assertSame($toBefore, $balances->currentBalance($this->cashierAccount));
    }

    public function test_school_admin_has_read_only_oversight_but_is_not_a_handover_participant(): void
    {
        $service = app(FundHandoverService::class);

        $this->assertTrue($service->canViewRegister($this->schoolAdmin));
        $this->assertFalse($service->isParticipant($this->schoolAdmin));

        $this->expectException(AccessDeniedHttpException::class);
        $service->assertParticipant($this->schoolAdmin);
    }

    public function test_participant_roles_are_authorized_without_generic_expense_permissions(): void
    {
        $service = app(FundHandoverService::class);

        // Fund Handover must use the dedicated custody roles, not generic
        // Expense permissions that a valid Head Finance/Cashier may not hold.
        $this->assertFalse($this->head->can('expense-create'));
        $this->assertFalse($this->cashierA->can('expense-list'));
        $this->assertTrue($service->canViewRegister($this->head));
        $this->assertTrue($service->canViewRegister($this->cashierA));
        $this->assertTrue($service->isParticipant($this->head));
        $this->assertTrue($service->isParticipant($this->cashierA));
    }

    public function test_receiver_confirmation_atomically_creates_one_completed_transfer_and_updates_balances_once(): void
    {
        $service = app(FundHandoverService::class);
        $balances = app(FundAccountBalanceService::class);
        $fromBefore = $balances->currentBalance($this->headAccount);
        $toBefore = $balances->currentBalance($this->cashierAccount);
        $transferCount = BankTransfer::where('school_id', 1)->count();
        $handover = $service->create($this->head, $this->payload($this->cashierA, 125));
        $confirmed = $service->confirm($this->cashierA, $handover->id);

        $this->assertSame(FundHandover::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame($this->cashierA->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertNotNull($confirmed->bank_transfer_id);
        $this->assertSame(1, BankTransfer::whereKey($confirmed->bank_transfer_id)->completed()->count());
        $this->assertSame($transferCount + 1, BankTransfer::where('school_id', 1)->count());
        $this->assertEqualsWithDelta($fromBefore - 125, $balances->currentBalance($this->headAccount), 0.001);
        $this->assertEqualsWithDelta($toBefore + 125, $balances->currentBalance($this->cashierAccount), 0.001);

        $this->expectException(\DomainException::class);
        $service->confirm($this->cashierA, $handover->id);
    }

    public function test_cashier_can_send_to_head_finance_but_cashier_to_cashier_and_cross_school_are_rejected(): void
    {
        $service = app(FundHandoverService::class);
        $cashToHead = $service->create($this->cashierA, $this->payload($this->head, 25, $this->cashierAccount, $this->headAccount));
        $this->assertSame(FundHandover::STATUS_PENDING, $cashToHead->status);

        try {
            $service->create($this->cashierA, $this->payload($this->cashierB, 25, $this->cashierAccount, $this->cashierAccount));
            $this->fail('Cashier-to-Cashier handovers must be rejected.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue(true);
        }

        $foreign = $this->user('foreign-head', 2, 'Head Finance');
        try {
            $service->create($this->cashierA, $this->payload($foreign, 25, $this->cashierAccount, $this->headAccount));
            $this->fail('Cross-school handovers must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_rejection_and_cancellation_require_the_correct_party_and_audit_reason_without_transfer(): void
    {
        $service = app(FundHandoverService::class);
        $transferCount = BankTransfer::where('school_id', 1)->count();
        $rejected = $service->create($this->head, $this->payload($this->cashierA));
        $result = $service->reject($this->cashierA, $rejected->id, 'QA receiver cannot accept this custody handover');
        $this->assertSame(FundHandover::STATUS_REJECTED, $result->status);
        $this->assertSame($this->cashierA->id, $result->rejected_by);
        $this->assertSame('QA receiver cannot accept this custody handover', $result->rejection_reason);

        $cancelled = $service->create($this->head, $this->payload($this->cashierA));
        $result = $service->cancel($this->head, $cancelled->id, 'QA sender cancellation reason');
        $this->assertSame(FundHandover::STATUS_CANCELLED, $result->status);
        $this->assertSame($this->head->id, $result->cancelled_by);
        $this->assertSame('QA sender cancellation reason', $result->cancellation_reason);
        $this->assertSame($transferCount, BankTransfer::where('school_id', 1)->count());
    }

    public function test_confirmation_rechecks_balance_and_does_not_create_a_transfer_when_balance_was_spent_after_request(): void
    {
        $service = app(FundHandoverService::class);
        $handover = $service->create($this->head, $this->payload($this->cashierA, 900));
        BankTransfer::create([
            'school_id' => 1, 'from_account_id' => $this->headAccount->id, 'to_account_id' => $this->cashierAccount->id,
            'amount' => 200, 'transfer_date' => '2026-01-02', 'status' => 'completed', 'created_by' => $this->head->id,
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance');
        $service->confirm($this->cashierA, $handover->id);
    }

    private function payload(User $receiver, float $amount = 100, ?BankAccount $from = null, ?BankAccount $to = null): array
    {
        return ['receiver_id' => $receiver->id, 'from_account_id' => ($from ?? $this->headAccount)->id, 'to_account_id' => ($to ?? $this->cashierAccount)->id, 'amount' => $amount, 'handover_date' => '2026-01-02', 'reference_no' => 'P3-' . uniqid(), 'notes' => 'Synthetic P3 handover'];
    }

    private function user(string $label, int $schoolId, string $role): User
    {
        $user = User::create(['first_name' => 'P3', 'last_name' => $label, 'email' => uniqid($label, true) . '@test.local', 'password' => bcrypt('local-only'), 'school_id' => $schoolId, 'status' => 1]);
        DB::table('roles')->updateOrInsert(['name' => $role, 'school_id' => $schoolId], ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->where('name', $role)->where('school_id', $schoolId)->value('id');
        DB::table('model_has_roles')->updateOrInsert(['role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class], []);
        return $user;
    }

    private function account(string $name, int $schoolId, float $opening): BankAccount
    {
        return BankAccount::create(['school_id' => $schoolId, 'account_name' => $name, 'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => $opening, 'is_active' => true]);
    }

    private function ensurePivotTable(): void
    {
        if (!Schema::hasTable('bank_account_user')) {
            Schema::create('bank_account_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['bank_account_id', 'user_id']);
            });
        }
    }

    private function ensureHandoverTable(): void
    {
        if (!Schema::hasTable('fund_handovers')) {
            Schema::create('fund_handovers', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('from_account_id'); $table->unsignedBigInteger('to_account_id'); $table->unsignedBigInteger('sender_id'); $table->unsignedBigInteger('receiver_id'); $table->decimal('amount', 14, 2); $table->date('handover_date'); $table->string('reference_no', 100)->nullable(); $table->text('notes')->nullable(); $table->string('status', 20); $table->unsignedBigInteger('bank_transfer_id')->nullable(); $table->timestamp('confirmed_at')->nullable(); $table->unsignedBigInteger('confirmed_by')->nullable(); $table->timestamp('rejected_at')->nullable(); $table->unsignedBigInteger('rejected_by')->nullable(); $table->text('rejection_reason')->nullable(); $table->timestamp('cancelled_at')->nullable(); $table->unsignedBigInteger('cancelled_by')->nullable(); $table->text('cancellation_reason')->nullable(); $table->timestamps();
            });
        }
    }
}

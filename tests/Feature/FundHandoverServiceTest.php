<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\FundHandover;
use App\Models\Expense;
use App\Models\OtherIncome;
use App\Models\User;
use App\Services\FundAccountBalanceService;
use App\Services\FundHandoverService;
use App\Services\FinanceTransactionRegisterService;
use App\Services\OtherIncomeService;
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
        $this->ensureOtherIncomesTable();
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

    public function test_unified_register_counts_source_records_once_and_excludes_pending_handover(): void
    {
        $register = app(FinanceTransactionRegisterService::class);
        $before = $register->register($this->head);
        $beforeTransferRows = $before['rows']->where('transaction_type', 'bank_transfer')->count();
        OtherIncome::create(['school_id' => 1, 'bank_account_id' => $this->headAccount->id, 'date' => '2026-01-02', 'payer' => 'QA donor', 'description' => 'QA receipt', 'amount' => 200, 'payment_method' => 'Cash', 'reference_no' => 'OI-REGISTER', 'created_by' => $this->head->id]);
        Expense::create(['school_id' => 1, 'bank_account_id' => $this->headAccount->id, 'date' => '2026-01-02', 'title' => 'QA expense', 'amount' => 50, 'created_by' => $this->head->id]);
        $transfer = BankTransfer::create(['school_id' => 1, 'from_account_id' => $this->headAccount->id, 'to_account_id' => $this->cashierAccount->id, 'amount' => 100, 'transfer_date' => '2026-01-02', 'reference_no' => 'TR-REGISTER', 'status' => 'completed', 'created_by' => $this->head->id]);
        FundHandover::create(['school_id' => 1, 'from_account_id' => $this->headAccount->id, 'to_account_id' => $this->cashierAccount->id, 'sender_id' => $this->head->id, 'receiver_id' => $this->cashierA->id, 'amount' => 100, 'handover_date' => '2026-01-02', 'status' => FundHandover::STATUS_CONFIRMED, 'bank_transfer_id' => $transfer->id]);
        FundHandover::create(['school_id' => 1, 'from_account_id' => $this->headAccount->id, 'to_account_id' => $this->cashierAccount->id, 'sender_id' => $this->head->id, 'receiver_id' => $this->cashierA->id, 'amount' => 99, 'handover_date' => '2026-01-02', 'status' => FundHandover::STATUS_PENDING]);

        $result = $register->register($this->head);
        $this->assertSame(200.0, $result['summary']['operating_income'] - $before['summary']['operating_income']);
        $this->assertSame(50.0, $result['summary']['operating_expense'] - $before['summary']['operating_expense']);
        $this->assertSame(100.0, $result['summary']['internal_in'] - $before['summary']['internal_in']);
        $this->assertSame(100.0, $result['summary']['internal_out'] - $before['summary']['internal_out']);
        $this->assertSame(150.0, $result['summary']['operating_net'] - $before['summary']['operating_net']);
        $this->assertSame(150.0, $result['summary']['net_movement'] - $before['summary']['net_movement']);
        $this->assertSame($beforeTransferRows + 1, $result['rows']->where('transaction_type', 'bank_transfer')->count());
    }

    public function test_receive_money_reuses_account_scope_and_reference_reservation(): void
    {
        $data = ['bank_account_id' => $this->cashierAccount->id, 'date' => '2026-01-03', 'payer' => 'QA source', 'description' => 'QA non-fee receipt', 'amount' => 75, 'payment_method' => 'Cash', 'reference_no' => 'OI-' . uniqid()];
        $income = app(OtherIncomeService::class)->receive($this->cashierA, $data);
        $this->assertSame($this->cashierA->id, $income->created_by);
        $this->assertEqualsWithDelta(175.0, app(FundAccountBalanceService::class)->currentBalance($this->cashierAccount), 0.001);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OtherIncomeService::class)->receive($this->cashierA, $data);
    }

    public function test_receive_money_rejects_missing_or_unassigned_fund_account_and_forged_filter(): void
    {
        $service = app(OtherIncomeService::class);
        $data = ['date' => '2026-01-03', 'payer' => 'QA', 'description' => 'QA', 'amount' => 1, 'payment_method' => 'Cash'];
        try { $service->receive($this->cashierA, $data); $this->fail('A fund account is mandatory.'); }
        catch (\Illuminate\Validation\ValidationException) { $this->assertTrue(true); }
        $data['bank_account_id'] = $this->headAccount->id;
        try { $service->receive($this->cashierA, $data); $this->fail('An unassigned fund account must be rejected.'); }
        catch (ModelNotFoundException) { $this->assertTrue(true); }
        $this->expectException(ModelNotFoundException::class);
        app(FinanceTransactionRegisterService::class)->register($this->cashierA, ['bank_account_id' => $this->headAccount->id]);
    }

    public function test_transaction_register_and_receive_money_enforce_cashier_scope_cross_school_and_active_account_rules(): void
    {
        $cashReference = 'OI-CASH-' . uniqid();
        $headReference = 'OI-HEAD-' . uniqid();
        OtherIncome::create(['school_id' => 1, 'bank_account_id' => $this->cashierAccount->id, 'date' => '2026-01-04', 'payer' => 'Cash QA', 'description' => 'Cash scoped receipt', 'amount' => 10, 'payment_method' => 'Cash', 'reference_no' => $cashReference, 'created_by' => $this->cashierA->id]);
        OtherIncome::create(['school_id' => 1, 'bank_account_id' => $this->headAccount->id, 'date' => '2026-01-04', 'payer' => 'Head QA', 'description' => 'Head-only receipt', 'amount' => 20, 'payment_method' => 'Cash', 'reference_no' => $headReference, 'created_by' => $this->head->id]);
        $cashierRows = app(FinanceTransactionRegisterService::class)->register($this->cashierA)['rows'];
        $this->assertTrue($cashierRows->contains(fn (array $row) => $row['reference'] === $cashReference));
        $this->assertFalse($cashierRows->contains(fn (array $row) => $row['reference'] === $headReference));

        $foreign = $this->account('Foreign income account', 2, 0);
        $inactive = $this->account('Inactive income account', 1, 0);
        $inactive->update(['is_active' => false]);
        $base = ['date' => '2026-01-04', 'payer' => 'QA', 'description' => 'Scoped receipt', 'amount' => 1, 'payment_method' => 'Cash'];
        foreach ([$foreign->id, $inactive->id] as $id) {
            try {
                app(OtherIncomeService::class)->receive($this->head, $base + ['bank_account_id' => $id]);
                $this->fail('Cross-school and inactive accounts must be rejected.');
            } catch (ModelNotFoundException | \Illuminate\Validation\ValidationException) {
                $this->assertTrue(true);
            }
        }
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
        foreach ($this->handoverPermissionsFor($role) as $permission) {
            DB::table('permissions')->updateOrInsert(['name' => $permission], ['guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => DB::table('permissions')->where('name', $permission)->value('id'),
                'role_id' => $roleId,
            ], []);
        }
        return $user;
    }

    private function handoverPermissionsFor(string $role): array
    {
        if ($role === 'School Admin') return ['finance-handover-view'];
        if (in_array($role, ['Head Finance', 'Cashier'], true)) {
            return ['finance-handover-view', 'finance-handover-create', 'finance-handover-confirm', 'finance-handover-reject', 'finance-handover-cancel'];
        }
        return [];
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

    private function ensureOtherIncomesTable(): void
    {
        if (!Schema::hasTable('other_incomes')) {
            Schema::create('other_incomes', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('bank_account_id');
                $table->date('date'); $table->string('payer'); $table->string('description', 1000);
                $table->decimal('amount', 15, 2); $table->string('payment_method', 50);
                $table->string('reference_no', 100)->nullable(); $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps(); $table->softDeletes();
            });
        }
    }
}

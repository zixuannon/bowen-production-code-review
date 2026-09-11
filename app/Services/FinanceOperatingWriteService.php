<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Fee;
use App\Models\FinanceGroupUser;
use App\Models\Students;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Scheme B adapter for the three approved existing tenant write paths.
 *
 * It does not create a parallel ledger/controller. Each action resolves the
 * current opaque context, re-validates Group/school/tenant identity, runs in
 * the trusted target tenant connection, and delegates to canonical services.
 */
class FinanceOperatingWriteService
{
    public function __construct(
        private readonly FinanceOperatingWorkspaceService $workspace,
        private readonly FinanceGroupScopeService $scope,
        private readonly FinanceOperatingAuditService $audit,
        private readonly OtherIncomeService $otherIncome,
        private readonly ExpenseCreationService $expenses,
        private readonly FeesPaymentService $fees,
        private readonly BankTransferService $transfers,
        private readonly FundHandoverService $handovers,
    ) {}

    /** @return array<string,mixed> */
    public function formOptions(User $central): array
    {
        $workspace = $this->workspace->workspace($central);
        return $this->scope->executeOperatingFinanceAsTenantIdentity(
            $workspace['groupUser'], $workspace['context']->schoolId,
            function (User $tenant): array {
                $accounts = app(FinanceAccountAccessService::class)->accessibleAccounts($tenant)->active()->orderBy('account_name')
                    ->get(['id', 'account_name', 'currency'])->map(fn ($a) => ['id'=>(int)$a->id, 'account_name'=>(string)$a->account_name, 'currency'=>(string)$a->currency])->all();
                // eligibleDestinationAccounts() rechecks same-School custody;
                // retain school_id on this deliberately narrow projection.
                $recipients = $this->handovers->recipientCandidates($tenant)->orderBy('first_name')->get(['id', 'school_id', 'first_name', 'last_name', 'email']);
                $recipientAccounts = [];
                foreach ($recipients as $recipient) {
                    $recipientAccounts[(int) $recipient->id] = $this->handovers->eligibleDestinationAccounts($tenant, $recipient)
                        ->active()->orderBy('account_name')->get(['id', 'account_name', 'currency'])
                        ->map(fn ($account) => ['id' => (int) $account->id, 'account_name' => (string) $account->account_name, 'currency' => (string) $account->currency])->all();
                }
                return [
                    'accounts' => $accounts,
                    'categories' => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int)$id => (string)$name])->all(),
                    'session_years' => DB::connection('school')->table('session_years')->orderByDesc('default')->orderByDesc('id')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int)$id => (string)$name])->all(),
                    'fees' => Fee::query()->where('school_id', $tenant->school_id)->orderBy('name')->get(['id','name'])->mapWithKeys(fn ($fee) => [(int)$fee->id => (string)$fee->name])->all(),
                    'students' => Students::query()->where('school_id', $tenant->school_id)->with('user:id,first_name,last_name')->get()->mapWithKeys(fn ($student) => [(int)$student->user_id => trim(($student->user?->first_name ?? '') . ' ' . ($student->user?->last_name ?? '')) ?: ('Student #' . $student->user_id)])->all(),
                    'handover_recipients' => $recipients->map(fn ($recipient) => ['id' => (int) $recipient->id, 'name' => trim($recipient->first_name . ' ' . $recipient->last_name) ?: (string) $recipient->email])->all(),
                    'handover_recipient_accounts' => $recipientAccounts,
                ];
            },
        );
    }

    /** @param array<string,mixed> $input */
    public function receiveMoney(User $central, array $input): int
    {
        return $this->within($central, 'finance-payment-create', function (User $tenant, $context) use ($input): int {
            $data = Validator::make($input, [
                'date' => ['required', 'date'], 'payer' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:1000'], 'amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['required', Rule::in(FeesPaymentService::PAYMENT_METHODS)],
                'bank_account_id' => ['required', 'integer'], 'reference_no' => ['nullable', 'string', 'max:100'],
                'transaction_currency' => ['nullable', Rule::in(['MMK', 'USD', 'CNY'])],
                'original_amount' => ['nullable', 'numeric', 'min:0.01'],
                'exchange_rate_snapshot' => ['nullable', 'numeric', 'min:0.00000001'],
                'remark' => ['nullable', 'string', 'max:5000'],
            ])->validate();
            $income = $this->otherIncome->receive($tenant, $data, fn ($source) => $this->audit->record($context, 'other_income', $source->id, 'receive_money'));
            return (int) $income->id;
        });
    }

    /** @param array<string,mixed> $input */
    public function createExpense(User $central, array $input): int
    {
        return $this->within($central, 'finance-expense-create', function (User $tenant, $context) use ($input): int {
            $data = Validator::make($input, [
                'ref_no' => ['nullable', Rule::unique('expenses', 'ref_no')->where(fn ($q) => $q->where('session_year_id', $input['session_year_id'] ?? null)->whereNull('vehicle_id')->whereNull('staff_id'))],
                'amount' => ['required', 'numeric', 'min:0'], 'transaction_currency' => ['nullable', 'string', 'size:3', Rule::in(['MMK','USD','CNY'])],
                'original_amount' => ['nullable', 'numeric', 'min:0'], 'exchange_rate_snapshot' => ['nullable', 'numeric', 'min:0'],
                'bank_account_id' => ['required', 'integer'], 'title' => ['required','string','max:255'],
                'date' => ['required'], 'session_year_id' => ['required','integer'], 'category_id' => ['required','integer'],
                'description' => ['nullable','string'], 'finance_category_id' => ['nullable','integer'],
            ])->validate();
            $expense = $this->expenses->create($tenant, $data, fn ($source) => $this->audit->record($context, 'expense', $source->id, 'create_expense'));
            return (int) $expense->id;
        });
    }

    /** @param array<string,mixed> $input */
    public function receiveStudentFee(User $central, array $input): int
    {
        return $this->within($central, 'finance-payment-create', function (User $tenant, $context) use ($input): int {
            $data = Validator::make($input, [
                'fees_id' => ['required','integer'], 'student_id' => ['required','integer'], 'date' => ['required','date'],
                'mode' => ['required', Rule::in(FeesPaymentService::PAYMENT_METHODS)], 'installment_mode' => ['required','boolean'],
                'bank_account_id' => ['required','integer'], 'total_amount' => ['nullable','numeric','min:0'], 'enter_amount' => ['nullable','numeric','min:0'],
                'advance' => ['nullable','numeric','min:0'], 'transaction_currency' => ['nullable', Rule::in(['MMK','USD','CNY'])],
                'original_amount' => ['nullable','numeric','min:0'], 'exchange_rate_snapshot' => ['nullable','numeric','min:0.0001'],
                'reference_no' => ['nullable','string','max:100'],
            ])->validate();
            $fee = Fee::query()->whereKey($data['fees_id'])->where('school_id', $tenant->school_id)->with('installments')->firstOrFail();
            Students::query()->where('user_id', $data['student_id'])->where('school_id', $tenant->school_id)->firstOrFail();
            $data['installment_mode'] = false;
            $data['total_amount'] = $data['enter_amount'] ?? $data['total_amount'] ?? $fee->total_compulsory_fees;
            return DB::connection('school')->transaction(function () use ($data, $fee, $tenant, $context): int {
                $result = $this->fees->processPayment($data, $fee, $tenant);
                foreach ($result['compulsory_fees'] as $source) {
                    $this->audit->record($context, 'compulsory_fee', $source->id, 'receive_student_fee');
                }
                return (int) $result['compulsory_fees'][0]->id;
            });
        });
    }

    /** @param array<string,mixed> $input */
    public function createBankTransfer(User $central, array $input): int
    {
        return $this->within($central, 'finance-transfer-create', function (User $tenant, $context) use ($input): int {
            $data = Validator::make($input, [
                'from_account_id' => ['required', 'integer'],
                'to_account_id' => ['required', 'integer', 'different:from_account_id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'transfer_date' => ['required', 'date'],
                'reference_no' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $transfer = $this->transfers->create($tenant, $data, fn ($source) => $this->audit->record($context, 'bank_transfer', $source->id, 'create_bank_transfer'));
            return (int) $transfer->id;
        });
    }

    /** @param array<string,mixed> $input */
    public function createFundHandover(User $central, array $input): int
    {
        return $this->within($central, 'finance-handover-create', function (User $tenant, $context) use ($input): int {
            $data = Validator::make($input, [
                'receiver_id' => ['required', 'integer'],
                'from_account_id' => ['required', 'integer'],
                'to_account_id' => ['required', 'integer', 'different:from_account_id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'handover_date' => ['required', 'date'],
                'reference_no' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $handover = $this->handovers->create($tenant, $data, fn ($source) => $this->audit->record($context, 'fund_handover', $source->id, 'create_fund_handover'));
            return (int) $handover->id;
        });
    }

    /** @template T @param callable(User,\App\ValueObjects\FinanceOperatingContext):T $operation @return T */
    private function within(User $central, string $permission, callable $operation)
    {
        $workspace = $this->workspace->workspace($central);
        return $this->scope->executeOperatingFinanceAsTenantIdentity($workspace['groupUser'], $workspace['context']->schoolId,
            function (User $tenant) use ($permission, $operation, $workspace) {
                app(FinanceAuthorizationService::class)->assert($tenant, $permission);
                return $operation($tenant, $workspace['context']);
            });
    }
}

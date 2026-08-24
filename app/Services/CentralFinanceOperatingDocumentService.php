<?php

namespace App\Services;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Central operating documents are canonical. Their Ledger entries are only
 * created here, while an audited soft delete appends one deterministic
 * reversal rather than deleting or changing historical Ledger rows.
 */
final class CentralFinanceOperatingDocumentService
{
    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly CentralFinanceLedgerService $ledger,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public function createExpense(CentralFinanceUser $actor, int $schoolId, int $categoryId, CentralFinanceFundAccount $account, float $amount, string $paymentMethod, CarbonImmutable $occurredAt, string $idempotencyReference, ?string $referenceNo = null, ?string $description = null, ?string $reimbursedBy = null): CentralFinanceExpense
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $this->assertInput($amount, $paymentMethod, $idempotencyReference, $referenceNo);

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $categoryId, $account, $amount, $paymentMethod, $occurredAt, $idempotencyReference, $referenceNo, $description, $reimbursedBy): CentralFinanceExpense {
            $this->schools->assertCanOperate($actor, $schoolId);
            $this->accounts->assertCanOperate($actor, $account);
            $key = $this->key('expense', $schoolId, $idempotencyReference);
            $existing = CentralFinanceExpense::on('mysql')->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            if ($account->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL && (int) $account->school_id !== $schoolId) {
                throw new InvalidArgumentException('A School Fund Account may record only that School\'s expenses.');
            }
            $this->category($schoolId, $categoryId, CentralFinanceCategory::EXPENSE);
            $this->assertReferenceFree(CentralFinanceExpense::class, $schoolId, $referenceNo);
            $values = [
                'school_id' => $schoolId, 'category_id' => $categoryId,
                'fund_account_id' => $account->id, 'idempotency_key' => $key,
                'reference_no' => $referenceNo, 'payment_method' => $paymentMethod,
                'expense_date' => $occurredAt->toDateString(), 'currency' => strtoupper($account->currency),
                'amount' => $amount, 'description' => $description, 'created_by' => $actor->id,
            ];
            if ($reimbursedBy !== null && trim($reimbursedBy) !== '') $values['reimbursed_by'] = trim($reimbursedBy);
            $expense = CentralFinanceExpense::on('mysql')->create($values);
            $this->ledger->recordOperatingExpense($actor, $account, $schoolId, 'central_expense', $expense->expense_uuid, $amount, $occurredAt, $referenceNo);
            $this->audits->record($actor, $expense, 'expense', 'created', null, null, $this->snapshot($expense));

            return $expense;
        });
    }

    public function createOtherIncome(CentralFinanceUser $actor, int $schoolId, int $categoryId, CentralFinanceFundAccount $account, float $amount, string $paymentMethod, CarbonImmutable $occurredAt, string $idempotencyReference, ?string $referenceNo = null, ?string $payer = null, ?string $description = null): CentralFinanceOtherIncome
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $this->assertInput($amount, $paymentMethod, $idempotencyReference, $referenceNo);

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $categoryId, $account, $amount, $paymentMethod, $occurredAt, $idempotencyReference, $referenceNo, $payer, $description): CentralFinanceOtherIncome {
            $this->schools->assertCanOperate($actor, $schoolId);
            $this->accounts->assertCanOperate($actor, $account);
            $key = $this->key('other_income', $schoolId, $idempotencyReference);
            $existing = CentralFinanceOtherIncome::on('mysql')->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $this->category($schoolId, $categoryId, CentralFinanceCategory::INCOME);
            $this->assertReferenceFree(CentralFinanceOtherIncome::class, $schoolId, $referenceNo);
            $income = CentralFinanceOtherIncome::on('mysql')->create([
                'school_id' => $schoolId, 'category_id' => $categoryId,
                'fund_account_id' => $account->id, 'idempotency_key' => $key,
                'reference_no' => $referenceNo, 'payment_method' => $paymentMethod,
                'income_date' => $occurredAt->toDateString(), 'payer' => $payer,
                'currency' => strtoupper($account->currency), 'amount' => $amount,
                'description' => $description, 'created_by' => $actor->id,
            ]);
            $this->ledger->recordOperatingIncome($actor, $account, $schoolId, 'central_other_income', $income->income_uuid, $amount, $occurredAt, $referenceNo);
            $this->audits->record($actor, $income, 'other_income', 'created', null, null, $this->snapshot($income));

            return $income;
        });
    }

    /** @param array{category_id?:int,description?:?string} $changes */
    public function updateExpenseDetails(CentralFinanceUser $actor, int $expenseId, array $changes, string $reason): CentralFinanceExpense
    {
        $existing = CentralFinanceExpense::on('mysql')->findOrFail($expenseId);
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $existing->school_id);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An edit reason is required.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $expenseId, $changes, $reason): CentralFinanceExpense {
            $expense = CentralFinanceExpense::on('mysql')->lockForUpdate()->findOrFail($expenseId);
            $this->schools->assertCanOperate($actor, $expense->school_id);
            $this->accounts->assertCanOperate($actor, CentralFinanceFundAccount::on('mysql')->findOrFail($expense->fund_account_id));
            $before = $this->snapshot($expense);
            if (array_key_exists('category_id', $changes)) {
                $this->category($expense->school_id, (int) $changes['category_id'], CentralFinanceCategory::EXPENSE);
                $expense->category_id = (int) $changes['category_id'];
            }
            if (array_key_exists('description', $changes)) {
                $expense->description = $changes['description'];
            }
            $expense->updated_by = $actor->id;
            $expense->edit_reason = trim($reason);
            $expense->save();
            $this->audits->record($actor, $expense, 'expense', 'updated', trim($reason), $before, $this->snapshot($expense));

            return $expense;
        });
    }

    public function voidExpense(CentralFinanceUser $actor, int $expenseId, string $reason, CarbonImmutable $occurredAt): CentralFinanceExpense
    {
        return $this->void($actor, CentralFinanceExpense::class, $expenseId, 'expense', $reason, $occurredAt);
    }

    public function voidOtherIncome(CentralFinanceUser $actor, int $incomeId, string $reason, CarbonImmutable $occurredAt): CentralFinanceOtherIncome
    {
        return $this->void($actor, CentralFinanceOtherIncome::class, $incomeId, 'other_income', $reason, $occurredAt);
    }

    /** @template T of CentralFinanceExpense|CentralFinanceOtherIncome @param class-string<T> $class @return T */
    private function void(CentralFinanceUser $actor, string $class, int $id, string $type, string $reason, CarbonImmutable $occurredAt): CentralFinanceExpense|CentralFinanceOtherIncome
    {
        $existing = $class::on('mysql')->withTrashed()->findOrFail($id);
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $existing->school_id);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A delete reason is required.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $class, $id, $type, $reason, $occurredAt): CentralFinanceExpense|CentralFinanceOtherIncome {
            $document = $class::on('mysql')->withTrashed()->lockForUpdate()->findOrFail($id);
            $this->schools->assertCanOperate($actor, $document->school_id);
            if ($document->trashed()) {
                return $document;
            }
            $account = CentralFinanceFundAccount::on('mysql')->active()->findOrFail($document->fund_account_id);
            $this->accounts->assertCanOperate($actor, $account);
            $before = $this->snapshot($document);
            if ($type === 'expense') {
                $this->ledger->reverseOperatingExpense($actor, $account, $document->school_id, 'central_expense_void', $document->expense_uuid, (float) $document->amount, $occurredAt, $document->reference_no);
            } else {
                $this->ledger->reverseOperatingIncome($actor, $account, $document->school_id, 'central_other_income_void', $document->income_uuid, (float) $document->amount, $occurredAt, $document->reference_no);
            }
            $document->deleted_by = $actor->id;
            $document->delete_reason = trim($reason);
            $document->save();
            $document->delete();
            $this->audits->record($actor, $document, $type, 'deleted', trim($reason), $before, $this->snapshot($document));

            return $document;
        });
    }

    private function category(int $schoolId, int $categoryId, string $type): CentralFinanceCategory
    {
        return CentralFinanceCategory::on('mysql')->where([
            'id' => $categoryId, 'school_id' => $schoolId, 'type' => $type, 'is_active' => true,
        ])->firstOrFail();
    }

    /** @param class-string<CentralFinanceExpense|CentralFinanceOtherIncome> $class */
    private function assertReferenceFree(string $class, int $schoolId, ?string $referenceNo): void
    {
        if ($referenceNo !== null && $referenceNo !== '' && $class::on('mysql')->withTrashed()->where([
            'school_id' => $schoolId, 'reference_no' => $referenceNo,
        ])->exists()) {
            throw new InvalidArgumentException('This reference number is already reserved for this School.');
        }
    }

    private function assertInput(float $amount, string $paymentMethod, string $idempotencyReference, ?string &$referenceNo): void
    {
        $referenceNo = $referenceNo === null ? null : trim($referenceNo);
        if ($amount <= 0 || !is_finite($amount)
            || !preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', $paymentMethod)
            || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)
            || ($referenceNo !== null && $referenceNo !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $referenceNo))) {
            throw new InvalidArgumentException('Central operating document input is invalid.');
        }
        $referenceNo = $referenceNo ?: null;
    }

    private function key(string $type, int $schoolId, string $idempotencyReference): string
    {
        return hash('sha256', $type.'|'.$schoolId.'|'.$idempotencyReference);
    }

    /** @return array<string,mixed> */
    private function snapshot(CentralFinanceExpense|CentralFinanceOtherIncome $document): array
    {
        return $document->only(['category_id', 'fund_account_id', 'reference_no', 'amount', 'currency', 'description', 'reimbursed_by', 'deleted_at']);
    }
}

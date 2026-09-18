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
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceLedgerService $ledger,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public function createExpense(CentralFinanceUser $actor, int $schoolId, int $categoryId, CentralFinanceFundAccount $account, float $amount, string $paymentMethod, CarbonImmutable $occurredAt, string $idempotencyReference, ?string $referenceNo = null, ?string $description = null, ?string $reimbursedBy = null): CentralFinanceExpense
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $this->assertInput($amount, $paymentMethod, $idempotencyReference, $referenceNo);

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $categoryId, $account, $amount, $paymentMethod, $occurredAt, $idempotencyReference, $referenceNo, $description, $reimbursedBy): CentralFinanceExpense {
            $this->schools->assertCanOperate($actor, $schoolId);
            $this->accounts->assertCanOperate($actor, $account, $schoolId);
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $key = $this->key('expense', $schoolId, $idempotencyReference);
            $existing = CentralFinanceExpense::on('mysql')->withTrashed()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplayIdentity($existing, $account, $categoryId, $amount, $referenceNo, $paymentMethod, $occurredAt);
                return $existing;
            }

            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $this->availability->assertAccountAvailableForSchool($account, $schoolId);
            $category = $this->category($schoolId, $categoryId, CentralFinanceCategory::EXPENSE, (int) $account->group_id);
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
            $this->ledger->recordOperatingExpense($actor, $account, $schoolId, 'central_expense', $expense->expense_uuid, $amount, $occurredAt, $referenceNo, $category->type === CentralFinanceCategory::EXPENSE, $expense->description);
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
            $this->accounts->assertCanOperate($actor, $account, $schoolId);
            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $key = $this->key('other_income', $schoolId, $idempotencyReference);
            $existing = CentralFinanceOtherIncome::on('mysql')->withTrashed()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplayIdentity($existing, $account, $categoryId, $amount, $referenceNo, $paymentMethod, $occurredAt);
                return $existing;
            }

            $account = CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            $this->availability->assertAccountAvailableForSchool($account, $schoolId);
            $category = $this->category($schoolId, $categoryId, CentralFinanceCategory::INCOME, (int) $account->group_id);
            $this->assertReferenceFree(CentralFinanceOtherIncome::class, $schoolId, $referenceNo);
            $income = CentralFinanceOtherIncome::on('mysql')->create([
                'school_id' => $schoolId, 'category_id' => $categoryId,
                'fund_account_id' => $account->id, 'idempotency_key' => $key,
                'reference_no' => $referenceNo, 'payment_method' => $paymentMethod,
                'income_date' => $occurredAt->toDateString(), 'payer' => $payer,
                'currency' => strtoupper($account->currency), 'amount' => $amount,
                'description' => $description, 'created_by' => $actor->id,
            ]);
            $this->ledger->recordOperatingIncome($actor, $account, $schoolId, 'central_other_income', $income->income_uuid, $amount, $occurredAt, $referenceNo, $category->type === CentralFinanceCategory::INCOME, $income->description);
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
            $this->accounts->assertCanOperate($actor, CentralFinanceFundAccount::on('mysql')->findOrFail($expense->fund_account_id), (int) $expense->school_id);
            $before = $this->snapshot($expense);
            if (array_key_exists('category_id', $changes)) {
                $replacement = $this->category($expense->school_id, (int) $changes['category_id'], CentralFinanceCategory::EXPENSE, (int) CentralFinanceFundAccount::findOrFail($expense->fund_account_id)->group_id);
                $this->assertSameClassification((int) $expense->category_id, $replacement);
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

    /**
     * Correct descriptive Other Income metadata without changing the canonical
     * amount, Fund Account, date, reference, or its already-posted Ledger.
     *
     * @param array{category_id?:int,payer?:?string,description?:?string} $changes
     */
    public function updateOtherIncomeDetails(CentralFinanceUser $actor, int $incomeId, array $changes, string $reason): CentralFinanceOtherIncome
    {
        $existing = CentralFinanceOtherIncome::on('mysql')->findOrFail($incomeId);
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $existing->school_id);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An edit reason is required.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $incomeId, $changes, $reason): CentralFinanceOtherIncome {
            $income = CentralFinanceOtherIncome::on('mysql')->lockForUpdate()->findOrFail($incomeId);
            $this->schools->assertCanOperate($actor, $income->school_id);
            $this->accounts->assertCanOperate($actor, CentralFinanceFundAccount::on('mysql')->findOrFail($income->fund_account_id), (int) $income->school_id);
            $before = $this->snapshot($income);
            if (array_key_exists('category_id', $changes)) {
                $replacement = $this->category($income->school_id, (int) $changes['category_id'], CentralFinanceCategory::INCOME, (int) CentralFinanceFundAccount::findOrFail($income->fund_account_id)->group_id);
                $this->assertSameClassification((int) $income->category_id, $replacement);
                $income->category_id = (int) $changes['category_id'];
            }
            if (array_key_exists('payer', $changes)) {
                $income->payer = $changes['payer'];
            }
            if (array_key_exists('description', $changes)) {
                $income->description = $changes['description'];
            }
            $income->updated_by = $actor->id;
            $income->edit_reason = trim($reason);
            $income->save();
            $this->audits->record($actor, $income, 'other_income', 'updated', trim($reason), $before, $this->snapshot($income));

            return $income;
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
            $this->accounts->assertCanOperate($actor, $account, (int) $document->school_id);
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

    private function category(int $schoolId, int $categoryId, string $type, int $groupId): CentralFinanceCategory
    {
        $query = CentralFinanceCategory::on('mysql')->availableForSchool($schoolId)
            ->forCashDirection($type)->where(['id' => $categoryId, 'is_active' => true]);
        app(CentralFinanceDataIsolationService::class)->apply($query, 'category');
        $category = $query->lockForUpdate()->firstOrFail();
        if ($category->group_id && (int) $category->group_id !== $groupId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The Account and Fund Account must belong to the same authorized Finance Group.');
        }
        return $category;
    }

    private function assertSameClassification(int $oldId, CentralFinanceCategory $replacement): void
    {
        if (CentralFinanceCategory::on('mysql')->findOrFail($oldId)->type !== $replacement->type) {
            throw new InvalidArgumentException('A posted cash movement cannot silently change its Account Type. Use an audited reversal and replacement.');
        }
    }

    private function assertReplayIdentity(CentralFinanceExpense|CentralFinanceOtherIncome $existing, CentralFinanceFundAccount $account, int $categoryId, float $amount, ?string $referenceNo, string $paymentMethod, CarbonImmutable $occurredAt): void
    {
        if ($existing->trashed() || (int) $existing->fund_account_id !== (int) $account->id
            || (int) $existing->category_id !== $categoryId || abs((float) $existing->amount - $amount) >= 0.0001
            || $existing->reference_no !== $referenceNo || $existing->currency !== $account->currency
            || $existing->payment_method !== $paymentMethod
            || ($existing instanceof CentralFinanceExpense ? $existing->expense_date : $existing->income_date)->toDateString() !== $occurredAt->toDateString()) {
            throw new InvalidArgumentException('The idempotency reference is already reserved for a different immutable cash movement.');
        }
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
        return $document->only(['category_id', 'fund_account_id', 'reference_no', 'amount', 'currency', 'payer', 'description', 'reimbursed_by', 'deleted_at']);
    }
}

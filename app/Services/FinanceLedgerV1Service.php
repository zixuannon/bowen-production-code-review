<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Stable, read-only Ledger V1 projection over the current canonical
 * transaction sources. This service never persists, recalculates, or repairs
 * a financial source record.
 */
class FinanceLedgerV1Service
{
    public function __construct(private readonly FinanceTransactionRegisterService $transactions)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float>}
     */
    public function register(User $actor, array $filters = []): array
    {
        $register = $this->transactions->register($actor, $filters);
        $rows = $register['rows']->map(fn (array $row) => $this->normalize($actor, $row))->values();

        if ($rows->pluck('ledger_key')->duplicates()->isNotEmpty()) {
            throw new LogicException('Ledger V1 source projection contains a duplicate canonical source identity.');
        }

        $summary = [
            'operating_income' => (float) $rows->sum('operating_income'),
            'operating_expense' => (float) $rows->sum('operating_expense'),
            'internal_transfer_amount' => (float) $rows->sum('internal_transfer_amount'),
        ];
        $summary['operating_net'] = $summary['operating_income'] - $summary['operating_expense'];

        return compact('rows', 'summary');
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(User $actor, array $row): array
    {
        $sourceType = $row['source_type'] ?? $this->sourceType($row['transaction_type']);
        $sourceId = (int) $row['source_id'];
        $transactionClass = match ($sourceType) {
            'compulsory_fee' => 'STUDENT_FEE',
            'optional_fee' => 'OPTIONAL_FEE',
            'other_income' => 'OTHER_INCOME',
            'expense' => 'EXPENSE',
            'bank_transfer' => 'INTERNAL_TRANSFER',
            'group_transfer' => 'INTERNAL_TRANSFER',
            default => throw new LogicException('Unsupported Ledger V1 source type.'),
        };
        $isInternal = $transactionClass === 'INTERNAL_TRANSFER';

        return [
            'ledger_key' => $sourceType === 'group_transfer'
                ? "group-transfer:{$sourceId}:school:{$actor->school_id}"
                : "tenant:{$actor->school_id}:{$sourceType}:{$sourceId}",
            'posting_date' => $row['date'],
            'group_id' => null,
            'school_id' => $actor->school_id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_status' => $row['status'],
            'transaction_class' => $transactionClass,
            'reference_no' => $row['reference'],
            'counterparty' => $row['counterparty'],
            'description' => $row['description'],
            'finance_category_id' => $row['finance_category_id'] ?? null,
            'fund_account_id' => $row['fund_account_id'] ?? null,
            'fund_account_name' => $row['fund_account'],
            'from_fund_account_id' => $row['from_fund_account_id'] ?? null,
            'from_fund_account_name' => $row['from_fund_account'] ?? null,
            'to_fund_account_id' => $row['to_fund_account_id'] ?? null,
            'to_fund_account_name' => $row['to_fund_account'] ?? null,
            'payment_method' => $row['payment_method'] === '-' ? null : $row['payment_method'],
            // A legacy source without an explicit immutable currency snapshot
            // stays null; Ledger V1 must not fabricate a foreign currency.
            'currency' => $row['transaction_currency'] ?? null,
            'original_amount' => $row['original_amount'] ?? null,
            'exchange_rate_snapshot' => $row['exchange_rate_snapshot'] ?? null,
            'reporting_amount_mmk' => $row['reporting_amount_mmk'] ?? null,
            'money_in' => (float) $row['money_in'],
            'money_out' => (float) $row['money_out'],
            'operating_income' => $transactionClass === 'STUDENT_FEE' || $transactionClass === 'OPTIONAL_FEE' || $transactionClass === 'OTHER_INCOME'
                ? (float) $row['money_in'] : 0.0,
            'operating_expense' => $transactionClass === 'EXPENSE' ? (float) $row['money_out'] : 0.0,
            // An all-account transfer is neutral in Money In/Out but carries
            // its canonical amount exactly once for funding visibility.
            'internal_transfer_amount' => $isInternal && (float) $row['money_in'] === 0.0 && (float) $row['money_out'] === 0.0
                ? (float) ($row['source_amount'] ?? 0) : 0.0,
            'operator_user_id' => $row['operator_user_id'] ?? null,
            'operator_name' => $row['operator'],
            'created_at' => $row['created_at_raw'] ?? null,
            'audit_state' => $row['status'],
        ];
    }

    private function sourceType(string $transactionType): string
    {
        return match ($transactionType) {
            'student_fee_payment' => 'compulsory_fee',
            'optional_fee_payment' => 'optional_fee',
            'other_income' => 'other_income',
            'expense' => 'expense',
            'bank_transfer' => 'bank_transfer',
            'group_transfer' => 'group_transfer',
            default => throw new LogicException('Unknown transaction-register source type.'),
        };
    }
}

<?php

namespace App\Services;

use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\OptionalFee;
use App\Models\OtherIncome;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only adapter over the existing financial source records. It never
 * persists a consolidated transaction/ledger row.
 */
class FinanceTransactionRegisterService
{
    public const TYPES = [
        'student_fee_payment', 'optional_fee_payment', 'other_income', 'expense', 'bank_transfer',
    ];

    public function register(User $actor, array $filters = []): array
    {
        $accountIds = app(FinanceAccountAccessService::class)->accessibleAccounts($actor)->pluck('id');
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $type = $filters['type'] ?? null;
        $accountId = isset($filters['bank_account_id']) ? (int) $filters['bank_account_id'] : null;
        $reference = trim((string) ($filters['reference'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        if ($accountId) {
            // Deliberately do not silently narrow a forged account filter.
            app(FinanceAccountAccessService::class)->authorize($actor, $accountId);
            $accountIds = collect([$accountId]);
        }

        $rows = collect();
        if (!$type || $type === 'student_fee_payment') {
            $rows = $rows->concat($this->compulsoryRows($actor, $accountIds, $from, $to));
        }
        if (!$type || $type === 'optional_fee_payment') {
            $rows = $rows->concat($this->optionalRows($actor, $accountIds, $from, $to));
        }
        if (!$type || $type === 'other_income') {
            $rows = $rows->concat($this->otherIncomeRows($actor, $accountIds, $from, $to));
        }
        if (!$type || $type === 'expense') {
            $rows = $rows->concat($this->expenseRows($actor, $accountIds, $from, $to));
        }
        if (!$type || $type === 'bank_transfer') {
            // Confirmed handovers are represented by their canonical transfer
            // only. Pending handovers never become a money-movement row.
            $rows = $rows->concat($this->transferRows($actor, $accountIds, $accountId, $from, $to));
        }

        $rows = $rows->filter(function (array $row) use ($reference, $keyword) {
            if ($reference !== '' && stripos((string) $row['reference'], $reference) === false) {
                return false;
            }
            if ($keyword === '') {
                return true;
            }
            return stripos(implode(' ', [
                $row['counterparty'], $row['description'], $row['reference'], $row['fund_account'],
            ]), $keyword) !== false;
        })->sortByDesc(fn (array $row) => $row['date'] . ':' . $row['source_id'])->values();

        $summary = [
            'money_in' => (float) $rows->sum('money_in'),
            'money_out' => (float) $rows->sum('money_out'),
            'operating_income' => (float) $rows->where('operating', 'income')->sum('money_in'),
            'operating_expense' => (float) $rows->where('operating', 'expense')->sum('money_out'),
            'internal_in' => (float) $rows->where('operating', 'internal')->sum('money_in'),
            'internal_out' => (float) $rows->where('operating', 'internal')->sum('money_out'),
        ];
        $summary['net_movement'] = $summary['money_in'] - $summary['money_out'];
        $summary['operating_net'] = $summary['operating_income'] - $summary['operating_expense'];

        return compact('rows', 'summary');
    }

    private function compulsoryRows(User $actor, Collection $accounts, ?string $from, ?string $to): Collection
    {
        return $this->between(CompulsoryFee::query()->with(['student:id,first_name,last_name', 'bank_account:id,account_name'])
            ->where('school_id', $actor->school_id)->where('status', 'Success')->whereIn('bank_account_id', $accounts), $from, $to)
            ->get()->map(fn (CompulsoryFee $row) => $this->row($row->date, 'student_fee_payment', $row->id,
                $row->reference_no, trim(($row->student?->first_name ?? '') . ' ' . ($row->student?->last_name ?? '')),
                __('Student fee payment'), $row->mode_name, $row->bank_account?->account_name, $row->amount, 0, 'income', null, null, [
                    'source_type' => 'compulsory_fee', 'fund_account_id' => $row->bank_account_id,
                    'finance_category_id' => null, 'source_amount' => (float) $row->amount,
                    'created_at_raw' => $row->getRawOriginal('created_at'),
                ]));
    }

    private function optionalRows(User $actor, Collection $accounts, ?string $from, ?string $to): Collection
    {
        return $this->between(OptionalFee::query()->with(['student:id,first_name,last_name', 'bank_account:id,account_name'])
            ->where('school_id', $actor->school_id)->where('status', 'Success')->whereIn('bank_account_id', $accounts), $from, $to)
            ->get()->map(fn (OptionalFee $row) => $this->row($row->date, 'optional_fee_payment', $row->id,
                null, trim(($row->student?->first_name ?? '') . ' ' . ($row->student?->last_name ?? '')),
                __('Optional fee payment'), $row->mode_name, $row->bank_account?->account_name, $row->amount, 0, 'income', null, null, [
                    'source_type' => 'optional_fee', 'fund_account_id' => $row->bank_account_id,
                    'finance_category_id' => null, 'source_amount' => (float) $row->amount,
                    'created_at_raw' => $row->getRawOriginal('created_at'),
                ]));
    }

    private function otherIncomeRows(User $actor, Collection $accounts, ?string $from, ?string $to): Collection
    {
        return $this->between(OtherIncome::query()->with(['bank_account:id,account_name', 'creator:id,first_name,last_name'])
            ->where('school_id', $actor->school_id)->whereIn('bank_account_id', $accounts), $from, $to)
            ->get()->map(fn (OtherIncome $row) => $this->row($row->date, 'other_income', $row->id,
                $row->reference_no, $row->payer, $row->description, $row->payment_method,
                $row->bank_account?->account_name, $row->amount, 0, 'income', $row->creator, null, [
                    'source_type' => 'other_income', 'fund_account_id' => $row->bank_account_id,
                    'finance_category_id' => null, 'source_amount' => (float) $row->amount,
                    'created_at_raw' => $row->getRawOriginal('created_at'), 'operator_user_id' => $row->created_by,
                ]));
    }

    private function expenseRows(User $actor, Collection $accounts, ?string $from, ?string $to): Collection
    {
        return $this->between(Expense::query()->with(['bank_account:id,account_name', 'creator:id,first_name,last_name'])
            ->where('school_id', $actor->school_id)->whereIn('bank_account_id', $accounts), $from, $to)
            ->get()->map(fn (Expense $row) => $this->row($row->date, 'expense', $row->id, $row->ref_no,
                $row->title, $row->description, $row->payment_method, $row->bank_account?->account_name,
                0, $row->amount_mmk > 0 ? $row->amount_mmk : $row->amount, 'expense', $row->creator, null, [
                    'source_type' => 'expense', 'fund_account_id' => $row->bank_account_id,
                    'finance_category_id' => $row->finance_category_id, 'source_amount' => (float) $row->amount,
                    'transaction_currency' => $row->transaction_currency, 'original_amount' => $row->original_amount,
                    'exchange_rate_snapshot' => $row->exchange_rate_snapshot, 'reporting_amount_mmk' => $row->amount_mmk,
                    'created_at_raw' => $row->getRawOriginal('created_at'), 'operator_user_id' => $row->created_by,
                ]));
    }

    private function transferRows(User $actor, Collection $accounts, ?int $selectedAccountId, ?string $from, ?string $to): Collection
    {
        return $this->between(BankTransfer::query()->with(['from_account:id,account_name', 'to_account:id,account_name'])
            ->where('school_id', $actor->school_id)->completed()
            ->where(fn ($query) => $query->whereIn('from_account_id', $accounts)->orWhereIn('to_account_id', $accounts)), $from, $to, 'transfer_date')
            ->get()->map(function (BankTransfer $row) use ($accounts, $selectedAccountId) {
                // An all-account view is an internal movement, not a receipt
                // plus payment. Render it once and keep Money In/Out neutral.
                // A selected account retains its genuine directional view.
                $out = $selectedAccountId && $accounts->contains($row->from_account_id) ? (float) $row->amount : 0;
                $in = $selectedAccountId && $accounts->contains($row->to_account_id) ? (float) $row->amount : 0;
                return $this->row($row->transfer_date, 'bank_transfer', $row->id, $row->reference_no,
                    trim(($row->from_account?->account_name ?? '') . ' → ' . ($row->to_account?->account_name ?? '')),
                    $row->notes, null, trim(($row->from_account?->account_name ?? '') . ' → ' . ($row->to_account?->account_name ?? '')),
                    $in, $out, 'internal', null, __('Internal Transfer'), [
                        'source_type' => 'bank_transfer', 'fund_account_id' => $selectedAccountId,
                        'from_fund_account_id' => $row->from_account_id, 'to_fund_account_id' => $row->to_account_id,
                        'from_fund_account' => $row->from_account?->account_name,
                        'to_fund_account' => $row->to_account?->account_name,
                        'source_amount' => (float) $row->amount, 'created_at_raw' => $row->getRawOriginal('created_at'),
                        'operator_user_id' => $row->created_by,
                    ]);
            });
    }

    private function between($query, ?string $from, ?string $to, string $column = 'date')
    {
        return $query->when($from, fn ($q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
    }

    /** @param array<string, mixed> $metadata */
    private function row($date, string $type, int $sourceId, ?string $reference, ?string $counterparty, ?string $description, ?string $method, ?string $account, float $in, float $out, string $operating, $operator = null, ?string $displayType = null, array $metadata = []): array
    {
        return array_merge([
            'date' => Carbon::parse($date)->toDateString(),
            'transaction_type' => $type,
            'display_type' => $displayType,
            'source_id' => $sourceId,
            'reference' => $reference,
            'counterparty' => $counterparty ?: '-',
            'description' => $description ?: '-',
            'payment_method' => $method ?: '-',
            'fund_account' => $account ?: '-',
            'money_in' => $in,
            'money_out' => $out,
            'operating' => $operating,
            'operator' => $operator ? trim(($operator->first_name ?? '') . ' ' . ($operator->last_name ?? '')) : '-',
            'status' => 'completed',
        ], $metadata);
    }
}

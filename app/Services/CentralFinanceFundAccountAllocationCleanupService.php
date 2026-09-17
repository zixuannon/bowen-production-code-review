<?php

namespace App\Services;

use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Removes obsolete monetary meaning from School allocation rows.
 * The compatibility column remains for rollback safety, but application code
 * never reads it and this exact-path cleanup makes every stored value zero.
 */
final class CentralFinanceFundAccountAllocationCleanupService
{
    public function __construct(
        private readonly CentralFinanceConfigurationAuthorizationService $authorization,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(): array
    {
        $this->assertSchema();

        $allocations = DB::connection('mysql')->table('central_finance_fund_account_school_allocations')
            ->orderBy('fund_account_id')->orderBy('school_id')->get();
        $nonZero = $allocations->filter(static fn ($row): bool => abs((float) $row->opening_allocation_amount) > 0.00005);

        return [
            'status' => $nonZero->isEmpty() ? 'complete' : 'eligible',
            'allocation_rows' => $allocations->count(),
            'legacy_amount_rows' => $nonZero->count(),
            'legacy_amount_account_ids' => $nonZero->pluck('fund_account_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
            'allocation_access_checksum' => $this->allocationAccessChecksum(),
            'account_opening_checksum' => $this->accountOpeningChecksum(),
            'ledger_checksum' => $this->ledgerChecksum(),
        ];
    }

    /** @return array<string,mixed> */
    public function execute(CentralFinanceUser $actor, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reviewed Fund Account V2.1 cleanup reason is required.');
        }

        $before = $this->preflight();
        if ($before['status'] === 'complete') {
            return $before;
        }

        $groupIds = CentralFinanceFundAccount::on('mysql')->withTrashed()
            ->whereIn('id', $before['legacy_amount_account_ids'])
            ->pluck('group_id')->map(static fn ($id): int => (int) $id)->unique()->values();
        if ($groupIds->isEmpty()) {
            throw new RuntimeException('Legacy allocation amounts do not resolve to Fund Accounts; zero rows were changed.');
        }
        foreach ($groupIds as $groupId) {
            $this->authorization->assertHeadFinanceCanConfigureGroup($actor, $groupId);
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $reason, $before): array {
            $locked = DB::connection('mysql')->table('central_finance_fund_account_school_allocations')
                ->orderBy('fund_account_id')->orderBy('school_id')->lockForUpdate()->get();
            $lockedIds = $locked->filter(static fn ($row): bool => abs((float) $row->opening_allocation_amount) > 0.00005)
                ->pluck('fund_account_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
            if ($lockedIds !== $before['legacy_amount_account_ids']) {
                throw new RuntimeException('Legacy allocation target set changed after preflight; zero rows were changed.');
            }

            $lockedAccounts = CentralFinanceFundAccount::on('mysql')->withTrashed()
                ->whereIn('id', $lockedIds)->orderBy('id')->lockForUpdate()->get();
            if ($lockedAccounts->count() !== count($lockedIds)) {
                throw new RuntimeException('A legacy allocation target no longer resolves to a Fund Account; zero rows were changed.');
            }
            foreach ($lockedAccounts->pluck('group_id')->map(static fn ($id): int => (int) $id)->unique() as $groupId) {
                $this->authorization->assertHeadFinanceCanConfigureGroup($actor, $groupId);
            }

            $rowsChanged = DB::connection('mysql')->table('central_finance_fund_account_school_allocations')
                ->where('opening_allocation_amount', '<>', 0)
                ->update(['opening_allocation_amount' => 0]);

            foreach ($lockedIds as $accountId) {
                $account = CentralFinanceFundAccount::on('mysql')->withTrashed()->findOrFail($accountId);
                CentralFinanceDocumentAudit::on('mysql')->create([
                    'school_id' => null,
                    'group_id' => (int) $account->group_id,
                    'document_type' => 'fund_account',
                    'document_id' => (int) $account->id,
                    'action' => 'legacy_school_allocation_amounts_cleared',
                    'actor_id' => (int) $actor->id,
                    'reason' => $reason,
                    'before_values' => ['legacy_amount_rows' => $locked->where('fund_account_id', $accountId)->filter(static fn ($row): bool => abs((float) $row->opening_allocation_amount) > 0.00005)->count()],
                    'after_values' => ['legacy_amount_rows' => 0, 'allocation_semantics' => 'access_only'],
                ]);
            }

            $after = $this->preflight();
            if ($after['status'] !== 'complete'
                || $after['allocation_access_checksum'] !== $before['allocation_access_checksum']
                || $after['account_opening_checksum'] !== $before['account_opening_checksum']
                || $after['ledger_checksum'] !== $before['ledger_checksum']) {
                throw new RuntimeException('Financial or allocation access state changed during cleanup; transaction rolled back.');
            }
            $after['rows_changed'] = $rowsChanged;

            return $after;
        });
    }

    private function assertSchema(): void
    {
        foreach (['central_finance_fund_accounts', 'central_finance_fund_account_school_allocations', 'central_finance_ledger_entries', 'central_finance_document_audits'] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException("Fund Account V2.1 cleanup requires {$table}.");
            }
        }
        if (!Schema::connection('mysql')->hasColumn('central_finance_fund_account_school_allocations', 'opening_allocation_amount')) {
            throw new RuntimeException('Fund Account V2.1 compatibility column is absent; no cleanup was run.');
        }
    }

    private function allocationAccessChecksum(): string
    {
        $rows = DB::connection('mysql')->table('central_finance_fund_account_school_allocations')
            ->orderBy('fund_account_id')->orderBy('school_id')
            ->get(['id', 'fund_account_id', 'school_id', 'effective_from', 'effective_to', 'status', 'is_active', 'assigned_by', 'assignment_reason', 'created_at', 'updated_at']);

        return hash('sha256', $rows->map(static fn ($row): string => json_encode((array) $row, JSON_UNESCAPED_SLASHES))->implode("\n"));
    }

    private function accountOpeningChecksum(): string
    {
        $rows = CentralFinanceFundAccount::on('mysql')->withTrashed()->orderBy('id')
            ->get(['id', 'account_uuid', 'account_code', 'opening_balance', 'currency']);

        return hash('sha256', $rows->map(static fn (CentralFinanceFundAccount $row): string => implode('|', [
            $row->id, $row->account_uuid, $row->account_code, $row->opening_balance, $row->currency,
        ]))->implode("\n"));
    }

    private function ledgerChecksum(): string
    {
        $rows = CentralFinanceLedgerEntry::on('mysql')->orderBy('id')
            ->get(['id', 'entry_uuid', 'school_id', 'fund_account_id', 'money_in', 'money_out', 'operating_income', 'operating_expense']);

        return hash('sha256', $rows->map(static fn (CentralFinanceLedgerEntry $row): string => implode('|', [
            $row->id, $row->entry_uuid, $row->school_id, $row->fund_account_id,
            $row->money_in, $row->money_out, $row->operating_income, $row->operating_expense,
        ]))->implode("\n"));
    }
}

<?php

namespace App\Services;

use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountSchoolAllocation;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One reviewed forward conversion for the known Bowen physical accounts.
 * It changes ownership metadata only; financial history and opening balances
 * are checksum-protected and never rewritten.
 */
final class CentralFinanceFundAccountV2ConversionService
{
    public const TARGET_CODES = ['B-0001', 'M-0001'];

    public function __construct(
        private readonly CentralFinanceConfigurationAuthorizationService $authorization,
    ) {}

    /** @return array<string,array<string,mixed>> */
    public function preflight(): array
    {
        $this->assertSchema();
        $accounts = CentralFinanceFundAccount::on('mysql')->withTrashed()
            ->whereIn('account_code', self::TARGET_CODES)
            ->orderBy('account_code')
            ->get()
            ->keyBy('account_code');

        $result = [];
        foreach (self::TARGET_CODES as $code) {
            $account = $accounts->get($code);
            if ($account === null) {
                $result[$code] = ['status' => 'not_present'];
                continue;
            }

            $allocationSchoolIds = CentralFinanceFundAccountSchoolAllocation::on('mysql')
                ->where('fund_account_id', $account->id)
                ->effective()
                ->orderBy('school_id')
                ->pluck('school_id')
                ->map(static fn ($id): int => (int) $id)
                ->values();
            $ledgerSchoolIds = CentralFinanceLedgerEntry::on('mysql')
                ->where('fund_account_id', $account->id)
                ->distinct()
                ->orderBy('school_id')
                ->pluck('school_id')
                ->map(static fn ($id): int => (int) $id)
                ->values();

            $result[$code] = [
                'status' => $account->owner_type === CentralFinanceFundAccount::OWNER_HQ && $account->school_id === null
                    ? 'already_converted'
                    : 'eligible',
                'account_id' => (int) $account->id,
                'group_id' => (int) $account->group_id,
                'owner_type' => (string) $account->owner_type,
                'school_id' => $account->school_id === null ? null : (int) $account->school_id,
                'opening_balance' => (string) $account->opening_balance,
                'allocation_school_ids' => $allocationSchoolIds->all(),
                'ledger_school_ids' => $ledgerSchoolIds->all(),
                'ledger_checksum' => $this->ledgerChecksum((int) $account->id),
            ];
        }

        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    public function execute(CentralFinanceUser $actor, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A reviewed Fund Account V2 conversion reason is required.');
        }

        $before = $this->preflight();
        if (collect($before)->contains(fn (array $state): bool => ($state['status'] ?? null) === 'not_present')) {
            throw new RuntimeException('The exact B-0001/M-0001 conversion set is incomplete; zero ownership rows were changed.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $reason, $before): array {
            $accounts = CentralFinanceFundAccount::on('mysql')->withTrashed()
                ->whereIn('account_code', self::TARGET_CODES)
                ->orderBy('account_code')
                ->lockForUpdate()
                ->get();

            foreach ($accounts as $account) {
                $this->authorization->assertHeadFinanceCanConfigureGroup($actor, (int) $account->group_id);
                $state = $before[$account->account_code];
                if ($state['status'] === 'already_converted') {
                    continue;
                }
                if ($account->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL || $account->school_id === null) {
                    throw new RuntimeException("{$account->account_code} has unexpected ownership metadata; zero conversion was committed.");
                }
                if ($state['allocation_school_ids'] === []) {
                    throw new RuntimeException("{$account->account_code} has no explicit active School allocation.");
                }
                if (!in_array((int) $account->school_id, $state['allocation_school_ids'], true)) {
                    throw new RuntimeException("{$account->account_code} legacy owner School is not protected by an active allocation.");
                }
                if (array_diff($state['ledger_school_ids'], $state['allocation_school_ids']) !== []) {
                    throw new RuntimeException("{$account->account_code} has Ledger activity outside its explicit allocations.");
                }

                $account->forceFill([
                    'owner_type' => CentralFinanceFundAccount::OWNER_HQ,
                    'school_id' => null,
                ])->save();

                CentralFinanceDocumentAudit::on('mysql')->create([
                    'school_id' => null,
                    'group_id' => (int) $account->group_id,
                    'document_type' => 'fund_account',
                    'document_id' => (int) $account->id,
                    'action' => 'group_ownership_migrated',
                    'actor_id' => (int) $actor->id,
                    'reason' => trim($reason),
                    'before_values' => [
                        'owner_type' => CentralFinanceFundAccount::OWNER_SCHOOL,
                        'school_id' => $state['school_id'],
                        'opening_balance' => $state['opening_balance'],
                        'allocation_school_ids' => $state['allocation_school_ids'],
                        'ledger_checksum' => $state['ledger_checksum'],
                    ],
                    'after_values' => [
                        'owner_type' => CentralFinanceFundAccount::OWNER_HQ,
                        'school_id' => null,
                        'opening_balance' => $state['opening_balance'],
                        'allocation_school_ids' => $state['allocation_school_ids'],
                        'ledger_checksum' => $this->ledgerChecksum((int) $account->id),
                    ],
                ]);
            }

            $after = $this->preflight();
            foreach (self::TARGET_CODES as $code) {
                if (($after[$code]['ledger_checksum'] ?? null) !== ($before[$code]['ledger_checksum'] ?? null)
                    || ($after[$code]['opening_balance'] ?? null) !== ($before[$code]['opening_balance'] ?? null)
                    || ($after[$code]['account_id'] ?? null) !== ($before[$code]['account_id'] ?? null)) {
                    throw new RuntimeException("{$code} financial identity changed during ownership conversion.");
                }
            }

            return $after;
        });
    }

    private function assertSchema(): void
    {
        foreach (['central_finance_fund_accounts', 'central_finance_fund_account_school_allocations', 'central_finance_ledger_entries', 'central_finance_document_audits'] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException("Fund Account V2 requires {$table}.");
            }
        }
        if (!Schema::connection('mysql')->hasColumns('central_finance_document_audits', ['group_id', 'school_id'])) {
            throw new RuntimeException('Fund Account V2 audit context migration is not complete.');
        }
    }

    /** @return array{count:int,money_in:string,money_out:string,operating_income:string,operating_expense:string,id_hash:string} */
    private function ledgerChecksum(int $accountId): array
    {
        $rows = CentralFinanceLedgerEntry::on('mysql')
            ->where('fund_account_id', $accountId)
            ->orderBy('id')
            ->get(['id', 'entry_uuid', 'school_id', 'money_in', 'money_out', 'operating_income', 'operating_expense']);

        return [
            'count' => $rows->count(),
            'money_in' => number_format((float) $rows->sum('money_in'), 4, '.', ''),
            'money_out' => number_format((float) $rows->sum('money_out'), 4, '.', ''),
            'operating_income' => number_format((float) $rows->sum('operating_income'), 4, '.', ''),
            'operating_expense' => number_format((float) $rows->sum('operating_expense'), 4, '.', ''),
            'id_hash' => hash('sha256', $rows->map(static fn (CentralFinanceLedgerEntry $row): string => implode('|', [
                $row->id, $row->entry_uuid, $row->school_id, $row->money_in, $row->money_out,
                $row->operating_income, $row->operating_expense,
            ]))->implode("\n")),
        ];
    }
}

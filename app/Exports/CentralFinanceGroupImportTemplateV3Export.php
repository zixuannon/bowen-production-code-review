<?php

namespace App\Exports;

use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Group cash-flow import. Summary balances are never imported or posted. */
final class CentralFinanceGroupImportTemplateV3Export implements WithMultipleSheets
{
    public const VERSION = 'group-finance-v3';
    public const ENTRY_ROWS = 250;
    public const HEADINGS = [
        '序号 / No.', '日期 / Date', '校区 / Campus', '学校代码 / School Code',
        '报销人/经办人 / Claimant / Handler', '摘要 / Description', '科目类型 / Account Type',
        '科目代码 / Account Code', '科目名称 / Account Name', '资金账户代码 / Fund Account Code',
        '资金账户名称 / Fund Account Name', '付款方式 / Payment Method', '收入金额 / Incoming',
        '支出金额 / Outgoing', '参考编号 / Reference No.', '备注 / Remarks',
    ];

    /** Inputs are authorized active allocation-expanded lookup snapshots, not school balances. */
    public function __construct(
        private readonly array $schools = [],
        private readonly array $accounts = [],
        private readonly array $categories = [],
    ) {}

    public function headings(): array { return self::HEADINGS; }
    public function array(): array { return []; }

    public function sheets(): array
    {
        $funds = $this->uniqueRows($this->accounts, 'code', ['name', 'account_type', 'owner_holder', 'currency', 'opening_balance', 'incoming', 'outgoing']);
        $chart = $this->uniqueRows($this->categories, 'category_code', ['type', 'name']);
        $activity = $this->schoolActivity();
        $fundRows = [];
        foreach ($funds as $index => $fund) {
            $row = $index + 2;
            $fundRows[] = [(string) $fund['code'], (string) $fund['name'], $fund['account_type'], $fund['owner_holder'] ?? '', $fund['currency'],
                (float) ($fund['opening_balance'] ?? 0),
                '=INDEX(V3_FundIncoming,MATCH("code:"&A'.$row.',V3_FundKeys,0))+SUMPRODUCT(--EXACT(Import!$J$2:$J$251,A'.$row.'),Import!$M$2:$M$251)',
                '=INDEX(V3_FundOutgoing,MATCH("code:"&A'.$row.',V3_FundKeys,0))+SUMPRODUCT(--EXACT(Import!$J$2:$J$251,A'.$row.'),Import!$N$2:$N$251)',
                '=F'.$row.'+G'.$row.'-H'.$row];
        }
        $currencyRows = [];
        $fundLast = max(count($funds) + 1, 2);
        foreach (array_values(array_unique(array_column($funds, 'currency'))) as $index => $currency) {
            $row = $index + 2;
            $currencyRows[] = [$currency,
                '=SUMIF(\'Fund Accounts\'!$E$2:$E$'.$fundLast.',A'.$row.',\'Fund Accounts\'!$F$2:$F$'.$fundLast.')',
                '=SUMIF(\'Fund Accounts\'!$E$2:$E$'.$fundLast.',A'.$row.',\'Fund Accounts\'!$G$2:$G$'.$fundLast.')',
                '=SUMIF(\'Fund Accounts\'!$E$2:$E$'.$fundLast.',A'.$row.',\'Fund Accounts\'!$H$2:$H$'.$fundLast.')',
                '=B'.$row.'+C'.$row.'-D'.$row];
        }
        $schoolRows = [];
        foreach ($activity as $index => $item) {
            $row = $index + 2;
            $incoming = '=Validation!T'.$row;
            $outgoing = '=Validation!U'.$row;
            foreach ($item['fund_codes'] as $code) {
                // Exact textual criteria preserve codes such as 0101 versus 101.
                $criteria = '"'.str_replace('"', '""', $code).'"';
                $incoming .= '+SUMPRODUCT(--EXACT(Import!$D$2:$D$251,A'.$row.'),--EXACT(Import!$J$2:$J$251,'.$criteria.'),Import!$M$2:$M$251)';
                $outgoing .= '+SUMPRODUCT(--EXACT(Import!$D$2:$D$251,A'.$row.'),--EXACT(Import!$J$2:$J$251,'.$criteria.'),Import!$N$2:$N$251)';
            }
            $schoolRows[] = [$item['school_code'], $item['school_name'], $item['currency'], $incoming, $outgoing, '=D'.$row.'-E'.$row];
        }

        return [
            new CentralFinanceGroupImportV3ImportSheet(),
            new CentralFinanceGroupImportV3ReferenceSheet('Schools', ['学校代码 / School Code', '校区 / Campus'], array_map(fn ($school) => [$school['code'], $school['name']], $this->schools)),
            new CentralFinanceGroupImportV3ReferenceSheet('Chart of Accounts', ['科目类型 / Account Type', '科目代码 / Account Code', '科目名称 / Account Name'], array_map(fn ($account) => [ucfirst($account['type']), $account['category_code'], $account['name']], $chart)),
            new CentralFinanceGroupImportV3ReferenceSheet('Account Allocations', ['科目代码 / Account Code', '学校代码 / School Code'], $this->allocations($this->categories, 'category_code')),
            new CentralFinanceGroupImportV3ReferenceSheet('Fund Accounts', ['资金账户代码 / Fund Account Code', '资金账户名称 / Fund Account Name', '账户类型 / Account Type', '账户持有人 / Owner / Holder', '币种 / Currency', '期初余额 / Opening Balance', '流入 / Incoming', '流出 / Outgoing', '预计期末余额 / Closing Balance'], $fundRows, ['G', 'H', 'I'], ['F', 'G', 'H', 'I'], 'Incoming/Outgoing include the canonical ledger snapshot plus unconfirmed Import rows. Closing is a projection, not a posted balance. Each physical account appears once. / 流入和流出包含账本快照及未确认导入行；期末余额仅为预计值，不代表已入账余额。'),
            new CentralFinanceGroupImportV3ReferenceSheet('Fund Allocations', ['资金账户代码 / Fund Account Code', '学校代码 / School Code'], $this->allocations($this->accounts, 'code')),
            new CentralFinanceGroupImportV3ReferenceSheet('By Currency', ['币种 / Currency', '期初 / Opening', '流入 / Incoming', '流出 / Outgoing', '预计期末 / Closing'], $currencyRows, ['B', 'C', 'D', 'E'], ['B', 'C', 'D', 'E']),
            new CentralFinanceGroupImportV3ReferenceSheet('By School', ['学校代码 / School Code', '校区 / Campus', '币种 / Currency', '流入 / Incoming', '流出 / Outgoing', '净变动 / Net Movement'], $schoolRows, ['D', 'E', 'F'], ['D', 'E', 'F'], 'This School Activity only, grouped by currency. It is not the physical account balance. Includes canonical activity plus unconfirmed Import rows. / 仅本校分币种活动，不代表资金账户实际余额。'),
            new CentralFinanceGroupImportV3ValidationSheet($this->schools, $funds, $chart, $this->accounts, $this->categories, $activity),
        ];
    }

    private function uniqueRows(array $rows, string $codeField, array $properties): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $code = (string) ($row[$codeField] ?? '');
            if ($code === '') throw new InvalidArgumentException('V3 lookup requires a canonical text code.');
            $key = 'code:'.$code;
            if (isset($unique[$key])) {
                foreach ($properties as $property) {
                    if ((string) ($unique[$key][$property] ?? '') !== (string) ($row[$property] ?? '')) {
                        throw new InvalidArgumentException('Conflicting V3 lookup metadata for code '.$code.'.');
                    }
                }
            } else $unique[$key] = $row;
        }
        return array_values($unique);
    }

    private function allocations(array $rows, string $codeField): array
    {
        $allowed = array_fill_keys(array_column($this->schools, 'code'), true);
        $result = [];
        foreach ($rows as $row) {
            $school = (string) ($row['school_code'] ?? '');
            if (!isset($allowed[$school])) continue;
            $code = (string) $row[$codeField];
            $result[$code."\0".$school] = [$code, $school];
        }
        return array_values($result);
    }

    private function schoolActivity(): array
    {
        $result = [];
        foreach ($this->schools as $school) {
            foreach ($this->accounts as $account) {
                if (($account['school_code'] ?? null) !== $school['code']) continue;
                $key = $school['code']."\0".$account['currency'];
                $result[$key] ??= ['school_code' => $school['code'], 'school_name' => $school['name'], 'currency' => $account['currency'], 'incoming' => 0, 'outgoing' => 0, 'fund_codes' => []];
                if (in_array((string) $account['code'], $result[$key]['fund_codes'], true)) continue;
                $result[$key]['incoming'] += (float) ($account['school_incoming'] ?? 0);
                $result[$key]['outgoing'] += (float) ($account['school_outgoing'] ?? 0);
                $result[$key]['fund_codes'][] = (string) $account['code'];
            }
        }
        return array_values($result);
    }
}

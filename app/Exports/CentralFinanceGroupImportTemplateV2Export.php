<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Group Finance Import V2.1 is an Excel-only usability layer over the
 * immutable V2 parser contract. The Import sheet always retains the exact
 * canonical headings consumed by CentralFinanceGroupImportService.
 *
 * @phpstan-type SchoolLookup array{code:string,name:string}
 * @phpstan-type AccountLookup array{code:string,name:string,school_code:?string,account_type:string,owner_type:string,currency:string}
 * @phpstan-type CategoryLookup array{school_code:string,type:string,category_code:string,name:string}
 */
final class CentralFinanceGroupImportTemplateV2Export implements WithMultipleSheets
{
    public const VERSION = 'group-finance-v2';

    public const HEADINGS = [
        '序号', 'School Code', '校区', '日期', '报销人', '摘要', 'Fund Account Code',
        'Fund Account Type', 'Account Owner', 'Category Code', '付款方式', '收入', '支出',
        '余款', 'Reference / 单据号', 'Currency', '备注',
    ];

    /** @param list<SchoolLookup> $schools @param list<AccountLookup> $accounts @param list<CategoryLookup> $categories */
    public function __construct(
        private readonly array $schools = [],
        private readonly array $accounts = [],
        private readonly array $categories = [],
    ) {}

    /** The parser and existing contract tests intentionally consume this directly. */
    public function headings(): array
    {
        return self::HEADINGS;
    }

    /** Retained for callers that use the original simple-export contract. */
    public function array(): array
    {
        return [[
            '1', 'SCH202615', 'Zixuan', '2026-09-02', 'Claimant', 'Description', 'ZIX-CASH',
            'cash', 'school', 'SUPPLIES-1', 'Cash', '1000', '', '', 'REF-001', 'MMK', '',
        ]];
    }

    public function sheets(): array
    {
        return [
            new CentralFinanceGroupImportV21ImportSheet($this->schools, $this->accounts, $this->categories),
            new CentralFinanceGroupImportV21LookupSheet('Schools', ['School Code', '校区'], array_map(
                static fn (array $school): array => [$school['code'], $school['name']],
                $this->schools,
            )),
            new CentralFinanceGroupImportV21LookupSheet('Fund Accounts', ['Fund Account Code', 'Account Name', 'School Code', 'Fund Account Type', 'Account Owner', 'Currency'], array_map(
                static fn (array $account): array => [$account['code'], $account['name'], $account['school_code'] ?? 'HQ', $account['account_type'], $account['owner_type'], $account['currency']],
                $this->accounts,
            )),
            new CentralFinanceGroupImportV21LookupSheet('Categories', ['School Code', 'Document Type', 'Category Code', 'Category Name'], array_map(
                static fn (array $category): array => [$category['school_code'], $category['type'], $category['category_code'], $category['name']],
                $this->categories,
            )),
            new CentralFinanceGroupImportV21ValidationListsSheet($this->schools, $this->accounts, $this->categories),
        ];
    }
}

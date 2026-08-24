<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Central Expense Import Template V1 — direct historical expense entry only. */
final class CentralExpenseImportTemplateExport implements FromArray, WithHeadings
{
    public const VERSION = 'central-expense-v1';

    public function headings(): array
    {
        return ['日期', '报销人', '摘要', '校区', 'Fund Account Code', 'Account Type', '费用类别', '付款方式', '支出', 'Reference No', '备注', '余款'];
    }

    public function array(): array
    {
        return [[
            '2026-08-24', 'Historical claimant', 'School supplies', 'Selected School', 'ZIX-CASH', 'School', 'Supplies', 'Cash', '50000', 'EXP-20260824-001', 'Optional note', '',
        ]];
    }
}

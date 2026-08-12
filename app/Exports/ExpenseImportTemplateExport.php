<?php

namespace App\Exports;

use App\Support\ExpenseImportTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ExpenseImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array { return ExpenseImportTemplate::HEADINGS; }
    public function array(): array { return [[date('Y-m-d'), 'Office Supplies', '', 'Sample expense', 'EXP-QA-001', 1000, 'Cash', 'Cash Drawer', 'Replace this row', date('Y')]]; }
}

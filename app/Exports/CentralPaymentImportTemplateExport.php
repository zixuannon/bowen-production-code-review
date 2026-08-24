<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Central Finance Payment Import Template V1. */
final class CentralPaymentImportTemplateExport implements FromArray, WithHeadings
{
    public const VERSION = 'central-payment-v1';

    public function headings(): array
    {
        return [
            'Payment Date', 'Student UUID', 'Student Code', 'Receivable Reference',
            'Fund Account Code', 'Amount', 'Payment Method', 'Payment Reference', 'Remarks',
        ];
    }

    public function array(): array
    {
        return [[
            '2026-08-24', 'student-source-uuid', 'student-code', 'receivable-uuid',
            'ZIX-CASH', '123456', 'Cash', 'PAY-20260824-001', 'Optional import note',
        ]];
    }
}

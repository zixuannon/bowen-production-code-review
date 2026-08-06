<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * FeesPaidSampleExport
 *
 * Generates a downloadable Excel template for Fees Paid batch import.
 * All fields are business identifiers — NO internal database IDs.
 *
 * Column mapping (A–K):
 *   Student Admission No, Academic Year, Class Name, Fee Structure Name,
 *   Bank Account Name, Installment Name, Payment Date, Amount (MMK),
 *   Payment Mode, Cheque No, Reference No
 */
class FeesPaidSampleExport implements FromArray, WithHeadings, WithColumnWidths, WithStrictNullComparison, WithEvents
{
    protected array $sampleRows;

    public function __construct(array $sampleRows = [])
    {
        $this->sampleRows = $sampleRows;
    }

    /**
     * Column headers — business fields only, no DB IDs.
     */
    public function headings(): array
    {
        return [
            'Student Admission No',  // A
            'Academic Year',         // B
            'Class Name',            // C
            'Fee Structure Name',    // D
            'Bank Account Name',     // E
            'Installment Name',      // F
            'Payment Date',          // G  — YYYY-MM-DD
            'Amount (MMK)',          // H
            'Payment Mode',          // I  — Cash/Cheque/KBZ Pay/etc.
            'Cheque No',             // J  — only if mode is Cheque
            'Reference No',          // K
        ];
    }

    /**
     * Sample data rows.
     */
    public function array(): array
    {
        if (!empty($this->sampleRows)) {
            return $this->sampleRows;
        }

        return [
            [
                'ADM-2025-001',             // Student Admission No
                '2025-2026',                // Academic Year
                'Grade 1',                  // Class Name
                'Tuition Fee',              // Fee Structure Name
                '',                         // Bank Account Name
                '',                         // Installment Name
                '2025-01-15',               // Payment Date
                50000,                      // Amount (MMK)
                'Cash',                     // Payment Mode
                '',                         // Cheque No
                'INV-20250101-001',         // Reference No
            ],
            [
                'ADM-2025-002',             // Student Admission No
                '2025-2026',                // Academic Year
                'Grade 1',                  // Class Name
                'Tuition Fee',              // Fee Structure Name
                '',                         // Bank Account Name
                '',                         // Installment Name
                '2025-01-16',               // Payment Date
                30000,                      // Amount (MMK)
                'KBZ Pay',                  // Payment Mode
                '',                         // Cheque No
                'INV-20250101-002',         // Reference No
            ],
            [
                'ADM-2025-003',             // Student Admission No
                '2025-2026',                // Academic Year
                'Grade 1',                  // Class Name
                'Tuition Fee',              // Fee Structure Name
                'Main Bank Account',        // Bank Account Name
                'Term 1',                   // Installment Name
                '2025-01-17',               // Payment Date
                75000,                      // Amount (MMK)
                'Cheque',                   // Payment Mode
                'CHQ-00123',                // Cheque No
                'INV-20250101-003',         // Reference No
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 18,
            'C' => 16,
            'D' => 22,
            'E' => 22,
            'F' => 22,
            'G' => 18,
            'H' => 18,
            'I' => 18,
            'J' => 18,
            'K' => 22,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Bold header row
                $sheet->getStyle('A1:K1')->getFont()->setBold(true);

                // Header background (light blue)
                $sheet->getStyle('A1:K1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFDCE6F1');

                // Freeze header row
                $sheet->freezePane('A2');
            },
        ];
    }
}

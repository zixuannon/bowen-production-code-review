<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;

class BankAccountSummaryExport implements FromArray, WithHeadings, ShouldAutoSize, WithStrictNullComparison, WithEvents
{
    protected array $rows;
    protected string $dateFrom;
    protected string $dateTo;
    protected ?string $bankAccountId;

    public function __construct(array $rows, string $dateFrom, string $dateTo, ?string $bankAccountId)
    {
        $this->rows          = $rows;
        $this->dateFrom      = $dateFrom;
        $this->dateTo        = $dateTo;
        $this->bankAccountId = $bankAccountId;
    }

    /**
     * Build the export rows in two sections:
     *   Section 1: Report Summary (label-value)
     *   Section 2: Bank Account Summary Table
     */
    public function array(): array
    {
        $data = [];

        // ── Section 1: Report Summary ──
        $data[] = ['Bank Account Summary Report'];
        $data[] = ['Date From', $this->dateFrom];
        $data[] = ['Date To', $this->dateTo];
        $data[] = ['Bank Account Filter', $this->bankAccountId
            ? ($this->rows[0]['account_name'] ?? '-')
            : 'All Accounts'];
        $data[] = [''];

        // ── Section 2: Summary Table ──
        $data[] = ['Account Name', 'Bank', 'Period Opening Balance', 'Income During Period', 'Expense During Period', 'Closing Balance'];

        $totalOpening = 0;
        $totalIncome  = 0;
        $totalExpense = 0;
        $totalClosing = 0;

        foreach ($this->rows as $row) {
            $data[] = [
                $row['account_name'],
                $row['bank_name'],
                $row['period_opening_balance'],
                $row['period_income'],
                $row['period_expense'],
                $row['closing_balance'],
            ];
            $totalOpening += $row['period_opening_balance'];
            $totalIncome  += $row['period_income'];
            $totalExpense += $row['period_expense'];
            $totalClosing += $row['closing_balance'];
        }

        // Total row
        $data[] = [
            'TOTAL', '',
            round($totalOpening, 2),
            round($totalIncome, 2),
            round($totalExpense, 2),
            round($totalClosing, 2),
        ];

        return $data;
    }

    /**
     * Generic headings placeholders — the real headings are in
     * Section 2 and the layout is free-form via FromArray.
     */
    public function headings(): array
    {
        return ['Field', 'Value', '', '', '', ''];
    }

    /**
     * Register sheet events for styling.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Bold the report title (row 1)
                $sheet->getStyle('A1:A1')->getFont()->setBold(true)->setSize(13);

                // Bold table header row: Section 1 has 5 rows (0-4), so header is row 6
                $headerRow = 6;
                $sheet->getStyle("A{$headerRow}:F{$headerRow}")->getFont()->setBold(true);

                // Bold the TOTAL row
                $totalRow = $headerRow + count($this->rows) + 1;
                $sheet->getStyle("A{$totalRow}:F{$totalRow}")->getFont()->setBold(true);

                // Number format for amount columns (C-F) in data rows
                $dataStart = $headerRow + 1;
                if ($dataStart <= $totalRow) {
                    $sheet->getStyle("C{$dataStart}:F{$totalRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                }
            },
        ];
    }
}

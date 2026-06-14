<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;

class FinanceReportExport implements FromArray, WithHeadings, ShouldAutoSize, WithStrictNullComparison, WithEvents
{
    protected array $data;
    protected string $schoolName;
    protected string $from;
    protected string $to;
    protected string $typeFilter;
    protected ?string $categoryFilter;

    public function __construct(array $data, string $schoolName, string $from, string $to, string $typeFilter, ?string $categoryFilter)
    {
        $this->data           = $data;
        $this->schoolName     = $schoolName;
        $this->from           = $from;
        $this->to             = $to;
        $this->typeFilter     = $typeFilter;
        $this->categoryFilter = $categoryFilter;
    }

    /**
     * Build the export rows:
     *   Section 1: Report Summary (label-value pairs)
     *   Section 2: Category Breakdown (table)
     */
    public function array(): array
    {
        $rows = [];

        // ── Section 1: Report Summary ──
        $rows[] = [__('Report Summary')];
        $rows[] = [__('School'), $this->schoolName];
        $rows[] = [__('Date From'), $this->from];
        $rows[] = [__('Date To'), $this->to];

        $typeLabel = match ($this->typeFilter) {
            'income'  => __('Income only'),
            'expense' => __('Expense only'),
            default   => __('All (Income + Expense)'),
        };
        $rows[] = [__('Type Filter'), $typeLabel];
        $rows[] = [__('Finance Category Filter'), $this->categoryFilter ?: __('All')];

        $rows[] = [''];
        $rows[] = [__('Total Income (MMK)'), $this->data['totalIncome']];
        $rows[] = [__('Total Expense (MMK)'), $this->data['totalExpense']];
        $rows[] = [__('Net Income (MMK)'), $this->data['netIncome']];
        $rows[] = [__('Compulsory Income (MMK)'), $this->data['totalCompulsoryIncome']];
        $rows[] = [__('Optional Income (MMK)'), $this->data['totalOptionalIncome']];
        $rows[] = [__('Current Outstanding (MMK)'), $this->data['currentOutstanding']];
        $rows[] = [__('Note'), __('Outstanding is reference only and is not included in Total Income or Net Income.')];

        // ── Section 2: Category Breakdown ──
        $rows[] = [''];
        $rows[] = [__('Category Breakdown')];

        $categoryRows = $this->data['categoryRows'] ?? collect();

        foreach ($categoryRows as $row) {
            $rows[] = [
                $row['category'],
                $row['type'],
                $row['source'],
                $row['amount'],
                $row['count'],
                $row['percentage'] . '%',
            ];
        }

        return $rows;
    }

    /**
     * Headings: generic enough for the whole sheet (used only as
     * the first row; Section 2 data rows are wider but we pad.)
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

                // Bold the "Report Summary" title (row 1)
                $sheet->getStyle('A1:A1')->getFont()->setBold(true)->setSize(13);

                // Bold the "Category Breakdown" title
                $row = 16; // after summary section
                $sheet->getStyle("A{$row}:A{$row}")->getFont()->setBold(true)->setSize(13);

                // Set number format for amount columns
                $highestRow = $sheet->getHighestRow();
                if ($highestRow > 16) {
                    $sheet->getStyle("D17:D{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');
                }
            },
        ];
    }
}

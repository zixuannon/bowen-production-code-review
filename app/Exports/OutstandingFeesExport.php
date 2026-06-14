<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;

class OutstandingFeesExport implements FromArray, WithHeadings, ShouldAutoSize, WithStrictNullComparison, WithEvents
{
    protected $resultRows;
    protected array $summary;
    protected string $schoolName;
    protected array $filters;

    public function __construct($resultRows, array $summary, string $schoolName, array $filters)
    {
        $this->resultRows = $resultRows;
        $this->summary    = $summary;
        $this->schoolName = $schoolName;
        $this->filters    = $filters;
    }

    /**
     * Build export rows:
     *   Section 1: Report Summary
     *   Section 2: Outstanding Fees List
     */
    public function array(): array
    {
        $rows = [];

        $search            = $this->filters['search'] ?? '';
        $classSectionFilter = $this->filters['classSectionFilter'] ?? '';
        $statusFilter       = $this->filters['statusFilter'] ?? '';
        $outstandingOnly    = $this->filters['outstandingOnly'] ?? false;

        // Session year name for display (P2-1: show name instead of ID)
        $filterSessionYearName = $this->filters['filterSessionYearName'] ?? '';
        $filterSessionYearId   = $this->filters['filterSessionYearId'] ?? '';

        // ── Section 1: Report Summary ──
        $rows[] = [__('Outstanding Fees Report')];
        $rows[] = [__('School'), $this->schoolName];
        $rows[] = [__('Export Date'), now()->toDateString()];
        $rows[] = [__('Search Filter'), $search ?: __('All')];
        $rows[] = [__('Class Section Filter'), $classSectionFilter ?: __('All')];
        $rows[] = [__('Session Year Filter'), $filterSessionYearName ?: __('All')];
        $rows[] = [__('Status Filter'), $statusFilter ?: __('All')];
        $rows[] = [__('Outstanding Only'), $outstandingOnly ? __('Yes') : __('No')];

        $rows[] = [''];
        $rows[] = [__('Total Students'), $this->summary['total_students'] ?? 0];
        $rows[] = [__('Total Expected Amount (MMK)'), $this->summary['total_expected'] ?? 0];
        $rows[] = [__('Total Paid Amount (MMK)'), $this->summary['total_paid'] ?? 0];
        $rows[] = [__('Total Outstanding Amount (MMK)'), $this->summary['total_outstanding'] ?? 0];
        $rows[] = [__('Note'), __('Outstanding amount is calculated from compulsory fees only. Optional fees are not included in outstanding.')];

        // ── Section 2: Outstanding Fees List ──
        $rows[] = [''];
        $rows[] = [__('Outstanding Fees List')];
        $rows[] = [
            __('Student Name'),
            __('Admission No'),
            __('Class'),
            __('Section'),
            __('Session Year'),
            __('Contact'),
            __('Expected Amount MMK'),
            __('Compulsory Paid MMK'),
            __('Optional Paid MMK'),
            __('Outstanding Amount MMK'),
            __('Status'),
            __('Last Payment Date'),
            __('User ID'),
        ];

        foreach ($this->resultRows as $row) {
            $rows[] = [
                $row['full_name'],
                $row['admission_no'],
                $row['class_name'],
                $row['section_name'],
                $filterSessionYearName ?: $filterSessionYearId,
                $row['contact'],
                $row['compulsory_expected'],
                $row['compulsory_paid'],
                $row['optional_paid'],
                $row['outstanding'],
                $row['status_label'],
                $row['last_payment_date'],
                $row['user_id'],
            ];
        }

        return $rows;
    }

    /**
     * Headings row (used as first row; actual data rows follow).
     */
    public function headings(): array
    {
        return ['Field', 'Value', '', '', '', '', '', '', '', '', '', '', ''];
    }

    /**
     * Register sheet styling events.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Bold title (row 1)
                $sheet->getStyle('A1:A1')->getFont()->setBold(true)->setSize(13);

                // Bold list section title (row 16 after adding column headers)
                $listTitleRow = 16;
                $sheet->getStyle("A{$listTitleRow}:A{$listTitleRow}")->getFont()->setBold(true)->setSize(13);

                // Bold column header row
                $headerRow = $listTitleRow + 1; // row 17
                $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getFont()->setBold(true);

                // Number format for amount columns (data starts at row 18)
                $highestRow = $sheet->getHighestRow();
                $dataStartRow = $headerRow + 1; // row 18
                if ($highestRow >= $dataStartRow) {
                    $sheet->getStyle("G{$dataStartRow}:J{$highestRow}")
                        ->getNumberFormat()->setFormatCode('#,##0');
                }
            },
        ];
    }
}

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
        $filterSessionYearId = $this->filters['filterSessionYearId'] ?? '';

        // ── Section 1: Report Summary ──
        $rows[] = ['Outstanding Fees Report'];
        $rows[] = ['School', $this->schoolName];
        $rows[] = ['Export Date', now()->toDateString()];
        $rows[] = ['Search Filter', $search ?: 'All'];
        $rows[] = ['Class Section Filter', $classSectionFilter ?: 'All'];
        $rows[] = ['Session Year Filter', $filterSessionYearId ?: 'All'];
        $rows[] = ['Status Filter', $statusFilter ?: 'All'];
        $rows[] = ['Outstanding Only', $outstandingOnly ? 'Yes' : 'No'];

        $rows[] = [''];
        $rows[] = ['Total Students', $this->summary['total_students'] ?? 0];
        $rows[] = ['Total Expected Amount (MMK)', $this->summary['total_expected'] ?? 0];
        $rows[] = ['Total Paid Amount (MMK)', $this->summary['total_paid'] ?? 0];
        $rows[] = ['Total Outstanding Amount (MMK)', $this->summary['total_outstanding'] ?? 0];
        $rows[] = ['Note', 'Outstanding amount is calculated from compulsory fees only. Optional fees are not included in outstanding.'];

        // ── Section 2: Outstanding Fees List ──
        $rows[] = [''];
        $rows[] = ['Outstanding Fees List'];

        foreach ($this->resultRows as $row) {
            $rows[] = [
                $row['full_name'],
                $row['admission_no'],
                $row['class_name'],
                $row['section_name'],
                $this->filters['filterSessionYearId'] ?? '',
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

                // Bold list section title
                $listTitleRow = 17;
                $sheet->getStyle("A{$listTitleRow}:A{$listTitleRow}")->getFont()->setBold(true)->setSize(13);

                // Number format for amount columns
                $highestRow = $sheet->getHighestRow();
                if ($highestRow > $listTitleRow) {
                    $sheet->getStyle("G{$listTitleRow}:J{$highestRow}")
                        ->getNumberFormat()->setFormatCode('#,##0');
                }
            },
        ];
    }
}

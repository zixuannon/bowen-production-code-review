<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;

final class CentralFinanceGroupImportV3ReferenceSheet implements FromArray, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(private readonly string $title, private readonly array $headings, private readonly array $rows, private readonly array $formulaColumns = [], private readonly array $moneyColumns = [], private readonly string $note = '') {}
    public function title(): string { return $this->title; }
    public function headings(): array { return $this->headings; }
    public function array(): array { return $this->rows; }
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $last = Coordinate::stringFromColumnIndex(count($this->headings));
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:'.$last.max(count($this->rows) + 1, 1));
            $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF174A72');
            $sheet->getStyle('A1:'.$last.'1')->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(44);
            foreach ($this->headings as $index => $heading) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getColumnDimension($column)->setWidth(str_contains($heading, 'Name') || str_contains($heading, 'Holder') ? 36 : 25);
                $lastRow = max(2, count($this->rows) + 1);
                if (str_contains($heading, 'Code')) $sheet->getStyle($column.'2:'.$column.$lastRow)->getNumberFormat()->setFormatCode('@');
                if (in_array($column, $this->moneyColumns, true)) $sheet->getStyle($column.'2:'.$column.$lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
                foreach ($this->rows as $rowIndex => $row) {
                    $value = $row[$index] ?? null;
                    // Data-derived text must never become a spreadsheet formula.
                    if (is_string($value) && !in_array($column, $this->formulaColumns, true)) $sheet->setCellValueExplicit($column.($rowIndex + 2), $value, DataType::TYPE_STRING);
                }
            }
            if ($this->note !== '') $sheet->getComment('A1')->getText()->createTextRun($this->note);
        }];
    }
}

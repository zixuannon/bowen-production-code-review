<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** @internal Human-readable reference sheet. */
final class CentralFinanceGroupImportV21LookupSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /** @param list<string> $headings @param list<list<string>> $rows */
    public function __construct(private readonly string $title, private readonly array $headings, private readonly array $rows) {}
    public function title(): string { return $this->title; }
    public function headings(): array { return $this->headings; }
    public function array(): array { return $this->rows; }
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate(); $lastColumn = $sheet->getHighestColumn();
            $sheet->freezePane('A2'); $sheet->setAutoFilter("A1:{$lastColumn}".$sheet->getHighestRow());
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF174A72');
            foreach ($this->headings as $index => $heading) {
                if (str_contains($heading, 'Code')) {
                    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    $sheet->getStyle("{$column}2:{$column}".$sheet->getHighestRow())->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                    foreach ($this->rows as $rowIndex => $row) {
                        if (array_key_exists($index, $row) && $row[$index] !== null) {
                            $sheet->setCellValueExplicit($column.($rowIndex + 2), (string) $row[$index], DataType::TYPE_STRING);
                        }
                    }
                }
            }
        }];
    }
}

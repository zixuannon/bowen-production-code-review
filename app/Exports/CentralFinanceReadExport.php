<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** A deliberately read-only Central Finance export with the eSchool tabular style. */
final class CentralFinanceReadExport implements FromArray, WithHeadings, ShouldAutoSize, WithStrictNullComparison, WithStyles, WithTitle
{
    /** @param list<string> $headings @param list<list<string|int|float|null>> $rows */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function title(): string { return $this->title; }
    public function headings(): array { return $this->headings; }
    public function array(): array { return $this->rows; }

    public function styles(Worksheet $sheet): array
    {
        $last = $sheet->getHighestColumn();
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$last.'1');

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
            ],
        ];
    }
}

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** @internal The editable, School-local Student Import V2 worksheet. */
final class StudentImportV2ImportSheet implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    private const ENTRY_ROWS = 250;

    /** @param list<array{id:int,name:string}> $classSections @param list<array{id:int,name:string}> $academicYears @param list<array{name:string,type:string,required:bool,values:list<string>}> $customFields */
    public function __construct(private readonly array $classSections, private readonly array $academicYears, private readonly array $customFields) {}
    public function title(): string { return 'Import'; }
    public function headings(): array { return array_merge(StudentImportV2TemplateExport::HEADINGS, array_map(static fn (array $field): string => $field['name'], $this->customFields)); }
    public function array(): array { return array_fill(0, self::ENTRY_ROWS, array_fill(0, count($this->headings()), null)); }
    public function columnWidths(): array { return ['A'=>18,'B'=>20,'C'=>20,'D'=>18,'E'=>14,'F'=>15,'G'=>16,'H'=>28,'I'=>28,'J'=>28,'K'=>20,'L'=>20,'M'=>18,'N'=>16,'O'=>28,'P'=>18]; }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate(); $lastColumn = $sheet->getHighestColumn(); $lastRow = self::ENTRY_ROWS + 1;
            $sheet->freezePane('A2'); $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF174A72');
            $sheet->getStyle("A2:{$lastColumn}{$lastRow}")->getAlignment()->setVertical('top');
            foreach (['A','D','M'] as $column) $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            foreach (['F','G'] as $column) $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            for ($index = 1; $index <= Coordinate::columnIndexFromString($lastColumn); $index++) { $column = Coordinate::stringFromColumnIndex($index); $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF8DB'); }
            $this->comment($sheet, 'A1', 'Required. Text format preserves leading zeroes, for example 00125.');
            $this->comment($sheet, 'D1', 'Text format preserves leading zeroes, for example 0912345678.');
            $this->comment($sheet, 'O1', 'Choose a Class Section for this School. Server-side ownership validation remains authoritative.');
            $this->comment($sheet, 'P1', 'Choose an Academic Year for this School. Server-side ownership validation remains authoritative.');
            for ($row = 2; $row <= $lastRow; $row++) {
                $this->list($sheet, "E{$row}", '=StudentImportGenders'); $this->list($sheet, "N{$row}", '=StudentImportGenders');
                $this->list($sheet, "O{$row}", '=StudentImportClassSections'); $this->list($sheet, "P{$row}", '=StudentImportAcademicYears');
                foreach ($this->customFields as $offset => $field) if ($field['values'] !== []) $this->list($sheet, $this->column(17 + $offset).$row, '=StudentImportCustom'.($offset + 1));
            }
        }];
    }

    private function list(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $formula): void
    {
        $v = $sheet->getCell($cell)->getDataValidation();
        $v->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(true)->setShowInputMessage(true)->setShowErrorMessage(true)->setShowDropDown(true)->setErrorTitle('Choose a template value')->setError('Choose a value from the dropdown list.')->setFormula1($formula);
    }
    private function comment(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $text): void { $sheet->getComment($cell)->getText()->createTextRun($text); }
    private function column(int $number): string { $name = ''; while ($number > 0) { $number--; $name = chr(65 + ($number % 26)).$name; $number = intdiv($number, 26); } return $name; }
}

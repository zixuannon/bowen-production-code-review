<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** @internal Hidden validation lists backing Student Import V2 dropdowns. */
final class StudentImportV2ValidationListsSheet implements FromArray, WithEvents, WithTitle
{
    /** @param list<array{id:int,name:string}> $classes @param list<array{id:int,name:string}> $years @param list<array{name:string,type:string,required:bool,values:list<string>}> $fields */
    public function __construct(private readonly array $classes, private readonly array $years, private readonly array $fields) {}
    public function title(): string { return 'Validation Lists'; }
    public function array(): array { $rows = [['Class Section','Academic Year','Gender']]; $max = max(count($this->classes), count($this->years), 2, 1); for ($i=0;$i<$max;$i++) $rows[] = [$this->classes[$i]['name'] ?? '',$this->years[$i]['name'] ?? '',$i===0?'male':($i===1?'female':'')]; return $rows; }
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate(); $book = $sheet->getParent(); $max = max(count($this->classes), count($this->years), 2, 1) + 1;
            $book->addNamedRange(new NamedRange('StudentImportClassSections', $sheet, "\$A\$2:\$A\${$max}")); $book->addNamedRange(new NamedRange('StudentImportAcademicYears', $sheet, "\$B\$2:\$B\${$max}")); $book->addNamedRange(new NamedRange('StudentImportGenders', $sheet, '\$C\$2:\$C\$3'));
            foreach ($this->fields as $index => $field) if ($field['values'] !== []) { $start=$sheet->getHighestRow()+2; foreach ($field['values'] as $offset=>$value) $sheet->setCellValue('E'.($start+$offset),$value); $end=$start+count($field['values'])-1; $book->addNamedRange(new NamedRange('StudentImportCustom'.($index+1),$sheet,"\$E\${$start}:\$E\${$end}")); }
            $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        }];
    }
}

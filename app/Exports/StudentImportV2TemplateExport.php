<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Zixuan Student Import V2: identity and phone fields are explicitly text. */
final class StudentImportV2TemplateExport implements WithMultipleSheets
{
    public const HEADINGS = [
        'Student Code', 'First Name', 'Last Name', 'Mobile', 'Gender', 'Date of Birth', 'Admission Date',
        'Current Address', 'Permanent Address', 'Guardian Email', 'Guardian First Name',
        'Guardian Last Name', 'Guardian Mobile', 'Guardian Gender', 'Class Section', 'Academic Year',
    ];

    /** @param list<array{id:int,name:string}> $classSections @param list<array{id:int,name:string}> $academicYears @param list<array{name:string,type:string,required:bool,values:list<string>}> $customFields */
    public function __construct(
        private readonly array $classSections = [],
        private readonly array $academicYears = [],
        private readonly array $customFields = [],
    ) {}

    /** @return list<string> */
    public function headings(): array
    {
        return array_merge(self::HEADINGS, array_map(static fn (array $field): string => $field['name'], $this->customFields));
    }

    public function sheets(): array
    {
        return [
            new StudentImportV2ImportSheet($this->classSections, $this->academicYears, $this->customFields),
            new StudentImportV2LookupSheet('Class Sections', ['Class Section'], array_map(static fn (array $item): array => [$item['name']], $this->classSections)),
            new StudentImportV2LookupSheet('Academic Years', ['Academic Year'], array_map(static fn (array $item): array => [$item['name']], $this->academicYears)),
            new StudentImportV2LookupSheet('Custom Fields', ['Field', 'Type', 'Allowed Values'], array_map(static fn (array $field): array => [$field['name'], $field['type'], implode(', ', $field['values'])], $this->customFields)),
            new StudentImportV2ValidationListsSheet($this->classSections, $this->academicYears, $this->customFields),
        ];
    }
}

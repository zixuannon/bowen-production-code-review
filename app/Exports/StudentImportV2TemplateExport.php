<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** Zixuan Student Import V2: identity and phone fields are explicitly text. */
final class StudentImportV2TemplateExport implements FromArray, WithHeadings, WithColumnFormatting, ShouldAutoSize, WithTitle
{
    /** @return list<string> */
    public function headings(): array
    {
        return [
            'Student Code', 'First Name', 'Last Name', 'Mobile', 'Gender', 'Date of Birth', 'Admission Date',
            'Current Address', 'Permanent Address', 'Guardian Email', 'Guardian First Name',
            'Guardian Last Name', 'Guardian Mobile', 'Guardian Gender',
        ];
    }

    /** @return list<list<string>> */
    public function array(): array
    {
        return [[
            '00125', 'Student first name', 'Student last name', '0912345678', 'female', '2015-01-31', '2026-09-01',
            'Current address', 'Permanent address', 'guardian@example.test', 'Guardian first name',
            'Guardian last name', '0998765432', 'female',
        ]];
    }

    public function columnFormats(): array
    {
        // Student Code, Student Mobile, and Guardian Mobile must retain leading zeroes.
        return ['A' => NumberFormat::FORMAT_TEXT, 'D' => NumberFormat::FORMAT_TEXT, 'M' => NumberFormat::FORMAT_TEXT];
    }

    public function title(): string
    {
        return 'Student Import V2';
    }
}

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
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** @internal Sheet implementation for the V2.1 human-friendly template. */
final class CentralFinanceGroupImportV21ImportSheet implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    private const ENTRY_ROWS = 250;

    /** @param list<array{code:string,name:string}> $schools @param list<array{code:string,name:string,school_code:?string,account_type:string,owner_type:string,currency:string}> $accounts @param list<array{school_code:string,type:string,category_code:string,name:string}> $categories */
    public function __construct(
        private readonly array $schools,
        private readonly array $accounts,
        private readonly array $categories,
    ) {}

    public function title(): string { return 'Import'; }
    public function headings(): array { return CentralFinanceGroupImportTemplateV2Export::HEADINGS; }

    public function array(): array
    {
        return array_fill(0, self::ENTRY_ROWS, array_fill(0, count(CentralFinanceGroupImportTemplateV2Export::HEADINGS), null));
    }

    public function columnWidths(): array
    {
        return ['A' => 8, 'B' => 17, 'C' => 24, 'D' => 14, 'E' => 20, 'F' => 32, 'G' => 24, 'H' => 20, 'I' => 18, 'J' => 22, 'K' => 20, 'L' => 16, 'M' => 16, 'N' => 16, 'O' => 28, 'P' => 12, 'Q' => 32];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $lastRow = self::ENTRY_ROWS + 1;
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:Q{$lastRow}");
            $sheet->getStyle('A1:Q1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF174A72');
            $sheet->getStyle("A2:Q{$lastRow}")->getAlignment()->setVertical('top');
            $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->getStyle("L2:N{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            foreach (['A', 'B', 'H', 'I', 'P'] as $column) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEAF1F6');
            }
            foreach (['C', 'D', 'E', 'F', 'G', 'J', 'K', 'L', 'M', 'N', 'O', 'Q'] as $column) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF8DB');
            }
            $this->headerComment($sheet, 'B1', 'Auto-filled from the selected 校区. Do not edit the canonical School Code.');
            $this->headerComment($sheet, 'C1', 'Choose the School display name. School Code will be filled automatically.');
            $this->headerComment($sheet, 'G1', 'Choose an exact canonical Fund Account Code for the selected School.');
            $this->headerComment($sheet, 'H1', 'Auto-filled from the selected Fund Account Code.');
            $this->headerComment($sheet, 'I1', 'Auto-filled from the selected Fund Account Code.');
            $this->headerComment($sheet, 'J1', 'Choose an active Category Code after entering Income or Expense.');
            $this->headerComment($sheet, 'O1', 'Required, editable business reference. Existing duplicate/idempotency rules remain unchanged.');
            $this->headerComment($sheet, 'P1', 'Auto-filled from the selected Fund Account Code.');
            for ($row = 2; $row <= $lastRow; $row++) {
                $sheet->setCellValue("A{$row}", "=IF(C{$row}=\"\",\"\",ROW()-1)");
                $sheet->setCellValue("B{$row}", "=IFERROR(INDEX(SchoolCodes,MATCH(C{$row},SchoolNames,0)),\"\")");
                $sheet->setCellValue("H{$row}", "=IFERROR(INDEX(FundAccountTypes,MATCH(G{$row},FundAccountCodes,0)),\"\")");
                $sheet->setCellValue("I{$row}", "=IFERROR(INDEX(FundAccountOwners,MATCH(G{$row},FundAccountCodes,0)),\"\")");
                $sheet->setCellValue("P{$row}", "=IFERROR(INDEX(FundAccountCurrencies,MATCH(G{$row},FundAccountCodes,0)),\"\")");
                $this->listValidation($sheet, "C{$row}", '=SchoolNames');
                $this->listValidation($sheet, "G{$row}", '=INDIRECT("FundAccounts_"&SUBSTITUTE($B'.$row.',"-","_"))');
                $this->listValidation($sheet, "J{$row}", '=INDIRECT("Categories_"&SUBSTITUTE($B'.$row.',"-","_")&"_"&IF($L'.$row.'>0,"income",IF($M'.$row.'>0,"expense","")))');
            }
        }];
    }

    private function listValidation(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $formula): void
    {
        $validation = $sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(true)->setShowInputMessage(true)->setShowErrorMessage(true)->setShowDropDown(true)->setErrorTitle('Use a listed canonical value')->setError('Choose a value from the template lookup list.')->setPromptTitle('Canonical lookup')->setPrompt('Use the dropdown. Server-side Group Import validation remains authoritative.')->setFormula1($formula);
    }

    private function headerComment(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $text): void
    {
        $sheet->getComment($cell)->getText()->createTextRun($text);
    }
}

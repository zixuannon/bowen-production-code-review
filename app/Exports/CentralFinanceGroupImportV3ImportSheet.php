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

final class CentralFinanceGroupImportV3ImportSheet implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function title(): string { return 'Import'; }
    public function headings(): array { return CentralFinanceGroupImportTemplateV3Export::HEADINGS; }
    public function array(): array { return array_fill(0, CentralFinanceGroupImportTemplateV3Export::ENTRY_ROWS, array_fill(0, 16, null)); }
    public function columnWidths(): array
    {
        return ['A' => 10, 'B' => 17, 'C' => 25, 'D' => 23, 'E' => 31, 'F' => 35, 'G' => 24, 'H' => 25, 'I' => 32, 'J' => 30, 'K' => 33, 'L' => 25, 'M' => 22, 'N' => 22, 'O' => 28, 'P' => 36];
    }
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $last = CentralFinanceGroupImportTemplateV3Export::ENTRY_ROWS + 1;
            $sheet->freezePane('D2');
            $sheet->setAutoFilter('A1:P'.$last);
            $sheet->getStyle('A1:P1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A1:P1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF174A72');
            $sheet->getStyle('A1:P1')->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(48);
            $sheet->getStyle('A2:P'.$last)->getAlignment()->setVertical('top');
            $sheet->getStyle('B2:B'.$last)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->getStyle('M2:N'.$last)->getNumberFormat()->setFormatCode('#,##0.00');
            foreach (['D', 'H', 'J', 'O'] as $column) $sheet->getStyle($column.'2:'.$column.$last)->getNumberFormat()->setFormatCode('@');
            foreach (range('A', 'P') as $column) $sheet->getStyle($column.'2:'.$column.$last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(in_array($column, ['A', 'D', 'G', 'I', 'K'], true) ? 'FFEAF1F6' : 'FFFFF8DB');
            $sheet->getComment('C1')->getText()->createTextRun('1. Choose Campus. 2. Choose allocated Account Code and Fund Account Code. Blue cells auto-fill; yellow cells are inputs. / 先选校区，再选已分配科目与资金账户；蓝色自动填充，黄色手工填写。');
            $sheet->getComment('M1')->getText()->createTextRun('Exactly one positive Incoming or Outgoing per transaction. Currency comes from the Fund Account. Preview never posts financial records. / 每笔仅填收入或支出且金额必须大于零，币种取自资金账户，预览不入账。');
            for ($row = 2; $row <= $last; $row++) {
                $sheet->setCellValue('A'.$row, '=IF(C'.$row.'="","",ROW()-1)');
                $sheet->setCellValue('D'.$row, '=IF(C'.$row.'="","",IFERROR(INDEX(V3_SchoolCodes,MATCH(C'.$row.',V3_SchoolNames,0)),""))');
                $sheet->setCellValue('G'.$row, '=IF(H'.$row.'="","",IFERROR(INDEX(V3_AccountTypes,MATCH("code:"&H'.$row.',V3_AccountKeys,0)),""))');
                $sheet->setCellValue('I'.$row, '=IF(H'.$row.'="","",IFERROR(INDEX(V3_AccountNames,MATCH("code:"&H'.$row.',V3_AccountKeys,0)),""))');
                $sheet->setCellValue('K'.$row, '=IF(J'.$row.'="","",IFERROR(INDEX(V3_FundNames,MATCH("code:"&J'.$row.',V3_FundKeys,0)),""))');
                $this->validation($sheet, 'C'.$row, '=V3_SchoolNames');
                $schoolKey = 'IFERROR(INDEX(V3_SchoolKeys,MATCH($D'.$row.',V3_SchoolCodes,0)),"EMPTY")';
                $this->validation($sheet, 'H'.$row, '=INDIRECT("V3_CoA_"&'.$schoolKey.')');
                $this->validation($sheet, 'J'.$row, '=INDIRECT("V3_Fund_"&'.$schoolKey.')');
                $this->validation($sheet, 'L'.$row, '=V3_PaymentMethods');
                $this->validation($sheet, 'M'.$row, '=OR(M'.$row.'="",AND(ISNUMBER(M'.$row.'),M'.$row.'>0,N'.$row.'=""))', true);
                $this->validation($sheet, 'N'.$row, '=OR(N'.$row.'="",AND(ISNUMBER(N'.$row.'),N'.$row.'>0,M'.$row.'=""))', true);
            }
        }];
    }
    private function validation(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $formula, bool $amount = false): void
    {
        $sheet->getCell($cell)->getDataValidation()->setType($amount ? DataValidation::TYPE_CUSTOM : DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(true)->setShowInputMessage(true)->setShowErrorMessage(true)->setShowDropDown(true)
            ->setErrorTitle($amount ? 'Invalid amount / 金额无效' : 'Use a listed value / 请选择列表值')
            ->setError($amount ? 'Enter one positive Incoming OR Outgoing, never both. / 收入支出只能填一个正数。' : 'Choose an active value allocated to the selected School. / 请选择当前校区已分配的有效项目。')
            ->setFormula1($formula);
    }
}

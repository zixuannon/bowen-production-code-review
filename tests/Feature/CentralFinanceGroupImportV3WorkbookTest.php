<?php

namespace Tests\Feature;

use App\Exports\CentralFinanceGroupImportTemplateV3Export;
use App\Exports\CentralFinanceGroupImportV3ValidationSheet;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CentralFinanceGroupImportV3WorkbookTest extends TestCase
{
    private function template(array $extraFunds = []): CentralFinanceGroupImportTemplateV3Export
    {
        $fund = ['code' => '00101', 'name' => 'Group Cash', 'account_type' => 'cash', 'owner_type' => 'hq', 'owner_holder' => 'Bowen Education', 'currency' => 'MMK', 'opening_balance' => '1000.0000', 'incoming' => '500.0000', 'outgoing' => '100.0000'];
        $funds = [$fund + ['school_code' => 'MMBOWEN02', 'school_incoming' => 200, 'school_outgoing' => 100], $fund + ['school_code' => 'MMBOWEN03', 'school_incoming' => 300, 'school_outgoing' => 0],
            array_replace($fund, ['code' => 'USD-1', 'name' => 'Dollar Bank', 'account_type' => 'bank', 'school_code' => 'MMBOWEN03', 'currency' => 'USD', 'opening_balance' => 20, 'incoming' => 4, 'outgoing' => 1, 'school_incoming' => 4, 'school_outgoing' => 1])];
        $chart = [];
        foreach (['asset', 'liability', 'equity', 'income', 'expense'] as $index => $type) foreach (['MMBOWEN02', 'MMBOWEN03'] as $school) $chart[] = ['school_code' => $school, 'type' => $type, 'category_code' => '0'.($index + 1).'01', 'name' => $type === 'income' ? 'Tuition' : ucfirst($type)];
        return new CentralFinanceGroupImportTemplateV3Export(
            [['code' => 'MMBOWEN02', 'name' => 'Bahan'], ['code' => 'MMBOWEN03', 'name' => 'Timecity'], ['code' => 'MMBOWEN04', 'name' => 'Unallocated']],
            [...$funds, ...$extraFunds], $chart,
        );
    }

    public function test_v3_roundtrip_preserves_exact_contract_scope_codes_and_formula_autofill(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'finance-v3-');
        $workbook = null;
        try {
            file_put_contents($path, Excel::raw($this->template(), ExcelFormat::XLSX));
            $workbook = IOFactory::load($path);
            $this->assertSame(['Import', 'Schools', 'Chart of Accounts', 'Account Allocations', 'Fund Accounts', 'Fund Allocations', 'By Currency', 'By School', 'Validation'], $workbook->getSheetNames());
            $sheet = $workbook->getSheetByName('Import');
            $this->assertSame(CentralFinanceGroupImportTemplateV3Export::HEADINGS, $sheet->rangeToArray('A1:P1')[0]);
            foreach (['A', 'D', 'G', 'I', 'K'] as $column) $this->assertSame('', $sheet->getCell($column.'2')->getCalculatedValue());
            $this->assertSame(16, count($this->template()->headings()));
            $this->assertSame('hidden', $workbook->getSheetByName('Validation')->getSheetState());
            $this->assertSame(3, $workbook->getSheetByName('Fund Accounts')->getHighestDataRow());
            $this->assertSame(6, $workbook->getSheetByName('Chart of Accounts')->getHighestDataRow());
            $this->assertSame(11, $workbook->getSheetByName('Account Allocations')->getHighestDataRow());
            $this->assertSame(4, $workbook->getSheetByName('Fund Allocations')->getHighestDataRow());
            $this->assertSame('B', $workbook->getSheetByName('Fund Allocations')->getHighestDataColumn());
            foreach (['D', 'H', 'J', 'O'] as $column) $this->assertSame('@', $sheet->getStyle($column.'2')->getNumberFormat()->getFormatCode());
            $fundCode = $workbook->getSheetByName('Fund Accounts')->getCell('A2');
            $this->assertSame('00101', $fundCode->getValue());
            $this->assertSame(DataType::TYPE_STRING, $fundCode->getDataType());
            $coaCode = $workbook->getSheetByName('Chart of Accounts')->getCell('B2');
            $this->assertSame('0101', $coaCode->getValue());
            $this->assertSame(DataType::TYPE_STRING, $coaCode->getDataType());
            $this->assertSame(['Asset', 'Liability', 'Equity', 'Income', 'Expense'], array_column($workbook->getSheetByName('Chart of Accounts')->rangeToArray('A2:A6'), 0));
            $this->assertSame(['00101'], $this->rangeValues($workbook, 'V3_Fund_'.CentralFinanceGroupImportV3ValidationSheet::schoolKey('MMBOWEN02')));
            $this->assertSame(['00101', 'USD-1'], $this->rangeValues($workbook, 'V3_Fund_'.CentralFinanceGroupImportV3ValidationSheet::schoolKey('MMBOWEN03')));
            $this->assertSame([null], $this->rangeValues($workbook, 'V3_Fund_'.CentralFinanceGroupImportV3ValidationSheet::schoolKey('MMBOWEN04')));
            $sheet->setCellValue('C2', 'Timecity');
            $sheet->setCellValueExplicit('H2', '0401', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('J2', '00101', DataType::TYPE_STRING);
            $sheet->setCellValue('M2', 500);
            $sheet->setCellValue('L2', 'Cash');
            $sheet->setCellValue('O2', 'QA-WORKBOOK-1');
            $workbook->getCalculationEngine()->clearCalculationCache();
            $this->assertSame('MMBOWEN03', $sheet->getCell('D2')->getCalculatedValue());
            $this->assertSame('Income', $sheet->getCell('G2')->getCalculatedValue());
            $this->assertSame('Tuition', $sheet->getCell('I2')->getCalculatedValue());
            $this->assertSame('Group Cash', $sheet->getCell('K2')->getCalculatedValue());
            $this->assertEquals(1900, $workbook->getSheetByName('Fund Accounts')->getCell('I2')->getCalculatedValue());
            $this->assertEquals(1900, $workbook->getSheetByName('By Currency')->getCell('E2')->getCalculatedValue());
            $this->assertEquals(23, $workbook->getSheetByName('By Currency')->getCell('E3')->getCalculatedValue());
            $this->assertEquals(100, $workbook->getSheetByName('By School')->getCell('F2')->getCalculatedValue());
            $this->assertEquals(800, $workbook->getSheetByName('By School')->getCell('F3')->getCalculatedValue());
            $this->assertSame('USD', $workbook->getSheetByName('By School')->getCell('C4')->getValue());
            IOFactory::createWriter($workbook, 'Xlsx')->save($path);
            $workbook->disconnectWorksheets();
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getSheetByName('Import');
            $this->assertSame('MMBOWEN03', $sheet->getCell('D2')->getCalculatedValue());
            $this->assertSame('00101', $sheet->getCell('J2')->getValue());
            $this->assertSame('0401', $sheet->getCell('H2')->getValue());
            $this->assertSame('list', $sheet->getCell('H2')->getDataValidation()->getType());
            $this->assertStringContainsString('V3_CoA_', $sheet->getCell('H2')->getDataValidation()->getFormula1());
            $this->assertStringContainsString('V3_Fund_', $sheet->getCell('J2')->getDataValidation()->getFormula1());
            $this->assertSame('=V3_PaymentMethods', $sheet->getCell('L2')->getDataValidation()->getFormula1());
            $this->assertSame('custom', $sheet->getCell('M2')->getDataValidation()->getType());
            $this->assertSame('=OR(M2="",AND(ISNUMBER(M2),M2>0,N2=""))', $sheet->getCell('M2')->getDataValidation()->getFormula1());
            $this->assertSame('=OR(N2="",AND(ISNUMBER(N2),N2>0,M2=""))', $sheet->getCell('N2')->getDataValidation()->getFormula1());
            $this->assertEquals(1900, $workbook->getSheetByName('Fund Accounts')->getCell('I2')->getCalculatedValue());
            if (getenv('COA_WORKBOOK_NATIVE') === '1') {
                IOFactory::createWriter($workbook, 'Xlsx')->save(sys_get_temp_dir().'/coa-v3-native-acceptance.xlsx');
            }
        } finally {
            $workbook?->disconnectWorksheets();
            if (is_file($path)) unlink($path);
        }
    }

    public function test_conflicting_physical_account_metadata_fails_instead_of_double_counting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting V3 lookup metadata');
        $this->template([['code' => '00101', 'name' => 'Different Account', 'account_type' => 'cash', 'owner_holder' => '', 'currency' => 'USD', 'school_code' => 'MMBOWEN03']])->sheets();
    }

    public function test_text_codes_do_not_merge_in_summary_and_data_cannot_inject_formulas(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'finance-v3-identifiers-');
        $workbook = null;
        try {
            $template = $this->template([['code' => '101', 'name' => '=1+1', 'account_type' => 'bank', 'owner_type' => 'hq', 'owner_holder' => '=HYPERLINK("https://example.invalid","test")', 'currency' => 'MMK', 'school_code' => 'MMBOWEN03', 'opening_balance' => 0, 'incoming' => 0, 'outgoing' => 0]]);
            file_put_contents($path, Excel::raw($template, ExcelFormat::XLSX));
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getSheetByName('Import');
            $sheet->setCellValue('C2', 'Timecity');
            $sheet->setCellValueExplicit('J2', '101', DataType::TYPE_STRING);
            $sheet->setCellValue('M2', 50);
            $workbook->getCalculationEngine()->clearCalculationCache();
            $this->assertSame('=1+1', $sheet->getCell('K2')->getCalculatedValue());
            $funds = $workbook->getSheetByName('Fund Accounts');
            $this->assertSame(DataType::TYPE_STRING, $funds->getCell('B4')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $funds->getCell('D4')->getDataType());
            $this->assertEquals(1400, $funds->getCell('I2')->getCalculatedValue(), '00101 must not include code 101 activity');
            $this->assertEquals(50, $funds->getCell('I4')->getCalculatedValue());
            $this->assertEquals(350, $workbook->getSheetByName('By School')->getCell('F3')->getCalculatedValue(), 'School summary counts each exact account code once');
            $this->assertEquals(1450, $workbook->getSheetByName('By Currency')->getCell('E2')->getCalculatedValue());
        } finally {
            $workbook?->disconnectWorksheets();
            if (is_file($path)) unlink($path);
        }
    }

    public function test_amount_validations_reject_zero_negative_text_and_two_sided_amounts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'finance-v3-amounts-');
        $workbook = null;
        try {
            file_put_contents($path, Excel::raw($this->template(), ExcelFormat::XLSX));
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getSheetByName('Import');
            $sheet->setCellValue('R2', $sheet->getCell('M2')->getDataValidation()->getFormula1());
            $sheet->setCellValue('S2', $sheet->getCell('N2')->getDataValidation()->getFormula1());
            foreach ([[100, null, true, true], [null, 100, true, true], [0, null, false, true], [-1, null, false, true], [100, 100, false, false], ['bad', null, false, true], [null, 0, true, false], [null, -5, true, false]] as [$incoming, $outgoing, $incomingValid, $outgoingValid]) {
                $sheet->setCellValue('M2', $incoming);
                $sheet->setCellValue('N2', $outgoing);
                $workbook->getCalculationEngine()->clearCalculationCache();
                $this->assertSame($incomingValid, $sheet->getCell('R2')->getCalculatedValue());
                $this->assertSame($outgoingValid, $sheet->getCell('S2')->getCalculatedValue());
            }
        } finally {
            $workbook?->disconnectWorksheets();
            if (is_file($path)) unlink($path);
        }
    }

    public function test_empty_workbook_exports_and_has_no_fabricated_lookup_data(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'finance-v3-empty-');
        try {
            file_put_contents($path, Excel::raw(new CentralFinanceGroupImportTemplateV3Export(), ExcelFormat::XLSX));
            $workbook = IOFactory::load($path);
            $this->assertSame('', $workbook->getSheetByName('Import')->getCell('G251')->getCalculatedValue());
            $this->assertNull($workbook->getSheetByName('Fund Accounts')->getCell('A2')->getValue());
            $this->assertNull($workbook->getSheetByName('By Currency')->getCell('A2')->getValue());
            $workbook->disconnectWorksheets();
        } finally { if (is_file($path)) unlink($path); }
    }

    private function rangeValues($workbook, string $name): array
    {
        $range = $workbook->getNamedRange($name);
        return array_column($range->getWorksheet()->rangeToArray(str_replace('$', '', preg_replace('/^.*!/', '', $range->getRange()))), 0);
    }
}

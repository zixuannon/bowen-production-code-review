<?php

namespace Tests\Feature;

use App\Exports\CentralFinanceGroupImportTemplateV2Export;
use App\Exports\FeesPaidSampleExport;
use App\Http\Controllers\CentralFinanceGroupImportController;
use App\Services\CentralFinanceGroupImportService;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

final class CentralFinanceGroupImportPreviewContractTest extends TestCase
{
    public function test_v2_template_uses_only_strict_canonical_identifiers(): void
    {
        $this->assertSame([
            '序号', 'School Code', '校区', '日期', '报销人', '摘要', 'Fund Account Code',
            'Fund Account Type', 'Account Owner', 'Category Code', '付款方式', '收入', '支出',
            'Statement Balance / 对账余款', 'Reference / 单据号', 'Currency', '备注',
        ], (new CentralFinanceGroupImportTemplateV2Export())->headings());
        $this->assertSame(CentralFinanceGroupImportService::SCHEMA_VERSION, CentralFinanceGroupImportTemplateV2Export::VERSION);
        $this->assertSame([], (new CentralFinanceGroupImportTemplateV2Export())->array());
    }

    public function test_group_template_route_and_export_can_never_fall_back_to_the_student_payment_template(): void
    {
        $route = app('router')->getRoutes()->getByName('central-finance.group-import.template');

        $this->assertSame('central-finance/group-import/template', $route->uri());
        $this->assertStringContainsString(CentralFinanceGroupImportController::class, $route->getActionName());

        $headings = (new CentralFinanceGroupImportTemplateV2Export())->headings();
        $studentPaymentHeadings = (new FeesPaidSampleExport())->headings();

        $this->assertContains('School Code', $headings);
        $this->assertContains('Fund Account Code', $headings);
        $this->assertContains('Category Code', $headings);
        $this->assertContains('收入', $headings);
        $this->assertContains('支出', $headings);
        $this->assertContains('Reference / 单据号', $headings);
        $this->assertContains('Currency', $headings);
        $this->assertNotSame($studentPaymentHeadings, $headings);
        $this->assertNotContains('Student Admission No', $headings);
        $this->assertNotContains('Academic Year', $headings);
        $this->assertNotContains('Fee Structure Name', $headings);
    }

    public function test_v22_template_has_scoped_dropdowns_positive_one_sided_amounts_and_text_codes(): void
    {
        $export = new CentralFinanceGroupImportTemplateV2Export(
            schools: [
                ['code' => 'MMBOWEN01', 'name' => 'Zixuan'],
                ['code' => 'SCH202616', 'name' => 'Times City'],
            ],
            accounts: [
                ['code' => 'SHARED-CASH', 'name' => 'Shared Cash', 'school_code' => 'MMBOWEN01', 'account_type' => 'cash', 'owner_type' => 'school', 'currency' => 'MMK'],
                ['code' => 'SHARED-CASH', 'name' => 'Shared Cash', 'school_code' => 'SCH202616', 'account_type' => 'cash', 'owner_type' => 'school', 'currency' => 'MMK'],
                ['code' => 'HQ-MMK', 'name' => 'HQ MMK', 'school_code' => null, 'account_type' => 'bank', 'owner_type' => 'hq', 'currency' => 'MMK'],
            ],
            categories: [
                ['school_code' => 'MMBOWEN01', 'type' => 'expense', 'category_code' => 'SUPPLIES-1', 'name' => 'Supplies'],
                ['school_code' => 'MMBOWEN01', 'type' => 'income', 'category_code' => 'OTHER-1', 'name' => 'Other Income'],
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'group-import-v21-');
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));
        $workbook = IOFactory::load($path);

        try {
            $this->assertSame(['Import', 'Schools', 'Fund Accounts', 'Categories', 'Validation Lists'], $workbook->getSheetNames());
            $import = $workbook->getSheetByName('Import');
            $this->assertSame(CentralFinanceGroupImportTemplateV2Export::HEADINGS, $import->rangeToArray('A1:Q1', null, true, false, false)[0]);
            $this->assertSame('=IFERROR(INDEX(SchoolCodes,MATCH(C2,SchoolNames,0)),"")', $import->getCell('B2')->getValue());
            $this->assertSame('=IFERROR(INDEX(FundAccountTypes,MATCH(G2,FundAccountCodes,0)),"")', $import->getCell('H2')->getValue());
            $this->assertSame('=IFERROR(INDEX(FundAccountOwners,MATCH(G2,FundAccountCodes,0)),"")', $import->getCell('I2')->getValue());
            $this->assertSame('=IFERROR(INDEX(FundAccountCurrencies,MATCH(G2,FundAccountCodes,0)),"")', $import->getCell('P2')->getValue());
            $this->assertSame('=SchoolNames', $import->getCell('C2')->getDataValidation()->getFormula1());
            $this->assertSame('=INDIRECT("FundAccounts_"&$B2)', $import->getCell('G2')->getDataValidation()->getFormula1());
            $this->assertTrue($import->getCell('C2')->getDataValidation()->getShowDropDown());
            $this->assertTrue($import->getCell('G2')->getDataValidation()->getShowDropDown());
            $this->assertSame('=IF($L2>0,INDIRECT("Categories_"&$B2&"_income"),IF($M2>0,INDIRECT("Categories_"&$B2&"_expense"),Empty_Options))', $import->getCell('J2')->getDataValidation()->getFormula1());
            $this->assertSame('=Payment_Methods', $import->getCell('K2')->getDataValidation()->getFormula1());
            $this->assertSame('custom', $import->getCell('L2')->getDataValidation()->getType());
            $this->assertSame('=OR(L2="",AND(ISNUMBER(L2),L2>0,M2=""))', $import->getCell('L2')->getDataValidation()->getFormula1());
            $this->assertSame('=OR(M2="",AND(ISNUMBER(M2),M2>0,L2=""))', $import->getCell('M2')->getDataValidation()->getFormula1());
            $this->assertSame('@', $import->getStyle('G2')->getNumberFormat()->getFormatCode());
            $this->assertNull($import->getCell('O2')->getValue());
            $this->assertSame('hidden', $workbook->getSheetByName('Validation Lists')->getSheetState());
            $this->assertNotNull($workbook->getNamedRange('SchoolCodes'));
            $this->assertNotNull($workbook->getNamedRange('FundAccountCodes'));
            $this->assertNotNull($workbook->getNamedRange('CategoryCodes'));
            $this->assertNotNull($workbook->getNamedRange('Payment_Methods'));
            $this->assertNotNull($workbook->getNamedRange('FundAccounts_MMBOWEN01'));
            $this->assertNotNull($workbook->getNamedRange('FundAccounts_SCH202616'));
            $this->assertNotNull($workbook->getNamedRange('Categories_MMBOWEN01_expense'));

            $zixuanAccounts = $workbook->getNamedRange('FundAccounts_MMBOWEN01');
            $timesCityAccounts = $workbook->getNamedRange('FundAccounts_SCH202616');
            $zixuanRange = str_replace('$', '', preg_replace('/^.*!/', '', $zixuanAccounts->getRange()));
            $timesCityRange = str_replace('$', '', preg_replace('/^.*!/', '', $timesCityAccounts->getRange()));
            $this->assertContains('SHARED-CASH', array_column($zixuanAccounts->getWorksheet()->rangeToArray($zixuanRange), 0));
            $this->assertContains('SHARED-CASH', array_column($timesCityAccounts->getWorksheet()->rangeToArray($timesCityRange), 0));

            $upload = new UploadedFile($path, 'group-finance-import-template-v2.2.xlsx', null, null, true);
            $imported = Excel::toArray([], $upload)[0];
            $this->assertSame(CentralFinanceGroupImportTemplateV2Export::HEADINGS, $imported[0]);
            $this->assertNotEmpty(array_filter(array_slice($imported, 1), static fn (array $row): bool => collect($row)->filter(static fn ($value): bool => $value !== null && trim((string) $value) !== '')->isNotEmpty()));
            $parser = new \ReflectionMethod(app(CentralFinanceGroupImportService::class), 'rowsFromFile');
            $parser->setAccessible(true);
            $this->assertSame([], $parser->invoke(app(CentralFinanceGroupImportService::class), $upload));

            $import->setCellValue('C2', 'Zixuan');
            $import->setCellValue('G2', 'SHARED-CASH');
            $import->setCellValue('J2', 'SUPPLIES-1');
            $import->setCellValue('K2', 'Cash');
            $import->setCellValue('M2', 100);
            $import->setCellValue('O2', 'REF-001');
            IOFactory::createWriter($workbook, 'Xlsx')->save($path);

            $reopened = IOFactory::load($path);
            try {
                $reopenedImport = $reopened->getSheetByName('Import');
                $this->assertSame('=SchoolNames', $reopenedImport->getCell('C2')->getDataValidation()->getFormula1());
                $this->assertSame('=INDIRECT("FundAccounts_"&$B2)', $reopenedImport->getCell('G2')->getDataValidation()->getFormula1());
                $this->assertTrue($reopenedImport->getCell('C2')->getDataValidation()->getShowDropDown());
                $this->assertTrue($reopenedImport->getCell('G2')->getDataValidation()->getShowDropDown());
            } finally {
                $reopened->disconnectWorksheets();
            }

            $parsed = $parser->invoke(app(CentralFinanceGroupImportService::class), new UploadedFile($path, 'group-finance-import-template-v2.2.xlsx', null, null, true));
            $this->assertCount(1, $parsed);
            $this->assertSame('MMBOWEN01', $parsed[0]['School Code']);
            $this->assertSame('cash', $parsed[0]['Fund Account Type']);
            $this->assertSame('school', $parsed[0]['Account Owner']);
            $this->assertSame('MMK', $parsed[0]['Currency']);
            $this->assertSame('REF-001', $parsed[0]['Reference / 单据号']);

            $legacyPath = tempnam(sys_get_temp_dir(), 'group-import-v2-');
            $legacy = new Spreadsheet();
            $legacy->getActiveSheet()->fromArray([
                CentralFinanceGroupImportTemplateV2Export::LEGACY_HEADINGS,
                ['1', 'MMBOWEN01', 'Zixuan', '2026-09-02', 'Claimant', 'Direct V2 row', 'ZIX-CASH', 'cash', 'school', 'SUPPLIES-1', 'Cash', '', '100', '', 'REF-DIRECT', 'MMK', ''],
            ]);
            IOFactory::createWriter($legacy, 'Xlsx')->save($legacyPath);
            try {
                $direct = $parser->invoke(app(CentralFinanceGroupImportService::class), new UploadedFile($legacyPath, 'group-finance-import-template-v2.xlsx', null, null, true));
                $this->assertSame('MMBOWEN01', $direct[0]['School Code']);
                $this->assertSame('cash', $direct[0]['Fund Account Type']);
                $this->assertSame('REF-DIRECT', $direct[0]['Reference / 单据号']);
            } finally {
                $legacy->disconnectWorksheets();
                @unlink($legacyPath);
            }
        } finally {
            $workbook->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_preview_service_cannot_reach_canonical_financial_write_services(): void
    {
        $source = file_get_contents(app_path('Services/CentralFinanceGroupImportService.php'));
        $previewOnly = substr($source, strpos($source, 'public function previewRows'), strpos($source, 'private function rowsFromFile') - strpos($source, 'public function previewRows'));
        $this->assertStringNotContainsString('createExpense(', $previewOnly);
        $this->assertStringNotContainsString('createOtherIncome(', $previewOnly);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $source);
        $this->assertStringContainsString('group-import-v2:', $source);
        $this->assertStringContainsString("'Duplicate'", $source);
        $this->assertStringContainsString("'Conflict'", $source);
    }

    public function test_confirm_route_is_present_without_payment_import_path(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->pluck('uri')->all();
        $this->assertContains('central-finance/group-import/preview', $routes);
        $this->assertContains('central-finance/group-import/{batch}/source/{row}', $routes);
        $this->assertContains('central-finance/group-import/{batch}/confirm', $routes);
        $source = file_get_contents(app_path('Services/CentralFinanceGroupImportService.php'));
        $this->assertStringContainsString('createExpense(', $source);
        $this->assertStringContainsString('createOtherIncome(', $source);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $source);
    }
}

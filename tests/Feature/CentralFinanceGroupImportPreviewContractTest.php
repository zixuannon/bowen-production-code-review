<?php

namespace Tests\Feature;

use App\Exports\CentralFinanceGroupImportTemplateV2Export;
use App\Services\CentralFinanceGroupImportService;
use Tests\TestCase;

final class CentralFinanceGroupImportPreviewContractTest extends TestCase
{
    public function test_v2_template_uses_only_strict_canonical_identifiers(): void
    {
        $this->assertSame([
            '序号', 'School Code', '校区', '日期', '报销人', '摘要', 'Fund Account Code',
            'Fund Account Type', 'Account Owner', 'Category Code', '付款方式', '收入', '支出',
            '余款', 'Reference / 单据号', 'Currency', '备注',
        ], (new CentralFinanceGroupImportTemplateV2Export())->headings());
        $this->assertSame(CentralFinanceGroupImportService::SCHEMA_VERSION, CentralFinanceGroupImportTemplateV2Export::VERSION);
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

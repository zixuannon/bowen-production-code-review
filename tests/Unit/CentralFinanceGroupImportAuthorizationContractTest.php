<?php

namespace Tests\Unit;

use Tests\TestCase;

final class CentralFinanceGroupImportAuthorizationContractTest extends TestCase
{
    public function test_every_group_import_surface_uses_the_group_level_gate(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/CentralFinanceGroupImportController.php'));
        $service = file_get_contents(app_path('Services/CentralFinanceGroupImportService.php'));
        $scope = file_get_contents(app_path('Services/FinanceGroupScopeService.php'));

        $this->assertStringContainsString('$groups = $this->imports->authorizedGroups($actor);', $controller);
        $this->assertSame(3, substr_count($controller, 'assertCanOperateGroup('));
        $this->assertStringContainsString('assertCanOperateGroup($actor, $group)', $service);
        $this->assertStringContainsString("hasActiveGroupScope(\$groupUser, 'operate_finance')", $service);
        $this->assertStringContainsString("contains('scope_type', 'GROUP')", $scope);
    }

    public function test_template_download_label_matches_the_v22_workbook_contract(): void
    {
        $view = file_get_contents(resource_path('views/central-finance/group-import/index.blade.php'));

        $this->assertStringContainsString('group-import.download_template_v22', $view);
        $this->assertStringNotContainsString('download_template_v21', $view);
        $this->assertSame(
            'Download Group Finance Import Template V2.2',
            trans('group-import.download_template_v22', locale: 'en'),
        );
        $this->assertSame(
            '下载集团财务导入模板 V2.2',
            trans('group-import.download_template_v22', locale: 'zh-cn'),
        );
    }
}

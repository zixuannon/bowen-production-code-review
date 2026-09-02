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

        $this->assertStringContainsString('authorizedGroups($this->actor())', $controller);
        $this->assertSame(3, substr_count($controller, 'assertCanOperateGroup('));
        $this->assertStringContainsString('assertCanOperateGroup($actor, $group)', $service);
        $this->assertStringContainsString("hasActiveGroupScope(\$groupUser, 'operate_finance')", $service);
        $this->assertStringContainsString("contains('scope_type', 'GROUP')", $scope);
    }
}

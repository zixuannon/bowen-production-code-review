<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StaffFinanceOnboardingContractTest extends TestCase
{
    public function test_formal_onboarding_route_and_multi_role_form_are_present(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $view = (string) file_get_contents($root.'/resources/views/staff/finance-onboarding.blade.php');

        $this->assertStringContainsString("Route::get('finance-onboarding'", $routes);
        $this->assertStringContainsString("Route::post('finance-onboarding'", $routes);
        $this->assertStringContainsString('name="roles[]"', $view);
        $this->assertStringContainsString("old('roles', [])", $view);
        $this->assertStringContainsString('name="reason"', $view);
        $this->assertStringContainsString('It does not grant Central Finance scope', $view);
        $this->assertMatchesRegularExpression(
            '/name="roles\[\]"[^>]*>\s*<i class="input-helper" aria-hidden="true"><\/i>/',
            $view,
            'The theme hides native checkboxes, so its visible input-helper must immediately follow each role input.',
        );
        $this->assertStringContainsString('for="onboarding-role-{{ $loop->index }}"', $view);
        $this->assertStringContainsString('id="onboarding-role-{{ $loop->index }}"', $view);
    }

    public function test_staff_edit_modal_preserves_all_assigned_roles(): void
    {
        $root = dirname(__DIR__, 2);
        $javascript = (string) file_get_contents($root.'/public/assets/js/custom/bootstrap-table/actionEvents.js');

        $this->assertStringContainsString(
            "$('#edit_role_id').val((row.roles || []).map(role => role.id)).trigger('change');",
            $javascript,
        );
        // Driver/Helper remains intentionally single-role; Staff must be the
        // only handler using the full role array.
        $this->assertSame(1, substr_count($javascript, "$('#edit_role_id').val(row.roles[0].id);"));
    }

    public function test_finance_group_grants_filter_by_tenant_role_and_require_audit_reason(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root.'/resources/views/finance-groups/show.blade.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/FinanceGroupController.php');

        $this->assertStringContainsString("where('eligible_accountant', true)", $view);
        $this->assertStringContainsString("where('eligible_principal', true)", $view);
        $this->assertStringContainsString("where('eligible_front_desk', true)", $view);
        $this->assertSame(3, substr_count($view, "{{ __('Audit reason') }}"));
        $this->assertSame(3, substr_count($controller, "'reason' => ['required', 'string', 'max:255']"));
    }

    public function test_onboarding_copy_keeps_the_three_role_boundaries_explicit(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string) file_get_contents($root.'/app/Services/TenantStaffRoleOnboardingService.php');
        $collection = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceStudentCollectionController.php');

        $this->assertStringContainsString('Tuition collection is not granted', $service);
        $this->assertStringContainsString('Pending Collection grant', $service);
        $this->assertStringContainsString('read-only when explicitly granted', $service);
        $this->assertStringContainsString('syncWithoutDetaching', $service);
        $this->assertStringContainsString('$this->workspace->assertHeadFinance($actor);', $collection);
    }
}

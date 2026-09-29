<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CentralFinanceOptionalFeeCollectionContractTest extends TestCase
{
    public function test_optional_items_are_a_central_collection_surface_without_a_parallel_payment_path(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceStudentCollectionController.php');
        $view = (string) file_get_contents($root.'/resources/views/central-finance/student-collection/show.blade.php');

        $this->assertStringContainsString("central-finance/student-collection/{profile}/optional-items", $routes);
        $this->assertStringContainsString("name('central-finance.student-collection.optional-items.store')", $routes);
        $this->assertStringContainsString('function addOptionalItems', $controller);
        $this->assertStringContainsString("'optional_fee_ids' => ['required', 'array', 'min:1']", $controller);
        $this->assertStringNotContainsString("'amount' =>", substr($controller, strpos($controller, 'function addOptionalItems'), strpos($controller, 'private function readContext') - strpos($controller, 'function addOptionalItems')));
        $this->assertStringContainsString('id="optional-fee-modal"', $view);
        $this->assertStringContainsString("route('central-finance.student-collection.optional-items.store'", $view);
        $this->assertStringNotContainsString('FeesPaid', $view);
    }

    public function test_optional_assignment_reuses_tenant_fee_snapshots_then_forces_central_projection(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string) file_get_contents($root.'/app/Services/CentralFinanceOptionalFeeAssignmentService.php');
        $assignment = (string) file_get_contents($root.'/app/Services/StudentFeeAssignmentService.php');

        $this->assertStringContainsString('configuredAdditionalItems', $service);
        $this->assertStringContainsString('saveAdditionalDraft', $service);
        $this->assertStringContainsString('assignments->confirm', $service);
        $this->assertStringContainsString('receivables->syncProfile', $service);
        $this->assertStringContainsString('CentralFinanceReceivableSyncService::SOURCE_TYPE', $service);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $service);
        $this->assertStringNotContainsString('CentralFinanceLedger', $service);
        $this->assertStringContainsString("->where('optional', true)", $assignment);
        $this->assertStringContainsString("->where('school_id', \$student->school_id)", $assignment);
        $this->assertStringContainsString("session_year_id", $assignment);
    }

    public function test_server_side_scope_and_tenant_identity_bridges_are_explicit(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string) file_get_contents($root.'/app/Services/CentralFinanceOptionalFeeAssignmentService.php');
        $identity = (string) file_get_contents($root.'/app/Services/CentralFinanceSchoolStaffIdentityService.php');

        $this->assertStringContainsString('assertCanSubmitCollectionsSchool', $service);
        $this->assertStringContainsString('assertCentralWritesAllowed', $service);
        $this->assertStringContainsString('executeAsTenantIdentity', $service);
        $this->assertStringContainsString('executeOperatingFinanceAsTenantIdentity', $service);
        $this->assertStringContainsString('function executeAsTenantIdentity', $identity);
        $this->assertStringContainsString("'central_finance_source_uuid' => \$identity->tenant_user_uuid", $identity);
    }

    public function test_front_desk_selects_only_existing_applicable_promotions_during_fee_setup(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceStudentCollectionController.php');
        $optionalFees = (string) file_get_contents($root.'/app/Services/CentralFinanceOptionalFeeAssignmentService.php');
        $promotions = (string) file_get_contents($root.'/app/Services/CentralFinancePromotionService.php');
        $view = (string) file_get_contents($root.'/resources/views/central-finance/student-collection/show.blade.php');

        $this->assertStringContainsString("'promotions' => ['nullable', 'array']", $controller);
        $this->assertStringContainsString('eligibleForFeeSetup', $optionalFees);
        $this->assertStringContainsString('applyFromFeeSetup', $optionalFees);
        $this->assertStringNotContainsString('->define(', $optionalFees);
        $this->assertStringNotContainsString('applyCorrection', $optionalFees);
        $this->assertStringNotContainsString('applyWaiver', $optionalFees);
        $this->assertStringContainsString('assertCanSubmitCollectionsSchool', $promotions);
        $this->assertStringContainsString("CentralFinancePromotion::ACTIVE", $promotions);
        $this->assertStringContainsString('fee_scope', $promotions);
        $this->assertStringContainsString('name="promotions[{{ $item->id }}]"', $view);
        $this->assertStringContainsString('already-approved applicable Promotion', $view);
    }
}

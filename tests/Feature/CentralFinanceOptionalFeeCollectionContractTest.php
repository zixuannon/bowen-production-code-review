<?php

namespace Tests\Feature;

use App\Services\CentralFinanceOptionalFeeAssignmentService;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
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
        $this->assertStringContainsString('optional-fee-toggle', $view);
        $this->assertStringContainsString('optional-fee-promotion', $view);
        $this->assertStringContainsString("promotion.value = '';", $view);
        $this->assertStringContainsString('promotion.disabled = !toggle.checked', $view);
    }

    public function test_blank_promotion_values_for_unselected_modal_rows_are_noops_but_a_real_stale_selection_is_denied(): void
    {
        $method = (new ReflectionClass(CentralFinanceOptionalFeeAssignmentService::class))
            ->getMethod('canonicalPromotionSelection');
        $service = (new ReflectionClass(CentralFinanceOptionalFeeAssignmentService::class))
            ->newInstanceWithoutConstructor();

        // The browser submits every rendered select in the modal.  An
        // unselected row's explicit "No Promotion" value must not be treated
        // as an attempt to attach a Promotion outside the selected draft.
        $this->assertSame([], $method->invoke($service, collect([17]), [17 => '', 23 => '']));
        $this->assertSame([17 => 9], $method->invoke($service, collect([17]), [17 => 9, 23 => '']));

        try {
            $method->invoke($service, collect([17]), [17 => '', 23 => 9]);
            $this->fail('A non-empty Promotion for a non-selected Fee Item must be denied.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'promotions' => ['A Promotion may be selected only for an item included in this Fee Setup.'],
            ], $exception->errors());
        }
    }

    public function test_tenant_student_fee_setup_uses_the_same_quantity_and_approved_promotion_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/StudentFeeAssignmentController.php');
        $tenantPromotion = (string) file_get_contents($root.'/app/Services/TenantStudentFeeSetupPromotionService.php');
        $assignment = (string) file_get_contents($root.'/app/Services/StudentFeeAssignmentService.php');
        $view = (string) file_get_contents($root.'/resources/views/students/fee-assignment.blade.php');
        $migration = (string) file_get_contents($root.'/database/migrations/schools/2026_09_30_000001_add_student_fee_assignment_promotion_selection.php');

        $this->assertStringContainsString("'optional_fee_quantities' => ['nullable', 'array']", $controller);
        $this->assertStringContainsString("'promotions' => ['nullable', 'array']", $controller);
        $this->assertStringContainsString('validateSelections', $controller);
        $this->assertStringContainsString('applyConfirmedSelections', $controller);
        $this->assertStringContainsString('assertCanSubmitCollectionsSchool', $controller);
        $this->assertStringContainsString('assertHeadFinance', $controller);
        $this->assertStringContainsString('optionalQuantities = []', $assignment);
        $this->assertStringContainsString('selectedPromotions = []', $assignment);
        $this->assertStringContainsString('selected_promotion_id', $assignment);
        $this->assertStringContainsString('resolveTrustedSession', $tenantPromotion);
        $this->assertStringContainsString('assertCentralWritesAllowed', $tenantPromotion);
        $this->assertStringContainsString('eligibleForFeeSetup', $tenantPromotion);
        $this->assertStringContainsString('applyFromFeeSetup', $tenantPromotion);
        $this->assertStringContainsString('tenant-fee-setup:', $tenantPromotion);
        $this->assertStringNotContainsString('->define(', $tenantPromotion);
        $this->assertStringNotContainsString('applyWaiver', $tenantPromotion);
        $this->assertStringNotContainsString('applyCorrection', $tenantPromotion);
        $this->assertStringContainsString('name="optional_fee_quantities[{{ $item->id }}]"', $view);
        $this->assertStringContainsString('name="promotions[{{ $item->id }}]"', $view);
        $this->assertStringContainsString("__('Fixed at 1')", $view);
        $this->assertStringContainsString('selected_promotion_id', $migration);
        $this->assertStringContainsString('sfa_item_selected_promotion_idx', $migration);
    }

    public function test_student_specific_promotion_scope_is_server_enforced_through_the_existing_promotion_engine(): void
    {
        $root = dirname(__DIR__, 2);
        $promotion = (string) file_get_contents($root.'/app/Services/CentralFinancePromotionService.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceWorkspaceController.php');
        $view = (string) file_get_contents($root.'/resources/views/central-finance/promotions.blade.php');
        $collection = (string) file_get_contents($root.'/resources/views/central-finance/student-collection/show.blade.php');
        $migration = (string) file_get_contents($root.'/database/migrations/2026_10_02_000001_add_student_scope_to_central_finance_promotions.php');

        $this->assertStringContainsString("'student_profile_id'", $migration);
        $this->assertStringContainsString('student_profile_id', $promotion);
        $this->assertStringContainsString('Student must belong to an allocated School', $promotion);
        $this->assertStringContainsString('exactly that Student’s School', $promotion);
        $this->assertStringContainsString("->whereNull('student_profile_id')", $promotion);
        $this->assertStringContainsString("CentralFinancePromotion::STUDENT_SPECIFIC", $promotion);
        $this->assertStringContainsString('defineStudentSpecificForFeeSetup', $promotion);
        $this->assertStringContainsString('central_finance_promotion_fee_allocations', $promotion);
        $this->assertStringContainsString('creation_idempotency_key', $promotion);
        $this->assertStringContainsString('student_discounts', $root ? (string) file_get_contents($root.'/app/Http/Controllers/StudentFeeAssignmentController.php') : '');
        $this->assertStringContainsString("'student_profile_id'=>['nullable','integer']", $controller);
        $this->assertStringContainsString('name="student_profile_id"', $view);
        $this->assertStringContainsString('All eligible Students', $view);
        $this->assertStringNotContainsString('name="discount_value"', $collection);
    }
}

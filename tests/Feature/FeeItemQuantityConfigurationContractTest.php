<?php

namespace Tests\Feature;

use App\Http\Controllers\FeesController;
use App\Models\FeesClassType;
use App\Services\StudentFeeAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FeeItemQuantityConfigurationContractTest extends TestCase
{
    public function test_fee_management_exposes_and_persists_quantity_independently_from_optional(): void
    {
        $root = dirname(__DIR__, 2);
        $create = (string) file_get_contents($root.'/resources/views/Income/index.blade.php');
        $edit = (string) file_get_contents($root.'/resources/views/Income/edit.blade.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/FeesController.php');
        $model = (string) file_get_contents($root.'/app/Models/FeesClassType.php');

        $this->assertStringContainsString('Allow Multiple Quantity', $create);
        $this->assertStringContainsString('Allow Multiple Quantity', $edit);
        $this->assertStringContainsString('compulsory_fees_type[][quantity_enabled]', $create);
        $this->assertStringContainsString('optional_fees_type[][quantity_enabled]', $create);
        $this->assertStringContainsString('quantity-enabled-toggle', $create);
        $this->assertStringContainsString('quantity-enabled-value', $create);
        $this->assertStringNotContainsString('type="checkbox" name="optional_fees_type[][quantity_enabled]"', $create);
        $this->assertStringContainsString('"quantity_enabled"', $edit);
        $this->assertStringContainsString('quantity-enabled-toggle', $edit);
        $this->assertStringContainsString('quantity-enabled-value', $edit);
        $this->assertStringNotContainsString('type="checkbox" name="quantity_enabled"', $edit);
        $this->assertStringContainsString('restoreQuantityEnabled(rows, feesData.compulsory_fees)', $edit);
        $this->assertStringContainsString('restoreQuantityEnabled(rows, feesData.optional_fees)', $edit);
        $this->assertStringContainsString('normalizeQuantityEnabledInputs($request)', $controller);
        $this->assertStringContainsString("=== ['0', '1']", $controller);
        $this->assertStringContainsString("'quantity_enabled'", $model);
        $this->assertStringContainsString('"quantity_enabled" => filter_var', $controller);
        $this->assertStringContainsString("['amount', 'optional', 'quantity_enabled'", $controller);
    }

    public function test_quantity_contract_is_bounded_server_side_and_does_not_trust_the_browser(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string) file_get_contents($root.'/app/Services/StudentFeeAssignmentService.php');
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/StudentFeeAssignmentController.php');
        $config = (string) file_get_contents($root.'/config/central_finance.php');

        $this->assertStringContainsString("'student_fee_max_quantity'", $config);
        $this->assertStringContainsString('DEFAULT_MAX_QUANTITY = 100', $service);
        $this->assertStringContainsString('validatedFeeQuantities', $service);
        $this->assertStringContainsString('$quantity > $this->maxQuantity()', $service);
        $this->assertStringContainsString('$quantity !== 1 && !(bool) ($template->quantity_enabled ?? false)', $service);
        $this->assertStringContainsString("'integer', 'min:1', 'max:'.\$this->assignments->maxQuantity()", $controller);
    }

    public function test_legacy_repeater_boolean_arrays_are_normalized_but_malformed_values_still_fail_validation(): void
    {
        $controller = (new \ReflectionClass(FeesController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FeesController::class, 'normalizeQuantityEnabledInputs');
        $method->setAccessible(true);

        $request = Request::create('/', 'POST', [
            'optional_fees_type' => [
                ['quantity_enabled' => ['0', '1']],
                ['quantity_enabled' => ['0']],
                ['quantity_enabled' => ['1', '1']],
                ['quantity_enabled' => ['0', '0']],
                ['quantity_enabled' => ['1', '0']],
                ['quantity_enabled' => ['0', '2']],
                ['quantity_enabled' => ['0', ['1']]],
            ],
        ]);

        $method->invoke($controller, $request);

        $this->assertSame('1', $request->input('optional_fees_type.0.quantity_enabled'));
        $this->assertSame(['0'], $request->input('optional_fees_type.1.quantity_enabled'));
        $this->assertSame(['1', '1'], $request->input('optional_fees_type.2.quantity_enabled'));
        $this->assertSame(['0', '0'], $request->input('optional_fees_type.3.quantity_enabled'));
        $this->assertSame(['1', '0'], $request->input('optional_fees_type.4.quantity_enabled'));
        $this->assertSame(['0', '2'], $request->input('optional_fees_type.5.quantity_enabled'));
        $this->assertSame(['0', ['1']], $request->input('optional_fees_type.6.quantity_enabled'));

        $rules = ['quantity_enabled' => ['nullable', 'boolean']];
        $this->assertFalse(Validator::make(['quantity_enabled' => '0'], $rules)->fails());
        $this->assertFalse(Validator::make(['quantity_enabled' => '1'], $rules)->fails());
        foreach (range(1, 6) as $index) {
            $this->assertTrue(Validator::make([
                'quantity_enabled' => $request->input("optional_fees_type.{$index}.quantity_enabled"),
            ], $rules)->fails());
        }
        $this->assertTrue(Validator::make(['quantity_enabled' => '2'], $rules)->fails());
    }

    public function test_server_rejects_tampered_fixed_and_excessive_quantities_before_a_snapshot_is_created(): void
    {
        $service = (new \ReflectionClass(StudentFeeAssignmentService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(StudentFeeAssignmentService::class, 'validatedFeeQuantities');
        $method->setAccessible(true);
        $fixed = new FeesClassType();
        $fixed->setRawAttributes(['id' => 1, 'optional' => 1, 'quantity_enabled' => 0]);
        $enabled = new FeesClassType();
        $enabled->setRawAttributes(['id' => 2, 'optional' => 1, 'quantity_enabled' => 1]);

        $valid = $method->invoke($service, collect([$fixed, $enabled]), [1 => '1', 2 => '2']);
        $this->assertSame([1 => 1, 2 => 2], $valid);

        foreach ([[1 => '2'], [2 => '0'], [2 => '-1'], [2 => '1.5'], [2 => '101']] as $payload) {
            try {
                $method->invoke($service, collect([$fixed, $enabled]), $payload);
                $this->fail('Invalid Fee quantity must be rejected by the service.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(ValidationException::class, $exception->getPrevious() ?? $exception);
            }
        }
    }

    public function test_fee_setup_and_receipts_use_immutable_unit_price_quantity_and_line_total_contracts(): void
    {
        $root = dirname(__DIR__, 2);
        $setup = (string) file_get_contents($root.'/resources/views/students/fee-assignment.blade.php');
        $assignment = (string) file_get_contents($root.'/app/Services/StudentFeeAssignmentService.php');
        $receipt = (string) file_get_contents($root.'/resources/views/central-finance/partials/receipt-document.blade.php');
        $promotion = (string) file_get_contents($root.'/app/Services/CentralFinancePromotionService.php');

        $this->assertStringContainsString('@if($item->quantity_enabled)', $setup);
        $this->assertStringContainsString('fee-line-total', $setup);
        $this->assertStringContainsString('Gross Line Total', $setup);
        $this->assertStringContainsString('Discount', $setup);
        $this->assertStringContainsString('Net Line Total', $setup);
        $this->assertStringContainsString("'unit_price_snapshot' => \$unitOriginal", $assignment);
        $this->assertStringContainsString("'quantity_snapshot' => \$quantity", $assignment);
        $this->assertStringContainsString("'amount_snapshot' => \$lineOriginal", $assignment);
        $this->assertStringContainsString('previewForFeeSetup', $promotion);
        $this->assertStringContainsString('discountForGross', $promotion);
        $this->assertStringContainsString("__('Unit price')", $receipt);
        $this->assertStringContainsString("__('Quantity')", $receipt);
        $this->assertStringContainsString("__('Line total')", $receipt);
    }
}

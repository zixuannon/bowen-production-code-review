<?php

namespace Tests\Unit;

use App\Services\PayrollCalculationService;
use Tests\TestCase;

class PayrollCalculationServiceTest extends TestCase
{
    private PayrollCalculationService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payroll = app(PayrollCalculationService::class);
    }

    public function test_a_31_day_month_with_four_sundays_has_27_working_days(): void
    {
        $this->assertSame(27, $this->payroll->workingDaysInMonth(2026, 7));
        $this->assertEqualsWithDelta(33333.333333, $this->payroll->lwpDeduction(900000, 2026, 7, 1), 0.000001);
    }

    public function test_30_day_month_uses_its_actual_non_sunday_days(): void
    {
        // April 2026 has four Sundays.
        $this->assertSame(26, $this->payroll->workingDaysInMonth(2026, 4));
    }

    public function test_february_and_leap_year_february_use_their_actual_non_sunday_days(): void
    {
        $this->assertSame(24, $this->payroll->workingDaysInMonth(2026, 2));
        $this->assertSame(25, $this->payroll->workingDaysInMonth(2028, 2));
    }

    public function test_month_with_five_sundays_excludes_all_five(): void
    {
        // May 2026 has 31 dates and five Sundays.
        $this->assertSame(26, $this->payroll->workingDaysInMonth(2026, 5));
    }

    public function test_zero_full_and_half_unpaid_leave_have_the_expected_deduction_days(): void
    {
        $this->assertSame(0.0, $this->payroll->unpaidLeaveDays(2, 2));
        $this->assertSame(1.0, $this->payroll->unpaidLeaveDays(3, 2));
        $this->assertSame(0.5, $this->payroll->unpaidLeaveDays(2.5, 2));
        $this->assertSame(0.0, $this->payroll->lwpDeduction(900000, 2026, 7, 0));
    }

    public function test_salary_total_reconciles_with_one_payroll_settings_transport_deduction(): void
    {
        $lwp = $this->payroll->lwpDeduction(900000, 2026, 7, 1);

        $this->assertEqualsWithDelta(
            816666.666667,
            $this->payroll->netSalary(900000, 50000, 100000, $lwp),
            0.000001,
        );
    }

    public function test_calculation_service_is_value_only_and_cannot_select_a_tenant_or_persist_payroll(): void
    {
        $source = file_get_contents(app_path('Services/PayrollCalculationService.php'));

        $this->assertStringNotContainsString('DB::', $source);
        $this->assertStringNotContainsString('Auth::', $source);
        $this->assertStringNotContainsString('transportationPayments', $source);
    }

    public function test_payroll_slip_uses_the_calculated_lwp_amount_and_not_the_incomplete_production_transportation_variable(): void
    {
        $slip = file_get_contents(resource_path('views/payroll/slip.blade.php'));

        $this->assertStringContainsString('$lwpAmount', $slip);
        $this->assertStringNotContainsString('$transportationPayments', $slip);
        $this->assertStringNotContainsString('$salary->basic_salary / $days', $slip);
    }

    public function test_payroll_persistence_and_transportation_settings_path_remain_unchanged(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/PayrollController.php'));

        $this->assertStringContainsString("PayrollSetting::where('name', 'Transportation Deduction')->first()", $controller);
        $this->assertStringNotContainsString('transportationPayments', $controller);
        $this->assertStringContainsString("updateOrCreate(['staff_id' => \$data['staff_id'], 'month' => \$data['month'], 'year' => \$data['year']]", $controller);
        $this->assertStringContainsString('Auth::user()->school_id', $controller);
    }
}

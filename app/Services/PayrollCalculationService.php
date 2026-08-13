<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Payroll calculations that must use the actual payroll month.
 *
 * Sunday is the sole non-working day defined by the approved payroll rule.
 */
class PayrollCalculationService
{
    public function workingDaysInMonth(int $year, int $month): int
    {
        $date = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $end = $date->endOfMonth();
        $workingDays = 0;

        while ($date->lessThanOrEqualTo($end)) {
            if (!$date->isSunday()) {
                $workingDays++;
            }

            $date = $date->addDay();
        }

        return $workingDays;
    }

    public function unpaidLeaveDays(float $totalLeaveDays, ?float $paidLeaveDays): float
    {
        if ($paidLeaveDays === null) {
            return 0.0;
        }

        return max(0.0, $totalLeaveDays - $paidLeaveDays);
    }

    public function lwpDeduction(float $basicSalary, int $year, int $month, float $unpaidLeaveDays): float
    {
        if ($unpaidLeaveDays <= 0) {
            return 0.0;
        }

        $workingDays = $this->workingDaysInMonth($year, $month);

        return $workingDays > 0 ? ($basicSalary / $workingDays) * $unpaidLeaveDays : 0.0;
    }

    public function netSalary(float $basicSalary, float $allowances, float $deductions, float $lwpDeduction): float
    {
        return $basicSalary + $allowances - $deductions - $lwpDeduction;
    }
}

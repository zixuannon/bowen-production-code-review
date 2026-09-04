<?php

namespace App\Services;

use App\Models\School;

/**
 * Read-only tenant-sidebar rollout decision.
 *
 * The current tenant database is established by the trusted School login
 * bootstrap.  Tenant-local numeric IDs must never be used to locate a
 * Central School because their values may collide with the Central registry.
 */
final class CentralFinanceSchoolFinanceNavigationService
{
    public function __construct(private readonly CentralFinanceSchoolCutoverService $cutovers) {}

    public function currentTenantSchool(): ?School
    {
        $database = trim((string) config('database.connections.school.database'));
        if ($database === '') {
            return null;
        }

        return School::on('mysql')->where('database_name', $database)->first();
    }

    public function usesCentralFinanceDailyWorkspace(): bool
    {
        $school = $this->currentTenantSchool();
        if ($school === null) {
            return false;
        }

        return in_array($school->code, config('central_finance.school_finance_navigation_rollout_codes', []), true)
            && $this->cutovers->allowsCentralWrites((int) $school->id);
    }
}

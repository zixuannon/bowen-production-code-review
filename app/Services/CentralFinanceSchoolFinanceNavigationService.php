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
        // A School Login records this value only after a validated School Code
        // has selected its single tenant.  On sidebar requests the connection
        // configuration may be restored before rendering, so prefer this
        // trusted session context over an ambient/default connection.
        $context = session(CentralFinanceSchoolStaffIdentityService::SESSION_KEY);
        $schoolId = is_array($context) ? (int) ($context['school_id'] ?? 0) : 0;
        if ($schoolId > 0) {
            $school = School::on('mysql')->whereKey($schoolId)->first();
            if ($school !== null) {
                return $school;
            }
        }

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

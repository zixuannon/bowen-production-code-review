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
        if (is_array($context)) {
            try {
                $principal = app(CentralFinanceSchoolStaffIdentityService::class)->resolveTrustedSession($context);
                $school = app(CentralFinanceWorkspaceService::class)->currentSchool($principal);
                if ($school !== null) {
                    return $school;
                }
            } catch (\Throwable) {
                // Fall through to the session's registry key/database lookup.
            }
        }
        $schoolId = is_array($context) ? (int) ($context['school_id'] ?? 0) : 0;
        if ($schoolId > 0) {
            $school = School::on('mysql')->whereKey($schoolId)->first();
            if ($school !== null) {
                return $school;
            }
        }

        // Login also persists the resolved database name for the tenant User
        // provider. It is set server-side only after School Code validation.
        $database = trim((string) (session('school_database_name') ?: config('database.connections.school.database')));
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

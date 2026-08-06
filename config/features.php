<?php

/**
 * Feature flags for gradual rollout of new functionality.
 *
 * Each flag serves as a master switch. Individual features may
 * additionally check database schema or other runtime conditions.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Staff Leave Supervisor Final Approval
    |--------------------------------------------------------------------------
    |
    | When set to true AND the school is in the allowlist, the
    | supervisor-final-approval flow is active:
    |   - Employees submit leave → direct supervisor approves or rejects
    |   - Supervisor approval = final (status=1), HR is notified (read-only)
    |   - Supervisor rejection = final (status=2), only employee is notified
    |
    | TWO-LEVEL GATING (fail-closed):
    |   1. staff_leave_two_stage_enabled = false → all schools: false
    |   2. staff_leave_two_stage_enabled = true, allowlist empty → all schools: false
    |   3. true + school in allowlist + schema complete → true
    |   4. true + school NOT in allowlist → false
    |   5. Cannot determine current school → false
    |
    | The feature also requires the following database columns:
    |   - staffs.supervisor_user_id
    |   - leaves.supervisor_status / supervisor_user_id / supervisor_reviewed_at
    |   - leaves.hr_status / hr_user_id / hr_reviewed_at / hr_comment
    |   - leaves.withdrawn_at / supervisor_comment
    |
    | DEFAULT: false — Must NOT be enabled in production without isolated testing.
    */
    'staff_leave_two_stage_enabled' => env('STAFF_LEAVE_TWO_STAGE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Staff Leave — School Allowlist (Grayscale)
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of school DATABASE names that are allowed to
    | use the two-stage leave workflow when the global flag is enabled.
    |
    | This is a FAIL-CLOSED allowlist:
    |   - Empty → no school is enabled, even if the global flag is true
    |   - Only schools listed here are eligible (must also pass schema check)
    |
    | Identified by school database name (e.g. "eschool_saas_15_zixuan"),
    | which is the most reliable tenant identifier across web/API/command
    | contexts. This avoids cross-school pollution and does not require
    | a per-request central schools table query.
    |
    | Example: STAFF_LEAVE_ENABLED_SCHOOL_DATABASES="eschool_saas_15_zixuan"
    */
    'staff_leave_enabled_school_databases' => env('STAFF_LEAVE_ENABLED_SCHOOL_DATABASES', ''),

];

<?php

namespace App\Services\StaffLeave;

use App\Models\Leave;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Shared service for the two-stage leave approval flow (Supervisor Final Approval).
 *
 * Provides:
 *  - Feature/schema detection (isEnabled) with school-level allowlist (fail-closed)
 *  - HR viewer discovery (for notification recipients)
 *  - Composite status helpers
 *
 * Gating (fail-closed, in order):
 *  1. Global flag == false → false (all schools)
 *  2. Allowlist empty          → false (all schools)
 *  3. School not in allowlist  → false
 *  4. Cannot determine school  → false
 *  5. Schema incomplete        → false
 *  6. All checks pass          → true
 */
class TwoStageLeaveService
{
    /**
     * Per-process cache keyed by composite key to prevent
     * cross-school cache pollution and to react to config changes
     * in long-running processes.
     *
     * Key format: {schoolDb}|{flag}|{allowlistMD5}
     *
     * @var array<string, bool|null>
     */
    private static array $enabled = [];

    /**
     * Determine whether the two-stage leave approval flow is fully operational
     * for the current school.
     *
     * Tier 1 — Global flag:
     *   config('features.staff_leave_two_stage_enabled') === true
     *
     * Tier 2 — School allowlist (fail-closed):
     *   Current school database name must be in the comma-separated
     *   STAFF_LEAVE_ENABLED_SCHOOL_DATABASES allowlist. Empty allowlist
     *   means NO school is enabled.
     *
     * Tier 3 — Schema (10 columns):
     *   staffs.supervisor_user_id
     *   leaves.supervisor_status / supervisor_comment / supervisor_user_id
     *   leaves.supervisor_reviewed_at
     *   leaves.hr_status / hr_user_id / hr_reviewed_at / hr_comment
     *   leaves.withdrawn_at
     *
     * Any unrecoverable context → false (safe fallback).
     */
    public static function isEnabled(): bool
    {
        $schoolDb = config('database.connections.school.database', 'default');
        $globalFlag = config('features.staff_leave_two_stage_enabled', false);
        $allowlistRaw = config('features.staff_leave_enabled_school_databases', '');

        // Composite cache key: school DB + flag + allowlist config hash
        $key = $schoolDb . '|' . ($globalFlag ? '1' : '0') . '|' . md5((string) $allowlistRaw);

        if (array_key_exists($key, self::$enabled)) {
            return self::$enabled[$key] ?? false;
        }

        // Tier 1: Global master kill switch
        if (!$globalFlag) {
            return self::$enabled[$key] = false;
        }

        // Tier 2: School allowlist (fail-closed — empty list disables all)
        $allowlist = self::parseAllowlist($allowlistRaw);
        if (empty($allowlist)) {
            return self::$enabled[$key] = false;
        }

        if (!in_array($schoolDb, $allowlist, true)) {
            return self::$enabled[$key] = false;
        }

        // Tier 3: Schema completeness (10 columns)
        try {
            return self::$enabled[$key] =
                Schema::connection('school')->hasColumn('staffs', 'supervisor_user_id')
                && Schema::connection('school')->hasColumn('leaves', 'supervisor_status')
                && Schema::connection('school')->hasColumn('leaves', 'supervisor_comment')
                && Schema::connection('school')->hasColumn('leaves', 'supervisor_user_id')
                && Schema::connection('school')->hasColumn('leaves', 'supervisor_reviewed_at')
                && Schema::connection('school')->hasColumn('leaves', 'hr_status')
                && Schema::connection('school')->hasColumn('leaves', 'hr_user_id')
                && Schema::connection('school')->hasColumn('leaves', 'hr_reviewed_at')
                && Schema::connection('school')->hasColumn('leaves', 'hr_comment')
                && Schema::connection('school')->hasColumn('leaves', 'withdrawn_at');
        } catch (\Throwable) {
            return self::$enabled[$key] = false;
        }
    }

    /**
     * Parse the comma-separated school database allowlist.
     *
     * Handles:
     *  - Whitespace trimming  (e.g. "a, b")
     *  - Empty entries        (e.g. "a,," → ignored)
     *  - Duplicates           (e.g. "a,a" → deduped)
     *  - Non-string values    (returns [])
     *
     * @param  mixed  $raw
     * @return string[]
     */
    private static function parseAllowlist($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $items = explode(',', $raw);
        $result = [];

        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Get all active user IDs who hold the hr-view-leave permission
     * in the current school. Used for HR notification only (not approval).
     *
     * Supervisor approval is now final; HR role is view-only.
     *
     * @return \Illuminate\Support\Collection<int>
     */
    public static function getHrNotificationRecipients()
    {
        if (!self::isEnabled()) {
            return collect();
        }

        try {
            return User::where('status', 1)
                ->whereHas('roles.permissions', function ($q) {
                    $q->where('name', 'hr-view-leave');
                })
                ->pluck('id');
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Get a human-readable composite status for display purposes ONLY.
     * Payroll / Dashboard / Report logic must continue to use `status`.
     *
     * Supervisor approval is now final — no pending_hr / approved_final / rejected_hr.
     * Historical hr_status=1/2 records remain compatible.
     */
    public static function getEffectiveStatus(Leave $leave): string
    {
        // Withdrawn
        if (!is_null($leave->withdrawn_at)) {
            return 'withdrawn';
        }

        // Old flow: supervisor_status is null
        if (is_null($leave->supervisor_status)) {
            if ($leave->status == Leave::STATUS_PENDING) {
                return 'old_flow_pending';
            }
            if ($leave->status == Leave::STATUS_APPROVED) {
                return 'old_flow_approved';
            }
            if ($leave->status == Leave::STATUS_REJECTED) {
                return 'old_flow_rejected';
            }
            return 'old_flow_unknown';
        }

        // Two-stage flow (Supervisor Final Approval)
        if ($leave->supervisor_status == Leave::APPROVAL_PENDING) {
            return 'pending_supervisor';
        }

        if ($leave->supervisor_status == Leave::APPROVAL_REJECTED) {
            return 'rejected_supervisor';
        }

        // supervisor_status == APPROVED → final approval
        if ($leave->supervisor_status == Leave::APPROVAL_APPROVED) {
            // Historical: if hr_status=1/2 was set in Phase 4, still display accordingly
            if ($leave->hr_status == Leave::APPROVAL_APPROVED) {
                return 'approved_final';
            }
            if ($leave->hr_status == Leave::APPROVAL_REJECTED) {
                return 'rejected_hr';
            }
            // New flow: supervisor approval is final
            return 'approved';
        }

        return 'unknown';
    }

    /**
     * Reset the per-process cache (useful in long-running processes,
     * multi-school Artisan commands, or after toggling config at runtime).
     */
    public static function resetCache(): void
    {
        self::$enabled = [];
    }
}

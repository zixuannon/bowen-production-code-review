<?php

namespace App\Services;

use App\Models\CentralFinanceReceivableSyncUatException;
use App\Models\CentralFinanceStudentProfile;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-closed read gate for a temporary Production UAT exception. It never
 * alters the School cutoff and only permits a confirmed per-Student assignment
 * already bound to the exact Central profile UUID.
 */
final class CentralFinanceReceivableSyncUatExceptionService
{
    /** @param array{source_scope?:string} $row */
    public function allows(CentralFinanceStudentProfile $profile, array $row): bool
    {
        if (($row['source_scope'] ?? null) !== 'student_fee_assignment'
            || !Schema::connection('mysql')->hasTable('central_finance_receivable_sync_uat_exceptions')) {
            return false;
        }

        return CentralFinanceReceivableSyncUatException::on('mysql')->where([
            'school_id' => $profile->school_id,
            'student_profile_id' => $profile->id,
            'student_source_uuid' => strtolower((string) $profile->source_uuid),
            'context' => CentralFinanceReceivableSyncUatException::CONTEXT,
            'status' => CentralFinanceReceivableSyncUatException::ACTIVE,
        ])->exists();
    }
}

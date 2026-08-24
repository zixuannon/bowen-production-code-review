<?php

namespace App\Services;

use App\Models\School;
use App\Models\Students;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A best-effort after-commit bridge from tenant academic changes to Central
 * Finance. The tenant commit remains the source-of-truth transaction; a
 * central outage is recorded for retry/backfill instead of rolling it back.
 */
final class CentralFinanceStudentProfilePublisher
{
    public function __construct(
        private readonly CentralFinanceStudentProfileSyncService $sync,
        private readonly CentralFinanceReceivablePublisher $receivables,
    ) {}

    public function studentChanged(Students $student): void
    {
        if (!$this->prerequisitesAvailable() || !$student->school_id) {
            return;
        }

        try {
            $school = School::on('mysql')->findOrFail((int) $student->school_id);
            $result = $this->sync->syncStudent($school, (int) $student->id);
            if ($result['profile_id'] !== null) {
                $this->receivables->studentChanged(\App\Models\CentralFinanceStudentProfile::on('mysql')->findOrFail($result['profile_id']));
            }
        } catch (Throwable $exception) {
            // Avoid logging PII/payloads. The registered school code/id and
            // bounded exception class are sufficient for an operator to run
            // the safe retry/backfill command later.
            Log::warning('Central Finance student profile sync deferred.', [
                'school_id' => (int) $student->school_id,
                'tenant_student_id' => (int) $student->id,
                'error' => $exception::class,
            ]);
        }
    }

    private function prerequisitesAvailable(): bool
    {
        return Schema::connection('mysql')->hasTable('central_finance_student_profiles')
            && Schema::connection('mysql')->hasTable('central_finance_sync_events')
            && Schema::hasColumn('students', 'central_finance_source_uuid');
    }
}

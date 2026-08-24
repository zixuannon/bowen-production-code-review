<?php

namespace App\Services;

use App\Models\CentralFinanceStudentProfile;
use App\Models\School;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Best-effort post-commit bridge; tenant fee changes never depend on Central availability. */
final class CentralFinanceReceivablePublisher
{
    public function __construct(private readonly CentralFinanceReceivableSyncService $sync) {}

    public function studentChanged(CentralFinanceStudentProfile $profile): void
    {
        if (!$this->available()) return;
        try {
            $this->sync->syncProfile($profile);
        } catch (Throwable $exception) {
            Log::warning('Central Finance receivable sync deferred after student change.', [
                'school_id' => $profile->school_id,
                'profile_id' => $profile->id,
                'error' => $exception::class,
            ]);
        }
    }

    /** @param list<int> $classIds */
    public function feeAssignmentsChanged(int $schoolId, array $classIds): void
    {
        if (!$this->available() || $schoolId < 1) return;
        try {
            $school = School::on('mysql')->findOrFail($schoolId);
            foreach (CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->whereIn('class_id', collect($classIds)->filter(fn ($id) => (int) $id > 0)->map(fn ($id) => (int) $id)->unique())->get() as $profile) {
                $this->sync->syncProfile($profile);
            }
        } catch (Throwable $exception) {
            Log::warning('Central Finance receivable sync deferred after fee assignment change.', [
                'school_id' => $schoolId,
                'error' => $exception::class,
            ]);
        }
    }

    private function available(): bool
    {
        return Schema::connection('mysql')->hasTable('central_finance_receivables')
            && Schema::connection('mysql')->hasTable('central_finance_receivable_sync_events');
    }
}

<?php

namespace App\Observers;

use App\Models\Students;
use App\Services\CentralFinanceStudentProfilePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tenant student data remains authoritative. This after-commit observer only
 * requests an asynchronous-safe central reference projection; it never writes
 * tenant Finance data and it never lets a central delivery failure undo a
 * committed academic/student change.
 */
final class CentralFinanceStudentProfileObserver
{
    public function __construct(private readonly CentralFinanceStudentProfilePublisher $publisher) {}

    public function creating(Students $student): void
    {
        $connection = $student->getConnectionName() ?: DB::getDefaultConnection();
        if (Schema::connection($connection)->hasColumn('students', 'central_finance_source_uuid')
            && !$student->getAttribute('central_finance_source_uuid')) {
            $student->setAttribute('central_finance_source_uuid', (string) Str::uuid());
        }
    }

    public function created(Students $student): void
    {
        $this->afterCommit($student);
    }

    public function updated(Students $student): void
    {
        $this->afterCommit($student);
    }

    public function deleted(Students $student): void
    {
        $this->afterCommit($student);
    }

    public function restored(Students $student): void
    {
        $this->afterCommit($student);
    }

    private function afterCommit(Students $student): void
    {
        DB::afterCommit(fn (): mixed => $this->publisher->studentChanged($student));
    }
}

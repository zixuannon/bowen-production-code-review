<?php

namespace App\Services;

use App\Models\School;
use Throwable;

/**
 * Controlled operational helper for the first tenant source-UUID backfill.
 * It accepts School models only; source database names are always resolved by
 * CentralFinanceStudentProfileSource from the central School registry.
 */
final class CentralFinanceStudentProfileBackfillService
{
    public function __construct(
        private readonly CentralFinanceStudentProfileSource $source,
        private readonly CentralFinanceStudentProfileSyncService $sync,
    ) {}

    /** @param iterable<School> $schools @return array<int, array<string, int|string>> */
    public function synchronizeSchools(iterable $schools, bool $backfill): array
    {
        $results = [];
        foreach ($schools as $school) {
            try {
                $backfilled = $backfill ? $this->source->backfillMissingSourceUuids($school) : 0;
                $outcomes = $this->sync->syncSchool($school);
                $results[(int) $school->id] = [
                    'status' => 'complete',
                    'backfilled' => $backfilled,
                    'synced' => count($outcomes),
                ];
            } catch (Throwable $exception) {
                // Failure isolation is intentional: a bad/missing tenant
                // cannot prevent another registered School from retrying.
                $results[(int) $school->id] = [
                    'status' => 'failed',
                    'error' => $exception::class,
                ];
            }
        }

        return $results;
    }
}

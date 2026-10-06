<?php

namespace App\Services;

use App\Models\School;

/** Resolves the one permanent Finance QA School through the trusted registry map. */
final class CentralFinanceQaSchoolIdentity
{
    public const SCHOOL_CODE = 'MMBOWEN01';

    public function resolve(): ?School
    {
        $database = (string) config('finance_release.p31_p32_tenants.'.self::SCHOOL_CODE, '');
        if ($database === '') {
            return null;
        }

        $school = app(SchoolCodeService::class)
            ->resolveTrustedRegistry([self::SCHOOL_CODE => $database])[self::SCHOOL_CODE] ?? null;

        if (!$school || $school->trashed() || !$school->installed
            || !in_array((string) $school->status, ['active', '1'], true)) {
            return null;
        }

        return $school;
    }

    public function isSchool(int $schoolId): bool
    {
        return (int) ($this->resolve()?->id ?? 0) === $schoolId;
    }
}

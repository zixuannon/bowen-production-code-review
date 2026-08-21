<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/** Fixed Gate A scope: never infer a production tenant from request input. */
final class CentralFinanceGateASchoolScope
{
    /** @var list<string> */
    public const ACTIVE_CODES = ['SCH202615', 'SCH202616', 'SCH202619', 'SCH202620', 'SCH202621', 'SCH202631', 'SCH202632'];

    /** @param list<string> $requestedCodes @return Collection<int, School> */
    public function resolve(array $requestedCodes = []): Collection
    {
        $requested = $requestedCodes === [] ? self::ACTIVE_CODES : array_values(array_unique($requestedCodes));
        if (array_diff($requested, self::ACTIVE_CODES) !== []) {
            throw new AuthorizationException('Gate A accepts only its fixed active School-code allowlist.');
        }
        $schools = School::on('mysql')->whereIn('code', $requested)->where('installed', true)->whereIn('status', ['active', 1])->orderBy('code')->get();
        if ($schools->count() !== count($requested)) {
            throw new AuthorizationException('A requested Gate A School is inactive, missing, or not installed.');
        }
        return $schools;
    }
}

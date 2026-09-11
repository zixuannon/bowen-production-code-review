<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/** Fixed Gate A scope: never infer a production tenant from request input. */
final class CentralFinanceGateASchoolScope
{
    /** @var list<string> */
    public const ACTIVE_CODES = ['MMBOWEN01', 'SCH202616', 'SCH202619', 'SCH202620', 'SCH202621', 'SCH202631', 'SCH202632'];

    /** @param list<string> $requestedCodes @return Collection<int, School> */
    public function resolve(array $requestedCodes = []): Collection
    {
        $requested = $requestedCodes === [] ? self::ACTIVE_CODES : array_values(array_unique($requestedCodes));
        $allowedIds = collect(self::ACTIVE_CODES)->map(fn (string $code): ?int => app(SchoolCodeService::class)->resolveCanonical($code)?->id)->filter()->map(fn ($id): int => (int) $id)->unique();
        $schools = collect($requested)->map(fn (string $code): ?School => app(SchoolCodeService::class)->resolveCanonical($code));
        if ($schools->contains(null)
            || $schools->pluck('id')->map(fn ($id): int => (int) $id)->unique()->count() !== count($requested)
            || $schools->contains(fn (School $school): bool => !$allowedIds->contains((int) $school->id))
            || $schools->contains(fn (School $school): bool => !$school->installed || !in_array((string) $school->status, ['active', '1'], true))) {
            throw new AuthorizationException('A requested Gate A School is inactive, missing, or not installed.');
        }
        return $schools->sortBy('code')->values();
    }
}

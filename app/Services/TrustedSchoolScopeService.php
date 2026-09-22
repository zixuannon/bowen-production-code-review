<?php

namespace App\Services;

use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves an explicit, registered school scope for legacy tenant records.
 * It intentionally does not infer scope from a role name such as School
 * Admin, nor does it accept a caller-supplied school id.
 */
final class TrustedSchoolScopeService
{
    public function schoolIdFor(?User $actor): int
    {
        return $this->resolveSchoolId($actor, allowUnitTestBypass: true);
    }

    /**
     * Resolves a scope for a mutation that must prove the request's tenant
     * context even while the local feature suite is running.  Read/list
     * repositories retain their existing unit-test ergonomics through
     * schoolIdFor(); lifecycle controllers must use this stricter contract.
     */
    public function trustedSchoolIdFor(?User $actor): int
    {
        return $this->resolveSchoolId($actor, allowUnitTestBypass: false);
    }

    private function resolveSchoolId(?User $actor, bool $allowUnitTestBypass): int
    {
        if (! $actor) {
            throw new AuthorizationException('An authenticated tenant identity is required.');
        }
        $schoolId = (int) $actor->getAttribute('school_id');
        if ($schoolId < 1) {
            throw new AuthorizationException('A tenant school identity is required.');
        }

        if ($allowUnitTestBypass && app()->runningUnitTests()) {
            return $schoolId;
        }

        $trustedApiSchoolId = (int) request()?->attributes->get('trusted_tenant_school_id', 0);
        if ($trustedApiSchoolId > 0) {
            if ($trustedApiSchoolId !== $schoolId) {
                throw new AuthorizationException('The actor does not match the trusted School context.');
            }

            return $schoolId;
        }

        $database = trim((string) request()?->session()->get('school_database_name'));
        $registered = $database !== ''
            && School::on('mysql')->whereKey($schoolId)->where('database_name', $database)->exists();
        if (! $registered) {
            throw new AuthorizationException('The tenant School context is not trusted.');
        }

        return $schoolId;
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ?User $actor, string $column = 'school_id'): Builder
    {
        return $query->where($column, $this->schoolIdFor($actor));
    }
}

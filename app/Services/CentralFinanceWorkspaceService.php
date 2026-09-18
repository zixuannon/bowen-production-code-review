<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupUser;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Resolves the Central Finance operating context without entering a tenant
 * database or replacing the authenticated central identity.
 */
final class CentralFinanceWorkspaceService
{
    public const SESSION_SCHOOL_KEY = 'central_finance_operating_school_id';

    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly FinanceGroupScopeService $groups,
        private readonly CentralFinanceSchoolStaffIdentityService $staffIdentities,
        private readonly CentralFinanceDataIsolationService $dataIsolation,
    ) {}

    public function actor(User $authenticated): CentralFinanceUser
    {
        $actor = CentralFinanceUser::on('mysql')->find($authenticated->id);
        // Read the raw value: User's legacy school_id accessor may inspect
        // tenant roles for Guardian browser flows, which Central Finance must
        // never trigger.
        if ($actor === null) {
            throw new AuthorizationException('A Central Finance identity is required.');
        }
        $type = $actor->getRawOriginal('central_finance_principal_type') ?? 'central_user';
        if (($type === 'central_user' && $actor->getRawOriginal('school_id') !== null)
            || ($type === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE && !$this->staffIdentities->isActivePrincipal($actor))
            || !in_array($type, ['central_user', CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE], true)
            || $this->groupUsers($actor)->isEmpty()
            || $this->accessibleSchools($actor, true)->isEmpty()) {
            throw new AuthorizationException('A Central Finance identity is required.');
        }
        return $actor;
    }

    /** @return Collection<int, School> */
    public function accessibleSchools(CentralFinanceUser $actor, bool $includeQaTest = false): Collection
    {
        // A Central Finance school scope is necessary but deliberately not
        // sufficient. The School must also be reachable through an active
        // Group membership with the explicit read capability. This makes the
        // Group configuration the first boundary and prevents a stray scope
        // row (or a Super Admin identity) from becoming finance authority.
        $connection = DB::connection('mysql');
        $scopeSchoolIds = $connection->table('central_finance_user_school_scopes')
            ->where('user_id', $actor->id)
            ->where('can_view', true)
            ->pluck('school_id');
        $groupSchoolIds = $this->groupUsers($actor)
            ->flatMap(fn (FinanceGroupUser $groupUser) => $this->groups->accessibleSchools($groupUser, 'view_reports')->pluck('school_id'))
            ->unique()
            ->values();

        $query = School::on('mysql')->whereIn('id', $scopeSchoolIds->intersect($groupSchoolIds)->values());
        // A Head Finance / Super Admin needs to be able to deliberately enter
        // a long-lived QA School.  This only exposes the School selector; all
        // records remain hidden unless the authorized user enables the
        // Include QA/Test filter.  School-staff identities never receive this
        // broader selector.
        $this->dataIsolation->apply($query, 'school', $includeQaTest || $this->dataIsolation->canIncludeQaTest($actor));

        return $query->orderBy('name')->get(['id', 'name', 'code'])
            ->each(fn (School $school) => $school->setAttribute('is_qa_test', $this->dataIsolation->isQaTestSchool((int) $school->id)));
    }

    public function enterSchool(CentralFinanceUser $actor, int $schoolId): School
    {
        $school = $this->assertCanViewSchool($actor, $schoolId);
        Session::put(self::SESSION_SCHOOL_KEY, $school->id);
        return $school;
    }

    /** Resolve a School only through the existing Central + Group read boundary. */
    public function assertCanViewSchool(CentralFinanceUser $actor, int $schoolId): School
    {
        $school = $this->accessibleSchools($actor, true)->firstWhere('id', $schoolId);
        if ($school === null) {
            throw new AuthorizationException('The Central Finance actor cannot view this School.');
        }

        return $school;
    }

    public function exitSchool(): void
    {
        Session::forget(self::SESSION_SCHOOL_KEY);
    }

    public function currentSchool(CentralFinanceUser $actor, bool $includeQaTest = false): ?School
    {
        $schoolId = Session::get(self::SESSION_SCHOOL_KEY);
        $schools = $this->accessibleSchools($actor, $includeQaTest);
        if (is_int($schoolId) || ctype_digit((string) $schoolId)) {
            $school = $schools->firstWhere('id', (int) $schoolId);
            if ($school !== null) {
                return $school;
            }
        }

        // A School Staff principal is a single-School Finance identity, not a
        // central All Schools identity. It must therefore fail closed when its
        // scoped School is ambiguous and otherwise default to its only School.
        if ($this->isSchoolStaffPrincipal($actor)) {
            // A single-School staff identity still needs its School as the
            // tenancy/scope label when that School is classified QA/Test.
            // Its records remain excluded by the classification query layer;
            // returning the School here avoids turning a clean empty state
            // into an authorization error for Front Desk or School Finance.
            if (!$includeQaTest && $schools->isEmpty()) {
                $allScopedSchools = $this->accessibleSchools($actor, true);
                if ($allScopedSchools->count() === 1) {
                    return $allScopedSchools->sole();
                }
            }
            if ($schools->count() !== 1) {
                throw new AuthorizationException('A School Staff Finance identity requires exactly one authorized School.');
            }

            return $schools->sole();
        }

        return null;
    }

    public function isSchoolStaffPrincipal(CentralFinanceUser $actor): bool
    {
        return $actor->getRawOriginal('central_finance_principal_type') === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE;
    }

    /**
     * School staff identities use Central canonical services behind a
     * single-School facade. Authorization remains server-side.
     */
    public function usesSchoolFinanceFacade(CentralFinanceUser $actor): bool
    {
        return $this->isSchoolStaffPrincipal($actor);
    }

    /** Pending Collection confirmation is intentionally Head Finance only. */
    public function assertHeadFinance(CentralFinanceUser $actor): void
    {
        if (!$this->canReviewPendingCollections($actor)) {
            throw new AuthorizationException('Only Head Finance can review Pending Collections.');
        }
    }

    public function canReviewPendingCollections(CentralFinanceUser $actor): bool
    {
        return $this->groupUsers($actor)
            ->contains(fn (FinanceGroupUser $groupUser): bool => $this->groups->isCentralHeadFinance($groupUser));
    }

    public function requireOperatingSchool(CentralFinanceUser $actor): School
    {
        $school = $this->currentSchool($actor);
        if ($school === null) {
            throw new AuthorizationException('Select an authorized School before operating Central Finance.');
        }
        return $this->assertCanOperateSchool($actor, (int) $school->id);
    }

    /**
     * The School is re-resolved from the central registry and then checked
     * against both Central School scope and active Group operating scope.
     * Import services use this rather than accepting a tenant database name.
     */
    public function assertCanOperateSchool(CentralFinanceUser $actor, int $schoolId): School
    {
        $school = $this->accessibleSchools($actor, true)->firstWhere('id', $schoolId);
        if ($school === null) {
            throw new AuthorizationException('The Central Finance actor cannot operate this School.');
        }
        $this->schools->assertCanOperate($actor, $school->id);
        if (!$this->groupUsers($actor)->contains(fn (FinanceGroupUser $groupUser): bool => $this->groups->canAccessSchool($groupUser, $school->id, 'operate_finance'))) {
            throw new AuthorizationException('The Central Finance actor has no operating Group scope for this School.');
        }
        return $school;
    }

    /** A Front Desk collection submission is deliberately narrower than Finance operation. */
    public function assertCanSubmitCollectionsSchool(CentralFinanceUser $actor, int $schoolId): School
    {
        $school = $this->accessibleSchools($actor, true)->firstWhere('id', $schoolId);
        if ($school === null) throw new AuthorizationException('The Central Finance actor cannot access this School.');
        $this->schools->assertCanSubmitCollections($actor, $school->id);
        return $school;
    }

    /** @return Collection<int, CentralFinanceFundAccount> */
    public function accessibleAccounts(CentralFinanceUser $actor, ?int $schoolId = null, bool $includeQaTest = false): Collection
    {
        $query = $this->accounts->visibleAccounts($actor, $schoolId, true)->active()->orderBy('account_name');
        $this->dataIsolation->apply($query, 'fund_account', $includeQaTest);
        return $query->get();
    }

    /**
     * Read models are School-scoped, not Fund-Account-operation-scoped.
     * A School Accountant may read the school's Ledger/Audit trail in order to
     * reconcile work performed by the finance team, while every write continues
     * to use accessibleAccounts() and the Fund Account scope service.
     *
     * @return Collection<int, CentralFinanceFundAccount>
     */
    public function readableAccounts(CentralFinanceUser $actor, ?int $schoolId = null, bool $includeQaTest = false): Collection
    {
        $query = $this->accounts->visibleAccounts($actor, $schoolId, false)->orderBy('account_name');
        $this->dataIsolation->apply($query, 'fund_account', $includeQaTest);

        return $query->get();
    }

    /**
     * Front Desk selects a parent-declared destination only; this is not
     * Fund-Account operation authority. The School collection role plus an
     * active explicit allocation is the complete selection boundary. Canonical
     * posting is re-authorized by Head Finance at confirmation.
     *
     * @return Collection<int, CentralFinanceFundAccount>
     */
    public function collectionBankAccounts(CentralFinanceUser $actor, int $schoolId, bool $includeQaTest = false): Collection
    {
        $this->assertCanSubmitCollectionsSchool($actor, $schoolId);
        $query = CentralFinanceFundAccount::on('mysql')->active()
            ->where('account_type', 'bank')
            ->whereHas('schoolAllocations', fn (Builder $allocations) => $allocations
                ->where('school_id', $schoolId)->effective())
            ->orderBy('account_name');
        $this->dataIsolation->apply($query, 'fund_account', $includeQaTest);
        return $query->get();
    }

    public function idempotencyReference(string $prefix): string
    {
        return $prefix.'-'.Str::uuid();
    }

    /** @return Collection<int, FinanceGroupUser> */
    private function groupUsers(CentralFinanceUser $actor): Collection
    {
        return FinanceGroupUser::on('mysql')->where('central_user_id', $actor->id)
            ->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->where('status', 'active'))
            ->get();
    }
}

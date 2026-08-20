<?php

namespace App\Services;

use App\Models\FinanceGroupSchool;
use App\Models\FinanceGroupUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Read-only adapter for a Scheme B Finance Operating Context.
 *
 * It reuses canonical Ledger V1 and Fund Account balance calculations through
 * a mapped tenant identity. The central authenticated user never becomes a
 * tenant user and this class exposes no tenant write operation.
 */
class FinanceOperatingWorkspaceService
{
    public function __construct(
        private readonly FinanceOperatingContextService $context,
        private readonly FinanceGroupScopeService $scope,
        private readonly FinanceLedgerV1Service $ledger,
    ) {
    }

    /** @return array{context:\App\ValueObjects\FinanceOperatingContext,group:\App\Models\FinanceGroup,school:\App\Models\School,groupUser:FinanceGroupUser,schools:\Illuminate\Database\Eloquent\Collection<int, FinanceGroupSchool>} */
    public function workspace(User $centralActor): array
    {
        $context = $this->context->current($centralActor);
        if (!$context) {
            throw new AuthorizationException('Select an authorized School before opening Finance operations.');
        }

        $group = $this->context->currentGroup($centralActor);
        $school = $this->context->currentSchool($centralActor);
        if (!$group || !$school) {
            throw new AuthorizationException('The Finance Operating Context is no longer available.');
        }

        $groupUser = FinanceGroupUser::query()
            ->where('group_id', $context->groupId)
            ->where('central_user_id', $context->centralActorId)
            ->where('status', 'active')
            ->first();
        if (!$groupUser) {
            throw new AuthorizationException('The Finance Operating Context is no longer authorized.');
        }

        $schools = $this->scope->accessibleSchools($groupUser, FinanceOperatingContextService::OPERATING_CAPABILITY)->load('school');

        return compact('context', 'group', 'school', 'groupUser', 'schools');
    }

    /** @param array<string,mixed> $filters
     * @return array{rows:\Illuminate\Support\Collection<int,array<string,mixed>>,summary:array<string,float>}
     */
    public function ledger(User $centralActor, array $filters = []): array
    {
        $workspace = $this->workspace($centralActor);
        $membership = FinanceGroupSchool::query()
            ->where('group_id', $workspace['context']->groupId)
            ->where('school_id', $workspace['context']->schoolId)
            ->where('status', 'active')
            ->first();
        if (!$membership) {
            throw new AuthorizationException('The selected School is not an active Group member.');
        }

        return $this->scope->readLedgerAsTenantIdentity(
            $workspace['groupUser'],
            $membership,
            $this->ledger,
            $filters,
            FinanceOperatingContextService::OPERATING_CAPABILITY,
        );
    }

    /** @return array<int,array{id:int,account_name:string,currency:string,money_in:float,money_out:float,current_balance:float,operating_income:float,operating_expense:float}> */
    public function accounts(User $centralActor): array
    {
        $workspace = $this->workspace($centralActor);

        return $this->scope->readActiveTenantAccountSummariesForGroupUser(
            $workspace['groupUser'],
            $workspace['context']->schoolId,
            FinanceOperatingContextService::OPERATING_CAPABILITY,
        );
    }
}

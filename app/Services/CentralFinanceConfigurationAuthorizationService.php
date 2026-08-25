<?php

namespace App\Services;

use App\Models\CentralFinanceUser;
use App\Models\CentralFinanceSchoolStaffIdentity;
use App\Models\FinanceGroupUser;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Central configuration is explicit: a role alone never grants School access. */
final class CentralFinanceConfigurationAuthorizationService
{
    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly FinanceGroupScopeService $groups,
    ) {}

    public function assertHeadFinanceCanConfigureSchool(CentralFinanceUser $actor, School $school): FinanceGroupUser
    {
        $actor = CentralFinanceUser::on('mysql')->findOrFail($actor->id);
        $roleIdentity = User::on('mysql')->findOrFail($actor->id);
        if ($actor->getRawOriginal('school_id') !== null
            || !Schema::connection('mysql')->hasTable('roles')
            || !Schema::connection('mysql')->hasTable('model_has_roles')
            || !$roleIdentity->hasRole('Head Finance')) {
            throw new AuthorizationException('Only Central Head Finance can configure Fund Accounts or cutover.');
        }

        $this->schools->assertCanOperate($actor, $school->id);
        $groupUser = FinanceGroupUser::on('mysql')->where('central_user_id', $actor->id)
            ->where('status', 'active')->get()
            ->first(fn (FinanceGroupUser $user): bool => $this->groups->canAccessSchool($user, $school->id, 'operate_finance'));
        if ($groupUser === null) {
            throw new AuthorizationException('Head Finance lacks active Group operating scope for this School.');
        }

        return $groupUser;
    }

    public function isHeadFinanceOperatingForSchool(CentralFinanceUser $actor, School $school, int $groupId): bool
    {
        try {
            $groupUser = $this->assertHeadFinanceCanConfigureSchool($actor, $school);
            return $groupUser->group_id === $groupId;
        } catch (\Throwable) {
            return false;
        }
    }

    /** HQ custody is a Group control-plane privilege, never an implied School-operate grant. */
    public function assertHeadFinanceCanConfigureHq(CentralFinanceUser $actor, School $school): FinanceGroupUser
    {
        $groupUser = $this->assertHeadFinanceCanConfigureSchool($actor, $school);
        if (!$this->groups->canControlHqAccounts($groupUser)) {
            throw new AuthorizationException('Head Finance lacks explicit Group HQ Fund Account control.');
        }

        return $groupUser;
    }

    public function assertEligibleAssignee(int $groupId, School $school, int $userId, bool $canOperate): CentralFinanceUser
    {
        $user = CentralFinanceUser::on('mysql')->findOrFail($userId);
        $principalType = $user->getRawOriginal('central_finance_principal_type') ?? 'central_user';
        $isVerifiedSchoolStaff = $principalType === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE
            && (int) $user->getRawOriginal('school_id') === $school->id
            && Schema::connection('mysql')->hasTable('central_finance_school_staff_identities')
            && CentralFinanceSchoolStaffIdentity::on('mysql')->where([
                'central_user_id' => $user->id,
                'school_id' => $school->id,
                'status' => 'active',
            ])->exists();
        if (!$isVerifiedSchoolStaff && ($principalType !== 'central_user' || $user->getRawOriginal('school_id') !== null)) {
            throw new AuthorizationException('A tenant identity cannot receive a Central Fund Account assignment.');
        }
        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')
            ->where(['user_id' => $user->id, 'school_id' => $school->id])->first();
        if (!$scope || !$scope->can_view || ($canOperate && !$scope->can_operate)) {
            throw new AuthorizationException('Fund Account assignees require matching Central School scope.');
        }
        $groupUser = FinanceGroupUser::on('mysql')->where(['group_id' => $groupId, 'central_user_id' => $user->id, 'status' => 'active'])->first();
        if (!$groupUser || !$this->groups->canAccessSchool($groupUser, $school->id, $canOperate ? 'operate_finance' : 'view_reports')) {
            throw new AuthorizationException('Fund Account assignees require matching active Group scope.');
        }

        return $user;
    }
}

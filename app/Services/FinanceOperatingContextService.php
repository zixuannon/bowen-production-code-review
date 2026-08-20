<?php

namespace App\Services;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupUser;
use App\Models\School;
use App\Models\User;
use App\ValueObjects\FinanceOperatingContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;

/**
 * Scheme B foundation for a central User operating in one trusted School.
 *
 * This service is intentionally context-only. It never calls Auth::login,
 * never stores a database name, and never exposes a generic tenant callback.
 * A later route adapter must consume this context before existing tenant
 * Finance routes may be entered.
 */
class FinanceOperatingContextService
{
    public const SESSION_KEY = 'finance_operating_context.v1';

    public const OPERATING_CAPABILITY = 'operate_finance';

    public function __construct(
        private readonly FinanceGroupScopeService $scope,
        private readonly Session $session,
    ) {
    }

    public function enterSchool(User $authenticated, int $groupId, int $schoolId): FinanceOperatingContext
    {
        $central = $this->centralActor($authenticated);
        $this->assertNoTenantLoginContext();

        $group = FinanceGroup::on('mysql')->whereKey($groupId)->where('status', 'active')->first();
        if (!$group) {
            throw new AuthorizationException('The requested Finance Group is unavailable.');
        }

        $groupUser = FinanceGroupUser::query()
            ->where('group_id', $group->id)
            ->where('central_user_id', $central->id)
            ->where('status', 'active')
            ->first();
        if (!$groupUser || !$this->scope->canAccessSchool($groupUser, $schoolId, self::OPERATING_CAPABILITY)) {
            throw new AuthorizationException('This central user is not authorized to operate the requested School.');
        }

        // The resolver performs a short-lived connection to a School resolved
        // from the central registry, confirms that the mapped user still
        // belongs to it, then restores the previous connection.
        $identity = $this->scope->resolveTrustedTenantIdentity(
            $groupUser,
            $schoolId,
            self::OPERATING_CAPABILITY,
        );

        $context = new FinanceOperatingContext(
            (int) $central->id,
            (int) $group->id,
            (int) $identity['school_id'],
            (int) $identity['tenant_user_id'],
        );
        $this->session->put(self::SESSION_KEY, $context->toSession());

        return $context;
    }

    public function exitSchool(User $authenticated): void
    {
        $context = $this->current($authenticated);
        if ($context !== null && $context->centralActorId !== $this->centralActor($authenticated)->id) {
            throw new AuthorizationException('Only the central actor may leave this Finance Operating Context.');
        }

        $this->session->forget(self::SESSION_KEY);
    }

    public function current(User $authenticated): ?FinanceOperatingContext
    {
        $this->assertNoTenantLoginContext();
        $raw = $this->session->get(self::SESSION_KEY);
        if ($raw === null) {
            return null;
        }

        try {
            $context = FinanceOperatingContext::fromSession($raw);
        } catch (InvalidArgumentException $exception) {
            $this->session->forget(self::SESSION_KEY);
            throw new AuthorizationException('The Finance Operating Context is invalid.', previous: $exception);
        }

        $central = $this->centralActor($authenticated);
        if ($context->centralActorId !== (int) $central->id) {
            $this->session->forget(self::SESSION_KEY);
            throw new AuthorizationException('The Finance Operating Context belongs to another central user.');
        }

        $group = FinanceGroup::on('mysql')->whereKey($context->groupId)->where('status', 'active')->first();
        if (!$group) {
            $this->session->forget(self::SESSION_KEY);
            throw new AuthorizationException('The Finance Group is no longer active.');
        }

        $groupUser = FinanceGroupUser::query()
            ->where('group_id', $group->id)
            ->where('central_user_id', $central->id)
            ->where('status', 'active')
            ->first();
        if (!$groupUser || !$this->scope->canAccessSchool($groupUser, $context->schoolId, self::OPERATING_CAPABILITY)) {
            $this->session->forget(self::SESSION_KEY);
            throw new AuthorizationException('The Finance Operating Context is no longer authorized.');
        }

        $identity = $this->scope->resolveTrustedTenantIdentity(
            $groupUser,
            $context->schoolId,
            self::OPERATING_CAPABILITY,
        );
        if ((int) $identity['tenant_user_id'] !== $context->tenantUserId) {
            $this->session->forget(self::SESSION_KEY);
            throw new AuthorizationException('The mapped tenant identity has changed.');
        }

        return $context;
    }

    public function currentGroup(User $authenticated): ?FinanceGroup
    {
        $context = $this->current($authenticated);
        return $context ? FinanceGroup::on('mysql')->find($context->groupId) : null;
    }

    public function currentSchool(User $authenticated): ?School
    {
        $context = $this->current($authenticated);
        return $context ? School::on('mysql')->find($context->schoolId) : null;
    }

    public function trustedTenantIdentity(User $authenticated): ?int
    {
        return $this->current($authenticated)?->tenantUserId;
    }

    /** @return array<int, array{id:int,account_name:string,currency:string}> */
    public function accessibleFundAccounts(User $authenticated): array
    {
        $context = $this->current($authenticated);
        if ($context === null) {
            return [];
        }

        $groupUser = FinanceGroupUser::query()
            ->where('group_id', $context->groupId)
            ->where('central_user_id', $context->centralActorId)
            ->where('status', 'active')
            ->first();
        if (!$groupUser) {
            throw new AuthorizationException('The Finance Operating Context is no longer authorized.');
        }

        // This is the existing FinanceAccountAccessService scope of the
        // mapped tenant identity. It is not an all-account Group shortcut.
        return $this->scope->accessibleActiveTenantAccountsForGroupUser(
            $groupUser,
            $context->schoolId,
            self::OPERATING_CAPABILITY,
        );
    }

    public function centralActor(User $authenticated): User
    {
        if ($authenticated->school_id !== null) {
            throw new AuthorizationException('A tenant user cannot enter Finance Operating Context.');
        }

        $central = User::on('mysql')->find($authenticated->id);
        if (!$central || $central->school_id !== null) {
            throw new AuthorizationException('A trusted central user is required.');
        }

        if (!$central->hasRole('Head Finance')) {
            throw new AuthorizationException('Only Central Head Finance may enter Finance Operating Context.');
        }

        return $central;
    }

    private function assertNoTenantLoginContext(): void
    {
        // Do not overwrite the established tenant-login session key. Scheme B
        // must start from a central login and keeps its own opaque context.
        if ($this->session->get('school_database_name')) {
            throw new AuthorizationException('A tenant-login session cannot enter Finance Operating Context.');
        }
    }
}

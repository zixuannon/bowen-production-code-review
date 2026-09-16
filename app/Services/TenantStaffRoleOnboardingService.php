<?php

namespace App\Services;

use App\Models\Role;
use App\Models\SchoolRecordLifecycleAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** Assigns fixed tenant identity roles without granting Central Finance scope. */
final class TenantStaffRoleOnboardingService
{
    public const SCHOOL_ACCOUNTANT = 'School Accountant';
    public const FRONT_DESK = 'Front Desk / Admissions & Collection';
    public const PRINCIPAL = 'Principal';

    /** @return array<string, string> */
    public static function roleDescriptions(): array
    {
        return [
            self::SCHOOL_ACCOUNTANT => 'Tenant identity only. Tuition collection is not granted by this role assignment.',
            self::FRONT_DESK => 'Tenant identity only. Central access remains limited to the explicit Pending Collection grant.',
            self::PRINCIPAL => 'Tenant identity only. Central Finance access remains read-only when explicitly granted.',
        ];
    }

    /** @return array<int, string> */
    public static function roleNames(): array
    {
        return array_keys(self::roleDescriptions());
    }

    /**
     * Add canonical roles to an existing Staff member. Existing roles are
     * retained; Central Finance identity/scope remains a separate Super Admin
     * action in Finance Groups.
     *
     * @param array<int, string> $roleNames
     */
    public function assign(User $actor, User $staff, array $roleNames, string $reason): User
    {
        if (!$actor->hasRole('School Admin')
            || !$actor->school_id
            || (int) $staff->school_id !== (int) $actor->school_id) {
            throw new AuthorizationException('Only the School Admin may assign onboarding roles within their own School.');
        }

        $roleNames = collect($roleNames)->map(fn ($name) => trim((string) $name))->filter()->unique()->values();
        if ($roleNames->isEmpty() || $roleNames->diff(self::roleNames())->isNotEmpty()) {
            throw ValidationException::withMessages(['roles' => [__('Select only approved School onboarding roles.')]]);
        }
        if (!$staff->staff()->exists() || (int) $staff->status !== 1 || $staff->trashed()) {
            throw ValidationException::withMessages(['staff_id' => [__('Select an active Staff member from this School.')]]);
        }

        return DB::connection('school')->transaction(function () use ($actor, $staff, $roleNames, $reason): User {
            /** @var User $locked */
            $locked = User::on('school')->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->roles()->orderBy('name')->pluck('name')->all();
            $roleIds = [];

            foreach ($roleNames as $roleName) {
                $role = Role::on('school')->withoutGlobalScopes()
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->where('school_id', $actor->school_id)
                    ->first();

                if ($role && $role->permissions()->exists()) {
                    throw ValidationException::withMessages([
                        'roles' => [__('An existing role with this name has custom permissions and requires manual review.')],
                    ]);
                }

                if (!$role) {
                    $role = Role::on('school')->withoutGlobalScopes()->create([
                        'name' => $roleName,
                        'guard_name' => 'web',
                        'school_id' => (int) $actor->school_id,
                        'custom_role' => 1,
                        'editable' => 0,
                    ]);
                } else {
                    // Make an existing permission-free canonical role visible
                    // to Staff administration without ever rewriting grants.
                    $role->forceFill(['custom_role' => 1, 'editable' => 0])->save();
                }
                $roleIds[] = (int) $role->id;
            }

            $locked->roles()->syncWithoutDetaching($roleIds);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $locked->unsetRelation('roles');
            $after = $locked->roles()->orderBy('name')->pluck('name')->all();

            app(SchoolRecordLifecycleAuditService::class)->record(
                $actor,
                $locked,
                SchoolRecordLifecycleAudit::ASSIGN_ROLE,
                $reason,
                ['requested_roles' => $roleNames->all(), 'before_roles' => $before, 'after_roles' => $after],
            );

            return $locked->load('roles');
        });
    }
}

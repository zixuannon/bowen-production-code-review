<?php

namespace App\Services;

use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Applies the deliberately narrow Finance permissions missing from tenants
 * that predate named Finance authorization. It never assigns roles/users or
 * changes a Fund Account or financial record.
 */
class ExistingTenantFinancePermissionBootstrap
{
    /** @var array<string, array<int, string>> */
    public const ROLE_PERMISSIONS = [
        'School Admin' => [
            'finance-staff-manage',
            'finance-handover-view',
            'finance-transfer-view',
            'finance-transfer-create',
        ],
        'Head Finance' => [
            'finance-staff-manage',
            'finance-handover-view',
            'finance-handover-create',
            'finance-handover-confirm',
            'finance-handover-reject',
            'finance-handover-cancel',
            'finance-transfer-view',
            'finance-transfer-create',
        ],
        'Cashier' => [
            'finance-handover-view',
            'finance-handover-create',
            'finance-handover-confirm',
            'finance-handover-reject',
            'finance-handover-cancel',
            'finance-transfer-view',
            'finance-transfer-create',
        ],
    ];

    /** @return array<int, string> */
    public static function permissionNames(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ROLE_PERMISSIONS))));
    }

    /** @return array<string, array<int, string>> */
    public function status(int $schoolId): array
    {
        $roles = $this->roles($schoolId);

        return collect(self::ROLE_PERMISSIONS)->mapWithKeys(
            fn (array $permissions, string $role) => [
                $role => $roles[$role]->permissions()
                    ->whereIn('name', self::permissionNames())
                    ->pluck('name')
                    ->sort()
                    ->values()
                    ->all(),
            ],
        )->all();
    }

    /** @return array<string, Role> */
    public function preflight(int $schoolId): array
    {
        return $this->roles($schoolId);
    }

    public function apply(int $schoolId): void
    {
        $roles = $this->roles($schoolId);

        foreach (self::permissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::ROLE_PERMISSIONS as $name => $permissions) {
            $roles[$name]->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, Role> */
    private function roles(int $schoolId): array
    {
        $roles = Role::withoutGlobalScope('school')
            ->where('school_id', $schoolId)
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys(self::ROLE_PERMISSIONS))
            ->get()
            ->keyBy('name');

        $missing = array_diff(array_keys(self::ROLE_PERMISSIONS), $roles->keys()->all());
        if ($missing !== []) {
            throw new \LogicException('Required Finance role definitions are missing: ' . implode(', ', $missing));
        }

        return $roles->all();
    }
}

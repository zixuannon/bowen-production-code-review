<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The only tenant permissions a canonical Front Desk role may receive.
 *
 * Collection authority is deliberately not represented by a tenant payment
 * permission. It remains the separately granted Central Finance pending-
 * collection scope. This keeps tenant master-data setup from reviving a
 * legacy direct-payment path after Central cutover.
 */
final class TenantFrontDeskFeeSetupPermissionContract
{
    /** @var list<string> */
    public const PERMISSIONS = [
        // Read-only student lookup is required to reach the existing Student
        // Profile → Fee Setup workflow. It deliberately grants no student
        // create, edit, or delete capability.
        'student-list',
        'fees-list',
        'fees-create',
        'fees-edit',
        'fees-type-list',
        'fees-type-create',
        'fees-type-edit',
        'fees-class-list',
        'fees-class-create',
        'fees-class-edit',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Adds only the known fee-setup permissions to a canonical Front Desk
     * role. Any existing unknown permission is a fail-closed manual review,
     * never a silent role rewrite.
     */
    public static function synchronize(ConnectionInterface $connection, int $roleId): void
    {
        $current = $connection->table('role_has_permissions as pivot')
            ->join('permissions', 'permissions.id', '=', 'pivot.permission_id')
            ->where('pivot.role_id', $roleId)
            ->pluck('permissions.name')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        if (array_diff($current, self::PERMISSIONS) !== []) {
            throw new RuntimeException('Canonical Front Desk role has non-contract permission grants and requires manual review.');
        }

        $ids = $connection->table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id', 'name');
        $missing = array_values(array_diff(self::PERMISSIONS, $ids->keys()->all()));
        if ($missing !== []) {
            throw new RuntimeException('Tenant is missing required Front Desk fee-setup permissions: '.implode(', ', $missing));
        }

        foreach (self::PERMISSIONS as $permission) {
            $connection->table('role_has_permissions')->updateOrInsert([
                'permission_id' => (int) $ids[$permission],
                'role_id' => $roleId,
            ]);
        }
    }
}

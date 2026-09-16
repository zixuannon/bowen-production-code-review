<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates only the canonical tenant identity role definitions used by the
 * Finance Staff Onboarding workflow. It never assigns a user, permission, or
 * Central Finance scope.
 */
final class TenantFinanceOnboardingRoleProvisioner
{
    /**
     * @return array<string, string> role name => created|reused
     */
    public function provision(int $schoolId, string $schoolCode, string $database, string $source): array
    {
        $schoolCode = trim($schoolCode);
        $database = trim($database);
        $source = trim($source);
        if ($schoolId < 1 || $schoolCode === '' || $database === '' || $source === '') {
            throw new RuntimeException('Finance onboarding role provisioning context is incomplete.');
        }

        $connection = DB::connection('school');
        if ($connection->getDatabaseName() !== $database) {
            throw new RuntimeException("Tenant connection mismatch for School {$schoolId}; provisioning refused.");
        }

        $result = $connection->transaction(function () use ($connection, $schoolId): array {
            $names = TenantStaffRoleOnboardingService::roleNames();

            // Validate every existing canonical-name role before the first
            // insert so a conflicting custom permission bundle fails closed.
            $existing = $connection->table('roles')
                ->where('school_id', $schoolId)
                ->where('guard_name', 'web')
                ->whereIn('name', $names)
                ->lockForUpdate()
                ->get()
                ->keyBy('name');

            foreach ($existing as $role) {
                if ($connection->table('role_has_permissions')->where('role_id', $role->id)->exists()) {
                    throw new RuntimeException("Canonical onboarding role [{$role->name}] has permission grants and requires manual review.");
                }
            }

            $status = [];
            foreach ($names as $name) {
                if ($existing->has($name)) {
                    // Reuse exactly as found. In particular, do not rewrite
                    // custom_role/editable flags on an existing tenant role.
                    $status[$name] = 'reused';
                    continue;
                }

                $inserted = $connection->table('roles')->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => 'web',
                    'school_id' => $schoolId,
                    'custom_role' => 1,
                    'editable' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $role = $connection->table('roles')
                    ->where('school_id', $schoolId)
                    ->where('guard_name', 'web')
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();
                if (!$role || $connection->table('role_has_permissions')->where('role_id', $role->id)->exists()) {
                    throw new RuntimeException("Canonical onboarding role [{$name}] could not be safely provisioned.");
                }

                $status[$name] = $inserted === 1 ? 'created' : 'reused';
            }

            return $status;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::info('Tenant Finance onboarding roles provisioned', [
            'event' => 'tenant_finance_onboarding_roles_provisioned',
            'school_id' => $schoolId,
            'school_code' => $schoolCode,
            'tenant_database' => $database,
            'source' => $source,
            'roles' => $result,
            'central_scope_granted' => false,
        ]);

        return $result;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\TenantFinanceOnboardingRoleProvisioner;
use App\Services\TenantStaffRoleOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Registry-driven role-definition provisioner for every active installed
 * tenant. Inactive/decommissioned registry rows are not operational tenants.
 * It is deliberately definition-only: no user assignment, permissions, or
 * Central Finance scope are granted.
 */
final class ProvisionFinanceOnboardingRoles extends Command
{
    protected $signature = 'finance:provision-onboarding-roles
        {--execute : Create only missing canonical tenant role definitions; otherwise dry-run}';

    protected $description = 'Verify or provision the three canonical Finance onboarding identity roles in every active installed tenant';

    public function handle(TenantFinanceOnboardingRoleProvisioner $provisioner): int
    {
        $previousDefault = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.school.database');

        try {
            $schools = $this->registry();
            if ($schools === null) {
                return self::FAILURE;
            }

            $preflight = [];
            foreach ($schools as $school) {
                if (!$this->connectAndValidate($school)) {
                    return self::FAILURE;
                }

                $preflight[(int) $school->id] = $this->status((int) $school->id);
                $this->line("[{$school->code}] {$school->database_name}: ".$this->format($preflight[(int) $school->id]));
            }

            if (!$this->option('execute')) {
                $this->info('Dry-run only; zero role, permission, user, and Central Finance scope writes.');

                return self::SUCCESS;
            }

            Log::notice('Global tenant Finance onboarding role provisioning started', [
                'event' => 'global_finance_onboarding_provisioning_started',
                'tenant_count' => $schools->count(),
                'roles' => TenantStaffRoleOnboardingService::roleNames(),
                'central_scope_granted' => false,
            ]);

            foreach ($schools as $school) {
                if (!$this->connectAndValidate($school)) {
                    return self::FAILURE;
                }
                if ($this->status((int) $school->id) !== $preflight[(int) $school->id]) {
                    return $this->fail("[{$school->code}] role state changed after preflight; provisioning stopped.");
                }

                try {
                    $result = $provisioner->provision(
                        (int) $school->id,
                        (string) $school->code,
                        (string) $school->database_name,
                        'global_existing_tenant_provisioning',
                    );
                } catch (Throwable $exception) {
                    return $this->fail("[{$school->code}] provisioning failed closed: {$exception->getMessage()}");
                }

                $after = $this->status((int) $school->id);
                if (in_array('missing', $after, true) || in_array('permission_conflict', $after, true)) {
                    return $this->fail("[{$school->code}] post-provision verification failed; provisioning stopped.");
                }
                $this->info("[{$school->code}] ".$this->format($result));
            }

            Log::notice('Global tenant Finance onboarding role provisioning completed', [
                'event' => 'global_finance_onboarding_provisioning_completed',
                'tenant_count' => $schools->count(),
                'central_scope_granted' => false,
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->fail('Global Finance onboarding provisioning failed closed: '.$exception->getMessage());
        } finally {
            Config::set('database.connections.school.database', $previousDatabase);
            DB::purge('school');
            DB::setDefaultConnection($previousDefault);
        }
    }

    private function registry(): ?\Illuminate\Database\Eloquent\Collection
    {
        $schools = School::on('mysql')->where('installed', 1)->where('status', 1)->whereNull('deleted_at')->orderBy('id')->get();
        if ($schools->isEmpty()) {
            $this->error('The active installed tenant registry is empty; refused.');

            return null;
        }

        $databases = [];
        foreach ($schools as $school) {
            $database = (string) $school->database_name;
            $safeDatabase = preg_match('/\A[A-Za-z0-9_]+\z/', $database) === 1
                || (app()->environment('testing')
                    && config('database.connections.mysql.driver') === 'sqlite'
                    && is_file($database));
            if (!$school->id || trim((string) $school->code) === '' || !$safeDatabase
                || $database === (string) config('database.connections.mysql.database') || isset($databases[$database])) {
                $this->error('The active installed tenant registry contains an incomplete, unsafe, or duplicate mapping; refused.');

                return null;
            }
            $databases[$database] = true;
        }

        return $schools;
    }

    private function connectAndValidate(School $school): bool
    {
        try {
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            $connection = DB::connection('school');
            $connection->getPdo();
            DB::setDefaultConnection('school');
            if ($connection->getDatabaseName() !== (string) $school->database_name) {
                throw new \LogicException('connection target differs from the central registry');
            }

            foreach (['schools', 'roles', 'role_has_permissions'] as $table) {
                if (!Schema::connection('school')->hasTable($table)) {
                    throw new \LogicException("required tenant table missing: {$table}");
                }
            }
            if (!Schema::connection('school')->hasColumns('roles', ['id', 'name', 'guard_name', 'school_id', 'custom_role', 'editable'])
                || !Schema::connection('school')->hasColumns('role_has_permissions', ['role_id', 'permission_id'])) {
                throw new \LogicException('required tenant role schema is incomplete');
            }

            $matches = $connection->table('schools')
                ->where('id', $school->id)
                ->where('database_name', $school->database_name)
                ->count();
            if ($matches !== 1) {
                throw new \LogicException('tenant School/database mapping differs from the central registry');
            }

            if (in_array('permission_conflict', $this->status((int) $school->id), true)) {
                throw new \LogicException('a canonical-name role already has permissions and requires manual review');
            }

            return true;
        } catch (Throwable $exception) {
            $this->error("[{$school->code}] tenant preflight failed closed: {$exception->getMessage()}");

            return false;
        }
    }

    /** @return array<string, string> */
    private function status(int $schoolId): array
    {
        $status = [];
        foreach (TenantStaffRoleOnboardingService::roleNames() as $name) {
            $role = DB::connection('school')->table('roles')
                ->where('school_id', $schoolId)
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->first();
            $status[$name] = !$role
                ? 'missing'
                : (DB::connection('school')->table('role_has_permissions')->where('role_id', $role->id)->exists()
                    ? 'permission_conflict'
                    : 'exists');
        }

        return $status;
    }

    /** @param array<string, string> $status */
    private function format(array $status): string
    {
        return collect($status)->map(fn (string $state, string $role) => "{$role}={$state}")->implode(', ');
    }

    private function fail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}

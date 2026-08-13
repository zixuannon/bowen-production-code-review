<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class XiaobailongReconcilePermissions extends Command
{
    protected $signature = 'xiaobailong:reconcile-permissions
                            {--dry-run : Report missing records without changing data}';

    protected $description = 'Ensure every school Teacher role can open the Xiaobailong workspace.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $originalDefault = DB::getDefaultConnection();
        $failures = 0;
        $rows = [];

        $schools = DB::connection('mysql')
            ->table('schools')
            ->whereNull('deleted_at')
            ->whereNotNull('database_name')
            ->orderBy('id')
            ->get(['id', 'name', 'database_name']);

        foreach ($schools as $school) {
            $database = (string) $school->database_name;

            if (!preg_match('/\Aeschool_saas_\d+_[A-Za-z0-9_]*\z/D', $database)) {
                $rows[] = [$school->id, $school->name, $database, 'SKIPPED', 'unsafe database name'];
                $failures++;
                continue;
            }

            try {
                Config::set('database.connections.school.database', $database);
                DB::purge('school');
                DB::connection('school')->getPdo();

                if (!Schema::connection('school')->hasTable('permissions') ||
                    !Schema::connection('school')->hasTable('roles') ||
                    !Schema::connection('school')->hasTable('role_has_permissions')) {
                    throw new \RuntimeException('permission tables are missing');
                }

                $permissionId = DB::connection('school')->table('permissions')
                    ->where('name', 'xiaobailong-use')
                    ->where('guard_name', 'web')
                    ->value('id');

                $teacherRoleIds = DB::connection('school')->table('roles')
                    ->where('name', 'Teacher')
                    ->where('guard_name', 'web')
                    ->pluck('id');

                $missingLinks = $permissionId
                    ? $teacherRoleIds->filter(function ($roleId) use ($permissionId) {
                        return !DB::connection('school')->table('role_has_permissions')
                            ->where('permission_id', $permissionId)
                            ->where('role_id', $roleId)
                            ->exists();
                    })
                    : $teacherRoleIds;

                $action = [];
                if (!$permissionId) {
                    $action[] = 'create permission';
                }
                if ($missingLinks->isNotEmpty()) {
                    $action[] = 'link ' . $missingLinks->count() . ' Teacher role(s)';
                }

                if (!$dryRun && (!$permissionId || $missingLinks->isNotEmpty())) {
                    DB::connection('school')->transaction(function () use (&$permissionId, $missingLinks) {
                        if (!$permissionId) {
                            $permissionId = DB::connection('school')->table('permissions')->insertGetId([
                                'name' => 'xiaobailong-use',
                                'guard_name' => 'web',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        foreach ($missingLinks as $roleId) {
                            DB::connection('school')->table('role_has_permissions')->insertOrIgnore([
                                'permission_id' => $permissionId,
                                'role_id' => $roleId,
                            ]);
                        }
                    });
                }

                $rows[] = [
                    $school->id,
                    $school->name,
                    $database,
                    $action ? ($dryRun ? 'WOULD REPAIR' : 'REPAIRED') : 'OK',
                    $action ? implode(', ', $action) : 'permission and role link present',
                ];
            } catch (Throwable $e) {
                $rows[] = [$school->id, $school->name, $database, 'FAILED', $this->firstLine($e->getMessage())];
                $failures++;
            } finally {
                DB::disconnect('school');
            }
        }

        DB::setDefaultConnection($originalDefault);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->table(['School', 'Name', 'Database', 'Status', 'Detail'], $rows);
        $this->line($dryRun ? 'Dry run complete; no data changed.' : 'Permission reconciliation complete.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }
}

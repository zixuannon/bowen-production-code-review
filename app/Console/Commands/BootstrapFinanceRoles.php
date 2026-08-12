<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\SchoolDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-time role-definition bootstrap for existing P2/P3 tenant databases.
 * It never assigns a role, changes permissions, or touches finance data.
 */
class BootstrapFinanceRoles extends Command
{
    protected $signature = 'finance:bootstrap-roles
        {--tenant=* : Exact approved tenant database name(s); required}
        {--execute : Create missing roles; otherwise report only}';

    protected $description = 'Safely verify or create only Head Finance and Cashier role definitions for approved tenants';

    public function handle(SchoolDataService $schools): int
    {
        $tenants = $this->option('tenant');
        if (!FinanceP2P3MigrationSafety::validTenantSelection($tenants)) {
            $this->error('Specify one or more unique tenant names from the fixed approved Finance P2/P3 allowlist only.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            if (!$this->connect($tenant)) {
                return self::FAILURE;
            }

            $school = School::on('school')->where('database_name', $tenant)->first();
            if (!$school) {
                $this->error("[$tenant] tenant school record is missing; refused.");

                return self::FAILURE;
            }

            $before = $this->roleStatus($school->id);
            $this->line("[$tenant] before: " . $this->format($before));

            if (!$this->option('execute')) {
                $this->info("[$tenant] dry-run only; no role definitions changed.");
                continue;
            }

            $schools->ensureFinanceRoles($school);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $after = $this->roleStatus($school->id);
            if (!$after['Head Finance'] || !$after['Cashier']) {
                $this->error("[$tenant] Finance role bootstrap verification failed.");

                return self::FAILURE;
            }
            $this->info("[$tenant] after: " . $this->format($after));
        }

        return self::SUCCESS;
    }

    public static function validTenantSelection(array $tenants): bool
    {
        return FinanceP2P3MigrationSafety::validTenantSelection($tenants);
    }

    private function connect(string $tenant): bool
    {
        try {
            if ($tenant === (string) config('database.connections.mysql.database')) {
                throw new \LogicException('central database cannot be selected');
            }
            Config::set('database.connections.school.database', $tenant);
            DB::purge('school');
            DB::connection('school')->getPdo();
            DB::setDefaultConnection('school');
            if (!Schema::connection('school')->hasTable('schools') || !Schema::connection('school')->hasTable('roles')) {
                throw new \LogicException('required tenant tables are missing');
            }

            return true;
        } catch (\Throwable $exception) {
            $this->error("[$tenant] tenant-only connection preflight failed; refused.");

            return false;
        }
    }

    /** @return array<string, bool> */
    private function roleStatus(int $schoolId): array
    {
        $names = DB::connection('school')->table('roles')
            ->where('school_id', $schoolId)
            ->where('guard_name', 'web')
            ->whereIn('name', ['Head Finance', 'Cashier'])
            ->pluck('name')
            ->all();

        return ['Head Finance' => in_array('Head Finance', $names, true), 'Cashier' => in_array('Cashier', $names, true)];
    }

    /** @param array<string, bool> $status */
    private function format(array $status): string
    {
        return collect($status)->map(fn (bool $exists, string $name) => "$name=" . ($exists ? 'exists' : 'missing'))->implode(', ');
    }
}

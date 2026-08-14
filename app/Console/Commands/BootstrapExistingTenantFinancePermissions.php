<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\ExistingTenantFinancePermissionBootstrap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BootstrapExistingTenantFinancePermissions extends Command
{
    /** @var array<string, string> Trusted school-code => tenant database mapping. */
    public const ACTIVE_TENANTS = [
        'SCH202615' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];

    protected $signature = 'finance:bootstrap-existing-permissions
        {--tenant=* : Exact trusted active school code(s); defaults to all seven active tenants}
        {--execute : Create the defined permission records and role grants; otherwise dry-run only}';

    protected $description = 'Safely bootstrap only missing named Finance permissions for existing active tenants';

    public function handle(ExistingTenantFinancePermissionBootstrap $bootstrap): int
    {
        $schoolCodes = $this->option('tenant') ?: array_keys(self::ACTIVE_TENANTS);
        if (!self::validTenantSelection($schoolCodes)) {
            return $this->fail('Tenant selection must contain unique school codes from the fixed seven-active-tenant allowlist; inactive Demo is refused.');
        }

        $previousDefault = DB::getDefaultConnection();
        $previousSchoolDatabase = config('database.connections.school.database');

        try {
            $schools = School::on('mysql')
                ->whereIn('code', $schoolCodes)
                ->where('status', 1)
                ->get()
                ->keyBy('code');
            if ($schools->count() !== count($schoolCodes)
                || $schools->contains(fn (School $school) => self::ACTIVE_TENANTS[$school->code] !== $school->database_name)) {
                return $this->fail('Trusted central registry does not contain exactly the requested active tenants; refused.');
            }

            // Preflight every selected tenant before the first permission write.
            foreach ($schoolCodes as $schoolCode) {
                $school = $schools->get($schoolCode);
                $tenant = $school->database_name;
                if (!$this->connect($tenant)) {
                    return self::FAILURE;
                }
                if (!School::on('school')->whereKey($school->id)->exists()) {
                    return $this->fail("[$schoolCode] tenant school record is missing; refused.");
                }
                try {
                    $before = $bootstrap->status($school->id);
                } catch (\Throwable $exception) {
                    return $this->fail("[$schoolCode] Finance role preflight failed; refused.");
                }
                $this->line("[$schoolCode] before: " . $this->format($before));
            }

            if (!$this->option('execute')) {
                $this->info('Dry-run only; no permissions or role grants changed.');

                return self::SUCCESS;
            }

            foreach ($schoolCodes as $schoolCode) {
                $school = $schools->get($schoolCode);
                $tenant = $school->database_name;
                if (!$this->connect($tenant)) {
                    return self::FAILURE;
                }
                try {
                    DB::connection('school')->transaction(fn () => $bootstrap->apply($school->id));
                    $after = $bootstrap->status($school->id);
                } catch (\Throwable $exception) {
                    return $this->fail("[$schoolCode] Finance permission bootstrap failed; stopped.");
                }
                $this->info("[$schoolCode] after: " . $this->format($after));
            }

            return self::SUCCESS;
        } finally {
            Config::set('database.connections.school.database', $previousSchoolDatabase);
            DB::purge('school');
            DB::setDefaultConnection($previousDefault);
        }
    }

    public static function validTenantSelection(array $tenants): bool
    {
        return $tenants !== []
            && !array_diff($tenants, array_keys(self::ACTIVE_TENANTS))
            && count($tenants) === count(array_unique($tenants));
    }

    private function connect(string $tenant): bool
    {
        try {
            Config::set('database.connections.school.database', $tenant);
            DB::purge('school');
            DB::connection('school')->getPdo();
            DB::setDefaultConnection('school');

            foreach (['schools', 'roles', 'permissions', 'role_has_permissions'] as $table) {
                if (!Schema::connection('school')->hasTable($table)) {
                    throw new \LogicException("required tenant table missing: $table");
                }
            }

            return true;
        } catch (\Throwable $exception) {
            $this->error("[$tenant] tenant-only connection preflight failed; refused.");

            return false;
        }
    }

    /** @param array<string, array<int, string>> $status */
    private function format(array $status): string
    {
        return collect($status)
            ->map(fn (array $permissions, string $role) => "$role=" . implode('|', $permissions))
            ->implode(', ');
    }

    private function fail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, additive-only runner for Operational UX identity schema. */
final class MigrateOperationalIdentitySchema extends Command
{
    public const CENTRAL_MIGRATION = '2026_09_11_000001_finalize_school_code_identity';
    public const TENANT_MIGRATION = '2026_09_11_000001_create_staff_invitation_tokens_table';
    public const PRODUCTION_TENANTS = [
        'MMBOWEN01' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];

    protected $signature = 'operational-identity:migrate {--execute : Apply only the two exact additive identity migrations}';
    protected $description = 'Preflight or apply canonical School Code history and tenant staff-invitation token schema';

    public function handle(): int
    {
        $original = Config::get('database.connections.school.database');
        try {
            $central = $this->centralState();
            if ($central === 'unexpected') return $this->fail('School Code mapping schema/history is partial; no migration was run.');
            if (!$this->registryMatches($central)) return $this->fail('Trusted active School registry does not match the canonical tenant allowlist.');

            $tenantStates = [];
            foreach (self::PRODUCTION_TENANTS as $code => $database) {
                if (!$this->connect($database)) return $this->fail("[$code] tenant connection failed.");
                $tenantStates[$code] = $this->tenantState();
                if ($tenantStates[$code] === 'unexpected') return $this->fail("[$code] staff invitation schema/history is partial; no migration was run.");
                $this->line("[$code] invitation={$tenantStates[$code]}");
            }
            $this->line("central_school_code_mapping={$central}");
            if (!$this->option('execute')) return self::SUCCESS;

            if ($central === 'eligible' && !$this->migrate('mysql', database_path('migrations/'.self::CENTRAL_MIGRATION.'.php'))) return self::FAILURE;
            if ($this->centralState() !== 'complete') return $this->fail('School Code mapping migration did not verify.');
            foreach (self::PRODUCTION_TENANTS as $code => $database) {
                if ($tenantStates[$code] === 'complete') continue;
                if (!$this->connect($database) || !$this->migrate('school', database_path('migrations/schools/'.self::TENANT_MIGRATION.'.php')) || $this->tenantState() !== 'complete') {
                    return $this->fail("[$code] staff invitation migration did not verify.");
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->fail('Operational identity migration preflight failed: '.get_class($exception));
        } finally {
            Config::set('database.connections.school.database', $original);
            DB::purge('school');
        }
    }

    private function centralState(): string
    {
        if (!$this->hasUniqueColumn('mysql', 'schools', 'code')) return 'unexpected';

        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::CENTRAL_MIGRATION)->exists();
        $history = Schema::connection('mysql')->hasTable('school_code_history');
        $sequences = Schema::connection('mysql')->hasTable('school_code_sequences');
        if (!$recorded && !$history && !$sequences) return 'eligible';
        if (!$recorded || !$history || !$sequences) return 'unexpected';
        if (!Schema::connection('mysql')->hasColumns('school_code_history', ['school_id', 'legacy_code', 'canonical_code', 'change_reason', 'changed_at'])
            || !Schema::connection('mysql')->hasColumns('school_code_sequences', ['prefix', 'next_number'])
            || !$this->hasUniqueIndex('mysql', 'school_code_history', 'school_code_history_legacy_unique')
            || !$this->hasUniqueIndex('mysql', 'school_code_history', 'school_code_history_canonical_unique')
            || !$this->hasForeignKey('mysql', 'school_code_history', 'school_code_history_school_id_foreign')
            || !DB::connection('mysql')->table('school_code_history')->where([
                'legacy_code' => 'SCH202615',
                'canonical_code' => 'MMBOWEN01',
            ])->exists()) {
            return 'unexpected';
        }
        return 'complete';
    }

    private function registryMatches(string $centralState): bool
    {
        $schoolIds = [];
        foreach (self::PRODUCTION_TENANTS as $canonicalCode => $database) {
            $lookupCode = $centralState === 'eligible' && $canonicalCode === 'MMBOWEN01'
                ? 'SCH202615'
                : $canonicalCode;
            $school = School::on('mysql')->whereCanonicalCode($lookupCode)->first();
            if (!$school || $school->trashed() || !$school->database_name
                || !hash_equals($database, (string) $school->database_name)
                || isset($schoolIds[(int) $school->id])) {
                return false;
            }
            $schoolIds[(int) $school->id] = true;
        }
        return true;
    }

    private function tenantState(): string
    {
        $recorded = DB::connection('school')->table('migrations')->where('migration', self::TENANT_MIGRATION)->exists();
        $table = Schema::connection('school')->hasTable('staff_invitation_tokens');
        if (!$recorded && !$table) return 'eligible';
        if (!$recorded || !$table) return 'unexpected';
        return Schema::connection('school')->hasColumns('staff_invitation_tokens', ['email', 'token', 'created_at'])
            && $this->hasUniqueIndex('school', 'staff_invitation_tokens', 'staff_invitation_tokens_email_unique')
            ? 'complete'
            : 'unexpected';
    }

    private function hasUniqueIndex(string $connection, string $table, string $name): bool
    {
        try {
            foreach (Schema::connection($connection)->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name && ($index['unique'] ?? false) === true) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private function hasUniqueColumn(string $connection, string $table, string $column): bool
    {
        try {
            foreach (Schema::connection($connection)->getIndexes($table) as $index) {
                if (($index['unique'] ?? false) === true && array_values($index['columns'] ?? []) === [$column]) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private function hasForeignKey(string $connection, string $table, string $name): bool
    {
        try {
            foreach (Schema::connection($connection)->getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['name'] ?? null) === $name) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private function connect(string $database): bool
    {
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            DB::connection('school')->getPdo();
            return Schema::connection('school')->hasTable('migrations');
        } catch (\Throwable) {
            return false;
        }
    }

    private function migrate(string $connection, string $path): bool
    {
        $exit = Artisan::call('migrate', ['--database' => $connection, '--path' => $path, '--realpath' => true, '--force' => true]);
        $this->output->write(Artisan::output());
        return $exit === self::SUCCESS;
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}

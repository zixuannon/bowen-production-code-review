<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\LegacySchemaIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, additive-only runner for School-scoped Student Code sequences. */
final class MigrateStudentCodeSchema extends Command
{
    public const TENANT_MIGRATION = '2026_09_14_000001_create_student_code_sequences';
    public const PRODUCTION_TENANTS = [
        'eschool_saas_15_zixuan' => ['MMBOWEN01'],
        'eschool_saas_17_bahan' => ['MMBOWEN02'],
        'eschool_saas_19_timecitys' => ['MMBOWEN03'],
        'eschool_saas_20_' => ['SCH202620'],
        'eschool_saas_21_' => ['SCH202621'],
        'eschool_saas_31_zixuanyang' => ['SCH202631'],
        'eschool_saas_32_' => ['SCH202632'],
    ];

    protected $signature = 'student-code:migrate {--execute : Apply only the exact additive Student Code sequence migration}';
    protected $description = 'Preflight or apply School-scoped Student Code sequence and import idempotency schema';

    public function handle(): int
    {
        $original = Config::get('database.connections.school.database');
        try {
            $targets = $this->validatedTargets();
            if ($targets === null) return self::FAILURE;

            $states = [];
            foreach ($targets as $database => $code) {
                if (!$this->connect($database)) return $this->fail("[$code] tenant connection failed.");
                $states[$database] = $this->state();
                $this->line("[$code] student_code_sequence={$states[$database]}");
                if ($states[$database] === 'unexpected') {
                    return $this->fail("[$code] Student Code schema/history mismatch; zero migration was run.");
                }
            }
            if (!$this->option('execute')) return self::SUCCESS;

            // Every target is validated before the first schema write.
            foreach ($targets as $database => $code) {
                if ($states[$database] === 'complete') continue;
                if (!$this->connect($database)
                    || !$this->migrate(database_path('migrations/schools/'.self::TENANT_MIGRATION.'.php'))
                    || $this->state() !== 'complete') {
                    return $this->fail("[$code] Student Code migration did not verify.");
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->fail('Student Code migration preflight failed: '.get_class($exception));
        } finally {
            Config::set('database.connections.school.database', $original);
            DB::purge('school');
        }
    }

    /** @return array<string,string>|null */
    private function validatedTargets(): ?array
    {
        $targets = [];
        foreach (self::PRODUCTION_TENANTS as $database => $allowedCodes) {
            $schools = School::on('mysql')->where('database_name', $database)->whereNull('deleted_at')->get(['id', 'code', 'database_name']);
            if ($schools->count() !== 1 || !in_array((string) $schools->first()->code, $allowedCodes, true)) {
                $this->fail("[$database] registry School/database mapping mismatch.");
                return null;
            }
            $targets[$database] = (string) $schools->first()->code;
        }
        return $targets;
    }

    private function state(): string
    {
        if (!app(LegacySchemaIntegrityService::class)->identityBaseComplete()) return 'unexpected';
        $recorded = DB::connection('school')->table('migrations')->where('migration', self::TENANT_MIGRATION)->exists();
        $table = Schema::connection('school')->hasTable('student_code_sequences');
        $column = Schema::connection('school')->hasColumn('student_import_identities', 'import_reference');
        $complete = $table && $column
            && Schema::connection('school')->hasColumns('student_code_sequences', ['school_id', 'next_number', 'created_at', 'updated_at'])
            && $this->hasUniqueColumns('student_code_sequences', ['school_id'])
            && $this->hasUniqueIndex('student_import_identities', 'student_import_identity_school_reference_unique', ['school_id', 'import_reference']);
        if (!$recorded && !$table && !$column) return 'eligible';
        return $recorded && $complete ? 'complete' : 'unexpected';
    }

    private function connect(string $database): bool
    {
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            return DB::connection('school')->getDatabaseName() === $database
                && Schema::connection('school')->hasTable('migrations');
        } catch (\Throwable) {
            return false;
        }
    }

    private function migrate(string $path): bool
    {
        $exit = Artisan::call('migrate', ['--database' => 'school', '--path' => $path, '--realpath' => true, '--force' => true]);
        $this->output->write(Artisan::output());
        return $exit === self::SUCCESS;
    }

    /** @param list<string> $columns */
    private function hasUniqueColumns(string $table, array $columns): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if ((bool) ($index['unique'] ?? false) && array_values($index['columns'] ?? []) === $columns) return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    /** @param list<string> $columns */
    private function hasUniqueIndex(string $table, string $name, array $columns): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name && (bool) ($index['unique'] ?? false)
                    && array_values($index['columns'] ?? []) === $columns) return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}

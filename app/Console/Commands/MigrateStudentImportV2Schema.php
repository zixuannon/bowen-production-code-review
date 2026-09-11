<?php

namespace App\Console\Commands;

use App\Services\LegacySchemaIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\SchoolCodeService;

/** Targeted, additive-only schema runner for the approved Zixuan V2 pilot. */
final class MigrateStudentImportV2Schema extends Command
{
    public const SCHOOL_CODE = 'MMBOWEN01';
    public const TENANT_MIGRATION = '2026_09_03_000001_create_student_import_identities_table';
    public const TENANT_V21_MIGRATION = '2026_09_03_000002_make_student_import_v21_identity_fields_nullable';
    public const CENTRAL_MIGRATION = '2026_09_03_000002_add_student_code_to_central_finance_student_profiles';

    protected $signature = 'student-import-v2:migrate {--execute : Apply only the exact additive Zixuan V2 migrations}';
    protected $description = 'Verify or apply the fixed Zixuan Student Import V2 central and tenant schema';

    public function handle(): int
    {
        $original = config('database.connections.school.database');
        try {
            $school = app(SchoolCodeService::class)->resolveCanonical(self::SCHOOL_CODE);
            if ($school === null || $school->trashed() || !$school->database_name) return $this->fail('Trusted Zixuan School registry row is unavailable or ambiguous.');
            if (!$this->connect((string) $school->database_name) || !$this->tenantBase()) return self::FAILURE;

            $central = $this->centralState(); $tenant = $this->tenantState();
            $this->line('['.self::SCHOOL_CODE."] central={$central}; tenant={$tenant}");
            if (in_array('unexpected', [$central, $tenant], true)) return $this->fail('Student Import V2 schema/history is partial or inconsistent; no migration was run.');
            if (!$this->option('execute')) return self::SUCCESS;
            if ($central === 'eligible' && !$this->migrate('mysql', database_path('migrations/'.self::CENTRAL_MIGRATION.'.php'))) return self::FAILURE;
            if ($this->centralState() !== 'complete') return $this->fail('Central Student Import V2 migration did not verify.');
            if (!$this->tenantMigration(self::TENANT_MIGRATION) && !$this->migrate('school', database_path('migrations/schools/'.self::TENANT_MIGRATION.'.php'))) return self::FAILURE;
            if (!$this->tenantMigration(self::TENANT_V21_MIGRATION) && !$this->migrate('school', database_path('migrations/schools/'.self::TENANT_V21_MIGRATION.'.php'))) return self::FAILURE;
            return $this->tenantState() === 'complete' ? self::SUCCESS : $this->fail('Zixuan Student Import V2 tenant migration did not verify.');
        } catch (\Throwable $exception) {
            return $this->fail('Student Import V2 migration preflight failed: '.get_class($exception));
        } finally {
            Config::set('database.connections.school.database', $original); DB::purge('school');
        }
    }

    private function connect(string $database): bool
    {
        try { Config::set('database.connections.school.database', $database); DB::purge('school'); return DB::connection('school')->getDatabaseName() === $database; }
        catch (\Throwable) { return false; }
    }
    private function tenantBase(): bool
    {
        foreach (['migrations', 'students', 'users'] as $table) if (!Schema::connection('school')->hasTable($table)) return false;
        return true;
    }
    private function centralState(): string
    {
        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::CENTRAL_MIGRATION)->exists();
        $column = Schema::connection('mysql')->hasColumn('central_finance_student_profiles', 'student_code');
        return !$recorded && !$column ? 'eligible' : ($recorded && $column ? 'complete' : 'unexpected');
    }
    private function tenantState(): string
    {
        $recorded = $this->tenantMigration(self::TENANT_MIGRATION);
        $v21Recorded = $this->tenantMigration(self::TENANT_V21_MIGRATION);
        $table = Schema::connection('school')->hasTable('student_import_identities');
        $identityComplete = app(LegacySchemaIntegrityService::class)->identityBaseComplete();
        $notes = Schema::connection('school')->hasColumn('students', 'notes');
        if ($recorded && $identityComplete && $v21Recorded && $this->v21Fields()) return 'complete';
        if ((!$recorded && !$table && !$v21Recorded && !$notes) || ($recorded && $identityComplete && !$v21Recorded && !$notes)) return 'eligible';
        return 'unexpected';
    }
    private function tenantMigration(string $migration): bool { return DB::connection('school')->table('migrations')->where('migration', $migration)->exists(); }
    private function v21Fields(): bool
    {
        try {
            $columns = collect(Schema::connection('school')->getColumns('users'))->keyBy('name');
            return Schema::connection('school')->hasColumn('students', 'notes')
                && (bool) ($columns->get('email')['nullable'] ?? false)
                && (bool) ($columns->get('last_name')['nullable'] ?? false);
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
    private function fail(string $message): int { $this->error($message); return self::FAILURE; }
}

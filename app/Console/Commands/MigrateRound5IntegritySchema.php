<?php

namespace App\Console\Commands;

use App\Services\LegacySchemaIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact-path, additive-only Round 5 runner for approved Production tenants. */
final class MigrateRound5IntegritySchema extends Command
{
    public const TENANTS = [
        'MMBOWEN01' => 'eschool_saas_15_zixuan',
        'SCH202616' => 'eschool_saas_17_bahan',
        'SCH202619' => 'eschool_saas_19_timecitys',
        'SCH202620' => 'eschool_saas_20_',
        'SCH202621' => 'eschool_saas_21_',
        'SCH202631' => 'eschool_saas_31_zixuanyang',
        'SCH202632' => 'eschool_saas_32_',
    ];
    public const MIGRATIONS = [
        '2026_09_03_000001_create_student_import_identities_table',
        LegacySchemaIntegrityService::ROUND5_MIGRATION,
    ];

    protected $signature = 'schema:round5-integrity
        {--tenant=* : Exact approved School code(s); defaults to all seven}
        {--execute : Apply only the two fixed tenant migration files}';
    protected $description = 'Preflight, verify, or apply only the Round 5 identity/bank constraints';

    public function handle(): int
    {
        $codes = $this->option('tenant') ?: array_keys(self::TENANTS);
        if (!self::validTenantSelection($codes)) return $this->fail('Tenant selection must contain unique School codes from the fixed allowlist only.');
        $original = config('database.connections.school.database');

        try {
            // Pass one is read-only across every selected tenant. No tenant is
            // altered if any Production-shaped orphan, duplicate, or partial
            // schema exists anywhere in the approved batch.
            foreach ($codes as $code) {
                if (!$this->connectAndVerifyRegistry($code)) return self::FAILURE;
                $state = $this->state();
                if ($state === 'partial') return $this->fail("[$code] partial or inconsistent schema/history; no migration was run.");
                $issues = app(LegacySchemaIntegrityService::class)->preflightIssues();
                if ($issues !== []) return $this->fail("[$code] orphan/duplicate preflight failed: ".json_encode($issues, JSON_THROW_ON_ERROR));
                $this->line("[$code] Round 5 state: $state; preflight=clean");
            }

            if (!$this->option('execute')) return self::SUCCESS;

            // Canary is fixed first by allowlist order; each file is exact and
            // realpath-enforced by ProductionMigrationGuard.
            foreach ($codes as $code) {
                if (!$this->connectAndVerifyRegistry($code)) return self::FAILURE;
                if ($this->state() === 'none' && !$this->migrate(self::MIGRATIONS[0])) return self::FAILURE;
                if ($this->state() === 'identity-ready' && !$this->migrate(self::MIGRATIONS[1])) return self::FAILURE;
                if ($this->state() !== 'complete') return $this->fail("[$code] exact schema/history verification failed; stop before the next tenant.");
                $this->info("[$code] Round 5 schema verified.");
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->fail('Round 5 schema runner failed closed: '.get_class($exception).': '.$exception->getMessage());
        } finally {
            Config::set('database.connections.school.database', $original);
            DB::purge('school');
        }
    }

    public static function validTenantSelection(array $codes): bool
    {
        return $codes !== [] && !array_diff($codes, array_keys(self::TENANTS)) && count($codes) === count(array_unique($codes));
    }

    public static function paths(): array
    {
        return array_map(fn (string $migration): string => database_path("migrations/schools/$migration.php"), self::MIGRATIONS);
    }

    private function connectAndVerifyRegistry(string $code): bool
    {
        $expected = self::TENANTS[$code] ?? null;
        $row = DB::connection('mysql')->table('schools')->where('code', $code)->whereNull('deleted_at')->first(['id','database_name']);
        if ($expected === null || $row === null || $row->database_name !== $expected) return $this->reject("[$code] central registry School/database mismatch or missing target.");
        Config::set('database.connections.school.database', $expected);
        DB::purge('school');
        try {
            if (DB::connection('school')->getDatabaseName() !== $expected) return $this->reject("[$code] tenant connection identity mismatch.");
            foreach (['migrations','students','users','bank_accounts','bank_transfers'] as $table) {
                if (!Schema::connection('school')->hasTable($table)) return $this->reject("[$code] required base table missing: $table");
            }
            $schoolId = (int) $row->id;
            foreach (['students','bank_accounts','bank_transfers'] as $table) {
                if (DB::connection('school')->table($table)->where('school_id', '<>', $schoolId)->orWhereNull('school_id')->exists()) {
                    return $this->reject("[$code] $table contains a row outside trusted School ID $schoolId.");
                }
            }
            if (Schema::connection('school')->hasTable('student_import_identities')
                && DB::connection('school')->table('student_import_identities')->where('school_id', '<>', $schoolId)->exists()) {
                return $this->reject("[$code] student_import_identities contains a cross-School row.");
            }
            $integrity = app(LegacySchemaIntegrityService::class);
            if (!$integrity->bankBaseComplete() || !$integrity->transferBaseComplete()
                || !Schema::connection('school')->hasColumns('bank_accounts', ['created_by','updated_by'])) {
                return $this->reject("[$code] base schema is incomplete.");
            }
            return true;
        } catch (\Throwable) {
            return $this->reject("[$code] tenant connection/schema verification failed.");
        }
    }

    private function state(): string
    {
        $integrity = app(LegacySchemaIntegrityService::class);
        $identityRecorded = $this->recorded(self::MIGRATIONS[0]);
        $round5Recorded = $this->recorded(self::MIGRATIONS[1]);
        $identity = $integrity->identityBaseComplete();
        $round5Present = $integrity->round5SchemaPresent();
        $round5 = $integrity->round5SchemaComplete();

        if (!$identityRecorded && !$round5Recorded && !$identity && !$round5Present) return 'none';
        if ($identityRecorded && !$round5Recorded && $identity && !$round5Present) return 'identity-ready';
        if ($identityRecorded && $round5Recorded && $identity && $round5) return 'complete';
        return 'partial';
    }

    private function recorded(string $migration): bool
    {
        return DB::connection('school')->table('migrations')->where('migration', $migration)->exists();
    }

    private function migrate(string $migration): bool
    {
        $exit = Artisan::call('migrate', [
            '--database' => 'school', '--path' => database_path("migrations/schools/$migration.php"),
            '--realpath' => true, '--force' => true,
        ]);
        $this->output->write(Artisan::output());
        return $exit === self::SUCCESS;
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }

    private function reject(string $message): bool
    {
        $this->error($message);
        return false;
    }
}

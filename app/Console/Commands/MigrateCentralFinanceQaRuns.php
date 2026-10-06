<?php

namespace App\Console\Commands;

use App\Services\ProductionMigrationGuard;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Exact-path central migration gate for permanent Zixuan QA Run tables. */
final class MigrateCentralFinanceQaRuns extends Command
{
    use ConfirmableTrait;

    public const MIGRATION = '2026_10_05_000001_create_central_finance_qa_runs';
    private const PRODUCTION_DATABASE = 'sql_43_160_241_126';
    private const APPROVED_PRODUCTION_BASELINE = '7d6e73c12f6de23c24e5dd62312df53fcef8d497';
    private const EXPECTED_ACTIVE_PRODUCTION_SHA = 'b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2';
    private const ACTIVE_PRODUCTION_ROOT = '/www/wwwroot/43.160.241.126';
    private const PRODUCTION_RELEASES_ROOT = '/www/wwwroot/releases';

    protected $signature = 'finance:qa-runs-migrate {--execute : Apply only the exact Central QA Run migration} {--deployment-verified : Internal: invoked by the trusted deployment wrapper}';
    protected $description = 'Verify or apply the Central Finance Zixuan QA Run schema';

    public function handle(): int
    {
        try {
            $production = app()->environment('production');
            if ($production && $this->option('execute')) {
                if (!$this->option('deployment-verified')) {
                    $confirmed = $this->confirmToProceed('Apply the exact Central QA Run migration to Production?');
                    if (!$confirmed) return self::FAILURE;
                    throw new RuntimeException('Production QA Run migrations must use the trusted deployment wrapper.');
                }
            }
            if ($production) {
                if (!$this->option('deployment-verified')) {
                    throw new RuntimeException('Production QA Run preflight must use the trusted deployment wrapper.');
                }
                $this->assertProductionReleaseIdentity();
                if (!$this->hasTrustedDeploymentInvocation(trim((string) getenv('QA_RUN_VERIFIED_RELEASE_SHA')))) {
                    throw new RuntimeException('Trusted deployment release verification is missing or invalid.');
                }
            }

            $db = DB::connection('mysql');
            $database = $db->getDatabaseName();
            if ($production) {
                $this->assertProductionDatabaseTarget($db->getDriverName(), $database);
            } else {
                if (!app()->environment(['local', 'testing'])) {
                    throw new RuntimeException('QA Run migration is limited to local, testing, or the guarded Production Central target.');
                }
                $url = strtolower((string) config('app.url'));
                if ($url !== '' && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1')) {
                    throw new RuntimeException('Non-production QA Run migration is limited to a local application URL.');
                }
                if ($database === '' || preg_match('/prod|production|staging/i', $database)) {
                    throw new RuntimeException('The configured Central database is not an eligible local QA target.');
                }
                $host = strtolower((string) config('database.connections.mysql.host', ''));
                if ($db->getDriverName() === 'mysql' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    throw new RuntimeException('The Central database host must be local for QA Run migration rehearsal.');
                }
            }

            $migrationPath = database_path('migrations/'.self::MIGRATION.'.php');
            if (!is_file($migrationPath) || is_link($migrationPath)) {
                throw new RuntimeException('The exact Central QA Run migration file is missing or indirect.');
            }
            if ($production) {
                app(ProductionMigrationGuard::class)->assertAllowed(
                    'migrate', 'finance:qa-runs-migrate', [$migrationPath], true, true,
                );
            }
            foreach (['migrations', 'schools', 'users'] as $table) {
                if (!Schema::connection('mysql')->hasTable($table)) {
                    throw new RuntimeException('Required Central schema missing: '.$table);
                }
            }

            $state = $this->schemaState();
            $this->line("central={$state}; database={$database}");
            if ($state === 'unexpected') {
                throw new RuntimeException('QA Run schema/history is partial or inconsistent; no migration was run.');
            }
            if (!$this->option('execute') || $state === 'complete') return self::SUCCESS;

            if ($production) {
                $this->assertProductionReleaseIdentity();
                if (!$this->hasTrustedDeploymentInvocation(trim((string) getenv('QA_RUN_VERIFIED_RELEASE_SHA')))) {
                    throw new RuntimeException('Trusted deployment release verification changed before migration execution.');
                }
                $this->assertProductionDatabaseTarget($db->getDriverName(), $database);
            }

            $exit = Artisan::call('migrate', [
                '--database' => 'mysql', '--path' => $migrationPath,
                '--realpath' => true, '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            return $exit === self::SUCCESS && $this->schemaState() === 'complete'
                ? self::SUCCESS
                : $this->fail('The exact Central QA Run migration did not verify after execution.');
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    private function assertProductionDatabaseTarget(string $driver, string $database): void
    {
        if ($driver !== 'mysql' || $database !== self::PRODUCTION_DATABASE) {
            throw new RuntimeException('Production Central database target does not match the approved identity.');
        }
    }

    private function assertProductionReleaseIdentity(): void
    {
        $candidatePath = realpath(base_path());
        $activePath = realpath(self::ACTIVE_PRODUCTION_ROOT);
        $releasesRoot = realpath(self::PRODUCTION_RELEASES_ROOT);
        if ($candidatePath === false || $activePath === false || $releasesRoot === false
            || $candidatePath === $activePath
            || !str_starts_with($candidatePath, $releasesRoot.DIRECTORY_SEPARATOR)
            || !\App\Console\Commands\MigrateCentralFinanceStaffUuid::isAllowedProductionExecutionPath($candidatePath)) {
            throw new RuntimeException('Production migration must run from a staged immutable release while the approved baseline remains active.');
        }

        $releasePermissions = fileperms($candidatePath);
        if (fileowner($candidatePath) !== 0 || $releasePermissions === false || ($releasePermissions & 0022) !== 0) {
            throw new RuntimeException('The staged immutable release must remain deployment-owned and not group/world writable.');
        }

        $active = $this->releaseIdentity($activePath);
        if (!self::hasExpectedActiveProductionIdentity($active['manifest'], $active['marker'])) {
            throw new RuntimeException('The active Production release is not the exact approved pre-migration baseline.');
        }

        $candidate = $this->releaseIdentity($candidatePath);
        if (!self::hasApprovedCandidateIdentity($candidate['manifest'], $candidate['marker'])) {
            throw new RuntimeException('The staged immutable release does not match the approved QA Run candidate lineage.');
        }
        $contractPath = $candidatePath.'/config/production-baseline.json';
        $contract = is_file($contractPath) && !is_link($contractPath)
            ? json_decode((string) file_get_contents($contractPath), true)
            : null;
        if (!self::hasApprovedProductionBaseline($contract)) {
            throw new RuntimeException('Production baseline contract does not match the approved exact SHA.');
        }

        if (trim((string) getenv('QA_RUN_VERIFIED_RELEASE_SHA')) !== $candidate['marker']) {
            throw new RuntimeException('Deployment-side Git verification does not attest this exact release SHA.');
        }

    }

    /** @return array{marker:string,manifest:?array<string,mixed>} */
    private function releaseIdentity(string $releasePath): array
    {
        $markerPath = $releasePath.'/.release-commit';
        $manifestPath = $releasePath.'/.release-manifest.json';
        if (!is_file($markerPath) || is_link($markerPath) || !is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Immutable release identity metadata is missing or indirect.');
        }

        foreach ([$markerPath, $manifestPath] as $metadataPath) {
            $permissions = fileperms($metadataPath);
            if (fileowner($metadataPath) !== 0 || $permissions === false || ($permissions & 0022) !== 0 || !is_readable($metadataPath)) {
                throw new RuntimeException('Immutable release metadata must remain deployment-owned, readable, and not runtime-writable.');
            }
        }

        $marker = trim((string) file_get_contents($markerPath));
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (preg_match('/\A[0-9a-f]{40}\z/', $marker) !== 1 || !is_array($manifest)
            || ($manifest['commit_sha'] ?? null) !== $marker) {
            throw new RuntimeException('Immutable release SHA/manifest identity is inconsistent.');
        }

        return ['marker' => $marker, 'manifest' => $manifest];
    }

    private function hasTrustedDeploymentInvocation(string $candidateSha): bool
    {
        $runtime = function_exists('posix_getpwnam') ? posix_getpwnam('www') : false;
        $parentPid = function_exists('posix_getppid') ? posix_getppid() : 0;
        $status = $parentPid > 0 ? @file_get_contents('/proc/'.$parentPid.'/status') : false;
        if (!is_array($runtime) || !is_string($status)) return false;
        if (preg_match('/^Uid:\\s+(\\d+)\\s+(\\d+)/m', $status, $matches) !== 1) return false;

        return self::hasTrustedDeploymentProcess(
            $candidateSha,
            trim((string) getenv('QA_RUN_VERIFIED_RELEASE_SHA')),
            function_exists('posix_geteuid') ? posix_geteuid() : -1,
            (int) $runtime['uid'],
            (int) $matches[2],
        );
    }

    public static function hasTrustedDeploymentProcess(
        string $candidateSha,
        string $attestedSha,
        int $runtimeUid,
        int $expectedRuntimeUid,
        int $parentEffectiveUid,
    ): bool {
        return preg_match('/\\A[0-9a-f]{40}\\z/', $candidateSha) === 1
            && hash_equals($candidateSha, $attestedSha)
            && $runtimeUid === $expectedRuntimeUid
            && $parentEffectiveUid === 0;
    }

    /** @param array<string, mixed>|null $contract */
    public static function hasApprovedProductionBaseline(?array $contract): bool
    {
        return ($contract['accepted_production_sha'] ?? null) === self::APPROVED_PRODUCTION_BASELINE;
    }

    /** @param array<string, mixed>|null $manifest */
    public static function hasExpectedActiveProductionIdentity(?array $manifest, string $marker): bool
    {
        return $marker === self::EXPECTED_ACTIVE_PRODUCTION_SHA
            && is_array($manifest)
            && ($manifest['commit_sha'] ?? null) === self::EXPECTED_ACTIVE_PRODUCTION_SHA
            && ($manifest['baseline_sha'] ?? null) === self::APPROVED_PRODUCTION_BASELINE;
    }

    /** @param array<string, mixed>|null $manifest */
    public static function hasApprovedCandidateIdentity(?array $manifest, string $marker): bool
    {
        return preg_match('/\\A[0-9a-f]{40}\\z/', $marker) === 1
            && $marker !== self::EXPECTED_ACTIVE_PRODUCTION_SHA
            && is_array($manifest)
            && ($manifest['commit_sha'] ?? null) === $marker
            && ($manifest['baseline_sha'] ?? null) === self::APPROVED_PRODUCTION_BASELINE;
    }

    private function schemaState(): string
    {
        $runs = Schema::connection('mysql')->hasTable('central_finance_qa_runs');
        $records = Schema::connection('mysql')->hasTable('central_finance_qa_run_records');
        $recorded = DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->count();
        if (!$runs && !$records && $recorded === 0) return 'eligible';
        if ($runs && $records && $recorded === 1 && $this->schemaComplete()) return 'complete';
        return 'unexpected';
    }

    private function schemaComplete(): bool
    {
        $schema = Schema::connection('mysql');
        return $schema->hasColumns('central_finance_qa_runs', [
            'id', 'run_uuid', 'school_id', 'run_number', 'label', 'status', 'created_by', 'completed_by',
            'completion_summary', 'archived_by', 'archive_reason', 'activated_at', 'completed_at', 'archived_at', 'created_at', 'updated_at',
        ]) && $schema->hasColumns('central_finance_qa_run_records', [
            'id', 'qa_run_id', 'school_id', 'subject_scope', 'subject_type', 'subject_id', 'source_identity', 'created_at',
        ]) && $this->hasIndex('central_finance_qa_runs', 'cf_qa_runs_uuid_uq', true)
            && $this->hasIndex('central_finance_qa_runs', 'cf_qa_runs_school_number_uq', true)
            && $this->hasIndex('central_finance_qa_runs', 'cf_qa_runs_school_status_ix', false)
            && $this->hasIndex('central_finance_qa_run_records', 'cf_qa_run_records_subject_uq', true)
            && $this->hasIndex('central_finance_qa_run_records', 'cf_qa_run_records_source_uq', true)
            && $this->hasIndex('central_finance_qa_run_records', 'cf_qa_run_records_run_subject_ix', false)
            && $this->hasIndex('central_finance_qa_run_records', 'cf_qa_run_records_school_subject_ix', false)
            && $this->hasForeignKey('central_finance_qa_runs', ['school_id'], 'schools')
            && $this->hasForeignKey('central_finance_qa_runs', ['created_by'], 'users')
            && $this->hasForeignKey('central_finance_qa_runs', ['completed_by'], 'users')
            && $this->hasForeignKey('central_finance_qa_runs', ['archived_by'], 'users')
            && $this->hasForeignKey('central_finance_qa_run_records', ['qa_run_id'], 'central_finance_qa_runs')
            && $this->hasForeignKey('central_finance_qa_run_records', ['school_id'], 'schools');
    }

    private function hasIndex(string $table, string $name, bool $unique): bool
    {
        try {
            foreach (Schema::connection('mysql')->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name && ($index['unique'] ?? false) === $unique) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /** @param list<string> $columns */
    private function hasForeignKey(string $table, array $columns, string $foreignTable): bool
    {
        try {
            foreach (Schema::connection('mysql')->getForeignKeys($table) as $key) {
                if (array_values($key['columns'] ?? []) === $columns
                    && ($key['foreign_table'] ?? null) === $foreignTable
                    && array_values($key['foreign_columns'] ?? []) === ['id']) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}

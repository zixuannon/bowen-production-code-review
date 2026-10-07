<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Deployment evidence is created by root; Laravel always boots as www. */
class UnidentifiedDepositP0ReleaseGate
{
    public const BASELINE = '135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e';
    public const ACTIVE = '/www/wwwroot/43.160.241.126';
    public const EVIDENCE = '/run/eschool-p0-gate/evidence.json';

    public function assertMayCloseWrites(): void
    {
        $migration = app(UnidentifiedDepositP0Migration::class);
        $migration->assertTarget();
        if (!in_array($migration->inspect()['state'], ['eligible', 'complete'], true)) {
            throw new RuntimeException('P0 is not eligible/complete; writer pause is not authorized.');
        }
        $this->assertEmptyQueue();
        if (app()->environment('production')) $this->assertDeployment('close');
    }

    public function assertReadyForMigration(bool $complete = false): void
    {
        $migration = app(UnidentifiedDepositP0Migration::class);
        $migration->assertTarget();
        $migration->migrationPath();
        if ($migration->inspect()['state'] !== ($complete ? 'complete' : 'eligible')) {
            throw new RuntimeException('P0 schema state differs from the exact runner contract.');
        }
        $gate = app(UnidentifiedDepositP0WriteGate::class);
        $gate->assertClosed();
        $gate->assertLockHeld();
        $this->assertEmptyQueue();
        if (app()->environment('production')) $this->assertDeployment('migrate');
    }

    public function assertReadyToReopen(): void
    {
        app(UnidentifiedDepositP0WriteGate::class)->assertLockHeld();
        if (app(UnidentifiedDepositP0Migration::class)->inspect()['state'] !== 'complete') {
            throw new RuntimeException('P0 cannot reopen writes against incomplete schema.');
        }
        app(UnidentifiedDepositP0Migration::class)->assertMigrationPreserved();
        $this->assertEmptyQueue();
        // No --force, env flag or local shortcut can reopen a deployment fence.
        // Disposable rehearsals inject their own proof provider, never alter
        // the production contract or immutable migration.
        $this->assertDeployment('open');
    }

    /** Counts only; never deserialize or print queued/failed payloads. */
    public function assertEmptyQueue(): array
    {
        if (!in_array(config('queue.default'), ['sync', 'database'], true)
            || config('queue.connections.database.driver') !== 'database'
            || config('queue.connections.database.table') !== 'jobs'
            || !in_array(config('queue.connections.database.connection'), [null, 'mysql'], true)
            || config('database.default') !== 'mysql'
            || !Schema::connection('mysql')->hasTable('jobs')
            || !Schema::connection('mysql')->hasTable('failed_jobs')) {
            throw new RuntimeException('P0 queue backend/inventory is not the audited Central database contract.');
        }
        $pending = DB::connection('mysql')->table('jobs')->count();
        $failed = DB::connection('mysql')->table('failed_jobs')->count();
        if ($pending !== 0) throw new RuntimeException('P0 requires zero queued/reserved jobs; no job was replayed or deleted.');
        return ['pending' => $pending, 'failed' => $failed];
    }

    protected function assertDeployment(string $phase): array
    {
        if (!app()->environment('production') || PHP_SAPI !== 'cli') {
            throw new RuntimeException('P0 deployment proof is available only to the trusted Production CLI.');
        }
        $www = posix_getpwnam('www');
        if (!$www || posix_geteuid() !== $www['uid']) throw new RuntimeException('P0 Laravel must run as www.');
        foreach ([dirname(self::EVIDENCE), self::EVIDENCE] as $path) {
            clearstatcache(true, $path);
            $s = lstat($path);
            if ($s === false || is_link($path) || $s['uid'] !== 0 || ($s['mode'] & 0022) !== 0
                || is_writable($path)) throw new RuntimeException('P0 evidence is not deployment-owned and immutable to runtime.');
        }
        $evidence = json_decode(file_get_contents(self::EVIDENCE), true, 512, JSON_THROW_ON_ERROR);
        $this->assertLiveIssuer($evidence['issuer'] ?? []);
        $active = realpath(self::ACTIVE);
        $candidate = realpath(base_path());
        $marker = trim((string) file_get_contents(base_path('.release-commit')));
        $manifest = json_decode(file_get_contents(base_path('.release-manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $activeMarker = trim((string) file_get_contents($active.'/.release-commit'));
        $activeManifest = json_decode(file_get_contents($active.'/.release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::validateEvidence($evidence, $phase, $candidate, $active, $marker, $activeMarker, $manifest, $activeManifest, time());
        // Match the existing R2E installed, non-deleted registry discovery,
        // including provisioned tenants regardless of account status. A school
        // created after backup makes that recovery set insufficient.
        $databases = DB::connection('mysql')->table('schools')->where('installed', 1)->whereNull('deleted_at')
            ->whereNotNull('database_name')->where('database_name', '!=', '')->pluck('database_name')->all();
        $databases[] = DB::connection('mysql')->getDatabaseName();
        $databases = array_values(array_unique($databases));
        sort($databases);
        $backedUp = $evidence['backup']['database_names'] ?? [];
        sort($backedUp);
        if ($databases !== $backedUp) throw new RuntimeException('P0 encrypted recovery set does not cover the current trusted tenant registry.');
        if ($phase === 'open') {
            $master = array_values(array_filter($evidence['runtime']['processes'], fn ($p) => $p['role'] === 'fpm_master'))[0]['pid'];
            foreach ($evidence['runtime']['processes'] as $process) {
                $root = '/proc/'.$process['pid'];
                $stat = @file_get_contents($root.'/stat');
                $fpm = in_array($process['role'], ['fpm_master', 'fpm_worker'], true);
                self::validateLiveProcess($process, $stat, $fpm ? false : realpath($root.'/exe'),
                    $fpm ? false : realpath($root.'/cwd'), $master);
                if (in_array($process['role'], ['queue', 'websocket'], true)) {
                    $command = @file_get_contents($root.'/cmdline');
                    if (!is_string($command) || !hash_equals($process['command_sha256'], hash('sha256', rtrim($command, "\0")))) {
                        throw new RuntimeException('P0 worker command changed after the deployment probe.');
                    }
                }
            }
        }
        return $evidence;
    }

    /** Root attests FPM paths: Linux non-dumpable workers hide them even from www. */
    public static function validateLiveProcess(array $process, string|false $stat, string|false $exe, string|false $cwd, int $master): void
    {
        $fields = is_string($stat) && strrpos($stat, ')') !== false
            ? preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 2))) : [];
        $fpm = in_array($process['role'], ['fpm_master', 'fpm_worker'], true);
        if (!in_array($process['role'], ['fpm_master', 'fpm_worker', 'queue', 'websocket'], true)
            || ($fields[19] ?? null) !== (string) $process['start_ticks']
            || in_array($fields[0] ?? null, [null, 'Z', 'X', 'x'], true)
            || ($process['role'] === 'fpm_master' && $process['pid'] !== $master)
            || ($process['role'] === 'fpm_worker' && (int) ($fields[1] ?? 0) !== $master)
            || (!$fpm && ($exe !== $process['exe'] || $cwd !== $process['cwd']))) {
            throw new RuntimeException('P0 runtime changed after the deployment probe; write fence retained.');
        }
    }

    private function assertLiveIssuer(array $issuer): void
    {
        $target = $issuer['pid'] ?? null;
        if (!is_int($target) || $target < 2 || !ctype_digit((string) ($issuer['start_ticks'] ?? ''))) {
            throw new RuntimeException('P0 evidence lacks a live deployment issuer.');
        }
        $pid = posix_getppid();
        for ($depth = 0; $depth < 16 && $pid > 1; $depth++) {
            $status = @file_get_contents('/proc/'.$pid.'/status');
            if (!is_string($status)) break;
            if ($pid === $target) {
                $stat = @file_get_contents('/proc/'.$pid.'/stat');
                $fields = is_string($stat) ? preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2)) : [];
                if (preg_match('/^Uid:\s+0\s+0\s+0\s+0\s*$/m', $status)
                    && ($fields[19] ?? null) === (string) $issuer['start_ticks']) return;
                break;
            }
            if (!preg_match('/^PPid:\s+(\d+)/m', $status, $matches)) break;
            $pid = (int) $matches[1];
        }
        throw new RuntimeException('P0 root deployment issuer is not the live ancestor; scheduler lock authority expired.');
    }

    /** Pure contract seam, exercised with negative release/runtime fixtures. */
    public static function validateEvidence(array $e, string $phase, string $candidate, string $active, string $sha, string $activeSha, array $manifest, array $activeManifest, int $now): void
    {
        if (($e['version'] ?? null) !== 1 || ($e['phase'] ?? null) !== $phase
            || !is_int($e['generated_at'] ?? null) || $e['generated_at'] > $now || $now - $e['generated_at'] > 60
            || !preg_match('/^[a-f0-9]{40}$/D', $sha) || $sha === self::BASELINE
            || ($e['candidate_sha'] ?? null) !== $sha || ($e['candidate_path'] ?? null) !== $candidate
            || ($e['active_sha'] ?? null) !== $activeSha || ($e['active_path'] ?? null) !== $active
            || ($e['baseline_sha'] ?? null) !== self::BASELINE
            || ($e['migration_sha256'] ?? null) !== UnidentifiedDepositP0Migration::HASH
            || ($manifest['commit_sha'] ?? null) !== $sha || ($manifest['release_name'] ?? null) !== basename($candidate)
            || ($activeManifest['commit_sha'] ?? null) !== $activeSha
            || !str_starts_with($candidate, '/www/wwwroot/releases/') || !str_starts_with($active, '/www/wwwroot/releases/')) {
            throw new RuntimeException('P0 exact candidate/baseline/manifest/evidence mismatch.');
        }
        if (($phase === 'open' && ($active !== $candidate || $activeSha !== $sha))
            || ($phase !== 'open' && $activeSha !== self::BASELINE)) {
            throw new RuntimeException('P0 active release is not the approved phase baseline.');
        }
        $backup = $e['backup'] ?? [];
        if (!is_string($backup['recovery_set'] ?? null) || $backup['recovery_set'] === ''
            || !preg_match('/^[a-f0-9]{64}$/D', $backup['sha256'] ?? '')
            || !is_int($backup['completed_at'] ?? null) || $backup['completed_at'] > $now || $now - $backup['completed_at'] > 86400
            || !is_int($backup['remote_verified_objects'] ?? null) || $backup['remote_verified_objects'] < 1) {
            throw new RuntimeException('P0 verified fresh encrypted recovery evidence is missing.');
        }
        if ($phase !== 'open') return;
        $probe = $e['runtime']['fpm_probe'] ?? [];
        if (($probe['release'] ?? null) !== $candidate || ($probe['sha'] ?? null) !== $sha
            || ($probe['sapi'] ?? null) !== 'fpm-fcgi' || !str_starts_with($probe['php_version'] ?? '', '8.3.')) {
            throw new RuntimeException('P0 PHP-FPM did not serve the exact active candidate.');
        }
        $roles = [];
        foreach ($e['runtime']['processes'] ?? [] as $p) {
            $role = $p['role'] ?? '';
            if (!in_array($role, ['fpm_master', 'fpm_worker', 'queue', 'websocket'], true)
                || !is_int($p['pid'] ?? null) || $p['pid'] < 1 || !ctype_digit((string) ($p['start_ticks'] ?? ''))
                || !is_string($p['exe'] ?? null) || !is_string($p['cwd'] ?? null)
                || !str_starts_with($p['php_version'] ?? '', '8.3.')
                || (in_array($role, ['queue', 'websocket'], true) && (($p['release_path'] ?? null) !== $candidate
                    || !preg_match('/^[a-f0-9]{64}$/D', $p['command_sha256'] ?? '')))) {
                throw new RuntimeException('P0 runtime process evidence is incomplete or stale.');
            }
            $roles[$role] = ($roles[$role] ?? 0) + 1;
        }
        if (($roles['fpm_master'] ?? 0) !== 1 || ($roles['fpm_worker'] ?? 0) < 1
            || ($roles['queue'] ?? 0) !== 1 || ($roles['websocket'] ?? 0) !== 1) {
            throw new RuntimeException('P0 requires the exact audited worker topology.');
        }
    }
}

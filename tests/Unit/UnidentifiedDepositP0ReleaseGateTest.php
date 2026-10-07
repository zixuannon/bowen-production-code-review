<?php

namespace Tests\Unit;

use App\Services\ProductionMigrationGuard;
use App\Services\UnidentifiedDepositP0Migration;
use App\Services\UnidentifiedDepositP0ReleaseGate;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UnidentifiedDepositP0ReleaseGateTest extends TestCase
{
    public function test_nondumpable_fpm_uses_root_attested_paths_but_checks_live_birth_and_parent(): void
    {
        $fields = array_fill(0, 20, '0');
        $fields[0] = 'S'; $fields[1] = '100'; $fields[19] = '123';
        $stat = '101 (php-fpm) '.implode(' ', $fields);
        $worker = ['role' => 'fpm_worker', 'pid' => 101, 'start_ticks' => 123, 'exe' => '/php-fpm', 'cwd' => '/'];
        UnidentifiedDepositP0ReleaseGate::validateLiveProcess($worker, $stat, false, false, 100);
        $this->addToAssertionCount(1);
        foreach (['missing', 'birth', 'parent', 'zombie', 'cli_exe', 'cli_cwd', 'unknown', 'master'] as $failure) {
            $p = $worker; $s = $stat; $exe = '/php-fpm'; $cwd = '/';
            if ($failure === 'missing') $s = false;
            if ($failure === 'birth') $p['start_ticks'] = 124;
            if ($failure === 'parent') $s = str_replace('S 100 ', 'S 99 ', $stat);
            if ($failure === 'zombie') $s = str_replace(') S ', ') Z ', $stat);
            if ($failure === 'cli_exe') { $p['role'] = 'queue'; $exe = false; }
            if ($failure === 'cli_cwd') { $p['role'] = 'websocket'; $cwd = false; }
            if ($failure === 'unknown') $p['role'] = 'other';
            if ($failure === 'master') $p['role'] = 'fpm_master';
            try {
                UnidentifiedDepositP0ReleaseGate::validateLiveProcess($p, $s, $exe, $cwd, 100);
                $this->fail('Invalid live identity accepted: '.$failure);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('write fence retained', $e->getMessage());
            }
        }
    }

    public function test_mysql_and_mariadb_sql_null_defaults_do_not_accept_literal_defaults(): void
    {
        $this->assertTrue(\App\Services\UnidentifiedDepositP0WriteGate::isNullDefault(null));
        $this->assertTrue(\App\Services\UnidentifiedDepositP0WriteGate::isNullDefault('NULL'));
        foreach (["'NULL'", 'null', 'open', 'closed', 0, false, ''] as $value) {
            $this->assertFalse(\App\Services\UnidentifiedDepositP0WriteGate::isNullDefault($value));
        }
    }

    private function fixture(string $phase = 'migrate'): array
    {
        $sha = str_repeat('a', 40);
        $candidate = '/www/wwwroot/releases/eschool-rc-p0';
        $active = $phase === 'open' ? $candidate : '/www/wwwroot/releases/eschool-rc-baseline';
        $activeSha = $phase === 'open' ? $sha : UnidentifiedDepositP0ReleaseGate::BASELINE;
        $e = ['version' => 1, 'generated_at' => 10000, 'phase' => $phase,
            'candidate_sha' => $sha, 'candidate_path' => $candidate, 'active_sha' => $activeSha,
            'active_path' => $active, 'baseline_sha' => UnidentifiedDepositP0ReleaseGate::BASELINE,
            'migration_sha256' => UnidentifiedDepositP0Migration::HASH,
            'backup' => ['recovery_set' => 'synthetic-recovery', 'sha256' => str_repeat('b', 64), 'completed_at' => 9990, 'remote_verified_objects' => 12],
            'runtime' => ['fpm_probe' => ['release' => $candidate, 'sha' => $sha, 'sapi' => 'fpm-fcgi', 'php_version' => '8.3.30'], 'processes' => []]];
        foreach (['fpm_master', 'fpm_worker', 'queue', 'websocket'] as $i => $role) {
            $e['runtime']['processes'][] = ['role' => $role, 'pid' => $i + 1, 'start_ticks' => '123', 'exe' => '/usr/bin/php83', 'cwd' => '/', 'release_path' => $candidate, 'command_sha256' => str_repeat('c', 64), 'php_version' => '8.3.30'];
        }
        return [$e, $phase, $candidate, $active, $sha, $activeSha, ['commit_sha' => $sha, 'release_name' => basename($candidate)], ['commit_sha' => $activeSha], 10000];
    }

    public function test_exact_immutable_release_contract_is_accepted_for_each_phase(): void
    {
        foreach (['close', 'migrate', 'open'] as $phase) {
            UnidentifiedDepositP0ReleaseGate::validateEvidence(...$this->fixture($phase));
            $this->addToAssertionCount(1);
        }
    }

    public function test_wrong_baseline_identity_hash_backup_or_stale_proof_cannot_authorize_migration(): void
    {
        foreach ([['baseline_sha', str_repeat('c', 40)], ['candidate_sha', str_repeat('d', 40)],
            ['migration_sha256', str_repeat('e', 64)], ['phase', 'open'], ['generated_at', 9939],
            ['generated_at', 10001], ['backup', []], ['active_sha', str_repeat('f', 40)], ['version', 2]] as [$key, $value]) {
            $args = $this->fixture();
            $args[0][$key] = $value;
            try {
                UnidentifiedDepositP0ReleaseGate::validateEvidence(...$args);
                $this->fail('Invalid evidence was accepted: '.$key);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('P0', $e->getMessage());
            }
        }
    }

    public function test_activation_failure_old_fpm_old_queue_and_missing_websocket_keep_gate_closed(): void
    {
        foreach (['active', 'fpm', 'queue', 'websocket', 'php'] as $failure) {
            $args = $this->fixture('open');
            if ($failure === 'active') $args[3] = '/www/wwwroot/releases/old';
            if ($failure === 'fpm') $args[0]['runtime']['fpm_probe']['sha'] = UnidentifiedDepositP0ReleaseGate::BASELINE;
            if ($failure === 'queue') $args[0]['runtime']['processes'][2]['release_path'] = '/www/wwwroot/releases/old';
            if ($failure === 'websocket') unset($args[0]['runtime']['processes'][3]);
            if ($failure === 'php') $args[0]['runtime']['processes'][2]['php_version'] = '8.1.2';
            try {
                UnidentifiedDepositP0ReleaseGate::validateEvidence(...$args);
                $this->fail('Activation mismatch reopened writes: '.$failure);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('P0', $e->getMessage());
            }
        }
    }

    public function test_guard_accepts_only_exact_p0_runner_connection_and_migration(): void
    {
        $gate = Mockery::mock(UnidentifiedDepositP0ReleaseGate::class);
        $gate->shouldReceive('assertReadyForMigration')->once()->withNoArgs();
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, $gate);
        $guard = new ProductionMigrationGuard();
        $path = database_path('migrations/'.UnidentifiedDepositP0Migration::MIGRATION.'.php');
        $guard->assertAllowed('migrate', 'finance:unidentified-deposit-p0-migrate', [$path], true, true, 'mysql');
        $this->addToAssertionCount(1);
        foreach ([
            ['migrate', 'migrate', [$path], true, true, 'mysql'],
            ['migrate', 'finance:qa-runs-migrate', [$path], true, true, 'mysql'],
            ['migrate:rollback', 'finance:unidentified-deposit-p0-migrate', [$path], true, true, 'mysql'],
            ['migrate', 'finance:unidentified-deposit-p0-migrate', [$path], true, true, 'school'],
            ['migrate', 'finance:unidentified-deposit-p0-migrate', [$path], true, true, null],
            ['migrate', 'finance:unidentified-deposit-p0-migrate', [$path, $path], true, true, 'mysql'],
            ['migrate', 'finance:unidentified-deposit-p0-migrate', [database_path('migrations')], true, true, 'mysql'],
            ['migrate', 'finance:unidentified-deposit-p0-migrate', [database_path('migrations/schools/'.UnidentifiedDepositP0Migration::MIGRATION.'.php')], true, true, 'mysql'],
        ] as $arguments) {
            try {
                $guard->assertAllowed(...$arguments);
                $this->fail('An incorrect P0 migration request was allowed.');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_exact_p0_hash_is_pinned_and_tampering_fails_closed(): void
    {
        $relative = 'database/migrations/'.UnidentifiedDepositP0Migration::MIGRATION.'.php';
        $this->assertTrue(ProductionMigrationGuard::matchesPinnedMigration($relative, base_path($relative), base_path()));
        $root = sys_get_temp_dir().'/p0-gate-hash-'.bin2hex(random_bytes(8));
        mkdir($root.'/database/migrations', 0700, true);
        try {
            file_put_contents($root.'/'.$relative, file_get_contents(base_path($relative))."\n// tampered\n");
            $this->assertFalse(ProductionMigrationGuard::matchesPinnedMigration($relative, $root.'/'.$relative, $root));
        } finally {
            unlink($root.'/'.$relative);
            rmdir($root.'/database/migrations');
            rmdir($root.'/database');
            rmdir($root);
        }
    }
}

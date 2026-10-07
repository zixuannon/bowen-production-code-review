<?php

namespace Tests\Feature;

use App\Services\UnidentifiedDepositP0ReleaseGate;
use App\Services\UnidentifiedDepositP0WriteGate;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/** Real MySQL only: never replace native trigger/MDL evidence with SQLite. */
class UnidentifiedDepositP0WriteGateTest extends TestCase
{
    private PDO $fixture;
    private array $fixtureConfig;
    private string $fixtureDatabase;
    private string $fixtureDsn;
    private UnidentifiedDepositP0WriteGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', $this->app->environment());
        $this->assertMatchesRegularExpression('/^eschool_[a-z0-9_]*(?:testing|test|qa|rehearsal)[a-z0-9_]*$/D', (string) config('database.connections.mysql.database'));
        $host = (string) config('database.connections.mysql.host');
        $this->assertContains($host, ['127.0.0.1', 'localhost', '::1']);
        if ($host === 'localhost') $host = '127.0.0.1'; // TCP only; never a configured socket.
        $port = filter_var(getenv('P0_REHEARSAL_MYSQL_PORT') ?: 3324, FILTER_VALIDATE_INT);
        $this->assertIsInt($port);
        $this->assertGreaterThan(1023, $port);
        $this->assertLessThan(65536, $port);
        $this->fixtureDsn = 'mysql:host='.$host.';port='.$port.';charset=utf8mb4';
        $this->fixture = new PDO($this->fixtureDsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame($port, (int) $this->fixture->query('SELECT @@port')->fetchColumn());
        $this->fixtureDatabase = 'eschool_ud_fence_testing_'.bin2hex(random_bytes(8));
        $this->fixture->exec('CREATE DATABASE `'.$this->fixtureDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->fixture->exec('USE `'.$this->fixtureDatabase.'`');
        foreach (UnidentifiedDepositP0WriteGate::TABLES as $table) {
            $this->fixture->exec('CREATE TABLE `'.$table.'` (id BIGINT UNSIGNED PRIMARY KEY, value INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
            $this->fixture->exec('INSERT INTO `'.$table.'` (id, value) VALUES (1, 10)');
        }
        $this->fixtureConfig = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $this->fixtureDatabase,
            'username' => 'root', 'password' => '', 'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        config(['database.connections.mysql' => $this->fixtureConfig]);
        DB::purge('mysql');
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, new class {
            public function assertMayCloseWrites(): void {}
            public function assertReadyToReopen(): void {}
        });
        $this->gate = new UnidentifiedDepositP0WriteGate;
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture) && $this->fixture->inTransaction()) $this->fixture->rollBack();
        DB::purge('mysql');
        // Retain disposable schemas as evidence. Never drop a caller database.
        parent::tearDown();
    }

    public function test_old_connection_cannot_write_any_fenced_table_but_reads_and_audits_survive(): void
    {
        $before = $this->fixture->query('SELECT * FROM central_finance_document_audits')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertTrue($this->gate->enable()['closed']);
        foreach (UnidentifiedDepositP0WriteGate::TABLES as $table) {
            foreach (["INSERT INTO `{$table}` (id) VALUES (2)", "UPDATE `{$table}` SET value=20 WHERE id=1", "DELETE FROM `{$table}` WHERE id=1"] as $sql) {
                $this->assertDenied($sql);
            }
            $this->assertSame(10, (int) $this->fixture->query('SELECT value FROM `'.$table.'` WHERE id=1')->fetchColumn());
        }
        $this->assertSame($before, $this->fixture->query('SELECT * FROM central_finance_document_audits')->fetchAll(PDO::FETCH_ASSOC));
        // No session flag can bypass an unconditional trigger.
        $this->fixture->exec('SET @finance_migration_bypass=1, @p0_write_gate_bypass=1');
        $this->assertDenied('UPDATE central_finance_payments SET value=30 WHERE id=1');
        $this->assertSame($this->gate->enable()['present'], $this->gate->assertClosed()['present']);
    }

    public function test_additive_ddl_and_new_identity_backfill_keep_old_tables_fenced(): void
    {
        $this->gate->enable();
        $this->gate->withClosedFence(function (): void {
            $this->gate->assertLockHeld();
            $this->fixture->exec('ALTER TABLE central_finance_payments ADD request_hash VARCHAR(64) NULL');
            $this->fixture->exec('CREATE TABLE central_finance_bank_transaction_identities (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
            $this->fixture->exec('INSERT INTO central_finance_bank_transaction_identities VALUES (1)');
        });
        $this->assertTrue($this->gate->assertClosed()['closed']);
        $this->assertDenied("UPDATE central_finance_payments SET request_hash='attempt' WHERE id=1");
        $this->assertSame(1, (int) $this->fixture->query('SELECT COUNT(*) FROM central_finance_bank_transaction_identities')->fetchColumn());
    }

    public function test_partial_installation_is_preserved_and_exact_missing_triggers_can_resume(): void
    {
        $definitions = $this->gate->definitions();
        $first = array_key_first($definitions);
        $wrong = array_key_last($definitions);
        $this->createTrigger($first, $definitions[$first]);
        $this->createTrigger($wrong, $definitions[$wrong], "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wrong message'");
        try { $this->gate->enable(); $this->fail('Conflicting trigger accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('conflict', $e->getMessage()); }
        $state = $this->gate->inspect();
        $this->assertSame([$first], $state['present']);
        $this->assertSame([$wrong], $state['conflicts']);
        $this->assertDenied('INSERT INTO central_finance_payments (id) VALUES (2)');
        try { $this->gate->assertClosed(); $this->fail('Partial fence accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('conflict', $e->getMessage()); }
        // Explicit test-only repair of the deliberately malformed fixture.
        $this->fixture->exec('DROP TRIGGER `'.$wrong.'`');
        $this->assertTrue($this->gate->enable()['closed']);
        $this->assertCount(count($definitions), $this->gate->inspect()['present']);
    }

    public function test_unfenced_cascading_parent_and_missing_baseline_table_are_rejected(): void
    {
        $this->fixture->exec('CREATE TABLE unfenced_parent (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $this->fixture->exec('ALTER TABLE central_finance_payments ADD parent_id BIGINT UNSIGNED NULL, ADD CONSTRAINT fixture_cascade FOREIGN KEY (parent_id) REFERENCES unfenced_parent (id) ON DELETE SET NULL');
        $this->assertContains('unfenced_parent:fixture_cascade', $this->gate->inspect()['conflicts']);
        try { $this->gate->enable(); $this->fail('Unfenced cascade accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('fixture_cascade', $e->getMessage()); }
        $this->fixture->exec('ALTER TABLE central_finance_payments DROP FOREIGN KEY fixture_cascade');
        $this->fixture->exec('RENAME TABLE central_finance_receipts TO preserved_receipts');
        try { $this->gate->enable(); $this->fail('Missing baseline table accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('central_finance_receipts', $e->getMessage()); }
        $this->assertSame([], $this->gate->inspect()['present']);
    }

    public function test_non_transactional_protected_table_is_rejected_before_installation(): void
    {
        $this->fixture->exec('ALTER TABLE central_finance_payments ENGINE=MyISAM');
        $this->assertContains('protected_engine:central_finance_payments', $this->gate->inspect()['conflicts']);
        $this->expectExceptionMessage('protected_engine:central_finance_payments');
        $this->gate->enable();
    }

    public function test_unreviewed_trigger_on_a_protected_table_is_rejected(): void
    {
        $this->fixture->exec('CREATE TRIGGER unrelated_finance_trigger BEFORE UPDATE ON central_finance_payments FOR EACH ROW SET NEW.value=99');
        $this->assertContains('unreviewed_trigger:unrelated_finance_trigger', $this->gate->inspect()['conflicts']);
        $this->expectExceptionMessage('unreviewed_trigger:unrelated_finance_trigger');
        $this->gate->enable();
    }

    public function test_interrupted_creation_never_removes_completed_triggers_and_retry_resumes(): void
    {
        $pdo = new class($this->fixtureDsn.';dbname='.$this->fixtureDatabase, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
            public bool $interrupt = true;
            private int $created = 0;
            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, 'CREATE TRIGGER') && $this->interrupt && ++$this->created === 4) {
                    throw new PDOException('synthetic DDL interruption');
                }
                return parent::exec($statement);
            }
        };
        DB::connection('mysql')->setPdo($pdo);
        try { $this->gate->enable(); $this->fail('Expected interrupted DDL.'); }
        catch (PDOException $e) { $this->assertSame('synthetic DDL interruption', $e->getMessage()); }
        $this->assertCount(3, $this->gate->inspect()['present']);
        $this->assertDenied('UPDATE central_finance_payments SET value=19 WHERE id=1');
        try { $this->gate->assertClosed(); $this->fail('Partial fence accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('not fully closed', $e->getMessage()); }
        $pdo->interrupt = false;
        $this->assertTrue($this->gate->enable()['closed']);
    }

    public function test_wrong_table_event_timing_and_unknown_reserved_trigger_are_conflicts(): void
    {
        $name = array_key_first($this->gate->definitions());
        foreach ([
            [$name, 'BEFORE', 'INSERT', 'central_finance_receipts'],
            [$name, 'BEFORE', 'UPDATE', 'central_finance_payments'],
            [$name, 'AFTER', 'INSERT', 'central_finance_payments'],
            [UnidentifiedDepositP0WriteGate::PREFIX.'unexpected', 'BEFORE', 'INSERT', 'central_finance_payments'],
        ] as [$trigger, $timing, $event, $table]) {
            $this->fixture->exec("CREATE TRIGGER `{$trigger}` {$timing} {$event} ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'finance migration paused'");
            $this->assertSame([$trigger], $this->gate->inspect()['conflicts']);
            try { $this->gate->enable(); $this->fail('Conflicting trigger accepted.'); }
            catch (RuntimeException $e) { $this->assertStringContainsString($trigger, $e->getMessage()); }
            $this->fixture->exec('DROP TRIGGER `'.$trigger.'`');
        }
    }

    public function test_sqlite_and_transaction_connections_are_rejected_without_a_skip(): void
    {
        DB::connection('mysql')->beginTransaction();
        try { $this->gate->inspect(); $this->fail('Transaction connection accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('outside a transaction', $e->getMessage()); }
        finally { DB::connection('mysql')->rollBack(); }
        config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('mysql');
        $this->expectExceptionMessage('requires the unprefixed Central mysql connection');
        $this->gate->assertClosed();
    }

    public function test_release_proof_is_required_for_enable_and_disable_and_failure_keeps_fence(): void
    {
        $deny = new class {
            public function assertMayCloseWrites(): void { throw new RuntimeException('release proof missing'); }
            public function assertReadyToReopen(): void { throw new RuntimeException('runtime proof missing'); }
        };
        $allow = $this->app->make(UnidentifiedDepositP0ReleaseGate::class);
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, $deny);
        try { $this->gate->enable(); $this->fail('Missing close proof accepted.'); }
        catch (RuntimeException $e) { $this->assertSame('release proof missing', $e->getMessage()); }
        $this->assertSame([], $this->gate->inspect()['present']);
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, $allow);
        $this->gate->enable();
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, $deny);
        try { $this->gate->disable(); $this->fail('Missing reopen proof accepted.'); }
        catch (RuntimeException $e) { $this->assertSame('runtime proof missing', $e->getMessage()); }
        $this->assertTrue($this->gate->assertClosed()['closed']);
        $this->app->instance(UnidentifiedDepositP0ReleaseGate::class, $allow);
        $opened = $this->gate->disable();
        $this->assertSame('open', $opened['latch']);
        $this->assertCount(count($this->gate->definitions()), $opened['present']);
        $this->fixture->exec('UPDATE central_finance_payments SET value=11 WHERE id=1');
        $this->assertSame(11, (int) $this->fixture->query('SELECT value FROM central_finance_payments WHERE id=1')->fetchColumn());
    }

    public function test_advisory_lock_serializes_separate_connections_and_nested_operations(): void
    {
        $lock = UnidentifiedDepositP0WriteGate::lockName($this->fixtureDatabase);
        $statement = $this->fixture->prepare('SELECT GET_LOCK(?,0)');
        $statement->execute([$lock]);
        $this->assertSame(1, (int) $statement->fetchColumn());
        try { $this->gate->enable(); $this->fail('Concurrent operation accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('Another P0', $e->getMessage()); }
        $release = $this->fixture->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
        $this->gate->withLock(function (): void {
            $this->gate->enable();
            $this->gate->withClosedFence(fn () => $this->gate->assertLockHeld());
            $this->gate->assertLockHeld();
        });
        $this->expectExceptionMessage('advisory lock is not held');
        $this->gate->assertLockHeld();
    }

    public function test_failed_atomic_open_preserves_every_trigger_and_closed_latch(): void
    {
        $this->gate->enable();
        $pdo = new class($this->fixtureDsn.';dbname='.$this->fixtureDatabase, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, "UPDATE eschool_p0_write_window SET state='open'")) {
                    throw new PDOException('synthetic atomic-open failure');
                }
                return parent::exec($statement);
            }
        };
        DB::connection('mysql')->setPdo($pdo);
        try { $this->gate->disable(); $this->fail('Expected atomic-open failure.'); }
        catch (PDOException $e) { $this->assertSame('synthetic atomic-open failure', $e->getMessage()); }
        $state = $this->gate->assertClosed();
        $this->assertSame('closed', $state['latch']);
        $this->assertCount(count($this->gate->definitions()), $state['present']);
        foreach (UnidentifiedDepositP0WriteGate::TABLES as $table) $this->assertDenied('UPDATE `'.$table.'` SET value=20 WHERE id=1');
    }

    public function test_old_transaction_cannot_reuse_an_open_snapshot_after_reclosing(): void
    {
        $this->gate->enable();
        $this->gate->disable();
        $this->fixture->beginTransaction();
        $this->assertSame('open', $this->fixture->query('SELECT state FROM eschool_p0_write_window WHERE id=1')->fetchColumn());
        $this->assertTrue($this->gate->enable()['closed']);
        // Its consistent snapshot is still open, but trigger locking read must
        // see current CLOSED and deny every subsequent protected statement.
        $this->assertSame('open', $this->fixture->query('SELECT state FROM eschool_p0_write_window WHERE id=1')->fetchColumn());
        $this->assertDenied('UPDATE central_finance_payments SET value=23 WHERE id=1');
        $this->fixture->rollBack();
    }

    public function test_latch_shape_missing_row_and_append_only_success_receipt_fail_closed(): void
    {
        $this->gate->enable();
        $this->assertNull($this->gate->migrationSuccessReceipt());
        $receipt = ['version' => 1, 'snapshot' => ['payments' => hash('sha256', 'synthetic')]];
        $this->gate->withClosedFence(fn () => $this->gate->recordMigrationSuccess($receipt));
        $this->assertSame($receipt, $this->gate->migrationSuccessReceipt());
        try { $this->gate->withClosedFence(fn () => $this->gate->recordMigrationSuccess(['version' => 2])); $this->fail('Receipt overwrite accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('already exists', $e->getMessage()); }
        $this->fixture->exec('DELETE FROM eschool_p0_write_window WHERE id=1');
        $this->assertDenied('UPDATE central_finance_payments SET value=24 WHERE id=1');
        try { $this->gate->assertClosed(); $this->fail('Missing latch row accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('not fully closed', $e->getMessage()); }
        $this->gate->enable();
        $this->fixture->exec('ALTER TABLE eschool_p0_write_window ADD unexpected INT NULL');
        try { $this->gate->assertClosed(); $this->fail('Latch schema drift accepted.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('latch_schema', $e->getMessage()); }
        $this->assertDenied('UPDATE central_finance_payments SET value=25 WHERE id=1');
    }

    public function test_enable_waits_for_old_writer_transaction_before_declaring_closed(): void
    {
        $this->fixture->beginTransaction();
        $this->fixture->exec('UPDATE central_finance_payments SET value=12 WHERE id=1');
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $configuration = var_export($this->fixtureConfig, true);
        $code = 'require '.$autoload.'; $capsule=new Illuminate\\Database\\Capsule\\Manager; '
            .'$capsule->addConnection('.$configuration.',"mysql"); '
            .'$container=$capsule->getContainer(); $container->instance("db",$capsule->getDatabaseManager()); '
            .'Illuminate\\Container\\Container::setInstance($container); Illuminate\\Support\\Facades\\Facade::setFacadeApplication($container); '
            .'$container->instance(App\\Services\\UnidentifiedDepositP0ReleaseGate::class,new class { public function assertMayCloseWrites():void{} }); '
            .'echo "START\\n"; (new App\\Services\\UnidentifiedDepositP0WriteGate)->enable(); echo "CLOSED\\n";';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        try {
            $pending = false;
            $deadline = microtime(true) + 10;
            do {
                $statement = DB::connection('mysql')->getPdo()->prepare("SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA=? AND OBJECT_NAME='central_finance_payments' AND LOCK_STATUS='PENDING'");
                $statement->execute([$this->fixtureDatabase]);
                $pending = (int) $statement->fetchColumn() > 0;
                if (!$pending) usleep(20000);
            } while (!$pending && microtime(true) < $deadline);
            $this->assertTrue($pending, 'Native trigger DDL must wait for pre-existing old writer metadata lock.');
            $this->assertFalse($this->gate->inspect()['closed']);
            $this->fixture->commit();
            $output = '';
            do {
                $output .= stream_get_contents($pipes[1]);
                if (str_contains($output, 'CLOSED')) break;
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertStringContainsString('CLOSED', $output, stream_get_contents($pipes[2]));
            $this->assertTrue($this->gate->assertClosed()['closed']);
            $this->assertSame(12, (int) $this->fixture->query('SELECT value FROM central_finance_payments WHERE id=1')->fetchColumn());
            $this->assertDenied('UPDATE central_finance_payments SET value=13 WHERE id=1');
        } finally {
            if ($this->fixture->inTransaction()) $this->fixture->rollBack();
            if (proc_get_status($process)['running']) proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    private function createTrigger(string $name, array $definition, ?string $body = null): void
    {
        $this->fixture->exec("CREATE TABLE IF NOT EXISTS eschool_p0_write_window (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, state ENUM('closed','open') NOT NULL, migration_receipt LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin");
        $this->fixture->exec("INSERT IGNORE INTO eschool_p0_write_window (id,state) VALUES (1,'closed')");
        $this->fixture->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$definition['event'].' ON `'.$definition['table'].'` FOR EACH ROW '.($body ?? $this->gate->triggerBody()));
    }

    private function assertDenied(string $sql): void
    {
        try { $this->fixture->exec($sql); $this->fail('Old direct SQL writer bypassed the fence.'); }
        catch (PDOException $e) {
            $this->assertSame('45000', $e->getCode());
            $this->assertStringContainsString(UnidentifiedDepositP0WriteGate::MESSAGE, $e->getMessage());
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/** Database-native pause covering old and candidate application writers alike. */
final class UnidentifiedDepositP0WriteGate
{
    public const MESSAGE = 'finance migration paused';
    public const PREFIX = 'cf_p0_pause_';
    public const LATCH = 'eschool_p0_write_window';

    // Exact Central baseline only. The NEW bank identity table is intentionally
    // absent: the immutable migration must backfill it while this fence is shut.
    public const TABLES = [
        'central_finance_payments',
        'central_finance_payment_allocations',
        'central_finance_payment_refunds',
        'central_finance_payment_reversals',
        'central_finance_receipts',
        'central_finance_ledger_entries',
        'central_finance_receivables',
        'central_finance_receivable_adjustments',
        'central_finance_receivable_sync_events',
        'central_finance_fund_accounts',
        'central_finance_fund_account_users',
        'central_finance_fund_account_school_allocations',
        'central_finance_fund_account_opening_balance_audits',
        'central_finance_pending_collections',
        'central_finance_pending_collection_allocations',
        'central_finance_collection_handover_batches',
        'central_finance_collection_handover_items',
        'central_finance_fund_handovers',
        'central_finance_internal_transfers',
        'central_finance_hq_funding_requests',
        'central_finance_import_batches',
        'central_finance_import_row_reservations',
        'central_finance_group_import_batches',
        'central_finance_group_import_preview_rows',
        'central_finance_expenses',
        'central_finance_reimbursement_requests',
        'central_finance_other_incomes',
        'central_finance_unidentified_deposits',
        'central_finance_unidentified_deposit_allocations',
        'central_finance_document_audits',
        'central_finance_data_classifications',
        'central_finance_data_classification_audits',
        'central_finance_qa_runs',
        'central_finance_qa_run_records',
        'central_finance_school_cutovers',
        'central_finance_student_profiles',
        'central_finance_categories',
        'central_finance_category_audits',
        'central_finance_category_school_allocations',
        'central_finance_content_translations',
        'central_finance_legacy_migration_records',
        'central_finance_pre_go_live_reset_manifests',
        'central_finance_promotion_applications',
        'central_finance_promotion_fee_allocations',
        'central_finance_promotion_school_allocations',
        'central_finance_promotions',
        'central_finance_receivable_sync_uat_exceptions',
        'central_finance_school_staff_identities',
        'central_finance_student_discount_requests',
        'central_finance_sync_events',
        'schools',
        // Revalidated historical-QA actor authority must remain stable across
        // inventory/backfill too. Login flows which update users pause here;
        // existing read-only sessions and unrelated tables remain available.
        'users',
        'roles',
        'model_has_roles',
        'central_finance_user_school_scopes',
        'finance_groups',
        'finance_group_schools',
        'finance_group_users',
        'finance_group_user_scopes',
    ];

    // A locking read observes current state even inside an old REPEATABLE READ
    // transaction. Its shared row lock also makes CLOSE drain existing writers.
    private const BODY = "BEGIN DECLARE gate_state VARCHAR(6) DEFAULT 'closed'; SELECT state INTO gate_state FROM eschool_p0_write_window WHERE id = 1 LOCK IN SHARE MODE; IF COALESCE(gate_state, 'closed') <> 'open' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'finance migration paused'; END IF; END";

    public function triggerBody(): string
    {
        return self::BODY;
    }

    public function enable(): array
    {
        return $this->serial(function (PDO $pdo): array {
            app(UnidentifiedDepositP0ReleaseGate::class)->assertMayCloseWrites();
            $state = $this->inspectUsing($pdo);
            $this->requireValid($state);
            if ($state['latch'] === 'absent') {
                $pdo->exec("CREATE TABLE eschool_p0_write_window (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, state ENUM('closed','open') NOT NULL, migration_receipt LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin");
            }
            if (in_array($state['latch'], ['absent', 'missing_row'], true)) {
                $pdo->exec("INSERT INTO eschool_p0_write_window (id,state) VALUES (1,'closed')");
            } else {
                $pdo->exec("UPDATE eschool_p0_write_window SET state='closed' WHERE id=1");
            }
            foreach ($state['missing'] as $name) {
                $definition = $this->definitions()[$name];
                // Native CREATE TRIGGER takes exclusive MDL. Its completion
                // drains transactions already using this table, including old
                // release writers. Never declare closed until ALL are present.
                $pdo->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$definition['event'].' ON `'.$definition['table'].'` FOR EACH ROW '.self::BODY);
            }
            return $this->requireClosed($this->inspectUsing($pdo));
        });
    }

    public function inspect(): array
    {
        return $this->inspectUsing($this->pdo());
    }

    public function assertClosed(): array
    {
        return $this->requireClosed($this->inspect());
    }

    /** Hold the same serial lock throughout the exact migration/history write. */
    public function withClosedFence(callable $operation): mixed
    {
        return $this->serial(function (PDO $pdo) use ($operation): mixed {
            $this->requireClosed($this->inspectUsing($pdo));
            $result = $operation();
            $this->requireClosed($this->inspectUsing($pdo));
            return $result;
        });
    }

    public function withLock(callable $operation): mixed
    {
        return $this->serial(fn (PDO $pdo): mixed => $operation());
    }

    public function assertLockHeld(): void
    {
        $this->requireLock($this->pdo());
    }

    /** Runner owns receipt content and independently verifies all saved hashes. */
    public function recordMigrationSuccess(array $receipt): void
    {
        $this->assertLockHeld();
        $this->assertClosed();
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
        $pdo = $this->pdo();
        $statement = $pdo->prepare("UPDATE eschool_p0_write_window SET migration_receipt=? WHERE id=1 AND state='closed' AND migration_receipt IS NULL");
        $statement->execute([$encoded]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('P0 migration success receipt already exists or latch is not closed.');
    }

    public function migrationSuccessReceipt(): ?array
    {
        $state = $this->inspect();
        $this->requireValid($state);
        if (in_array($state['latch'], ['absent', 'missing_row'], true)) return null;
        $encoded = $this->pdo()->query('SELECT migration_receipt FROM eschool_p0_write_window WHERE id=1')->fetchColumn();
        if ($encoded === null) return null;
        $receipt = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($receipt)) throw new RuntimeException('P0 migration success receipt is malformed.');
        return $receipt;
    }

    /** No force flag, environment escape, SQL session variable or caller proof. */
    public function disable(): array
    {
        return $this->serial(function (PDO $pdo): array {
            $this->requireClosed($this->inspectUsing($pdo));
            app(UnidentifiedDepositP0ReleaseGate::class)->assertReadyToReopen();
            // One atomic transition. NEVER drop triggers sequentially: a DDL
            // failure would otherwise reopen only part of the financial graph.
            if ($pdo->exec("UPDATE eschool_p0_write_window SET state='open' WHERE id=1 AND state='closed'") !== 1) {
                throw new RuntimeException('P0 finance write latch did not open atomically.');
            }
            $state = $this->inspectUsing($pdo);
            $this->requireValid($state);
            if ($state['latch'] !== 'open' || $state['missing'] !== []) throw new RuntimeException('P0 finance write latch verification failed.');
            return $state;
        });
    }

    /** @return array<string, array{table:string,event:string}> */
    public function definitions(): array
    {
        $definitions = [];
        foreach (self::TABLES as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $definitions[self::PREFIX.substr(hash('sha256', $table), 0, 24).'_'.strtolower($event)] = ['table' => $table, 'event' => $event];
            }
        }
        return $definitions;
    }

    private function pdo(): PDO
    {
        $connection = DB::connection('mysql');
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') {
            throw new RuntimeException('P0 finance write fence requires the unprefixed Central mysql connection.');
        }
        $pdo = $connection->getPdo();
        if ($connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new RuntimeException('P0 finance write fence requires a connection outside a transaction.');
        }
        // Use this exact PDO throughout: automatic reconnect could silently
        // lose the advisory lock between verification and a DDL statement.
        return $pdo;
    }

    private function inspectUsing(PDO $pdo): array
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') throw new RuntimeException('Central database is not selected.');
        $tables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        $engines = $pdo->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        $missingTables = array_values(array_filter(self::TABLES, fn (string $table): bool => ($tables[$table] ?? null) !== 'BASE TABLE'));
        $rows = $pdo->query('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_ORIENTATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $definitions = $this->definitions();
        $present = [];
        $conflicts = [];
        foreach (self::TABLES as $table) {
            if (isset($tables[$table]) && ($engines[$table] ?? null) !== 'InnoDB') $conflicts[] = 'protected_engine:'.$table;
        }
        foreach ($rows as $row) {
            $name = $row['TRIGGER_NAME'];
            if ($row['EVENT_OBJECT_TABLE'] === self::LATCH) $conflicts[] = 'latch_trigger:'.$name;
            if (!str_starts_with($name, self::PREFIX)) {
                if (in_array($row['EVENT_OBJECT_TABLE'], self::TABLES, true)) $conflicts[] = 'unreviewed_trigger:'.$name;
                continue;
            }
            $definition = $definitions[$name] ?? null;
            if ($definition === null || $row['EVENT_OBJECT_TABLE'] !== $definition['table']
                || $row['EVENT_MANIPULATION'] !== $definition['event'] || $row['ACTION_TIMING'] !== 'BEFORE'
                || $row['ACTION_ORIENTATION'] !== 'ROW' || trim($row['ACTION_STATEMENT']) !== self::BODY) {
                $conflicts[] = $name;
            } else {
                $present[] = $name;
            }
        }
        [$latch, $latchConflicts] = $this->inspectLatch($pdo, $tables);
        $conflicts = array_merge($conflicts, $latchConflicts);
        // MySQL does not execute child triggers for FK cascades. Refuse schema
        // drift that could mutate a protected table through an unfenced parent.
        $foreignKeys = $pdo->query('SELECT CONSTRAINT_NAME, TABLE_NAME, UNIQUE_CONSTRAINT_SCHEMA, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreignKeys as $key) {
            if (in_array($key['TABLE_NAME'], self::TABLES, true)
                && ($key['UNIQUE_CONSTRAINT_SCHEMA'] !== $database || !in_array($key['REFERENCED_TABLE_NAME'], self::TABLES, true))
                && (in_array($key['UPDATE_RULE'], ['CASCADE', 'SET NULL'], true) || in_array($key['DELETE_RULE'], ['CASCADE', 'SET NULL'], true))) {
                $conflicts[] = 'unfenced_parent:'.$key['CONSTRAINT_NAME'];
            }
        }
        $missing = array_values(array_diff(array_keys($definitions), $present));
        return ['database' => $database, 'latch' => $latch, 'closed' => $latch === 'closed' && $missing === [] && $conflicts === [] && $missingTables === [],
            'expected_count' => count($definitions), 'present' => $present, 'missing' => $missing,
            'missing_tables' => $missingTables, 'conflicts' => $conflicts];
    }

    private function inspectLatch(PDO $pdo, array $tables): array
    {
        if (!isset($tables[self::LATCH])) return ['absent', []];
        if ($tables[self::LATCH] !== 'BASE TABLE') return ['invalid', ['latch_schema']];
        $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eschool_p0_write_window'")->fetchColumn();
        $columns = $pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eschool_p0_write_window' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $indexes = $pdo->query("SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eschool_p0_write_window'")->fetchAll(PDO::FETCH_ASSOC);
        $keys = $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='eschool_p0_write_window'")->fetchColumn();
        if ($engine !== 'InnoDB' || count($columns) !== 3 || count($indexes) !== 1 || (int) $keys !== 0
            || $columns[0]['COLUMN_NAME'] !== 'id' || !preg_match('/^tinyint(?:\\(3\\))? unsigned$/D', $columns[0]['COLUMN_TYPE'])
            || $columns[1]['COLUMN_NAME'] !== 'state' || $columns[1]['COLUMN_TYPE'] !== "enum('closed','open')"
            || $columns[2]['COLUMN_NAME'] !== 'migration_receipt' || $columns[2]['COLUMN_TYPE'] !== 'longtext' || $columns[2]['IS_NULLABLE'] !== 'YES'
            || $indexes[0]['INDEX_NAME'] !== 'PRIMARY' || $indexes[0]['COLUMN_NAME'] !== 'id' || (int) $indexes[0]['NON_UNIQUE'] !== 0) {
            return ['invalid', ['latch_schema']];
        }
        foreach ($columns as $index => $column) {
            if (($index < 2 && $column['IS_NULLABLE'] !== 'NO') || !self::isNullDefault($column['COLUMN_DEFAULT']) || $column['EXTRA'] !== '') return ['invalid', ['latch_schema']];
        }
        $rows = $pdo->query('SELECT id,state FROM eschool_p0_write_window')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return ['missing_row', []];
        if (count($rows) !== 1 || (int) $rows[0]['id'] !== 1 || !in_array($rows[0]['state'], ['closed', 'open'], true)) return ['invalid', ['latch_rows']];
        return [$rows[0]['state'], []];
    }

    public static function isNullDefault(mixed $default): bool
    {
        // MariaDB reports SQL NULL as the unquoted metadata string NULL.
        // A literal string default is quoted and must remain rejected.
        return in_array($default, [null, 'NULL'], true);
    }

    private function requireValid(array $state): void
    {
        if ($state['missing_tables'] !== [] || $state['conflicts'] !== []) {
            throw new RuntimeException('P0 finance write fence schema/trigger conflict: '.json_encode([
                'missing_tables' => $state['missing_tables'], 'conflicts' => $state['conflicts'],
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function requireClosed(array $state): array
    {
        $this->requireValid($state);
        if (!$state['closed']) throw new RuntimeException('P0 finance write fence is not fully closed.');
        return $state;
    }

    public static function lockName(string $database): string
    {
        return 'cf_p0_pause:'.substr(hash('sha256', $database), 0, 40);
    }

    private function serial(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $lock = self::lockName((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Another P0 finance fence operation is running.');
        $wait = (int) $pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
        try {
            $pdo->exec('SET SESSION lock_wait_timeout = 30');
            $this->requireLock($pdo);
            $result = $operation($pdo);
            $this->requireLock($pdo);
            return $result;
        } finally {
            // Never remove triggers on error. Partial closure must remain in
            // place for a reviewed retry; migration may not run in that state.
            $pdo->exec('SET SESSION lock_wait_timeout = '.$wait);
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    private function requireLock(PDO $pdo): void
    {
        $lock = self::lockName((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $statement = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('P0 finance fence advisory lock is not held.');
    }
}

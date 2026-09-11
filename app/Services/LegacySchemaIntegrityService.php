<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Read-only structural and data preflight for the Round 5 tenant migration. */
final class LegacySchemaIntegrityService
{
    public const ROUND5_MIGRATION = '2026_09_12_000001_harden_legacy_student_import_and_bank_transfer_integrity';

    public const INDEXES = [
        'students_id_school_unique',
        'users_id_school_unique',
        'bank_accounts_id_school_unique',
        'bank_transfer_school_reference_unique',
    ];

    public const FOREIGN_KEYS = [
        'student_identity_student_school_fk',
        'student_identity_user_school_fk',
        'student_identity_actor_school_fk',
        'bank_account_created_actor_school_fk',
        'bank_account_updated_actor_school_fk',
        'bank_transfer_from_school_fk',
        'bank_transfer_to_school_fk',
        'bank_transfer_actor_school_fk',
    ];

    public const CHECKS = [
        'student_identity_school_positive_chk',
        'bank_account_school_positive_chk',
        'bank_transfer_school_positive_chk',
        'bank_transfer_accounts_different_chk',
        'bank_transfer_amount_positive_chk',
        'bank_transfer_status_chk',
    ];

    public function identityBaseComplete(): bool
    {
        return $this->hasColumns('student_import_identities', [
            'id', 'school_id', 'student_code', 'student_id', 'user_id', 'created_by', 'created_at', 'updated_at',
        ])
            && $this->indexMatches('student_import_identities', 'student_import_identity_school_code_unique', ['school_id', 'student_code'], true)
            && $this->indexMatches('student_import_identities', 'student_import_identities_student_id_unique', ['student_id'], true)
            && $this->indexMatches('student_import_identities', 'student_import_identities_user_id_unique', ['user_id'], true)
            && $this->stringColumn('student_import_identities', 'student_code');
    }

    public function bankBaseComplete(): bool
    {
        return $this->hasColumns('bank_accounts', [
            'id', 'school_id', 'account_name', 'account_type', 'currency', 'opening_balance',
            'is_active', 'is_default', 'created_at', 'updated_at', 'deleted_at',
        ])
            && $this->indexContains('bank_accounts', ['school_id'])
            && $this->indexContains('bank_accounts', ['is_active']);
    }

    public function transferBaseComplete(): bool
    {
        return $this->hasColumns('bank_transfers', [
            'id', 'school_id', 'from_account_id', 'to_account_id', 'amount', 'transfer_date',
            'reference_no', 'status', 'created_by', 'created_at', 'updated_at', 'deleted_at',
        ])
            && $this->indexContains('bank_transfers', ['school_id'])
            && $this->indexContains('bank_transfers', ['from_account_id'])
            && $this->indexContains('bank_transfers', ['to_account_id'])
            && $this->indexContains('bank_transfers', ['transfer_date'])
            && $this->indexContains('bank_transfers', ['status']);
    }

    public function round5BaseComplete(): bool
    {
        return $this->identityBaseComplete()
            && $this->bankBaseComplete()
            && $this->transferBaseComplete()
            && $this->hasColumns('students', ['id', 'school_id', 'user_id'])
            && $this->hasColumns('users', ['id', 'school_id'])
            && $this->hasColumns('bank_accounts', ['created_by', 'updated_by']);
    }

    /** @return array<string,int> */
    public function preflightIssues(): array
    {
        $identityQueries = [
            'identity_duplicate_school_code' => 'SELECT COUNT(*) c FROM (SELECT school_id, student_code FROM student_import_identities GROUP BY school_id, student_code HAVING COUNT(*) > 1) x',
            'identity_duplicate_student' => 'SELECT COUNT(*) c FROM (SELECT student_id FROM student_import_identities GROUP BY student_id HAVING COUNT(*) > 1) x',
            'identity_duplicate_user' => 'SELECT COUNT(*) c FROM (SELECT user_id FROM student_import_identities GROUP BY user_id HAVING COUNT(*) > 1) x',
            'identity_invalid_school' => 'SELECT COUNT(*) c FROM student_import_identities WHERE school_id < 1',
            'identity_orphan_student' => 'SELECT COUNT(*) c FROM student_import_identities i LEFT JOIN students s ON s.id = i.student_id WHERE s.id IS NULL',
            'identity_orphan_user' => 'SELECT COUNT(*) c FROM student_import_identities i LEFT JOIN users u ON u.id = i.user_id WHERE u.id IS NULL',
            'identity_orphan_actor' => 'SELECT COUNT(*) c FROM student_import_identities i LEFT JOIN users u ON u.id = i.created_by WHERE i.created_by IS NOT NULL AND u.id IS NULL',
            'identity_student_school_mismatch' => 'SELECT COUNT(*) c FROM student_import_identities i JOIN students s ON s.id = i.student_id WHERE s.school_id <> i.school_id',
            'identity_user_school_mismatch' => 'SELECT COUNT(*) c FROM student_import_identities i JOIN users u ON u.id = i.user_id WHERE u.school_id IS NULL OR u.school_id <> i.school_id',
            'identity_actor_school_mismatch' => 'SELECT COUNT(*) c FROM student_import_identities i JOIN users u ON u.id = i.created_by WHERE i.created_by IS NOT NULL AND (u.school_id IS NULL OR u.school_id <> i.school_id)',
            'identity_student_user_mismatch' => 'SELECT COUNT(*) c FROM student_import_identities i JOIN students s ON s.id = i.student_id WHERE s.user_id <> i.user_id',
        ];
        $bankQueries = [
            'bank_account_invalid_school' => 'SELECT COUNT(*) c FROM bank_accounts WHERE school_id < 1',
            'bank_account_orphan_created_actor' => 'SELECT COUNT(*) c FROM bank_accounts a LEFT JOIN users u ON u.id = a.created_by WHERE a.created_by IS NOT NULL AND u.id IS NULL',
            'bank_account_orphan_updated_actor' => 'SELECT COUNT(*) c FROM bank_accounts a LEFT JOIN users u ON u.id = a.updated_by WHERE a.updated_by IS NOT NULL AND u.id IS NULL',
            'bank_account_created_actor_school_mismatch' => 'SELECT COUNT(*) c FROM bank_accounts a JOIN users u ON u.id = a.created_by WHERE a.created_by IS NOT NULL AND (u.school_id IS NULL OR u.school_id <> a.school_id)',
            'bank_account_updated_actor_school_mismatch' => 'SELECT COUNT(*) c FROM bank_accounts a JOIN users u ON u.id = a.updated_by WHERE a.updated_by IS NOT NULL AND (u.school_id IS NULL OR u.school_id <> a.school_id)',
            'transfer_duplicate_reference' => 'SELECT COUNT(*) c FROM (SELECT school_id, reference_no FROM bank_transfers WHERE reference_no IS NOT NULL GROUP BY school_id, reference_no HAVING COUNT(*) > 1) x',
            'transfer_invalid_school' => 'SELECT COUNT(*) c FROM bank_transfers WHERE school_id < 1',
            'transfer_orphan_from' => 'SELECT COUNT(*) c FROM bank_transfers t LEFT JOIN bank_accounts a ON a.id = t.from_account_id WHERE a.id IS NULL',
            'transfer_orphan_to' => 'SELECT COUNT(*) c FROM bank_transfers t LEFT JOIN bank_accounts a ON a.id = t.to_account_id WHERE a.id IS NULL',
            'transfer_orphan_actor' => 'SELECT COUNT(*) c FROM bank_transfers t LEFT JOIN users u ON u.id = t.created_by WHERE t.created_by IS NOT NULL AND u.id IS NULL',
            'transfer_from_school_mismatch' => 'SELECT COUNT(*) c FROM bank_transfers t JOIN bank_accounts a ON a.id = t.from_account_id WHERE a.school_id <> t.school_id',
            'transfer_to_school_mismatch' => 'SELECT COUNT(*) c FROM bank_transfers t JOIN bank_accounts a ON a.id = t.to_account_id WHERE a.school_id <> t.school_id',
            'transfer_actor_school_mismatch' => 'SELECT COUNT(*) c FROM bank_transfers t JOIN users u ON u.id = t.created_by WHERE t.created_by IS NOT NULL AND (u.school_id IS NULL OR u.school_id <> t.school_id)',
            'transfer_same_account' => 'SELECT COUNT(*) c FROM bank_transfers WHERE from_account_id = to_account_id',
            'transfer_nonpositive_amount' => 'SELECT COUNT(*) c FROM bank_transfers WHERE amount <= 0',
            'transfer_invalid_status' => "SELECT COUNT(*) c FROM bank_transfers WHERE status NOT IN ('completed', 'cancelled')",
        ];

        $queries = Schema::connection('school')->hasTable('student_import_identities')
            ? array_merge($identityQueries, $bankQueries)
            : $bankQueries;

        $issues = [];
        foreach ($queries as $name => $sql) {
            $count = (int) (DB::connection('school')->selectOne($sql)->c ?? 0);
            if ($count > 0) $issues[$name] = $count;
        }
        return $issues;
    }

    public function assertPreflightClean(): void
    {
        $issues = $this->preflightIssues();
        if ($issues !== []) {
            throw new RuntimeException('Round 5 orphan/duplicate preflight failed: '.json_encode($issues, JSON_THROW_ON_ERROR));
        }
    }

    public function round5SchemaPresent(): bool
    {
        foreach (self::INDEXES as $name) if ($this->namedIndexExists($name)) return true;
        foreach (self::FOREIGN_KEYS as $name) if ($this->namedConstraintExists($name, 'FOREIGN KEY')) return true;
        foreach (self::CHECKS as $name) if ($this->namedConstraintExists($name, 'CHECK')) return true;
        return false;
    }

    public function round5SchemaComplete(): bool
    {
        return $this->indexMatches('students', self::INDEXES[0], ['id', 'school_id'], true)
            && $this->indexMatches('users', self::INDEXES[1], ['id', 'school_id'], true)
            && $this->indexMatches('bank_accounts', self::INDEXES[2], ['id', 'school_id'], true)
            && $this->indexMatches('bank_transfers', self::INDEXES[3], ['school_id', 'reference_no'], true)
            && $this->foreignKeyMatches('student_import_identities', self::FOREIGN_KEYS[0], ['student_id', 'school_id'], 'students', ['id', 'school_id'])
            && $this->foreignKeyMatches('student_import_identities', self::FOREIGN_KEYS[1], ['user_id', 'school_id'], 'users', ['id', 'school_id'])
            && $this->foreignKeyMatches('student_import_identities', self::FOREIGN_KEYS[2], ['created_by', 'school_id'], 'users', ['id', 'school_id'])
            && $this->foreignKeyMatches('bank_accounts', self::FOREIGN_KEYS[3], ['created_by', 'school_id'], 'users', ['id', 'school_id'])
            && $this->foreignKeyMatches('bank_accounts', self::FOREIGN_KEYS[4], ['updated_by', 'school_id'], 'users', ['id', 'school_id'])
            && $this->foreignKeyMatches('bank_transfers', self::FOREIGN_KEYS[5], ['from_account_id', 'school_id'], 'bank_accounts', ['id', 'school_id'])
            && $this->foreignKeyMatches('bank_transfers', self::FOREIGN_KEYS[6], ['to_account_id', 'school_id'], 'bank_accounts', ['id', 'school_id'])
            && $this->foreignKeyMatches('bank_transfers', self::FOREIGN_KEYS[7], ['created_by', 'school_id'], 'users', ['id', 'school_id'])
            && $this->checkContains(self::CHECKS[0], ['school_id', '>', '0'])
            && $this->checkContains(self::CHECKS[1], ['school_id', '>', '0'])
            && $this->checkContains(self::CHECKS[2], ['school_id', '>', '0'])
            && $this->checkContains(self::CHECKS[3], ['from_account_id', '<>', 'to_account_id'])
            && $this->checkContains(self::CHECKS[4], ['amount', '>', '0'])
            && $this->checkContains(self::CHECKS[5], ['status', 'completed', 'cancelled']);
    }

    /** @param list<string> $columns */
    private function hasColumns(string $table, array $columns): bool
    {
        if (!Schema::connection('school')->hasTable($table)) return false;
        foreach ($columns as $column) if (!Schema::connection('school')->hasColumn($table, $column)) return false;
        return true;
    }

    private function stringColumn(string $table, string $column): bool
    {
        try {
            $type = strtolower((string) Schema::connection('school')->getColumnType($table, $column, true));
            return str_contains($type, 'char') || str_contains($type, 'text');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<string> $columns */
    private function indexContains(string $table, array $columns): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if (array_values($index['columns'] ?? []) === $columns) return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    /** @param list<string> $columns */
    private function indexMatches(string $table, string $name, array $columns, bool $unique): bool
    {
        try {
            foreach (Schema::connection('school')->getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name
                    && array_values($index['columns'] ?? []) === $columns
                    && (bool) ($index['unique'] ?? false) === $unique) return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private function namedIndexExists(string $name): bool
    {
        return (int) (DB::connection('school')->selectOne(
            'SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ?', [$name]
        )->c ?? 0) > 0;
    }

    private function namedConstraintExists(string $name, string $type): bool
    {
        return (int) (DB::connection('school')->selectOne(
            'SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?', [$name, $type]
        )->c ?? 0) > 0;
    }

    /** @param list<string> $columns @param list<string> $referencedColumns */
    private function foreignKeyMatches(string $table, string $name, array $columns, string $referencedTable, array $referencedColumns): bool
    {
        $rows = DB::connection('school')->select(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table, $name]
        );
        return array_map(fn ($row) => $row->COLUMN_NAME, $rows) === $columns
            && array_map(fn ($row) => $row->REFERENCED_COLUMN_NAME, $rows) === $referencedColumns
            && $rows !== []
            && count(array_unique(array_map(fn ($row) => $row->REFERENCED_TABLE_NAME, $rows))) === 1
            && $rows[0]->REFERENCED_TABLE_NAME === $referencedTable;
    }

    /** @param list<string> $needles */
    private function checkContains(string $name, array $needles): bool
    {
        $row = DB::connection('school')->selectOne(
            'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?', [$name]
        );
        if ($row === null) return false;
        $clause = strtolower(str_replace('`', '', (string) $row->CHECK_CLAUSE));
        foreach ($needles as $needle) if (!str_contains($clause, strtolower($needle))) return false;
        return true;
    }
}

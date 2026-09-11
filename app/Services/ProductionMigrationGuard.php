<?php

namespace App\Services;

use RuntimeException;

final class ProductionMigrationGuard
{
    /** @var array<string, list<string>> */
    private const RUNNER_PATHS = [
        'finance:p1-audit-safety' => [
            'database/migrations/schools/2026_08_10_000001_add_soft_deletes_to_expenses.php',
            'database/migrations/schools/2026_08_10_000002_add_updated_by_to_expenses.php',
            'database/migrations/schools/2026_08_10_000003_add_soft_delete_audit_to_fee_tables.php',
            'database/migrations/schools/2026_08_10_000004_add_audit_fields_to_bank_accounts.php',
            'database/migrations/schools/2026_08_10_000005_create_expense_change_logs_table.php',
            'database/migrations/schools/2026_08_10_000006_create_bank_account_balance_adjustments_table.php',
        ],
        'finance:p2-p3-migration-safety' => [
            'database/migrations/schools/2026_08_11_000001_create_bank_account_user_table.php',
            'database/migrations/schools/2026_08_12_000001_create_fund_handovers_table.php',
        ],
        'finance:migrate-p31-p32' => [
            'database/migrations/schools/2026_08_12_000002_create_expense_import_batches_table.php',
            'database/migrations/schools/2026_08_13_000001_create_other_incomes_table.php',
        ],
        'finance:migrate-record-lifecycle' => [
            'database/migrations/schools/2026_09_01_000001_create_school_record_lifecycle_audits_table.php',
            'database/migrations/schools/2026_09_01_000002_add_deleted_at_to_fees_class_types.php',
        ],
        'student-import-v2:migrate' => [
            'database/migrations/2026_09_03_000002_add_student_code_to_central_finance_student_profiles.php',
            'database/migrations/schools/2026_09_03_000001_create_student_import_identities_table.php',
            'database/migrations/schools/2026_09_03_000002_make_student_import_v21_identity_fields_nullable.php',
        ],
        'finance:migrate-central-finance-staff-uuid' => [
            'database/migrations/schools/2026_08_24_000003_add_central_finance_source_uuid_to_users_table.php',
        ],
        'finance:migrate-student-fee-assignments' => [
            'database/migrations/schools/2026_08_27_000001_create_student_fee_assignment_tables.php',
            'database/migrations/schools/2026_08_27_000003_add_type_to_student_fee_assignments.php',
            'database/migrations/schools/2026_08_27_000004_create_student_fee_assignment_source_locks.php',
        ],
        'finance:migrate-currency-history' => [
            'database/migrations/schools/2026_09_11_000001_add_financial_currency_history_integrity.php',
        ],
    ];

    /**
     * @param list<string> $paths
     */
    public function assertAllowed(string $command, ?string $outerCommand, array $paths, bool $realPath, bool $production): void
    {
        if (!$production || !str_starts_with($command, 'migrate')) {
            return;
        }

        if (!in_array($command, ['migrate', 'migrate:rollback'], true)) {
            throw new RuntimeException("{$command} is disabled in production.");
        }
        if (!$outerCommand || !isset(self::RUNNER_PATHS[$outerCommand]) || !$realPath || $paths === []) {
            throw new RuntimeException('Generic production migrations are disabled; use an allowlisted exact-path runner.');
        }

        $approved = array_map(fn (string $path): string => $this->normalize(base_path($path)), self::RUNNER_PATHS[$outerCommand]);
        foreach ($paths as $path) {
            $normalized = $this->normalize($path);
            if (!str_ends_with($normalized, '.php') || !in_array($normalized, $approved, true)) {
                throw new RuntimeException('Production migration path is not in the selected runner allowlist.');
            }
        }
    }

    /** @return list<string> */
    public static function allowedRunners(): array
    {
        return array_keys(self::RUNNER_PATHS);
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', preg_replace('#/+#', '/', $path));
    }
}

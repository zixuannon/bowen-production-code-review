<?php

namespace App\Services;

use App\Models\CentralFinancePreGoLiveResetManifest;
use App\Models\CentralFinanceUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A deliberately narrow pre-go-live reset.  It never touches tenants, users,
 * identities, scopes, schools, subscription billing, or system configuration.
 * Every table in BUSINESS_TABLES is an explicit reviewed allowlist entry.
 */
final class CentralFinancePreGoLiveResetService
{
    public function __construct(private readonly CentralFinanceConfigurationAuthorizationService $authorization) {}
    /** Child/dependent rows always precede their parent business record. */
    private const BUSINESS_TABLES = [
        'central_finance_ledger_entries',
        'central_finance_payment_refunds',
        'central_finance_receipt_items',
        'central_finance_receipts',
        'central_finance_payments',
        'central_finance_receivable_adjustments',
        'central_finance_receivables',
        'central_finance_collection_handover_items',
        'central_finance_collection_handover_batches',
        'central_finance_fund_handovers',
        'central_finance_pending_collections',
        'central_finance_reimbursement_requests',
        'central_finance_internal_transfers',
        'central_finance_hq_funding_requests',
        'central_finance_group_import_preview_rows',
        'central_finance_import_batch_preview_rows',
        'central_finance_import_batches',
        'central_finance_group_import_batches',
        'central_finance_expenses',
        'central_finance_other_incomes',
        'central_finance_fund_account_users',
        'central_finance_fund_account_school_allocations',
        'central_finance_fund_account_opening_balance_audits',
        'central_finance_fund_accounts',
        'central_finance_category_school_allocations',
        'central_finance_category_audits',
        'central_finance_categories',
        'central_finance_student_profiles',
        'central_finance_receivable_sync_events',
        'central_finance_receivable_sync_uat_exceptions',
    ];

    /** These are hashed before and after every execution and must never change. */
    private const PROTECTED_TABLES = [
        'schools',
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'central_finance_users',
        'central_finance_school_staff_identities',
        'central_finance_user_school_scopes',
        'finance_groups',
        'finance_group_schools',
        'finance_group_users',
        'finance_group_user_scopes',
        'finance_group_user_tenant_identities',
        'central_finance_school_cutovers',
        'subscriptions',
        'subscription_bills',
        'payment_transactions',
    ];

    public function preflight(): array
    {
        $schema = Schema::connection('mysql');
        $business = [];
        foreach (self::BUSINESS_TABLES as $table) {
            $business[$table] = $schema->hasTable($table) ? DB::connection('mysql')->table($table)->count() : null;
        }

        return [
            'mode' => 'dry-run',
            'business_allowlist' => $business,
            'external_dependency_blockers' => $this->externalDependencyBlockers(),
            'protected_tables' => array_values(array_filter(self::PROTECTED_TABLES, fn (string $table): bool => $schema->hasTable($table))),
            'protected_hash' => $this->protectedHash(),
            'subscription_bills_with_payment_transaction' => $schema->hasTable('subscription_bills')
                ? DB::connection('mysql')->table('subscription_bills')->whereNotNull('payment_transaction_id')->count() : 0,
            'document_audits_retained' => $schema->hasTable('central_finance_document_audits')
                ? DB::connection('mysql')->table('central_finance_document_audits')->count() : 0,
        ];
    }

    /** @return array{deleted_counts:array<string,int>,protected_hash:string,manifest_id:int} */
    public function execute(CentralFinanceUser $actor, string $approvalReference, string $reason): array
    {
        $approvalReference = trim($approvalReference);
        $reason = trim($reason);
        if ($approvalReference === '' || $reason === '') {
            throw new RuntimeException('An approval reference and audit reason are required.');
        }

        $groupId = (int) DB::connection('mysql')->table('finance_groups')->orderBy('id')->value('id');
        if ($groupId < 1) {
            throw new RuntimeException('No Finance Group exists to authorize the reset.');
        }
        $this->authorization->assertHeadFinanceCanConfigureGroup($actor, $groupId);

        $plan = $this->preflight();
        if ($plan['external_dependency_blockers'] !== []) {
            throw new RuntimeException('External FK dependencies reference reset data; reset aborted: '.json_encode($plan['external_dependency_blockers'], JSON_THROW_ON_ERROR));
        }
        $beforeHash = $plan['protected_hash'];

        return DB::connection('mysql')->transaction(function () use ($actor, $approvalReference, $reason, $plan, $beforeHash): array {
            // Lock a stable configuration record so two reset commands cannot
            // run concurrently. No financial or identity record is used as a lock.
            DB::connection('mysql')->table('finance_groups')->orderBy('id')->lockForUpdate()->first();
            if ($this->protectedHash() !== $beforeHash) {
                throw new RuntimeException('Protected configuration changed after preflight; reset aborted before deletion.');
            }

            $deleted = [];
            $schema = Schema::connection('mysql');
            foreach (self::BUSINESS_TABLES as $table) {
                if (!$schema->hasTable($table)) {
                    continue;
                }
                // Intentionally delete only from the named allowlist table.
                // No TRUNCATE, no dynamic table name, and no tenant connection.
                $deleted[$table] = DB::connection('mysql')->table($table)->delete();
            }

            $afterHash = $this->protectedHash();
            if (!hash_equals($beforeHash, $afterHash)) {
                throw new RuntimeException('Protected configuration checksum changed; transaction rolled back.');
            }

            $manifest = CentralFinancePreGoLiveResetManifest::on('mysql')->create([
                'reset_uuid' => (string) Str::uuid(),
                'actor_id' => $actor->id,
                'approval_reference' => $approvalReference,
                'reason' => $reason,
                'pre_reset_counts' => $plan['business_allowlist'],
                'deleted_counts' => $deleted,
                'protected_hash_before' => $beforeHash,
                'protected_hash_after' => $afterHash,
                'executed_at' => now(),
            ]);

            return ['deleted_counts' => $deleted, 'protected_hash' => $afterHash, 'manifest_id' => $manifest->id];
        });
    }

    private function protectedHash(): string
    {
        $schema = Schema::connection('mysql');
        $snapshot = [];
        foreach (self::PROTECTED_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $query = DB::connection('mysql')->table($table);
            $columns = $schema->getColumnListing($table);
            if (in_array('id', $columns, true)) {
                $query->orderBy('id');
            }
            $snapshot[$table] = $query->get()->map(fn ($row): array => (array) $row)->all();
        }
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * A reset may never strand a record outside the reviewed allowlist. MySQL
     * provides the authoritative FK graph; SQLite test databases deliberately
     * return an empty result because their schema is synthetic.
     *
     * @return array<int,array{table:string,column:string,references:string,referenced_column:string,rows:int}>
     */
    private function externalDependencyBlockers(): array
    {
        $connection = DB::connection('mysql');
        if ($connection->getDriverName() !== 'mysql') return [];

        $databaseRow = $connection->selectOne('select database() as name');
        $database = (string) ($databaseRow->name ?? '');
        if ($database === '') throw new RuntimeException('Cannot determine Central database for dependency preflight.');
        $placeholders = implode(',', array_fill(0, count(self::BUSINESS_TABLES), '?'));
        $foreignKeys = $connection->select(
            'select table_name, column_name, referenced_table_name, referenced_column_name
             from information_schema.key_column_usage
             where constraint_schema = ? and referenced_table_name in ('.$placeholders.')',
            array_merge([$database], self::BUSINESS_TABLES)
        );

        $blockers = [];
        foreach ($foreignKeys as $foreignKey) {
            $table = (string) $foreignKey->table_name;
            if (in_array($table, self::BUSINESS_TABLES, true)) continue;
            $column = (string) $foreignKey->column_name;
            $referencedTable = (string) $foreignKey->referenced_table_name;
            $referencedColumn = (string) $foreignKey->referenced_column_name;
            foreach ([$table, $column, $referencedTable, $referencedColumn] as $identifier) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) throw new RuntimeException('Unsafe FK metadata identifier in dependency preflight.');
            }
            $rows = (int) $connection->table($table)->whereNotNull($column)
                ->whereIn($column, $connection->table($referencedTable)->select($referencedColumn))->count();
            if ($rows > 0) {
                $blockers[] = [
                    'table' => $table, 'column' => $column, 'references' => $referencedTable,
                    'referenced_column' => $referencedColumn, 'rows' => $rows,
                ];
            }
        }
        return $blockers;
    }
}

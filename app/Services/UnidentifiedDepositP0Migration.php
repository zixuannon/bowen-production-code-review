<?php

namespace App\Services;

use App\Support\CentralFinanceCurrency;
use App\Support\CentralFinanceDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;

/** Read-only Central inventory for the single immutable P0 migration. */
class UnidentifiedDepositP0Migration
{
    public const MIGRATION = '2026_10_07_000001_close_unidentified_deposit_p0';
    public const HASH = '8cb9cc668a4ad17ad4c7543564beba3b21f9d6d6ad583ce4acb3f18e1ea4c432';
    public const IDENTITIES = 'central_finance_bank_transaction_identities';
    private const DEPOSITS = 'central_finance_unidentified_deposits';
    private const PAYMENTS = 'central_finance_payments';
    private const ALLOCATIONS = 'central_finance_unidentified_deposit_allocations';
    private const ADDITIONS = [
        self::DEPOSITS => ['request_hash' => ['varchar(64)', true], 'manual_identity' => ['varchar(100)', true], 'manual_reason' => ['text', true]],
        self::PAYMENTS => ['request_hash' => ['varchar(64)', true], 'unidentified_deposit_id' => ['bigint unsigned', true]],
        self::ALLOCATIONS => ['request_hash' => ['varchar(64)', true], 'payment_id' => ['bigint unsigned', true]],
    ];

    public function migrationPath(): string
    {
        $path = database_path('migrations/'.self::MIGRATION.'.php');
        if (!is_file($path) || is_link($path) || realpath($path) !== $path
            || !hash_equals(self::HASH, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('The exact P0 migration path/hash is invalid.');
        }
        return $path;
    }

    public function assertTarget(): void
    {
        $db = DB::connection('mysql');
        $name = $db->getDatabaseName();
        if ($db->getDriverName() !== 'mysql' || $db->getTablePrefix() !== '') {
            throw new RuntimeException('P0 requires the unprefixed Central mysql connection with the MySQL driver.');
        }
        if (app()->environment('production')) {
            if ($name !== 'sql_43_160_241_126') throw new RuntimeException('Unexpected Production Central database.');
        } else {
            $url = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (!app()->environment(['local', 'testing'])
                || !in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
                || !in_array($url, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
                || $name === '' || $name === 'sql_43_160_241_126' || preg_match('/prod|staging/i', $name)) {
                throw new RuntimeException('P0 rehearsal requires a local Central database and application URL.');
            }
        }
        if ($db->selectOne('SELECT DATABASE() AS database_name')->database_name !== $name) {
            throw new RuntimeException('Resolved Central database differs from configuration.');
        }
    }

    /** @return array{state:string,identities:array,conflicts:array} */
    public function inspect(): array
    {
        $this->assertTarget();
        $this->migrationPath();
        $state = $this->schemaState();
        $identities = [];
        $conflicts = [];
        if ($state === 'eligible') {
            // Invoke exactly the unchanged SELECT-only migration inventory,
            // including independent historical QA audit revalidation.
            $migration = require $this->migrationPath();
            $identities = (new ReflectionMethod($migration, 'historicalIdentities'))->invoke($migration);
            $conflicts = $this->conflictCounts();
            if (array_sum($conflicts) !== 0) $state = 'not_ready';
        } elseif ($state === 'complete' && !$this->identityCoverageComplete()) {
            $state = 'unexpected';
        }
        return compact('state', 'identities', 'conflicts');
    }

    public function schemaState(): string
    {
        $schema = Schema::connection('mysql');
        foreach (['migrations', self::DEPOSITS, self::ALLOCATIONS, self::PAYMENTS, 'central_finance_fund_accounts',
            'central_finance_pending_collections', 'central_finance_import_batches', 'central_finance_import_row_reservations', 'central_finance_group_import_batches',
            'central_finance_receipts', 'central_finance_receivables', 'central_finance_ledger_entries', 'central_finance_other_incomes'] as $table) {
            if (!$schema->hasTable($table)) {
                if ($schema->hasTable(self::IDENTITIES)
                    || ($schema->hasTable('migrations') && DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->exists())) return 'unexpected';
                foreach (self::ADDITIONS as $source => $columns) {
                    foreach ($columns as $column => $_) if ($schema->hasTable($source) && $schema->hasColumn($source, $column)) return 'unexpected';
                }
                return 'not_ready';
            }
        }
        $history = DB::connection('mysql')->table('migrations')->where('migration', self::MIGRATION)->get();
        $present = $schema->hasTable(self::IDENTITIES) ? 1 : 0;
        foreach (self::ADDITIONS as $table => $columns) {
            foreach ($columns as $column => $_) $present += (int) $schema->hasColumn($table, $column);
        }
        if ($present === 0 && $history->isEmpty()) {
            return $this->hasIndex(self::ALLOCATIONS, 'cfuda_deposit_receivable_unique', ['unidentified_deposit_id', 'receivable_id'], true)
                ? 'eligible' : 'unexpected';
        }
        if ($present !== 8 || $history->count() !== 1 || (int) $history->first()->batch < 1) return 'unexpected';
        foreach (self::ADDITIONS as $table => $columns) {
            if (!$this->columnsMatch($table, $columns)) return 'unexpected';
        }
        $columns = [
            'id' => ['bigint unsigned', false], 'fund_account_id' => ['bigint unsigned', false],
            'currency' => ['varchar(3)', false], 'identity_hash' => ['varchar(64)', false],
            'identity_namespace' => ['varchar(24)', false], 'normalized_identity' => ['varchar(100)', false],
            'manual_reason' => ['text', true], 'source_type' => ['varchar(32)', false], 'source_id' => ['varchar(64)', false],
            'amount' => ['decimal(20,4)', false], 'payload_hash' => ['varchar(64)', true],
            'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ];
        if (!$this->columnsMatch(self::IDENTITIES, $columns, true)
            || count($schema->getIndexes(self::IDENTITIES)) !== 3
            || count($schema->getForeignKeys(self::IDENTITIES)) !== 1
            || !$this->hasIndex(self::IDENTITIES, 'primary', ['id'], true)
            || !$this->hasIndex(self::IDENTITIES, 'cfbti_physical_identity_unique', ['fund_account_id', 'currency', 'identity_hash'], true)
            || !$this->hasIndex(self::IDENTITIES, 'cfbti_origin_unique', ['source_type', 'source_id'], true)
            || !$this->hasIndex(self::ALLOCATIONS, 'cfuda_payment_unique', ['payment_id'], true)
            || collect($schema->getIndexes(self::ALLOCATIONS))->contains(fn ($i) => $i['name'] === 'cfuda_deposit_receivable_unique'
                || ($i['unique'] && count($i['columns']) === 2 && array_diff($i['columns'], ['unidentified_deposit_id', 'receivable_id']) === []))
            || !$this->hasForeignKey(self::IDENTITIES, 'cfbti_account_fk', 'fund_account_id', 'central_finance_fund_accounts')
            || !$this->hasForeignKey(self::PAYMENTS, 'cfp_unidentified_deposit_fk', 'unidentified_deposit_id', self::DEPOSITS)
            || !$this->hasForeignKey(self::ALLOCATIONS, 'cfuda_payment_fk', 'payment_id', self::PAYMENTS)) return 'unexpected';
        return 'complete';
    }

    private function columnsMatch(string $table, array $expected, bool $exact = false): bool
    {
        $columns = collect(DB::connection('mysql')->select('SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::connection('mysql')->getDatabaseName(), $table]))->keyBy('name');
        if ($exact && $columns->count() !== count($expected)) return false;
        foreach ($expected as $name => [$type, $nullable]) {
            $column = $columns->get($name);
            // MariaDB exposes integer display widths; these are not type widths.
            $actualType = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($column->type ?? ''));
            if ($column === null || $actualType !== $type || ($column->nullable === 'YES') !== $nullable
                || !in_array($column->default_value, [null, 'NULL'], true)
                || strtolower($column->extra) !== ($name === 'id' ? 'auto_increment' : '')) return false;
        }
        return true;
    }

    private function hasIndex(string $table, string $name, array $columns, bool $unique): bool
    {
        foreach (Schema::connection('mysql')->getIndexes($table) as $index) {
            if ($index['name'] === $name) return $index['columns'] === $columns && $index['unique'] === $unique && strtolower($index['type']) === 'btree'
                && !DB::connection('mysql')->table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::connection('mysql')->getDatabaseName())->where('TABLE_NAME', $table)->where('INDEX_NAME', $name)->whereNotNull('SUB_PART')->exists();
        }
        return false;
    }

    private function hasForeignKey(string $table, string $name, string $column, string $target): bool
    {
        foreach (Schema::connection('mysql')->getForeignKeys($table) as $key) {
            if ($key['name'] === $name) return $key['columns'] === [$column] && $key['foreign_table'] === $target
                && $key['foreign_schema'] === DB::connection('mysql')->getDatabaseName() && $key['foreign_columns'] === ['id']
                && in_array(strtolower($key['on_delete']), ['restrict', 'no action'], true)
                && in_array(strtolower($key['on_update']), ['restrict', 'no action'], true);
        }
        return false;
    }

    /** Linked Pending rows describe the same payment, never another cash fact. */
    public function conflictCounts(): array
    {
        $db = DB::connection('mysql');
        $counts = ['pending_unresolved' => 0, 'pending_link_conflicts' => 0, 'import_inflight' => 0, 'import_reserved' => 0, 'group_import_inflight' => 0];
        foreach ($db->table('central_finance_pending_collections')->orderBy('id')->cursor() as $pending) {
            if ($pending->confirmed_payment_id !== null) {
                $payment = $db->table(self::PAYMENTS)->find($pending->confirmed_payment_id);
                $invalid = $pending->status !== 'confirmed' || $payment === null;
                if (!$invalid) {
                    $invalid = (int) $pending->school_id !== (int) $payment->school_id
                        || (int) $pending->receivable_id !== (int) $payment->receivable_id
                        || CentralFinanceCurrency::normalize($pending->currency) !== CentralFinanceCurrency::normalize($payment->currency)
                        || CentralFinanceDecimal::compare((string) $pending->amount, (string) $payment->amount) !== 0
                        || $payment->idempotency_key !== hash('sha256', $pending->school_id.'|'.$pending->receivable_id.'|pending-collection:'.$pending->pending_collection_uuid);
                    // Cash destination is chosen at confirmation. Only a bank
                    // Pending reserves an original physical account/reference.
                    $bank = $db->table('central_finance_fund_accounts')->where('id', $pending->intended_fund_account_id)->value('account_type') === 'bank';
                    if ($bank) $invalid = $invalid || (int) $pending->intended_fund_account_id !== (int) $payment->fund_account_id
                        || CentralFinanceBankTransactionIdentityService::normalizeReference($pending->payment_reference)
                            !== CentralFinanceBankTransactionIdentityService::normalizeReference($payment->payment_reference);
                }
                $counts['pending_link_conflicts'] += (int) $invalid;
            } elseif ($pending->status === 'confirmed') {
                $counts['pending_link_conflicts']++;
            } elseif (!in_array($pending->status, ['rejected', 'cancelled'], true)) {
                $bank = $db->table('central_finance_fund_accounts')->where('id', $pending->intended_fund_account_id)->value('account_type') === 'bank';
                if ($bank || $pending->intended_fund_account_id === null) $counts['pending_unresolved']++;
            }
        }
        // A preview/reservation can become a writer after the inventory. Require
        // operators to resolve it before opening the DDL window.
        $counts['import_inflight'] = $db->table('central_finance_import_batches')->whereIn('status', ['pending', 'processing'])->count();
        $counts['import_reserved'] = $db->table('central_finance_import_row_reservations')->where('status', 'reserved')->count();
        $counts['group_import_inflight'] = $db->table('central_finance_group_import_batches')->whereIn('status', ['previewed', 'pending', 'processing'])->count();
        return $counts;
    }

    /** New allocation Payments are attribution, not additional physical cash. */
    private function identityCoverageComplete(): bool
    {
        $db = DB::connection('mysql');
        $sources = [[self::DEPOSITS, 'unidentified_deposit', 'bank_reference'], [self::PAYMENTS, 'payment', 'payment_reference'], ['central_finance_other_incomes', 'other_income', 'reference_no']];
        $expectedCount = 0;
        foreach ($sources as [$table, $type, $reference]) {
            foreach ($db->table($table)->orderBy('id')->cursor() as $row) {
                if ($type === 'payment' && $row->unidentified_deposit_id !== null) continue;
                $account = $db->table('central_finance_fund_accounts')->find($row->fund_account_id);
                if ($account === null) return false;
                if ($type !== 'unidentified_deposit' && $account->account_type !== 'bank') continue;
                $expectedCount++;
                $stored = $db->table(self::IDENTITIES)->where('source_type', $type)->where('source_id', $row->idempotency_key)->first();
                if ($stored === null || (int) $stored->fund_account_id !== (int) $row->fund_account_id
                    || $stored->currency !== CentralFinanceCurrency::normalize($row->currency)
                    || $stored->currency !== CentralFinanceCurrency::normalize($account->currency)
                    || CentralFinanceDecimal::compare((string) $stored->amount, (string) $row->amount) !== 0
                    || ($type !== 'other_income' && $stored->payload_hash !== $row->request_hash)) return false;
                $identity = $type === 'payment' && $row->{$reference} === null
                    ? app(CentralFinanceHistoricalQaIdentityService::class)->verifiedIdentityForPayment((int) $row->id) : null;
                $identity ??= CentralFinanceBankTransactionIdentityService::identity($row->{$reference}, $row->manual_identity ?? null, $row->manual_reason ?? null);
                foreach ($identity as $key => $value) if ($stored->{$key} !== $value) return false;
            }
        }
        return $db->table(self::IDENTITIES)->count() === $expectedCount;
    }

    /** Freeze every existing Central Finance row, including balances and audit metadata. */
    public function financialSnapshot(): array
    {
        $schema = Schema::connection('mysql');
        $db = DB::connection('mysql');
        $hashes = [];
        foreach ($schema->getTables() as $table) {
            $name = $table['name'];
            if ((!str_starts_with($name, 'central_finance_') && !in_array($name, UnidentifiedDepositP0WriteGate::TABLES, true)) || $name === self::IDENTITIES) continue;
            $columns = array_values(array_diff($schema->getColumnListing($name), array_keys(self::ADDITIONS[$name] ?? [])));
            sort($columns);
            $query = $db->table($name)->select($columns);
            // PK order is stable; metadata tables without an id use all fields.
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) $query->orderBy($column);
            $hash = hash_init('sha256');
            foreach ($query->cursor() as $row) hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
            $hashes[$name] = hash_final($hash);
        }
        ksort($hashes);
        return $hashes;
    }

    public function historySnapshot(): array
    {
        return DB::connection('mysql')->table('migrations')->where('migration', '!=', self::MIGRATION)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /** Called only after this runner observed eligible -> complete and compared its pre-DDL snapshots. */
    public function recordMigrationSuccess(array $before, array $history): void
    {
        $gate = app(UnidentifiedDepositP0WriteGate::class);
        $gate->assertLockHeld();
        $gate->assertClosed();
        if ($gate->migrationSuccessReceipt() !== null) throw new RuntimeException('A P0 preservation receipt already exists; it may not be replaced.');
        if ($this->inspect()['state'] !== 'complete' || $this->financialSnapshot() !== $before || $this->historySnapshot() !== $history) {
            throw new RuntimeException('P0 preservation evidence changed; do not reopen writers.');
        }
        $receipt = $this->receiptIdentity() + ['finance' => $before, 'unrelated_history' => $history];
        $nextBatch = max(array_merge([0], array_column($history, 'batch'))) + 1;
        if ((int) $receipt['migration_history']['batch'] !== $nextBatch) throw new RuntimeException('P0 migration did not record the exact next migration batch.');
        $gate->recordMigrationSuccess($receipt);
        $this->assertMigrationPreserved();
    }

    /** Reopening and completed retries require durable PRE-migration evidence, never a new post-state receipt. */
    public function assertMigrationPreserved(): void
    {
        $gate = app(UnidentifiedDepositP0WriteGate::class);
        $gate->assertLockHeld();
        $gate->assertClosed();
        $receipt = $gate->migrationSuccessReceipt();
        if ($receipt === null || $this->inspect()['state'] !== 'complete'
            || $receipt !== $this->receiptIdentity() + ['finance' => $this->financialSnapshot(), 'unrelated_history' => $this->historySnapshot()]) {
            throw new RuntimeException('P0 durable migration preservation receipt is missing or differs from current evidence; keep writers blocked.');
        }
    }

    private function receiptIdentity(): array
    {
        $this->assertTarget();
        $this->migrationPath();
        if (app()->environment('production')) {
            $path = base_path('.release-commit');
            $candidate = is_file($path) && !is_link($path) ? trim((string) file_get_contents($path)) : '';
            if (!preg_match('/\A[0-9a-f]{40}\z/', $candidate)) throw new RuntimeException('P0 candidate identity is missing.');
        } else {
            $candidate = 'local:'.hash('sha256', (string) hash_file('sha256', __FILE__).hash_file('sha256', app_path('Console/Commands/MigrateUnidentifiedDepositP0.php')));
        }
        $db = DB::connection('mysql');
        return [
            'version' => 1, 'candidate' => $candidate, 'database' => $db->getDatabaseName(),
            'migration' => self::MIGRATION, 'migration_hash' => self::HASH,
            'migration_history' => (array) $db->table('migrations')->where('migration', self::MIGRATION)->sole(),
            'identity_hash' => hash('sha256', $db->table(self::IDENTITIES)->orderBy('id')->get()->toJson(JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }
}

<?php

/** Exact migration rehearsal, restricted to one dedicated disposable MySQL. */
declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const UD_REHEARSAL_DATABASE = 'eschool_ud_migration_rehearsal_income';
const UD_REHEARSAL_DATADIR = '/private/tmp/unidentified-p0-mysql.mxlBZu/';
const UD_IDENTITY_TABLE = 'central_finance_bank_transaction_identities';

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    fwrite(STDOUT, "PASS {$message}\n");
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3324;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $pdo->query('SELECT @@port AS port, @@datadir AS datadir, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
verify((int) $server['port'] === 3324 && $server['datadir'] === UD_REHEARSAL_DATADIR && $server['version'] === '9.6.0', 'dedicated localhost MySQL 9.6.0 port/datadir identity');
$existing = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'eschool_ud_migration_rehearsal_income'")->fetchColumn();
verify($existing === false, 'rehearsal schema is new; existing schemas are never overwritten');
$pdo->exec('CREATE DATABASE eschool_ud_migration_rehearsal_income CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Config::set('database.connections.mysql', [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3324, 'database' => UD_REHEARSAL_DATABASE,
    'username' => 'root', 'password' => '', 'unix_socket' => '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
verify($db->selectOne('SELECT DATABASE() AS name')->name === UD_REHEARSAL_DATABASE, 'application connection targets only the dedicated rehearsal schema');
$db->statement('CREATE TABLE central_finance_fund_accounts (id BIGINT UNSIGNED PRIMARY KEY, account_type VARCHAR(20) NOT NULL, currency CHAR(3) NOT NULL) ENGINE=InnoDB');
foreach (['central_finance_payments' => 'payment_reference', 'central_finance_unidentified_deposits' => 'bank_reference'] as $table => $reference) {
    $db->statement("CREATE TABLE {$table} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fund_account_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, amount DECIMAL(20,4) NOT NULL, idempotency_key VARCHAR(64) NOT NULL UNIQUE, {$reference} VARCHAR(100) NULL) ENGINE=InnoDB");
}
$db->statement('CREATE TABLE central_finance_unidentified_deposit_allocations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidentified_deposit_id BIGINT UNSIGNED NOT NULL, receivable_id BIGINT UNSIGNED NOT NULL, UNIQUE KEY cfuda_deposit_receivable_unique (unidentified_deposit_id,receivable_id)) ENGINE=InnoDB');
$db->statement('CREATE TABLE central_finance_other_incomes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fund_account_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, amount DECIMAL(20,4) NOT NULL, idempotency_key VARCHAR(64) NOT NULL UNIQUE, reference_no VARCHAR(100) NULL, deleted_at TIMESTAMP NULL) ENGINE=InnoDB');
$db->table('central_finance_fund_accounts')->insert(['id' => 1, 'account_type' => 'bank', 'currency' => 'MMK']);
$migration = fn () => require dirname(__DIR__, 2).'/database/migrations/2026_10_07_000001_close_unidentified_deposit_p0.php';

$migration()->up();
verify(Schema::connection('mysql')->hasTable(UD_IDENTITY_TABLE), 'empty migration applies');
$migration()->down();
verify(!Schema::connection('mysql')->hasTable(UD_IDENTITY_TABLE) && !Schema::connection('mysql')->hasColumn('central_finance_payments', 'unidentified_deposit_id'), 'unused migration rolls back completely');
$migration()->up();
verify(Schema::connection('mysql')->hasTable(UD_IDENTITY_TABLE), 'empty migration reapplies');
$migration()->down();

$bankPayment = ['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000.0000', 'idempotency_key' => hash('sha256', 'ud-rehearsal-payment'), 'payment_reference' => 'BANK-001'];
$bankDeposit = ['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000.0000', 'idempotency_key' => hash('sha256', 'ud-rehearsal-deposit'), 'bank_reference' => ' bank-001 '];
$db->beginTransaction();
try {
    $db->table('central_finance_payments')->insert($bankPayment);
    $db->table('central_finance_unidentified_deposits')->insert($bankDeposit);
    try { $migration()->up(); throw new LogicException('Duplicate migration unexpectedly applied.'); }
    catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'Duplicate historical bank transaction'), 'historical cross-source normalized duplicate is rejected'); }
    verify(!Schema::connection('mysql')->hasTable(UD_IDENTITY_TABLE) && !Schema::connection('mysql')->hasColumn('central_finance_payments', 'request_hash'), 'duplicate rejection precedes every schema change');
} finally { $db->rollBack(); }

$db->beginTransaction();
try {
    $db->table('central_finance_payments')->insert(array_replace($bankPayment, ['payment_reference' => null]));
    try { $migration()->up(); throw new LogicException('Empty-reference migration unexpectedly applied.'); }
    catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'no Bank Reference'), 'historical missing Bank Reference is rejected'); }
    verify(!Schema::connection('mysql')->hasTable(UD_IDENTITY_TABLE), 'missing-reference rejection precedes schema changes');
} finally { $db->rollBack(); }

foreach ([null => null, 'duplicate' => 'bank-001'] as $case => $reference) {
    $db->beginTransaction();
    try {
        $db->table('central_finance_payments')->insert($bankPayment);
        $db->table('central_finance_other_incomes')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000.0000', 'idempotency_key' => hash('sha256', 'historical-income'), 'reference_no' => $reference]);
        try { $migration()->up(); throw new LogicException('Ambiguous historical Income unexpectedly migrated.'); }
        catch (RuntimeException $e) { verify(str_contains($e->getMessage(), $reference === null ? 'no Bank Reference' : 'Duplicate historical bank transaction'), 'historical Other Income '.($reference === null ? 'blank reference' : 'duplicate identity').' rejected'); }
        verify(!Schema::connection('mysql')->hasTable(UD_IDENTITY_TABLE), 'Other Income ambiguity fails before DDL');
    } finally { $db->rollBack(); }
}

$paymentId = $db->table('central_finance_payments')->insertGetId($bankPayment);
$depositId = $db->table('central_finance_unidentified_deposits')->insertGetId(array_replace($bankDeposit, ['bank_reference' => 'BANK-002']));
$incomeId = $db->table('central_finance_other_incomes')->insertGetId(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '250000.0000', 'idempotency_key' => hash('sha256', 'historical-income'), 'reference_no' => 'BANK-003', 'deleted_at' => now()]);
$beforePayment = (array) $db->table('central_finance_payments')->find($paymentId);
$beforeDeposit = (array) $db->table('central_finance_unidentified_deposits')->find($depositId);
$migration()->up();
verify($db->table(UD_IDENTITY_TABLE)->count() === 3, 'historical bank facts receive three physical identities');
verify($db->table(UD_IDENTITY_TABLE)->where('source_type', 'other_income')->where('source_id', hash('sha256', 'historical-income'))->count() === 1
    && $db->table('central_finance_other_incomes')->where('id', $incomeId)->whereNotNull('deleted_at')->exists(), 'voided historical Other Income remains reserved and unmodified');
verify(array_intersect_key((array) $db->table('central_finance_payments')->find($paymentId), $beforePayment) === $beforePayment
    && array_intersect_key((array) $db->table('central_finance_unidentified_deposits')->find($depositId), $beforeDeposit) === $beforeDeposit, 'historical bank fact columns remain byte-for-byte unchanged');
$indexes = $db->select('SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? GROUP BY INDEX_NAME, NON_UNIQUE', [UD_REHEARSAL_DATABASE, UD_IDENTITY_TABLE]);
$indexMap = [];
foreach ($indexes as $index) $indexMap[$index->INDEX_NAME] = [$index->NON_UNIQUE, $index->columns_list];
verify($indexMap['cfbti_physical_identity_unique'] === [0, 'fund_account_id,currency,identity_hash'], 'physical identity composite unique index');
verify($indexMap['cfbti_origin_unique'] === [0, 'source_type,source_id'], 'canonical origin unique index');
$foreignKeys = $db->select("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME IN ('cfbti_account_fk','cfp_unidentified_deposit_fk','cfuda_payment_fk')", [UD_REHEARSAL_DATABASE]);
verify(count($foreignKeys) === 3, 'all three financial reference foreign keys exist');

$allocationPaymentIds = [];
foreach (['first', 'second'] as $line) {
    $id = $db->table('central_finance_payments')->insertGetId(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '250000.0000', 'idempotency_key' => hash('sha256', 'allocation-'.$line), 'unidentified_deposit_id' => $depositId]);
    $allocationPaymentIds[] = $id;
    $db->table('central_finance_unidentified_deposit_allocations')->insert(['unidentified_deposit_id' => $depositId, 'receivable_id' => 71, 'payment_id' => $id]);
}
verify($db->table('central_finance_unidentified_deposit_allocations')->where('unidentified_deposit_id', $depositId)->where('receivable_id', 71)->count() === 2, 'two canonical allocations to the same receivable are accepted');
try {
    $db->table('central_finance_unidentified_deposit_allocations')->insert(['unidentified_deposit_id' => $depositId, 'receivable_id' => 72, 'payment_id' => $allocationPaymentIds[0]]);
    throw new LogicException('Payment link uniqueness was not enforced.');
} catch (Illuminate\Database\QueryException $e) { verify((int) $e->errorInfo[1] === 1062, 'duplicate canonical Payment link rejected by MySQL'); }
try {
    $db->table('central_finance_unidentified_deposit_allocations')->insert(['unidentified_deposit_id' => $depositId, 'receivable_id' => 73, 'payment_id' => 999999]);
    throw new LogicException('Payment link foreign key was not enforced.');
} catch (Illuminate\Database\QueryException $e) { verify((int) $e->errorInfo[1] === 1452, 'nonexistent canonical Payment link rejected by MySQL'); }
try { $migration()->down(); throw new LogicException('Used migration unexpectedly rolled back.'); }
catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'is in use'), 'used migration rollback refuses financial-history loss'); }
verify($db->table(UD_IDENTITY_TABLE)->count() === 3 && $db->table('central_finance_unidentified_deposit_allocations')->count() === 2, 'used rollback guard preserves every identity and allocation');
fwrite(STDOUT, "MYSQL REHEARSAL PASS; dedicated schema retained for inspection; Production untouched.\n");

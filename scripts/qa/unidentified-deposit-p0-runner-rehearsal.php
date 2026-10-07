<?php

/**
 * Reproducible exact-runner integration against retained synthetic local fixtures.
 * Creates a fresh schema only; source schemas are SELECT-only and never modified.
 * Requires the dedicated P0 MySQL and the earlier audited HQA synthetic fixture.
 */
declare(strict_types=1);

use App\Services\UnidentifiedDepositP0Migration as P0;
use App\Services\UnidentifiedDepositP0ReleaseGate;
use App\Services\UnidentifiedDepositP0WriteGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function runnerCheck(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    fwrite(STDOUT, 'PASS '.$label."\n");
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3324;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $pdo->query('SELECT @@port AS port, @@datadir AS datadir, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
runnerCheck((int) $server['port'] === 3324 && $server['datadir'] === '/private/tmp/unidentified-p0-mysql.mxlBZu/' && $server['version'] === '9.6.0', 'dedicated disposable MySQL identity');
$baseline = 'eschool_testing';
$historical = 'eschool_ud_hqa_cli_20261007';
$baselineTables = $pdo->query('SHOW TABLES FROM `'.$baseline.'`')->fetchAll(PDO::FETCH_COLUMN);
$historicalTables = $pdo->query('SHOW TABLES FROM `'.$historical.'`')->fetchAll(PDO::FETCH_COLUMN);
runnerCheck(count($baselineTables) >= 100 && in_array(P0::IDENTITIES, $historicalTables, true), 'retained baseline and HQA fixture schemas exist');
$school = $pdo->query('SELECT name FROM `'.$historical.'`.schools')->fetchAll(PDO::FETCH_COLUMN);
runnerCheck(count($school) === 1 && str_contains(strtolower($school[0]), 'synthetic'), 'historical source is the synthetic fixture');
function createRunnerFixture(PDO $pdo, string $baseline, string $historical, array $baselineTables, array $historicalTables): string
{
$database = 'eschool_ud_p0_runner_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `'.$database.'`');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$additions = [
    'central_finance_unidentified_deposits' => ['request_hash', 'manual_identity', 'manual_reason'],
    'central_finance_payments' => ['request_hash', 'unidentified_deposit_id'],
    'central_finance_unidentified_deposit_allocations' => ['request_hash', 'payment_id'],
];
$tables = array_values(array_diff(array_unique(array_merge($baselineTables, $historicalTables)), [P0::IDENTITIES]));
foreach ($tables as $table) {
    $source = in_array($table, $historicalTables, true) && $table !== 'migrations' ? $historical : $baseline;
    $create = $pdo->query('SHOW CREATE TABLE `'.$source.'`.`'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    $lines = explode("\n", $create);
    // Remove only P0 fields from the NEW empty CREATE statement. No existing
    // schema or financial row is altered, and no migration down() is invoked.
    $lines = array_values(array_filter($lines, function ($line) use ($table, $additions): bool {
        foreach ($additions[$table] ?? [] as $column) {
            if (preg_match('/^\s*`'.preg_quote($column, '/').'`\s/', $line)) return false;
        }
        return !preg_match('/`(?:cfp_unidentified_deposit_fk|cfuda_payment_fk|cfuda_payment_unique)`/', $line);
    }));
    if ($table === 'central_finance_unidentified_deposit_allocations') {
        $last = count($lines) - 1;
        $lines[$last - 1] = rtrim($lines[$last - 1], ',').',';
        array_splice($lines, $last, 0, ['  UNIQUE KEY `cfuda_deposit_receivable_unique` (`unidentified_deposit_id`,`receivable_id`)']);
    }
    $create = preg_replace('/,\n\)/', "\n)", implode("\n", $lines));
    $pdo->exec($create);
    // Copy synthetic HQA rows and baseline migration history only. Other
    // baseline tables provide exact existing schema without unrelated data.
    if ($source === $historical || $table === 'migrations') {
        $columns = $pdo->query('SHOW COLUMNS FROM `'.$database.'`.`'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
        $columnSql = implode(',', array_map(fn ($c) => '`'.$c.'`', $columns));
        $pdo->exec('INSERT INTO `'.$database.'`.`'.$table.'` ('.$columnSql.') SELECT '.$columnSql.' FROM `'.$source.'`.`'.$table.'`');
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
return $database;
}

$database = createRunnerFixture($pdo, $baseline, $historical, $baselineTables, $historicalTables);

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env'] = 'testing';
config(['app.url' => 'http://127.0.0.1', 'database.connections.mysql' => [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3324, 'database' => $database,
    'username' => 'root', 'password' => '', 'unix_socket' => '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'queue.connections.database.table' => 'jobs', 'queue.connections.database.connection' => 'mysql']);
$db = DB::connection('mysql');
$service = app(P0::class);
runnerCheck($service->inspect()['state'] === 'eligible', 'full baseline history plus independently audited historical QA is eligible');
$beforeMissing = $service->financialSnapshot();
$db->beginTransaction();
try {
    $payment = (array) $db->table('central_finance_payments')->first();
    unset($payment['id']);
    $payment['idempotency_key'] = hash('sha256', 'synthetic-runner-official-unapproved');
    $payment['payment_uuid'] = (string) Illuminate\Support\Str::uuid();
    $db->table('central_finance_payments')->insert($payment);
    try { $service->inspect(); throw new LogicException('Official missing reference was accepted.'); }
    catch (RuntimeException $error) { runnerCheck(str_contains($error->getMessage(), 'no Bank Reference'), 'unapproved official missing reference fails before DDL'); }
    runnerCheck(!Schema::connection('mysql')->hasTable(P0::IDENTITIES), 'failure creates no P0 table');
} finally { $db->rollBack(); }
runnerCheck($beforeMissing === $service->financialSnapshot(), 'rejected missing-reference fixture fully rolled back');

// The immutable migration must carry an ordinary real-reference identity too.
$payment = (array) $db->table('central_finance_payments')->first();
unset($payment['id']);
$payment['idempotency_key'] = hash('sha256', 'synthetic-runner-bank-reference');
$payment['payment_uuid'] = (string) Illuminate\Support\Str::uuid();
$payment['payment_reference'] = 'SYNTHETIC-RUNNER-BANK-REFERENCE';
$db->table('central_finance_payments')->insert($payment);
$before = $service->financialSnapshot();
$history = $service->historySnapshot();
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate') === 0, 'default command preflight is eligible');
runnerCheck($before === $service->financialSnapshot() && $history === $service->historySnapshot(), 'read-only default leaves data and history untouched');
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1, 'open write fence rejects execution');
runnerCheck(!Schema::connection('mysql')->hasTable(P0::IDENTITIES), 'open-fence rejection precedes DDL');

// Only deployment evidence is simulated. Actual queue, target, schema,
// preservation-receipt and lock checks run through the real ReleaseGate class.
// Paths below are validation fixtures, never filesystem/network destinations.
$proof = new class extends UnidentifiedDepositP0ReleaseGate {
    public bool $activated = false;
    public bool $activationFailed = false;
    protected function assertDeployment(string $phase): array
    {
        $sha = str_repeat('a', 40);
        $candidate = '/www/wwwroot/releases/synthetic-p0-candidate';
        $active = $this->activated ? $candidate : '/www/wwwroot/releases/synthetic-baseline';
        $activeSha = $this->activated ? $sha : self::BASELINE;
        $now = time();
        $e = ['version' => 1, 'generated_at' => $now, 'phase' => $phase,
            'candidate_sha' => $sha, 'candidate_path' => $candidate, 'active_sha' => $activeSha,
            'active_path' => $active, 'baseline_sha' => self::BASELINE, 'migration_sha256' => P0::HASH,
            'backup' => ['recovery_set' => 'synthetic-proof-only', 'sha256' => str_repeat('b', 64), 'completed_at' => $now, 'remote_verified_objects' => 12],
            'runtime' => ['fpm_probe' => ['release' => $candidate, 'sha' => $this->activationFailed ? self::BASELINE : $sha, 'sapi' => 'fpm-fcgi', 'php_version' => '8.3.30'], 'processes' => []]];
        foreach (['fpm_master', 'fpm_worker', 'queue', 'websocket'] as $i => $role) $e['runtime']['processes'][] = [
            'role' => $role, 'pid' => $i + 1, 'start_ticks' => '123', 'exe' => '/synthetic/php83',
            'cwd' => '/', 'release_path' => $candidate, 'command_sha256' => str_repeat('c', 64), 'php_version' => '8.3.30',
        ];
        self::validateEvidence($e, $phase, $candidate, $active, $sha, $activeSha, ['commit_sha' => $sha, 'release_name' => basename($candidate)], ['commit_sha' => $activeSha], $now);
        return $e;
    }
};
$app->instance(UnidentifiedDepositP0ReleaseGate::class, $proof);
$gate = app(UnidentifiedDepositP0WriteGate::class);
$job = ['queue' => 'synthetic-p0-rehearsal', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()];
$jobId = $db->table('jobs')->insertGetId($job);
try { $gate->enable(); throw new LogicException('Queued work allowed the fence to close.'); }
catch (RuntimeException $error) { runnerCheck(str_contains($error->getMessage(), 'zero queued') && $gate->inspect()['latch'] === 'absent', 'fault before close: queued work leaves baseline and latch untouched'); }
$db->table('jobs')->where('id', $jobId)->delete();
runnerCheck($gate->enable()['closed'], 'all database-native writer triggers installed');
$jobId = $db->table('jobs')->insertGetId($job);
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1 && $gate->assertClosed()['closed'] && !Schema::connection('mysql')->hasTable(P0::IDENTITIES), 'fault after close: newly queued work prevents DDL and preserves closed fence');
$db->table('jobs')->where('id', $jobId)->delete();
$exit = Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]);
fwrite(STDOUT, Artisan::output());
runnerCheck($exit === 0 && $service->inspect()['state'] === 'complete', 'exact runner applies and verifies full schema/history/backfill');
runnerCheck($db->table('migrations')->where('migration', P0::MIGRATION)->count() === 1, 'exact migration history recorded once');
runnerCheck($before === $service->financialSnapshot() && $history === $service->historySnapshot(), 'every pre-existing finance row and unrelated migration history preserved');
runnerCheck($db->table(P0::IDENTITIES)->where('identity_namespace', 'historical_qa')->count() === 1 && $db->table(P0::IDENTITIES)->where('identity_namespace', 'bank_reference')->count() === 1, 'historical QA and bank-reference namespaces remain distinct');
$completeHistory = $db->table('migrations')->orderBy('id')->get()->toJson();
$completeIdentity = $db->table(P0::IDENTITIES)->orderBy('id')->get()->toJson();
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 0, 'completed guarded retry is a no-op');
runnerCheck($completeHistory === $db->table('migrations')->orderBy('id')->get()->toJson() && $completeIdentity === $db->table(P0::IDENTITIES)->orderBy('id')->get()->toJson() && $before === $service->financialSnapshot(), 'retry preserves exact history, identities, and finance');
$receipt = $db->table(UnidentifiedDepositP0WriteGate::LATCH)->where('id', 1)->value('migration_receipt');
runnerCheck(is_string($receipt) && json_decode($receipt, true)['finance'] === $before, 'durable receipt contains verified pre-DDL financial snapshot');
// Negative checks alter only the newly created local operational latch, saving
// and restoring the exact verified receipt; no financial row is changed.
$db->table(UnidentifiedDepositP0WriteGate::LATCH)->where('id', 1)->update(['migration_receipt' => null]);
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1
    && $db->table(UnidentifiedDepositP0WriteGate::LATCH)->where('id', 1)->value('migration_receipt') === null, 'complete schema without receipt fails and never mints post-state evidence');
$tampered = json_decode($receipt, true, 512, JSON_THROW_ON_ERROR);
$tampered['finance']['central_finance_payments'] = str_repeat('0', 64);
$db->table(UnidentifiedDepositP0WriteGate::LATCH)->where('id', 1)->update(['migration_receipt' => json_encode($tampered, JSON_THROW_ON_ERROR)]);
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1, 'tampered preservation receipt fails closed');
$db->table(UnidentifiedDepositP0WriteGate::LATCH)->where('id', 1)->update(['migration_receipt' => $receipt]);
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 0 && $before === $service->financialSnapshot(), 'original verified receipt remains valid and finance stayed unchanged');
$db->beginTransaction();
try {
    $db->table('migrations')->insert(['migration' => P0::MIGRATION, 'batch' => 999]);
    runnerCheck($service->inspect()['state'] === 'unexpected' && Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1, 'duplicate exact history fails closed');
} finally { $db->rollBack(); }
runnerCheck($gate->assertClosed()['closed'], 'fence remains closed after success, retry and failure');
try { $gate->disable(); throw new LogicException('Pre-activation state reopened writers.'); }
catch (RuntimeException $error) { runnerCheck(str_contains($error->getMessage(), 'active release') && $gate->assertClosed()['closed'], 'post-migration pre-activation proof keeps fence closed'); }
$proof->activated = true;
$proof->activationFailed = true;
try { $gate->disable(); throw new LogicException('Failed activation reopened writers.'); }
catch (RuntimeException $error) { runnerCheck(str_contains($error->getMessage(), 'PHP-FPM') && $gate->assertClosed()['closed'], 'failed candidate runtime proof keeps fence closed'); }
$proof->activationFailed = false;
$opened = $gate->disable();
runnerCheck($opened['latch'] === 'open' && count($opened['present']) === $opened['expected_count'], 'validated synthetic activation proof opens one atomic latch and retains every trigger');
$db->beginTransaction();
try {
    runnerCheck($db->table('central_finance_payments')->where('id', 50)->update(['note' => 'synthetic reopened writer probe']) === 1, 'open latch admits ordinary disposable finance DML');
} finally { $db->rollBack(); }
runnerCheck($before === $service->financialSnapshot() && $completeHistory === $db->table('migrations')->orderBy('id')->get()->toJson(), 'reopened writer probe rolls back without any money or history change');

// A separate fresh fixture injects a database-level constraint collision. The
// pinned migration itself is unchanged; MySQL fails after its first DDL.
$faultDatabase = createRunnerFixture($pdo, $baseline, $historical, $baselineTables, $historicalTables);
config(['database.connections.mysql.database' => $faultDatabase]);
DB::purge('mysql');
$db = DB::connection('mysql');
$proof->activated = false;
Schema::connection('mysql')->create('synthetic_p0_ddl_fault', function (Illuminate\Database\Schema\Blueprint $t): void {
    $t->id(); $t->unsignedBigInteger('fund_account_id')->nullable();
    $t->foreign('fund_account_id', 'cfp_unidentified_deposit_fk')->references('id')->on('central_finance_fund_accounts')->restrictOnDelete();
});
runnerCheck($service->inspect()['state'] === 'eligible', 'separate DDL-fault fixture passes read-only inventory');
$faultBefore = $service->financialSnapshot();
$gate->enable();
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1, 'injected MySQL constraint collision fails during exact immutable DDL');
runnerCheck(Schema::connection('mysql')->hasTable(P0::IDENTITIES) && $service->schemaState() === 'unexpected', 'partial DDL is classified unexpected');
runnerCheck($db->table('migrations')->where('migration', P0::MIGRATION)->count() === 0 && $gate->migrationSuccessReceipt() === null && $gate->assertClosed()['closed'], 'during-DDL failure records no history or preservation receipt and leaves all writers blocked');
runnerCheck($faultBefore === $service->financialSnapshot(), 'during-DDL failure preserves original finance and audit rows');
runnerCheck(Artisan::call('finance:unidentified-deposit-p0-migrate', ['--execute' => true]) === 1 && $gate->assertClosed()['closed'], 'partial-schema retry remains fail-closed');
fwrite(STDOUT, 'P0 RUNNER REHEARSAL PASS; synthetic success schema '.$database.' OPEN, synthetic fault schema '.$faultDatabase." CLOSED; no Production connection.\n");

<?php

/**
 * Scoped maintenance, NOT a deployment or migration runner.
 * JSON approval/evidence arrives on STDIN, never through a URL or CLI secret.
 * An isolated reviewed bundle may use the current release's dependencies.
 */
declare(strict_types=1);

use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceHistoricalQaIdentityService;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !in_array($argv[2], ['--preflight', '--inventory', '--execute'], true)) {
        throw new RuntimeException('Usage: php reconcile_historical_qa_identity.php ABSOLUTE_RELEASE --preflight|--inventory|--execute < private-approval.json');
    }
    $approval = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    foreach (['expected_production_sha', 'scope', 'actor_id'] as $field) {
        if (!isset($approval[$field])) throw new RuntimeException('Missing approval field: '.$field);
    }
    if (!preg_match('/^[a-f0-9]{40}$/D', $approval['expected_production_sha']) || !is_array($approval['scope']) || (int) $approval['actor_id'] < 1) {
        throw new RuntimeException('Invalid scoped approval.');
    }
    $release = realpath($argv[1]);
    if ($release === false || !is_file($release.'/artisan')) throw new RuntimeException('Release unavailable.');
    $production = str_starts_with($release, '/www/wwwroot/releases/');
    $verifyRelease = function () use ($production, $release, $approval): void {
        if (!$production) return;
        if (realpath('/www/wwwroot/43.160.241.126') !== $release) throw new RuntimeException('Active release changed.');
        if (!function_exists('posix_geteuid') || posix_getpwuid(posix_geteuid())['name'] !== 'www') throw new RuntimeException('Use the www runtime user.');
        foreach (['.release-commit', '.release-manifest.json'] as $file) {
            $path = $release.'/'.$file;
            if (is_link($path) || !is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0022)) throw new RuntimeException('Untrusted release marker.');
        }
        if (trim(file_get_contents($release.'/.release-commit')) !== $approval['expected_production_sha']) throw new RuntimeException('Production SHA mismatch.');
        $manifest = json_decode(file_get_contents($release.'/.release-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['commit'] ?? $manifest['commit_sha'] ?? $manifest['sha'] ?? null) !== $approval['expected_production_sha']) throw new RuntimeException('Manifest SHA mismatch.');
    };
    $verifyRelease();
    require $release.'/vendor/autoload.php';
    require_once dirname(__DIR__, 2).'/app/Services/CentralFinanceHistoricalQaIdentityService.php';
    $app = require $release.'/bootstrap/app.php';
    // Resolve Laravel's real configuration before any provider can query a DB.
    (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
    (new Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
    if (!$production && !$app->environment(['local', 'testing'])) throw new RuntimeException('Nonlocal maintenance requires the immutable Production path.');
    if (!$production) {
        if (config('database.default') !== 'mysql') throw new RuntimeException('Disposable default connection must be mysql.');
        $connection = config('database.connections.mysql');
        if (($connection['driver'] ?? null) !== 'mysql'
            || !in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || (int) ($connection['port'] ?? 0) !== 3324
            || !preg_match('/^eschool_(?:testing|ud_[a-z0-9_]+)$/D', (string) ($connection['database'] ?? ''))
            || !empty($connection['url']) || !empty($connection['unix_socket'])
            || isset($connection['read']) || isset($connection['write'])) {
            throw new RuntimeException('Local execution requires the dedicated disposable MySQL fixture; refusing unknown database target.');
        }
        $tenantConnection = config('database.connections.school');
        if (($tenantConnection['driver'] ?? null) !== 'mysql'
            || !in_array($tenantConnection['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || (int) ($tenantConnection['port'] ?? 0) !== 3324
            || !empty($tenantConnection['url']) || !empty($tenantConnection['unix_socket'])
            || isset($tenantConnection['read']) || isset($tenantConnection['write'])
            || !in_array($tenantConnection['database'] ?? '', ['', 'school_testing'], true)) {
            throw new RuntimeException('Disposable tenant connection is not isolated.');
        }
    }
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('mysql');
    if (!$production) {
        $server = $db->selectOne('SELECT @@port AS port, @@datadir AS datadir');
        if ((int) $server->port !== 3324 || !preg_match('#^/private/tmp/unidentified-p0-mysql\.[A-Za-z0-9]+/$#D', $server->datadir)) {
            throw new RuntimeException('Disposable MySQL server identity mismatch.');
        }
    }
    $service = app(CentralFinanceHistoricalQaIdentityService::class);
    $financialFreeze = function () use ($db): array {
        $result = [];
        // Hash only; no financial rows, tokens or personal data leave the process.
        foreach (['payments', 'payment_allocations', 'receipts', 'ledger_entries', 'pending_collections', 'receivables', 'fund_accounts', 'data_classifications', 'data_classification_audits', 'qa_run_records'] as $suffix) {
            $table = 'central_finance_'.$suffix;
            $hash = hash_init('sha256'); $count = 0;
            foreach ($db->table($table)->orderBy('id')->cursor() as $row) {
                hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR)."\n"); $count++;
            }
            $result[$suffix] = ['count' => $count, 'sha256' => hash_final($hash)];
        }
        return $result;
    };
    if ($argv[2] === '--inventory') {
        // Section 15 read-only evidence only. This output is NOT a deployment
        // gate approval; schema and Pending/Import findings need separate review.
        if ($db->getDriverName() === 'mysql') $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            require_once dirname(__DIR__, 2).'/app/Services/CentralFinanceBankTransactionIdentityService.php';
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_07_000001_close_unidentified_deposit_p0.php';
            $inventory = new ReflectionMethod($migration, 'historicalIdentities');
            $identities = $inventory->invoke($migration); // SELECT-only helper, never up/down.
            $counts = array_count_values(array_column($identities, 'identity_namespace'));
            $historical = array_values(array_filter($identities, fn ($row) => $row['identity_namespace'] === 'historical_qa'));
            $target = $db->table('central_finance_payments')->where('id', (int) $approval['scope']['payment_id'])->first();
            if ($target === null) throw new RuntimeException('Approved payment no longer exists.');
            $onlyApprovedHistorical = count($historical) === 1 && $historical[0]['source_type'] === 'payment' && $historical[0]['source_id'] === $target->idempotency_key;
            $schema = Illuminate\Support\Facades\Schema::connection('mysql');
            $schemaFlags = ['identity_table_present' => $schema->hasTable('central_finance_bank_transaction_identities'),
                'migration_record_present' => $db->table('migrations')->where('migration', '2026_10_07_000001_close_unidentified_deposit_p0')->exists()];
            foreach (['unidentified_deposits' => ['request_hash', 'manual_identity', 'manual_reason'], 'unidentified_deposit_allocations' => ['request_hash', 'payment_id'], 'payments' => ['request_hash', 'unidentified_deposit_id']] as $suffix => $columns) {
                foreach ($columns as $column) $schemaFlags[$suffix.'.'.$column] = $schema->hasColumn('central_finance_'.$suffix, $column);
            }
            $verifyRelease();
        } finally { $db->rollBack(); }
        echo json_encode(['mode' => 'read_only_inventory', 'identity_counts' => $counts,
            'only_approved_historical_source' => $onlyApprovedHistorical, 'schema_observations' => $schemaFlags,
            'migration_executed' => false, 'deployment_authorized' => false], JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT), "\n";
    } elseif ($argv[2] === '--preflight') {
        if ($db->getDriverName() === 'mysql') $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            $preview = $service->preview($approval['scope']);
            $freeze = $financialFreeze();
            $verifyRelease();
        } finally { $db->rollBack(); }
        echo json_encode(['mode' => 'read_only', 'preview' => $preview, 'financial_freeze' => $freeze], JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT), "\n";
    } else {
        if (!preg_match('/^[a-f0-9]{64}$/D', (string) ($approval['expected_evidence_hash'] ?? ''))
            || empty($approval['verified_backup_recovery_set']) || empty($approval['approval_reference'])) {
            throw new RuntimeException('Execution requires reviewed evidence, backup and approval references.');
        }
        if ($db->getDriverName() === 'mysql') $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $result = $db->transaction(function () use ($db, $service, $approval, $financialFreeze, $verifyRelease): array {
            $verifyRelease();
            // Current locked evidence precedes every financial snapshot read.
            $service->lockedPreview($approval['scope']);
            $before = $financialFreeze();
            $actor = CentralFinanceUser::on('mysql')->findOrFail((int) $approval['actor_id']);
            $audit = $service->reconcile($actor, $approval['scope'], $approval['expected_evidence_hash']);
            $after = $financialFreeze();
            if ($before !== $after) throw new RuntimeException('Financial/classification/Run freeze mismatch; audit rolled back.');
            $verifyRelease();
            return ['audit_id' => $audit->id, 'actor_id' => $actor->id, 'financial_state_unchanged' => true,
                'financial_freeze' => $after, 'identity' => $service->verifiedIdentityForPayment((int) $approval['scope']['payment_id'])];
        });
        echo json_encode(['mode' => 'execute', 'result' => $result], JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT), "\n";
    }
} catch (Throwable $e) {
    // Never echo database errors, SQL bindings, environment or credential data.
    fwrite(STDERR, json_encode(['result' => 'BLOCKED', 'error_class' => get_class($e), 'message' => $e instanceof RuntimeException && !($e instanceof PDOException) && !($e instanceof Illuminate\Database\QueryException) ? $e->getMessage() : 'Maintenance validation failed.'])."\n");
    exit(1);
}

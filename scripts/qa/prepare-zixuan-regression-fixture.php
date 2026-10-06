<?php

declare(strict_types=1);

/**
 * Prepare the fixed disposable MySQL fixtures used by the local Zixuan QA Run
 * regression. This is not a migration and must never target a shared database.
 */
$expected = [
    'QA_RUN_REGRESSION_FIXTURE' => '1',
    'APP_ENV' => 'testing',
    'APP_URL' => 'http://127.0.0.1',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3317',
    'DB_DATABASE' => 'eschool_testing',
    'DB_SCHOOL_DATABASE' => 'school_testing',
];

foreach ($expected as $name => $value) {
    if (getenv($name) !== $value) {
        fwrite(STDERR, "Refusing fixture preparation: {$name} must be {$value}.\n");
        exit(2);
    }
}

$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3317;dbname=eschool_testing;charset=utf8mb4',
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$server = $pdo->query('SELECT @@port AS port, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
if ((int) ($server['port'] ?? 0) !== 3317
    || !str_starts_with((string) ($server['datadir'] ?? ''), '/private/tmp/qa-run-regression-mysql/')) {
    fwrite(STDERR, "Refusing fixture preparation: MySQL is not the dedicated disposable instance.\n");
    exit(3);
}

$centralSchool = $pdo->query("SELECT database_name FROM schools WHERE id = 1")->fetchColumn();
if ($centralSchool !== 'school_testing') {
    fwrite(STDERR, "Refusing fixture preparation: test Central School 1 is not bound to school_testing.\n");
    exit(4);
}

$tenant = new PDO(
    'mysql:host=127.0.0.1;port=3317;dbname=school_testing;charset=utf8mb4',
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$columns = [
    'fees' => [
        'total_compulsory_fees' => '`total_compulsory_fees` DECIMAL(12,2) NULL',
    ],
    'fees_class_types' => [
        'fee_currency' => "`fee_currency` VARCHAR(3) NOT NULL DEFAULT 'MMK'",
        'fee_original_amount' => '`fee_original_amount` DECIMAL(12,2) NOT NULL DEFAULT 0',
        'fee_exchange_rate_snapshot' => '`fee_exchange_rate_snapshot` DECIMAL(12,4) NOT NULL DEFAULT 1.0000',
        'fee_amount_mmk' => '`fee_amount_mmk` DECIMAL(12,2) NOT NULL DEFAULT 0',
    ],
];

foreach ($columns as $table => $definitions) {
    foreach ($definitions as $name => $definition) {
        $query = $tenant->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $query->execute([$table, $name]);
        if (!$query->fetchColumn()) {
            $tenant->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        }
    }
}

$tenant->beginTransaction();
try {
    $tenant->exec('UPDATE `session_years` SET `default` = 0 WHERE `school_id` = 1');
    $query = $tenant->prepare('SELECT 1 FROM `session_years` WHERE `id` = 1');
    $query->execute();
    if ($query->fetchColumn()) {
        $update = $tenant->prepare(
            'UPDATE `session_years` SET `name` = ?, `default` = 1, `start_date` = ?, `end_date` = ?, `school_id` = 1, `updated_at` = NOW() WHERE `id` = 1'
        );
        $update->execute(['2025-2026', '2025-06-01', '2026-05-31']);
    } else {
        $insert = $tenant->prepare(
            'INSERT INTO `session_years` (`id`, `name`, `default`, `start_date`, `end_date`, `school_id`, `created_at`, `updated_at`) VALUES (1, ?, 1, ?, ?, 1, NOW(), NOW())'
        );
        $insert->execute(['2025-2026', '2025-06-01', '2026-05-31']);
    }
    $tenant->commit();
} catch (Throwable $error) {
    if ($tenant->inTransaction()) {
        $tenant->rollBack();
    }
    throw $error;
}

$actual = $tenant->query('SELECT `id`, `name`, `default`, `school_id` FROM `session_years` WHERE `id` = 1')->fetch(PDO::FETCH_ASSOC);
if ($actual !== ['id' => 1, 'name' => '2025-2026', 'default' => 1, 'school_id' => 1]) {
    fwrite(STDERR, "Fixture verification failed for the default academic year.\n");
    exit(5);
}

echo "Zixuan regression fixture ready on dedicated localhost:3317: Central and tenant targets verified; fee schema and default year normalized.\n";

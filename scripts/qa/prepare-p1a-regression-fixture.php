<?php

declare(strict_types=1);

$expected = [
    'P1A_REGRESSION_FIXTURE' => '1',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3318',
    'DB_DATABASE' => 'eschool_testing',
    'DB_SCHOOL_DATABASE' => 'school_testing',
];
foreach ($expected as $name => $value) {
    if (getenv($name) !== $value) {
        fwrite(STDERR, "Refusing fixture preparation: {$name} must be {$value}.\n");
        exit(2);
    }
}

$password = getenv('DB_PASSWORD') ?: '';
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3318;dbname=school_testing;charset=utf8mb4',
    getenv('DB_USERNAME') ?: 'root',
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
        $query = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $query->execute([$table, $name]);
        if (!$query->fetchColumn()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        }
    }
}

$years = [
    1 => ['2025-2026', 1, '2025-06-01', '2026-05-31'],
];
$pdo->beginTransaction();
try {
    $pdo->exec('UPDATE `session_years` SET `default`=0 WHERE `school_id`=1');
    foreach ($years as $id => [$name, $default, $start, $end]) {
        $statement = $pdo->prepare('UPDATE `session_years` SET `name`=?, `default`=?, `start_date`=?, `end_date`=?, `school_id`=1, `updated_at`=NOW() WHERE `id`=?');
        $statement->execute([$name, $default, $start, $end, $id]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM `session_years` WHERE `id`=?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                $insert = $pdo->prepare('INSERT INTO `session_years` (`id`,`name`,`default`,`start_date`,`end_date`,`school_id`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,1,NOW(),NOW())');
                $insert->execute([$id, $name, $default, $start, $end]);
            }
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

$actual = $pdo->query('SELECT `id`,`name`,`default`,`school_id` FROM `session_years` WHERE `id`=1')->fetchAll(PDO::FETCH_ASSOC);
$actual = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'name' => (string) $row['name'],
    'default' => (int) $row['default'],
    'school_id' => (int) $row['school_id'],
], $actual);
if ($actual !== [
    ['id' => 1, 'name' => '2025-2026', 'default' => 1, 'school_id' => 1],
]) {
    fwrite(STDERR, "Fixture verification failed for the default academic years.\n");
    exit(3);
}

echo "P1-A isolated regression fixture ready: fee compatibility columns present; default years verified.\n";

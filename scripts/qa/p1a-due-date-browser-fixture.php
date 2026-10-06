<?php

declare(strict_types=1);

$expected = [
    'P1A_DUE_DATE_BROWSER' => '1',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3318',
    'DB_DATABASE' => 'eschool_testing',
    'DB_SCHOOL_DATABASE' => 'eschool_local_bowen_qa',
];
foreach ($expected as $key => $value) {
    if (getenv($key) !== $value) {
        fwrite(STDERR, "Refusing P1-A browser fixture outside its disposable localhost databases: {$key}.\n");
        exit(2);
    }
}

$action = $argv[1] ?? '';
$stateFile = sys_get_temp_dir().'/p1a-due-date-browser-db-name.txt';
$pdoFor = static fn (string $database): PDO => new PDO(
    "mysql:host=127.0.0.1;port=3318;dbname={$database};charset=utf8mb4",
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$central = $pdoFor('eschool_testing');
$tenant = $pdoFor('eschool_local_bowen_qa');
$school = $central->query("SELECT id, database_name FROM schools WHERE code='BOWEN_QA' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$school || $school['database_name'] !== 'eschool_local_bowen_qa') {
    fwrite(STDERR, "Canonical BOWEN_QA School is missing from the disposable central database.\n");
    exit(3);
}

if ($action === 'prepare') {
    $suffix = bin2hex(random_bytes(4));
    $name = 'P1A Browser Due Date '.$suffix;
    $now = date('Y-m-d H:i:s');
    $schoolId = (int) $school['id'];
    $tenant->beginTransaction();
    try {
    $feeType = $tenant->prepare('INSERT INTO fees_types (name, school_id, created_at, updated_at) VALUES (?,?,?,?)');
    $feeType->execute([$name.' Type', $schoolId, $now, $now]);
    $feeTypeId = (int) $tenant->lastInsertId();
    $fee = $tenant->prepare('INSERT INTO fees (name, due_date, due_charges, due_charges_amount, class_id, school_id, session_year_id, created_at, updated_at) VALUES (?,NULL,0,0,1,?,1,?,?)');
    $fee->execute([$name, $schoolId, $now, $now]);
    $feeId = (int) $tenant->lastInsertId();
    $item = $tenant->prepare('INSERT INTO fees_class_types (class_id, fees_id, fees_type_id, amount, optional, school_id, created_at, updated_at) VALUES (1,?,?,500,0,?,?,?)');
    $item->execute([$feeId, $feeTypeId, $schoolId, $now, $now]);

    $editorEmail = 'qa_head_finance@bowen-qa.test';
    $editorQuery = $tenant->prepare('SELECT u.id FROM users u JOIN model_has_roles m ON m.model_id=u.id AND m.model_type=? JOIN roles r ON r.id=m.role_id WHERE u.email=? AND r.name=? AND r.school_id=? LIMIT 1');
    $editorQuery->execute(['App\\Models\\User', $editorEmail, 'Head Finance', $schoolId]);
    $editorId = (int) $editorQuery->fetchColumn();
    if ($editorId < 1) {
        throw new RuntimeException('The established synthetic BOWEN_QA Head Finance user is missing.');
    }

    $permissionQuery = $tenant->prepare('SELECT id FROM permissions WHERE name=? AND guard_name=?');
    $permissionQuery->execute(['fees-edit', 'web']);
    $permissionId = $permissionQuery->fetchColumn();
    $createdPermission = false;
    if (!$permissionId) {
        $permissionInsert = $tenant->prepare('INSERT INTO permissions (name, guard_name, created_at, updated_at) VALUES (?,?,?,?)');
        $permissionInsert->execute(['fees-edit', 'web', $now, $now]);
        $permissionId = (int) $tenant->lastInsertId();
        $createdPermission = true;
    }
    $grant = $tenant->prepare('SELECT 1 FROM model_has_permissions WHERE permission_id=? AND model_type=? AND model_id=?');
    $grant->execute([$permissionId, 'App\\Models\\User', $editorId]);
    $addedGrant = !$grant->fetchColumn();
    if ($addedGrant) {
        $tenant->prepare('INSERT INTO model_has_permissions (permission_id, model_type, model_id) VALUES (?,?,?)')
            ->execute([$permissionId, 'App\\Models\\User', $editorId]);
    }

    $tenant->commit();

    $state = [
        'original_database_name' => (string) $school['database_name'],
        'school_id' => (int) $school['id'],
        'editor_id' => $editorId,
        'editor_email' => $editorEmail,
        'permission_id' => (int) $permissionId,
        'created_permission' => $createdPermission,
        'added_grant' => $addedGrant,
    ];
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    } catch (Throwable $error) {
        if ($tenant->inTransaction()) {
            $tenant->rollBack();
        }
        throw $error;
    }

    echo json_encode(['fee_id' => $feeId, 'fee_type_id' => $feeTypeId, 'name' => $name, 'editor_email' => $editorEmail], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($action === 'verify') {
    $feeId = (int) ($argv[2] ?? 0);
    $row = $tenant->query('SELECT due_date FROM fees WHERE id='.(int) $feeId.' AND name LIKE \'P1A Browser Due Date %\'')->fetch(PDO::FETCH_ASSOC);
    $count = $tenant->query('SELECT COUNT(*) FROM fees WHERE id='.(int) $feeId)->fetchColumn();
    if (!$row || (int) $count !== 1 || $row['due_date'] !== null) {
        fwrite(STDERR, "Browser Fee fixture did not remain a single undated Fee after reload/clear.\n");
        exit(4);
    }
    echo "Verified one Fee row remains and its due_date is SQL NULL.\n";
    exit(0);
}

if ($action === 'cleanup') {
    $feeId = (int) ($argv[2] ?? 0);
    if ($feeId < 1) {
        fwrite(STDERR, "A positive synthetic Fee id is required for cleanup.\n");
        exit(5);
    }
    $references = [
        'fees_paids' => 'fees_id',
        'student_fee_assignment_items' => 'fee_id',
    ];
    foreach ($references as $table => $column) {
        $tableExists = $tenant->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $tableExists->execute(['eschool_local_bowen_qa', $table]);
        if ($tableExists->fetchColumn()) {
            $check = $tenant->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");
            $check->execute([$feeId]);
            if ((int) $check->fetchColumn() !== 0) {
                fwrite(STDERR, "Refusing cleanup: the synthetic Fee unexpectedly has a {$table} reference.\n");
                exit(6);
            }
        }
    }
    $feeTypeId = $tenant->prepare('SELECT fees_type_id FROM fees_class_types WHERE fees_id=?');
    $feeTypeId->execute([$feeId]);
    $typeId = $feeTypeId->fetchColumn();
    $tenant->prepare('DELETE FROM fees_class_types WHERE fees_id=?')->execute([$feeId]);
    $tenant->prepare('DELETE FROM fees_installments WHERE fees_id=?')->execute([$feeId]);
    $tenant->prepare('DELETE FROM fees WHERE id=? AND name LIKE \'P1A Browser Due Date %\'')->execute([$feeId]);
    if ($typeId) {
        $tenant->prepare('DELETE FROM fees_types WHERE id=? AND name LIKE \'P1A Browser Due Date % Type\'')->execute([(int) $typeId]);
    }
    if (is_file($stateFile)) {
        $stateContents = trim((string) file_get_contents($stateFile));
        $state = json_decode($stateContents, true);
        if (is_array($state)) {
            if (!empty($state['added_grant'])) {
                $tenant->prepare('DELETE FROM model_has_permissions WHERE permission_id=? AND model_type=? AND model_id=?')
                    ->execute([(int) $state['permission_id'], 'App\\Models\\User', (int) $state['editor_id']]);
            }
            if (!empty($state['created_permission'])) {
                $tenant->prepare('DELETE FROM permissions WHERE id=? AND name=? AND guard_name=?')
                    ->execute([(int) $state['permission_id'], 'fees-edit', 'web']);
            }
            $originalDatabase = (string) ($state['original_database_name'] ?? '');
        } else {
            $originalDatabase = $stateContents;
        }
        if ($originalDatabase !== 'eschool_local_bowen_qa') {
            fwrite(STDERR, "Refusing cleanup because the saved BOWEN_QA tenant mapping is unexpected.\n");
            exit(7);
        }
        unlink($stateFile);
    }
    echo "Removed only the synthetic P1-A Fee fixture; the canonical BOWEN_QA mapping was not changed.\n";
    exit(0);
}

fwrite(STDERR, "Usage: php p1a-due-date-browser-fixture.php prepare|verify <fee-id>|cleanup <fee-id>\n");
exit(64);

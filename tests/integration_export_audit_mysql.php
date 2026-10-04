<?php
/** Проверка журнала выгрузок на одноразовой MySQL из CI. */
$host = getenv('TEST_DB_HOST');
if (!$host) {
    echo "SKIP: TEST_DB_HOST не задан\n";
    exit(0);
}

$dbName = getenv('TEST_DB_NAME') ?: 'dashboard_test';
if ($dbName !== 'dashboard_test') {
    fwrite(STDERR, "Export audit test runs only against dashboard_test\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(
    $host,
    getenv('TEST_DB_USER') ?: 'root',
    getenv('TEST_DB_PASS') ?: '',
    $dbName,
    (int)(getenv('TEST_DB_PORT') ?: 3306)
);
if ($db->connect_errno) {
    fwrite(STDERR, "MySQL connection failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');
$db->query('DROP TABLE IF EXISTS export_audit');

$_SESSION = ['username' => 'auditor'];
$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
$_SERVER['HTTP_USER_AGENT'] = 'Export audit test';
require_once __DIR__ . '/../includes/ExportAudit.php';

try {
    $event = new ExportAudit($db, 'accounts', 'csv', 'file', 'selected', ['id', 'login'], ['sale']);
    $event->addRow(['status' => 'sale']);
    $event->addRow(['status' => 'sale']);
    $event->addRow(['status' => 'pending']);
    $event->complete();

    $row = $db->query('SELECT * FROM export_audit ORDER BY id DESC LIMIT 1')->fetch_assoc();
    if ($row['username'] !== 'auditor' || $row['ip_address'] !== '203.0.113.42'
        || $row['row_count'] !== '3' || $row['state'] !== 'completed'
        || json_decode($row['status_counts_json'], true) !== ['sale' => 2, 'pending' => 1]
        || json_decode($row['columns_json'], true) !== ['id', 'login']) {
        throw new RuntimeException('Completed export audit content differs from exported rows');
    }

    $interrupted = new ExportAudit($db, 'accounts', 'txt', 'rows', 'custom', ['login']);
    $interrupted->addRow(['status' => 'sale']);
    unset($interrupted);
    $row = $db->query('SELECT row_count, state FROM export_audit ORDER BY id DESC LIMIT 1')->fetch_assoc();
    if ($row['row_count'] !== '1' || $row['state'] !== 'interrupted') {
        throw new RuntimeException('Interrupted request was recorded as completed');
    }

    echo "PASS: export audit stores actor, IP, actual statuses and interrupted state\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
} finally {
    $db->query('DROP TABLE IF EXISTS export_audit');
    $db->close();
}

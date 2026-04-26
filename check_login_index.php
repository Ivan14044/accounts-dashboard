<?php
/**
 * Проверка критичных индексов по slow log (login, status + deleted_at).
 * Запуск: php check_login_index.php
 *
 * Медленные запросы:
 * - WHERE login = 97693... → нужен idx_login + в приложении передавать login как строку.
 * - WHERE status IN (...) AND deleted_at IS NULL ORDER BY id → нужен idx_deleted_status_id.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAuth();

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    echo "Ошибка: нет подключения к БД. Проверьте config.php.\n";
    exit(1);
}

$table = 'accounts';
$criticalIndexes = [
    'idx_login' => 'accounts(login)',
    'idx_deleted_status_id' => 'accounts(deleted_at, status, id)'
];

echo "========================================\n";
echo "  Проверка критичных индексов (slow log)\n";
echo "========================================\n\n";

$res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
if (!$res || $res->num_rows === 0) {
    echo "Таблица '$table' не найдена.\n";
    exit(0);
}

$missing = [];
foreach ($criticalIndexes as $name => $spec) {
    $result = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $mysqli->real_escape_string($name) . "'");
    if ($result && $result->num_rows > 0) {
        echo "  [OK] $name\n";
    } else {
        echo "  [--] $name отсутствует\n";
        $missing[$name] = $spec;
    }
}

if (count($missing) > 0) {
    echo "\nСоздайте недостающие индексы.\n";
    echo "Вариант 1 — выполнить SQL вручную (phpMyAdmin / mysql):\n";
    echo "  Файл: sql/critical_indexes_slow_log.sql\n\n";
    foreach ($missing as $name => $spec) {
        $cols = preg_match('#\((.+)\)$#', $spec, $m) ? $m[1] : 'login';
        echo "  CREATE INDEX $name ON $table($cols);\n";
    }
    echo "\nВариант 2 — полный набор индексов:\n";
    echo "  php apply_indexes_safe.php\n\n";
}

echo "---\n";
echo "Запросы WHERE login = число (без кавычек) индекс не используют.\n";
echo "В приложении, которое выполняет запрос (например accountfactory), нужно передавать login как строку: bind_param('s', \$login).\n";
exit(0);

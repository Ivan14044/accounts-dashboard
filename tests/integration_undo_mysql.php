<?php
/**
 * Интеграционный тест undo на РЕАЛЬНОМ MySQL (полный путь:
 * AccountsService → AuditLogger/user_actions → UndoService).
 *
 * Без TEST_DB_* тест тихо скипается (exit 0), чтобы не ломать CI без БД.
 *
 * Запуск через Docker:
 *   docker network create undo-test-net
 *   docker run -d --rm --name undo-test-mysql --network undo-test-net \
 *     -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=undodb mysql:8
 *   docker run --rm --network undo-test-net -v "$PWD":/app -w /app \
 *     -e TEST_DB_HOST=undo-test-mysql -e TEST_DB_USER=root \
 *     -e TEST_DB_PASS=test -e TEST_DB_NAME=undodb \
 *     php:8.2-cli sh -c 'docker-php-ext-install mysqli >/dev/null 2>&1 && php tests/integration_undo_mysql.php'
 *
 * Проверяет:
 *  - авто-миграцию старой account_history (добавление action_id/table_name);
 *  - откат массовой смены статуса с пропуском конфликтов (правка после действия);
 *  - блокировку повторного отката;
 *  - откат удаления в корзину (частично восстановленные строки пропускаются);
 *  - восстановление NULL для nullable-колонок;
 *  - пропуск чувствительных полей;
 *  - что действие-undo не предлагается к отмене;
 *  - изоляцию таблиц (действие в одной таблице не трогает другую);
 *  - аудит самого отката в account_history.
 */

$host = getenv('TEST_DB_HOST');
if (!$host) {
    echo "SKIP: TEST_DB_HOST не задан — интеграционный тест пропущен\n";
    exit(0);
}
$dbUser = getenv('TEST_DB_USER') ?: 'root';
$dbPass = getenv('TEST_DB_PASS') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: 'undodb';
$dbPort = (int)(getenv('TEST_DB_PORT') ?: 3306);

$_SESSION = ['username' => 'tester'];

// Прод работает без mysqli-исключений (код рассчитан на «prepare → false → fallback»,
// например ColumnMetadata). В PHP >= 8.1 отчёт по умолчанию STRICT — выключаем,
// чтобы тест воспроизводил реальное поведение.
mysqli_report(MYSQLI_REPORT_OFF);

// Глобальное подключение — Database::getInstance() подхватит его (как config.php)
$mysqli = new mysqli($host, $dbUser, $dbPass, $dbName, $dbPort);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "FAIL: не удалось подключиться к MySQL: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$failures = 0;
$passed   = 0;

function ok(string $name, bool $cond, string $detail = ''): void {
    global $failures, $passed;
    if ($cond) { $passed++; echo "  ✓ $name\n"; }
    else       { $failures++; fwrite(STDERR, "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"); }
}

function fetchOne(mysqli $db, string $sql) {
    $res = $db->query($sql);
    $row = $res ? $res->fetch_row() : null;
    return $row ? $row[0] : null;
}

// --- Чистая схема + СТАРЫЙ формат account_history (проверяем авто-миграцию) ---
$mysqli->query("DROP TABLE IF EXISTS accounts");
$mysqli->query("DROP TABLE IF EXISTS accounts_second");
$mysqli->query("DROP TABLE IF EXISTS account_history");
$mysqli->query("DROP TABLE IF EXISTS user_actions");

$mysqli->query("CREATE TABLE account_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    field_name VARCHAR(255) NOT NULL,
    old_value TEXT,
    new_value TEXT,
    changed_by VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    INDEX idx_account_id (account_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$accountsDdl = "CREATE TABLE %s (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(100) NOT NULL DEFAULT '',
    note TEXT NULL,
    password VARCHAR(255) NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$mysqli->query(sprintf($accountsDdl, 'accounts'));
$mysqli->query(sprintf($accountsDdl, 'accounts_second'));

for ($i = 1; $i <= 6; $i++) {
    $mysqli->query("INSERT INTO accounts (login, status) VALUES ('user$i', 'new')");
}
$mysqli->query("INSERT INTO accounts_second (login, status) VALUES ('other1', 'fresh')");

require_once __DIR__ . '/../includes/AccountsService.php';
require_once __DIR__ . '/../includes/UndoService.php';

$svc  = new AccountsService('accounts');
$undo = new UndoService();

echo "Интеграционный тест undo (MySQL " . $mysqli->server_info . "):\n";

// Миграция выполняется при первой инициализации AuditLogger
AuditLogger::getInstance();

// 1. Авто-миграция старой account_history
$col = $mysqli->query("SHOW COLUMNS FROM account_history LIKE 'action_id'");
ok('авто-миграция: action_id добавлен в старую account_history', $col && $col->num_rows === 1);
$col = $mysqli->query("SHOW COLUMNS FROM account_history LIKE 'table_name'");
ok('авто-миграция: table_name добавлен', $col && $col->num_rows === 1);
ok('user_actions создана', (bool)fetchOne($mysqli, "SHOW TABLES LIKE 'user_actions'"));

// 2. Массовая смена статуса + конфликт + откат
$affected = $svc->updateStatus([1, 2, 3], 'sold');
ok('updateStatus затронул 3 строки', $affected === 3, "affected=$affected");

$last = $undo->getLastUndoableAction('tester');
ok('последнее действие — update_status/3', $last && $last['action_type'] === 'update_status' && $last['affected_count'] === 3,
    json_encode($last, JSON_UNESCAPED_UNICODE));

// Правка ПОСЛЕ действия — при откате строку 3 трогать нельзя
$mysqli->query("UPDATE accounts SET status = 'hold' WHERE id = 3");

$report = $undo->undoAction($last['id'], 'tester');
ok('откат: reverted=2, conflict=1', $report['reverted'] === 2 && $report['skipped_conflict'] === 1,
    json_encode($report, JSON_UNESCAPED_UNICODE));
ok('строки 1,2 вернулись к new', fetchOne($mysqli, "SELECT status FROM accounts WHERE id=1") === 'new'
    && fetchOne($mysqli, "SELECT status FROM accounts WHERE id=2") === 'new');
ok('строка 3 осталась hold (поздняя правка не затёрта)', fetchOne($mysqli, "SELECT status FROM accounts WHERE id=3") === 'hold');

// 3. Повторный откат заблокирован
try {
    $undo->undoAction($last['id'], 'tester');
    ok('повторный откат заблокирован', false, 'исключение не выброшено');
} catch (Exception $e) {
    ok('повторный откат заблокирован', strpos($e->getMessage(), 'уже отменено') !== false, $e->getMessage());
}

// 4. Чужое действие недоступно
try {
    $undo->undoAction($last['id'], 'another_user');
    ok('чужое действие недоступно', false, 'исключение не выброшено');
} catch (Exception $e) {
    ok('чужое действие недоступно', true);
}

// 5. Действие undo не предлагается к отмене; журнал отката записан
$lastAfterUndo = $undo->getLastUndoableAction('tester');
ok('после отката нет отменяемых действий (undo не в счёт)', $lastAfterUndo === null,
    json_encode($lastAfterUndo, JSON_UNESCAPED_UNICODE));
$undoRows = (int)fetchOne($mysqli,
    "SELECT COUNT(*) FROM account_history h JOIN user_actions a ON h.action_id = a.id WHERE a.action_type = 'undo'");
ok('аудит отката записан в account_history', $undoRows === 2, "rows=$undoRows");

// 6. Удаление в корзину + частичное ручное восстановление + откат
$svc->deleteAccounts([4, 5]);
ok('строки 4,5 в корзине', fetchOne($mysqli, "SELECT COUNT(*) FROM accounts WHERE id IN (4,5) AND deleted_at IS NOT NULL") === '2');
$mysqli->query("UPDATE accounts SET deleted_at = NULL WHERE id = 5"); // руками восстановили

$last = $undo->getLastUndoableAction('tester');
ok('последнее действие — delete', $last && $last['action_type'] === 'delete');
$report = $undo->undoAction($last['id'], 'tester');
ok('откат удаления: reverted=1, conflict=1', $report['reverted'] === 1 && $report['skipped_conflict'] === 1,
    json_encode($report, JSON_UNESCAPED_UNICODE));
ok('строка 4 восстановлена', fetchOne($mysqli, "SELECT deleted_at FROM accounts WHERE id=4") === null);

// 7. updateField: NULL восстанавливается как NULL (не '')
$svc->updateField(6, 'note', 'временная заметка');
$last = $undo->getLastUndoableAction('tester');
ok('последнее действие — update_field', $last && $last['action_type'] === 'update_field');
$report = $undo->undoAction($last['id'], 'tester');
ok('откат поля: reverted=1', $report['reverted'] === 1, json_encode($report, JSON_UNESCAPED_UNICODE));
$noteIsNull = fetchOne($mysqli, "SELECT note IS NULL FROM accounts WHERE id=6");
ok('note вернулась к NULL (не пустой строке)', $noteIsNull === '1', "IS NULL = $noteIsNull");

// 8. Чувствительное поле: действие есть, но значения не откатываются
$svc->updateField(6, 'password', 'secret123');
$last = $undo->getLastUndoableAction('tester');
ok('действие по password зафиксировано', $last && $last['action_type'] === 'update_field');
$report = $undo->undoAction($last['id'], 'tester');
ok('password: reverted=0, skipped_sensitive=1', $report['reverted'] === 0 && $report['skipped_sensitive'] === 1,
    json_encode($report, JSON_UNESCAPED_UNICODE));
ok('значение password не тронуто', fetchOne($mysqli, "SELECT password FROM accounts WHERE id=6") === 'secret123');

// 9. Изоляция таблиц: действие в accounts_second не трогает accounts
$svc2 = new AccountsService('accounts_second');
$svc2->updateStatus([1], 'archived');
$last = $undo->getLastUndoableAction('tester');
ok('действие привязано к accounts_second', $last && $last['table_name'] === 'accounts_second',
    json_encode($last, JSON_UNESCAPED_UNICODE));
// В account_history старое значение читалось из правильной таблицы (fix хардкода accounts)
$oldVal = fetchOne($mysqli,
    "SELECT old_value FROM account_history WHERE action_id = {$last['id']} LIMIT 1");
ok('старое значение из правильной таблицы (fresh, не из accounts)', $oldVal === 'fresh', "old_value=$oldVal");
$report = $undo->undoAction($last['id'], 'tester');
ok('откат в accounts_second: reverted=1', $report['reverted'] === 1);
ok('accounts_second вернулась к fresh', fetchOne($mysqli, "SELECT status FROM accounts_second WHERE id=1") === 'fresh');
ok('accounts не задета (id=1 всё ещё new)', fetchOne($mysqli, "SELECT status FROM accounts WHERE id=1") === 'new');

echo "\nИтог: passed={$passed}, failed={$failures}\n";
exit($failures > 0 ? 1 : 0);

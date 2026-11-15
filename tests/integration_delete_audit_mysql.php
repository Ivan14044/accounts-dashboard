<?php
/**
 * Удаление выбранных аккаунтов в корзину: журнал пишется пачкой, а не строкой
 * на каждый аккаунт. Тест на РЕАЛЬНОМ MySQL.
 *
 * Зачем. На проде удаление 1000 выбранных аккаунтов шло 45–57 секунд — 42 раза
 * подряд в системном журнале с 25.09 по 30.10.2026 («DELETE: Successfully
 * deleted accounts {"count":1000}», +45 000…+56 579 мс). Массовая смена статуса
 * на 13 625 строк в те же дни укладывалась в 7 секунд. Разница — в журнале:
 * AccountsService::deleteAccounts() звал AuditLogger::logChange() в цикле,
 * то есть отдельный INSERT (prepare + execute, свой коммит) на КАЖДЫЙ аккаунт.
 * На стенде одно удаление 1000 строк давало ~1035 обращений к базе.
 *
 * Заодно тест стережёт вторую грабли того же цикла: в журнал попадали ВСЕ
 * присланные id, включая те, что уже лежали в корзине. Отмена такой операции
 * («Отменить последнее») вытаскивала бы из корзины и их — то, что удалял
 * кто-то раньше.
 *
 * Без TEST_DB_* тест тихо скипается (exit 0). Запуск — как в шапке
 * tests/integration_undo_mysql.php, только имя файла другое.
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
mysqli_report(MYSQLI_REPORT_OFF);

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

function scalar(mysqli $db, string $sql) {
    $res = $db->query($sql);
    $row = $res ? $res->fetch_row() : null;
    return $row ? $row[0] : null;
}

/** Сколько команд отправило В БАЗУ именно это подключение (SHOW SESSION STATUS). */
function questions(mysqli $db): int {
    $row = $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc();
    return (int)$row['Value'];
}

$mysqli->query("DROP TABLE IF EXISTS accounts");
$mysqli->query("DROP TABLE IF EXISTS account_history");
$mysqli->query("DROP TABLE IF EXISTS user_actions");
$mysqli->query("CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(100) NOT NULL DEFAULT '',
    deleted_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$total = 1200;
$values = [];
for ($i = 1; $i <= $total; $i++) {
    $values[] = "('user$i', 'new')";
}
$mysqli->query("INSERT INTO accounts (login, status) VALUES " . implode(',', $values));
// Пять строк уже в корзине — их удалял кто-то раньше
$mysqli->query("UPDATE accounts SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (3, 77, 500, 999, 1200)");

require_once __DIR__ . '/../includes/AccountsService.php';
require_once __DIR__ . '/../includes/UndoService.php';

echo "Удаление в корзину пачкой (MySQL " . $mysqli->server_info . "):\n";

$svc = new AccountsService('accounts');
AuditLogger::getInstance(); // миграция журнала — до замера

$ids = range(1, $total);
$before = questions($mysqli);
$affected = $svc->deleteAccounts($ids);
$spent = questions($mysqli) - $before;

ok('удалено ровно столько, сколько было вне корзины', $affected === $total - 5, "affected=$affected");
// Было: ~2 команды на КАЖДЫЙ аккаунт (≈2400 на 1200). Стало: выборка живых id,
// UPDATE, пачки INSERT по 500 и пара служебных — десяток с запасом.
ok('журнал пишется пачкой: обращений к базе не больше 20 на 1200 аккаунтов', $spent <= 20, "обращений: $spent");

$actionId = (int)scalar($mysqli, "SELECT MAX(id) FROM user_actions WHERE action_type = 'delete'");
$logged = (int)scalar($mysqli, "SELECT COUNT(*) FROM account_history WHERE action_id = $actionId AND field_name = 'deleted_at'");
ok('в журнале по строке на каждый реально удалённый аккаунт', $logged === $total - 5, "строк: $logged");

$badOld = (int)scalar($mysqli, "SELECT COUNT(*) FROM account_history WHERE action_id = $actionId AND (old_value IS NOT NULL AND old_value <> '')");
$emptyNew = (int)scalar($mysqli, "SELECT COUNT(*) FROM account_history WHERE action_id = $actionId AND (new_value IS NULL OR new_value = '')");
ok('старое значение пустое, новое — время удаления', $badOld === 0 && $emptyNew === 0, "old!='' : $badOld, new='' : $emptyNew");

$foreign = (int)scalar($mysqli, "SELECT COUNT(*) FROM account_history WHERE action_id = $actionId AND account_id IN (3, 77, 500, 999, 1200)");
ok('аккаунты, лежавшие в корзине раньше, в журнал этой операции не попали', $foreign === 0, "попало: $foreign");

$who = (int)scalar($mysqli, "SELECT COUNT(*) FROM account_history WHERE action_id = $actionId AND changed_by = 'tester' AND table_name = 'accounts'");
ok('у каждой строки журнала указан автор и таблица', $who === $total - 5, "строк: $who");

$count = (int)scalar($mysqli, "SELECT affected_count FROM user_actions WHERE id = $actionId");
ok('у операции в списке действий верное число строк', $count === $total - 5, "affected_count=$count");

// Отмена штатной кнопкой: возвращаются только удалённые этой операцией
$undo = new UndoService();
$last = $undo->getLastUndoableAction('tester');
$report = $last ? $undo->undoAction($last['id'], 'tester') : ['error' => 'нет действия для отмены'];
$inTrash = (int)scalar($mysqli, "SELECT COUNT(*) FROM accounts WHERE deleted_at IS NOT NULL");
$oldTrashKept = (int)scalar($mysqli, "SELECT COUNT(*) FROM accounts WHERE id IN (3, 77, 500, 999, 1200) AND deleted_at IS NOT NULL");
ok('отмена вернула удалённые этой операцией', $inTrash === 5, "в корзине осталось: $inTrash; отчёт: " . json_encode($report, JSON_UNESCAPED_UNICODE));
ok('отмена не вытащила из корзины чужие старые удаления', $oldTrashKept === 5, "осталось из старых: $oldTrashKept");

$mysqli->query("DROP TABLE IF EXISTS accounts");
$mysqli->query("DROP TABLE IF EXISTS account_history");
$mysqli->query("DROP TABLE IF EXISTS user_actions");

echo "\n$passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);

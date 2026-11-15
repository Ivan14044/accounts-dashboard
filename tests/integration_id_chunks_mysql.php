<?php
/**
 * Массовые действия с выбранными аккаунтами не шлют в базу списки по 1000 id.
 * Тест на РЕАЛЬНОМ MySQL.
 *
 * Зачем. Интерфейс отправляет выбранные строки пачками по 1000 (удаление,
 * смена статуса, правка поля). На проде такой запрос
 * `UPDATE accounts ... WHERE id IN (<1000 id>) AND deleted_at IS NULL`
 * перебирает ВСЮ таблицу: в журнале медленных запросов базы (до 03.09.2026)
 * удаление — 185 037…203 298 прочитанных строк и 64–67 с, смена статуса —
 * 43 раза по 185 037 строк и ~67 с. Исправление 03.09.2026 (поднять
 * range_optimizer_max_mem_size своей сессии до 8 МБ) на проде НЕ помогло:
 * в системном журнале панели после него удаление 1000 аккаунтов по-прежнему
 * шло 45–57 с (41 раз с 25.09 по 30.10), смена статуса 1000 — 59–63 с
 * (07.09 и 09.09). Зато пачки меньше — 835, 912, 950 — проходят за доли
 * секунды. Почему 8 МБ на проде не действуют, без доступа к базе не выяснить
 * (догадка: хостинг ограничивает эту настройку сверху). Механизм же
 * воспроизводится: при нехватке памяти оптимизатору MySQL молча отказывается
 * от поиска по номеру и читает всю таблицу, а память нужна пропорционально
 * длине списка.
 *
 * Тест сам подбирает лимит памяти, при котором 1000 id уже читают таблицу
 * целиком, а 500 — ещё нет (это и есть картина прода), и проверяет, что
 * удаление, смена статуса, правка поля, восстановление и журнал при таком
 * лимите не делают ни одного полного прохода (Handler_read_rnd_next = 0).
 *
 * Без TEST_DB_* тест тихо скипается (exit 0). Запуск — как в шапке
 * tests/integration_undo_mysql.php.
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
function rndNext(mysqli $db): int {
    $row = $db->query("SHOW SESSION STATUS LIKE 'Handler_read_rnd_next'")->fetch_assoc();
    return (int)$row['Value'];
}
/** Сколько строк прочитано полным проходом за время $fn (за вычетом самого SHOW STATUS). */
function scanned(mysqli $db, callable $fn): int {
    $base = rndNext($db);
    $idle = rndNext($db) - $base; // сколько добавляет сам SHOW STATUS
    $a = rndNext($db);
    $fn();
    return max(0, rndNext($db) - $a - $idle);
}

$mysqli->query("DROP TABLE IF EXISTS accounts");
$mysqli->query("DROP TABLE IF EXISTS account_history");
$mysqli->query("DROP TABLE IF EXISTS user_actions");
$mysqli->query("DROP TABLE IF EXISTS account_favorites");
// Индексы с deleted_at впереди — как на проде: от их числа зависит, сколько
// памяти оптимизатор тратит на каждый id из списка
$mysqli->query("CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(100) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_deleted_id (deleted_at, id),
    INDEX idx_deleted_status (deleted_at, status),
    INDEX idx_deleted_status_id (deleted_at, status, id),
    INDEX idx_deleted_status_dates (deleted_at, status, updated_at, created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$total = 6000;
$mysqli->query("SET SESSION cte_max_recursion_depth = 10000");
$mysqli->query("INSERT INTO accounts (login, status)
    WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < $total)
    SELECT CONCAT('user', n), CONCAT('s', n % 7) FROM seq");

require_once __DIR__ . '/../includes/AccountsService.php';
$svc = new AccountsService('accounts');
AuditLogger::getInstance();

echo "Списки id режутся на части (MySQL " . $mysqli->server_info . "):\n";

/** Читает ли прямой UPDATE по $n id всю таблицу при текущем лимите. */
$probe = function (int $n) use ($mysqli): bool {
    $ids = range(1, $n);
    $ph = implode(',', array_fill(0, $n, '?'));
    $stmt = $mysqli->prepare("UPDATE accounts SET note = note WHERE id IN ($ph) AND deleted_at IS NULL");
    $stmt->bind_param(str_repeat('i', $n), ...$ids);
    $rows = scanned($mysqli, function () use ($stmt) { $stmt->execute(); });
    $stmt->close();
    return $rows > 0;
};

// Подбираем лимит: 1000 id → полный проход, 500 id → поиск по номеру
$limit = null;
for ($mem = 8 * 1048576; $mem >= 32768; $mem = (int)($mem * 0.8)) {
    $mysqli->query("SET SESSION range_optimizer_max_mem_size = $mem");
    if ($probe(1000) && !$probe(500)) {
        $limit = $mem;
        break;
    }
}
ok('нашёлся лимит памяти, при котором 1000 id читают всю таблицу, а 500 — нет (картина прода)',
    $limit !== null);
if ($limit === null) {
    echo "\n$passed passed, $failures failed\n";
    exit(1);
}
echo "    лимит: $limit байт\n";

$ids = range(1001, 2000); // ровно 1000 — как присылает интерфейс

$rows = scanned($mysqli, function () use ($svc, $ids) { $svc->updateStatus($ids, 'moved'); });
$moved = (int)$mysqli->query("SELECT COUNT(*) FROM accounts WHERE status = 'moved'")->fetch_row()[0];
ok('смена статуса 1000 выбранных: без полного прохода по таблице', $rows === 0, "прочитано полным проходом: $rows");
ok('смена статуса 1000 выбранных: изменены все 1000', $moved === 1000, "изменено: $moved");

$rows = scanned($mysqli, function () use ($svc, $ids) { $svc->bulkUpdateField($ids, 'note', 'n1'); });
$noted = (int)$mysqli->query("SELECT COUNT(*) FROM accounts WHERE note = 'n1'")->fetch_row()[0];
ok('правка поля у 1000 выбранных: без полного прохода', $rows === 0, "прочитано полным проходом: $rows");
ok('правка поля у 1000 выбранных: изменены все 1000', $noted === 1000, "изменено: $noted");

$affected = 0;
$rows = scanned($mysqli, function () use ($svc, $ids, &$affected) { $affected = $svc->deleteAccounts($ids); });
ok('удаление 1000 выбранных: без полного прохода', $rows === 0, "прочитано полным проходом: $rows");
ok('удаление 1000 выбранных: в корзине все 1000', $affected === 1000, "удалено: $affected");

$restored = 0;
$rows = scanned($mysqli, function () use ($svc, $ids, &$restored) { $restored = $svc->restoreAccounts($ids); });
ok('восстановление 1000 из корзины: без полного прохода', $rows === 0, "прочитано полным проходом: $rows");
ok('восстановление 1000 из корзины: вернулись все 1000', $restored === 1000, "восстановлено: $restored");

$history = (int)$mysqli->query("SELECT COUNT(*) FROM account_history")->fetch_row()[0];
ok('журнал записал все изменения (статус, поле, удаление, восстановление)', $history >= 3000, "строк журнала: $history");

$mysqli->query("DROP TABLE IF EXISTS accounts");
$mysqli->query("DROP TABLE IF EXISTS account_history");
$mysqli->query("DROP TABLE IF EXISTS user_actions");

echo "\n$passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);

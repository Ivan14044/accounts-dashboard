<?php
/**
 * Интеграционный тест на РЕАЛЬНОМ MySQL: длинный список «id IN (...)» обязан
 * идти по первичному ключу, а не читать таблицу целиком.
 *
 * Без TEST_DB_* тест тихо скипается (exit 0), чтобы не ломать CI без БД.
 *
 * Запуск через Docker:
 *   docker run -d --rm --name rom-test-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
 *     -e MYSQL_DATABASE=romdb -p 3399:3306 mysql:8.0
 *   docker run --rm -v "$PWD":/app -w /app --network host \
 *     -e TEST_DB_HOST=127.0.0.1 -e TEST_DB_PORT=3399 -e TEST_DB_USER=root \
 *     -e TEST_DB_PASS= -e TEST_DB_NAME=romdb \
 *     php:8.2-cli sh -c 'docker-php-ext-install mysqli >/dev/null 2>&1 && php tests/integration_range_optimizer_mysql.php'
 *
 * Что проверяется (история — в шапке tests/test_range_optimizer_session.php):
 *  - при заниженном range_optimizer_max_mem_size тот же самый запрос читает
 *    таблицу целиком (счётчик Handler_read_rnd_next растёт) — это и есть баг,
 *    из-за которого массовая смена статуса на проде шла по 67 секунд;
 *  - строка SET SESSION из Database::__construct лечит план: поиск по ключу,
 *    ноль чтений подряд;
 *  - «без лимита» (0) наша строка не портит.
 */

$host = getenv('TEST_DB_HOST');
if (!$host) {
    echo "SKIP: TEST_DB_HOST не задан — интеграционный тест пропущен\n";
    exit(0);
}
$dbUser = getenv('TEST_DB_USER') ?: 'root';
$dbPass = getenv('TEST_DB_PASS') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: 'romdb';
$dbPort = (int)(getenv('TEST_DB_PORT') ?: 3306);

// Прод работает без mysqli-исключений — воспроизводим то же поведение.
mysqli_report(MYSQLI_REPORT_OFF);

$db = new mysqli($host, $dbUser, $dbPass, $dbName, $dbPort);
if ($db->connect_errno) {
    fwrite(STDERR, "FAIL: не удалось подключиться к MySQL: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$failures = 0;
$passed   = 0;

/**
 * Фиксирует результат проверки.
 *
 * @param string $name Что проверяли
 * @param bool $cond Результат
 * @param string $detail Подробности при провале
 * @return void
 */
function romOk($name, $cond, $detail = '')
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  ✓ $name\n"; }
    else       { $failures++; fwrite(STDERR, "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"); }
}

/**
 * Текущее значение счётчика «прочитано строк подряд» (полный скан) для сессии.
 *
 * @param mysqli $db Подключение
 * @return int Значение Handler_read_rnd_next
 */
function romScanCounter(mysqli $db)
{
    $res = $db->query("SHOW SESSION STATUS LIKE 'Handler_read_rnd_next'");
    $row = $res ? $res->fetch_row() : null;
    return $row ? (int)$row[1] : -1;
}

/**
 * Выполняет массовую смену статуса ровно тем запросом, что строит
 * AccountsRepository::updateStatus, и возвращает, сколько строк прочитано подряд.
 *
 * @param mysqli $db Подключение
 * @param array $ids Список ID
 * @param string $status Новый статус
 * @return int Прирост Handler_read_rnd_next (0 = плана по ключу хватило)
 */
function romRunUpdate(mysqli $db, array $ids, $status)
{
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE rom_accounts SET status = ? , updated_at = CURRENT_TIMESTAMP "
         . "WHERE id IN ($ph) AND deleted_at IS NULL";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        fwrite(STDERR, "FAIL: prepare: {$db->error}\n");
        exit(1);
    }
    $types  = 's' . str_repeat('i', count($ids));
    $params = array_merge(array($status), $ids);
    $stmt->bind_param($types, ...$params);
    $before = romScanCounter($db);
    $stmt->execute();
    $after = romScanCounter($db);
    $stmt->close();
    return $after - $before;
}

echo "\n=== Длинный IN(...) и лимит памяти оптимизатора диапазонов ===\n\n";

$db->query("DROP TABLE IF EXISTS rom_accounts");
$db->query(
    "CREATE TABLE rom_accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(50),
        deleted_at DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status (status),
        KEY idx_deleted_at (deleted_at),
        KEY idx_deleted_status (deleted_at, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// 20 000 строк: полного скана хватает, чтобы счётчик вырос на порядки,
// а наливка занимает секунды.
$db->query("SET SESSION autocommit = 0");
for ($chunk = 0; $chunk < 20; $chunk++) {
    $values = array();
    for ($i = 0; $i < 1000; $i++) {
        $values[] = "('base', NULL)";
    }
    $db->query("INSERT INTO rom_accounts (status, deleted_at) VALUES " . implode(',', $values));
}
$db->query("COMMIT");
$db->query("SET SESSION autocommit = 1");
$db->query("ANALYZE TABLE rom_accounts");

$total = (int)$db->query("SELECT COUNT(*) FROM rom_accounts")->fetch_row()[0];
romOk('таблица налита (20 000 строк)', $total === 20000, "строк: $total");

$hasVar = (bool)$db->query("SELECT @@SESSION.range_optimizer_max_mem_size");
if (!$hasVar) {
    echo "SKIP: сервер не знает range_optimizer_max_mem_size (MariaDB / MySQL < 5.7.9)\n";
    $db->query("DROP TABLE IF EXISTS rom_accounts");
    exit(0);
}

$ids = range(1, 1000);

// 1. Заниженный лимит — воспроизводим боевую беду.
$db->query("SET SESSION range_optimizer_max_mem_size = 16384");
$scannedBroken = romRunUpdate($db, $ids, 'broken');
romOk(
    'при лимите 16 КБ запрос действительно читает таблицу целиком',
    $scannedBroken > $total / 2,
    "прочитано подряд строк: $scannedBroken (ожидали больше " . (int)($total / 2) . ")"
);

// 2. Наша строка из Database::__construct — тот же запрос, тот же сеанс.
$db->query(
    "SET SESSION range_optimizer_max_mem_size = CAST("
    . "IF(@@SESSION.range_optimizer_max_mem_size = 0, 0, "
    . "GREATEST(@@SESSION.range_optimizer_max_mem_size, 8388608)) AS UNSIGNED)"
);
$value = (int)$db->query("SELECT @@SESSION.range_optimizer_max_mem_size")->fetch_row()[0];
romOk('лимит поднят до 8 МБ', $value === 8388608, "значение: $value");

$scannedFixed = romRunUpdate($db, $ids, 'fixed');
romOk(
    'после подъёма лимита тот же запрос идёт по первичному ключу',
    $scannedFixed === 0,
    "прочитано подряд строк: $scannedFixed (ожидали 0)"
);

$affected = (int)$db->query("SELECT COUNT(*) FROM rom_accounts WHERE status = 'fixed'")->fetch_row()[0];
romOk('обновлены ровно те 1000 строк, что просили', $affected === 1000, "обновлено: $affected");

// 3. «Без лимита» наша строка не портит.
$db->query("SET SESSION range_optimizer_max_mem_size = 0");
$db->query(
    "SET SESSION range_optimizer_max_mem_size = CAST("
    . "IF(@@SESSION.range_optimizer_max_mem_size = 0, 0, "
    . "GREATEST(@@SESSION.range_optimizer_max_mem_size, 8388608)) AS UNSIGNED)"
);
$zero = (int)$db->query("SELECT @@SESSION.range_optimizer_max_mem_size")->fetch_row()[0];
romOk('значение 0 («без лимита») остаётся нулём', $zero === 0, "значение: $zero");

$db->query("DROP TABLE IF EXISTS rom_accounts");

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

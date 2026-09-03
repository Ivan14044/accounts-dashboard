<?php
/**
 * Интеграционный тест на РЕАЛЬНОМ MySQL: счётчики пустых фильтров
 * (status_marketplace, currency, geo, status_rk) считаются по индексам,
 * а не полным проходом по таблице — и при этом дают те же числа.
 *
 * Без TEST_DB_* тест тихо скипается (exit 0), чтобы не ломать CI без БД.
 *
 * Запуск через Docker — как у tests/integration_range_optimizer_mysql.php.
 *
 * Зачем этот тест существует (найдено 2026-09-03 разбором slow log прода).
 *
 * Эти счётчики были одним запросом с четырьмя SUM(CASE ...) по четырём разным
 * колонкам. Одним индексом такое не покрыть, поэтому запрос читал таблицу
 * целиком: в боевом журнале 198 запусков, 182 087 строк за запуск, 5,9 секунды
 * в среднем и до 8,2 секунды — суммарно 1177 секунд, второй по стоимости
 * запрос самой панели.
 *
 * Четыре отдельных COUNT(*) вместо одного запроса каждый ложится на свой
 * покрывающий индекс (deleted_at, колонка), которые в проекте уже есть.
 * Замер на стенде (185 000 строк прод-формы, mysql:8.0, прогретый кэш):
 * 373–460 мс одним запросом против 32–35 мс четырьмя, то есть в 12 раз быстрее.
 *
 * Здесь проверяются ЧИСЛА: четыре отдельных запроса обязаны дать ровно то же,
 * что старый общий. Что запросы именно четыре (а не снова один) — стережёт
 * tests/test_empty_filter_counts_split.php.
 *
 * Осторожно: счётчики Handler_* здесь не годятся для проверки «стало быстрее» —
 * замерено, что и старый, и новый вариант дают Handler_read_rnd_next = 0
 * (оба идут по индексу deleted_at), а разница в том, лезет ли запрос за самими
 * строками. Поэтому выигрыш подтверждён замером времени на стенде, а не тестом.
 */

$host = getenv('TEST_DB_HOST');
if (!$host) {
    echo "SKIP: TEST_DB_HOST не задан — интеграционный тест пропущен\n";
    exit(0);
}
$dbUser = getenv('TEST_DB_USER') ?: 'root';
$dbPass = getenv('TEST_DB_PASS') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: 'dashboard_test';
$dbPort = (int)(getenv('TEST_DB_PORT') ?: 3306);

$_SESSION = ['username' => 'tester'];

// Прод работает без mysqli-исключений — воспроизводим то же поведение.
mysqli_report(MYSQLI_REPORT_OFF);

// Глобальное подключение: Database::getInstance() подхватит его, как это делает config.php
$mysqli = new mysqli($host, $dbUser, $dbPass, $dbName, $dbPort);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "FAIL: не удалось подключиться к MySQL: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

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
function efcOk($name, $cond, $detail = '')
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  ✓ $name\n"; }
    else       { $failures++; fwrite(STDERR, "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"); }
}

echo "\n=== Счётчики пустых фильтров: те же числа, но без полного прохода ===\n\n";

$mysqli->query("DROP TABLE IF EXISTS accounts");
$mysqli->query(
    "CREATE TABLE accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(255),
        status VARCHAR(50),
        status_marketplace VARCHAR(50),
        currency VARCHAR(20),
        geo VARCHAR(50),
        status_rk VARCHAR(50),
        deleted_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_deleted_at (deleted_at),
        KEY idx_deleted_status (deleted_at, status),
        KEY idx_deleted_status_marketplace (deleted_at, status_marketplace),
        KEY idx_deleted_currency (deleted_at, currency),
        KEY idx_deleted_geo (deleted_at, geo),
        KEY idx_deleted_status_rk (deleted_at, status_rk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// 20 000 строк со всеми интересными случаями: NULL, пустая строка, значение,
// плюс удалённые в корзину (их считать нельзя).
$mysqli->query("SET SESSION autocommit = 0");
for ($chunk = 0; $chunk < 20; $chunk++) {
    $values = array();
    for ($i = 0; $i < 1000; $i++) {
        $n = $chunk * 1000 + $i;
        $mk  = ($n % 3 === 0) ? 'NULL' : (($n % 3 === 1) ? "''" : "'active'");
        $cur = ($n % 4 === 0) ? "''"   : "'USD'";
        $geo = ($n % 5 === 0) ? 'NULL' : "'US'";
        $rk  = ($n % 7 === 0) ? "''"   : "'ok'";
        $del = ($n % 11 === 0) ? "'2026-01-01 00:00:00'" : 'NULL';
        $values[] = "('acc$n', 'st', $mk, $cur, $geo, $rk, $del)";
    }
    $mysqli->query(
        "INSERT INTO accounts (login, status, status_marketplace, currency, geo, status_rk, deleted_at) VALUES "
        . implode(',', $values)
    );
}
$mysqli->query("COMMIT");
$mysqli->query("SET SESSION autocommit = 1");
$mysqli->query("ANALYZE TABLE accounts");

// Эталон: ровно тот запрос, что был в computeEmptyFilterCounts() до правки.
$row = $mysqli->query(
    "SELECT
        SUM(CASE WHEN status_marketplace IS NULL OR status_marketplace = '' THEN 1 ELSE 0 END) as empty_status_marketplace,
        SUM(CASE WHEN currency IS NULL OR currency = '' THEN 1 ELSE 0 END) as empty_currency,
        SUM(CASE WHEN geo IS NULL OR geo = '' THEN 1 ELSE 0 END) as empty_geo,
        SUM(CASE WHEN status_rk IS NULL OR status_rk = '' THEN 1 ELSE 0 END) as empty_status_rk
     FROM accounts WHERE deleted_at IS NULL"
)->fetch_assoc();
$expected = array(
    'status_marketplace' => (int)$row['empty_status_marketplace'],
    'currency'           => (int)$row['empty_currency'],
    'geo'                => (int)$row['empty_geo'],
    'status_rk'          => (int)$row['empty_status_rk'],
);

require_once dirname(__DIR__) . '/includes/StatisticsService.php';
$service = new StatisticsService('accounts');

$actual = $service->computeEmptyFilterCounts();

foreach ($expected as $field => $want) {
    efcOk(
        "счётчик пустых «{$field}» совпадает со старым запросом",
        isset($actual[$field]) && (int)$actual[$field] === $want,
        'ожидали ' . $want . ', получили ' . (isset($actual[$field]) ? $actual[$field] : 'ничего')
    );
}

efcOk(
    'удалённые в корзину не попадают в счётчики',
    $expected['currency'] < 20000 / 4,
    'проверка самих данных: строки с deleted_at должны быть исключены'
);

efcOk(
    'запрос не разъехался с эталоном ни на одной из четырёх колонок',
    $actual === $expected,
    'получено: ' . json_encode($actual) . ', ожидали: ' . json_encode($expected)
);

$mysqli->query("DROP TABLE IF EXISTS accounts");

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

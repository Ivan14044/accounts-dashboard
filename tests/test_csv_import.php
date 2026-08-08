<?php
/**
 * Тест импорта CSV (CsvParser) — то, что реально портит данные аккаунтов.
 *
 * Запуск:  php tests/test_csv_import.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД: пишем временный CSV, читаем его CsvParser'ом, сверяем значения.
 * Ключевой сценарий — «полный круг»: выгрузили через Csv::writeRow, импортировали
 * обратно CsvParser'ом, получили ровно то, что выгружали. Именно на этом круге
 * раньше ломались cookies: обратный слэш в JSON съедал экранирование.
 *
 * До этого файла импорт CSV не был покрыт вообще: tests/test_csv_cookies.php
 * (теперь tests/diagnose_csv_cookies.php) только печатал отчёт и всегда завершался
 * кодом 0, то есть в CI ничего не проверял.
 */

require_once __DIR__ . '/../includes/Csv.php';
require_once __DIR__ . '/../includes/CsvParser.php';

$failures = 0;
$passed   = 0;

function check(string $name, bool $ok, string $msg = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($msg !== '' ? " — $msg" : '') . "\n";
    }
}

/**
 * Пишет временный CSV и разбирает его CsvParser'ом.
 *
 * @param array  $headers   Заголовки колонок
 * @param array  $rows      Строки значений
 * @param string $delimiter Разделитель
 * @param bool   $bom       Добавить UTF-8 BOM (как это делает Excel)
 * @return array Разобранные строки (ассоциативные массивы)
 */
function parseWritten(array $headers, array $rows, string $delimiter = ';', bool $bom = false): array
{
    $path = tempnam(sys_get_temp_dir(), 'csvtest');
    $h = fopen($path, 'w');
    if ($bom) {
        fwrite($h, "\xEF\xBB\xBF");
    }
    Csv::writeRow($h, $headers, $delimiter);
    foreach ($rows as $row) {
        Csv::writeRow($h, $row, $delimiter);
    }
    fclose($h);

    try {
        $parser = new CsvParser(10000, $delimiter);
        return $parser->parse($path);
    } finally {
        @unlink($path);
    }
}

echo "\n=== Полный круг: запись → импорт ===\n\n";

$cookies = '[{"name":"c_user","value":"{\"id\":\"f2a5cd5ab\",\"ts\":1774209718197.7}"}]';

$roundTripCases = [
    'cookies с экранированным JSON' => ['cookies', $cookies],
    'обратные слэши в пути'         => ['extra_info_1', 'C:\\Users\\test\\file.txt'],
    'кавычки в значении'            => ['extra_info_1', 'он сказал "да"'],
    'разделитель внутри значения'   => ['extra_info_1', 'a;b;c'],
    'запятая внутри значения'       => ['extra_info_1', 'фамилия, имя'],
    'перевод строки внутри поля'    => ['extra_info_1', "строка1\nстрока2"],
    'кириллица'                     => ['first_name', 'Пётр'],
    'пустое значение'               => ['extra_info_1', ''],
    'значение из одних кавычек'     => ['extra_info_1', '""'],
];

foreach ($roundTripCases as $name => [$column, $value]) {
    $rows = parseWritten(['login', $column], [['user1', $value]]);
    $got = $rows[0][$column] ?? null;
    check(
        $name,
        count($rows) === 1 && $got === $value,
        'записали ' . json_encode($value, JSON_UNESCAPED_UNICODE)
            . ', прочитали ' . json_encode($got, JSON_UNESCAPED_UNICODE)
    );
}

echo "\n=== Разделители и BOM ===\n\n";

foreach ([';', ','] as $delimiter) {
    $rows = parseWritten(['login', 'cookies'], [['user1', $cookies]], $delimiter);
    check(
        "автоопределение разделителя '$delimiter'",
        ($rows[0]['cookies'] ?? null) === $cookies,
        'прочитали ' . json_encode($rows[0]['cookies'] ?? null, JSON_UNESCAPED_UNICODE)
    );
}

$rows = parseWritten(['login', 'cookies'], [['user1', $cookies]], ';', true);
check(
    'BOM в начале файла не ломает первую колонку',
    isset($rows[0]['login']) && $rows[0]['login'] === 'user1',
    'ключи строки: ' . implode(', ', array_keys($rows[0] ?? []))
);

echo "\n=== Заголовки ===\n\n";

check('звёздочка обязательного поля срезается', CsvParser::normalizeHeader('login*') === 'login');
check('регистр и пробелы нормализуются', CsvParser::normalizeHeader('  Email_Password  ') === 'email_password');
check('BOM в заголовке срезается', CsvParser::normalizeHeader("\xEF\xBB\xBFlogin") === 'login');
check('кириллический заголовок в нижний регистр', CsvParser::normalizeHeader('Логин') === 'логин');

echo "\n=== Многострочность и объём ===\n\n";

$many = [];
for ($i = 1; $i <= 200; $i++) {
    $many[] = ["user$i", $cookies];
}
$rows = parseWritten(['login', 'cookies'], $many);
check('200 строк читаются полностью', count($rows) === 200, 'получили ' . count($rows));
check('последняя строка не потеряна', ($rows[199]['login'] ?? null) === 'user200');
check('значение в последней строке целое', ($rows[199]['cookies'] ?? null) === $cookies);

$rows = parseWritten(['login', 'extra_info_1'], [
    ['user1', "многострочное\nзначение"],
    ['user2', 'обычное'],
]);
check(
    'поле с переводом строки не сдвигает следующие строки',
    count($rows) === 2 && ($rows[1]['login'] ?? null) === 'user2',
    'строк: ' . count($rows) . ', вторая: ' . json_encode($rows[1] ?? null, JSON_UNESCAPED_UNICODE)
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

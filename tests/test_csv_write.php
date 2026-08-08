<?php
/**
 * Тест записи CSV через общую обёртку Csv::writeRow().
 *
 * Запуск:  php tests/test_csv_write.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД. Проверяем главное свойство: что записали — то и прочитали
 * обратно по RFC 4180, включая значения с обратными слэшами.
 *
 * Регрессия, ради которой тест написан: `fputcsv()` с дефолтным escape='\'
 * ломает JSON-подобные значения (cookies, token) — обратный слэш съедает
 * удвоение кавычки, и round-trip даёт не то, что записали. В export.php и
 * CsvParser это уже лечилось через escape='', а admin_logs.php писал дефолтом.
 */

require_once __DIR__ . '/../includes/Csv.php';

$failures = 0;
$passed   = 0;

/**
 * @param string $name
 * @param bool   $ok
 * @param string $msg сообщение при провале
 */
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
 * Пишет строку через Csv::writeRow и читает обратно по RFC 4180.
 *
 * @param array  $fields
 * @param string $delimiter
 * @return array{0: array, 1: string} [прочитанные поля, сырая строка CSV]
 */
function roundTrip(array $fields, string $delimiter = ';'): array
{
    $h = fopen('php://temp', 'r+');
    Csv::writeRow($h, $fields, $delimiter);
    rewind($h);
    $raw = stream_get_contents($h);
    rewind($h);
    // Читаем строго по RFC 4180: удвоенная кавычка — единственное экранирование.
    $read = PHP_VERSION_ID >= 70400
        ? fgetcsv($h, 0, $delimiter, '"', '')
        : fgetcsv($h, 0, $delimiter, '"');
    fclose($h);
    return [$read === false ? [] : $read, $raw];
}

echo "\n=== Csv::writeRow — round-trip ===\n\n";

$cases = [
    'простые значения'            => ['id' => '1', 'login' => 'user1'],
    'обратные слэши в JSON'       => ['1', '[{"name":"c","value":"{\"id\":\"abc\"}"}]'],
    'одинарный обратный слэш'     => ['C:\\path\\to\\file', 'a\\b'],
    'кавычки внутри значения'     => ['он сказал "привет"', 'x'],
    'разделитель внутри значения' => ['a;b', 'c;d'],
    'перевод строки'              => ["первая\nвторая", 'x'],
    'пустые и null-подобные'      => ['', '0'],
    'кириллица'                   => ['Иван', 'Петров'],
    'слэш в конце значения'       => ['value\\', 'next'],
];

foreach ($cases as $label => $fields) {
    [$read, $raw] = roundTrip(array_values($fields));
    $expected = array_map('strval', array_values($fields));
    check(
        $label,
        $read === $expected,
        'записали ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . ', прочитали ' . json_encode($read, JSON_UNESCAPED_UNICODE)
            . ', сырая строка: ' . trim($raw)
    );
}

echo "\n=== Csv::writeRow — разделители ===\n\n";

foreach ([';', ',', "\t", '|'] as $delimiter) {
    $fields = ['a' . $delimiter . 'b', '[{"v":"{\"k\":1}"}]'];
    [$read] = roundTrip($fields, $delimiter);
    check(
        "разделитель '" . ($delimiter === "\t" ? '\t' : $delimiter) . "'",
        $read === $fields,
        'прочитали ' . json_encode($read, JSON_UNESCAPED_UNICODE)
    );
}

echo "\n=== Csv::writeRow — контракт ===\n\n";

check('возвращает длину записанного (не false)', (static function () {
    $h = fopen('php://temp', 'r+');
    $n = Csv::writeRow($h, ['a', 'b']);
    fclose($h);
    return is_int($n) && $n > 0;
})());

check('числа и null приводятся к строке без ошибок', (static function () {
    [$read] = roundTrip([1, 2.5, null, true]);
    return $read === ['1', '2.5', '', '1'];
})());

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

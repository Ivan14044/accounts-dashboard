<?php
/**
 * Тест: CSV в CP1251 импортируется без порчи кириллицы.
 *
 * Запуск:  php tests/test_csv_encoding.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (воспроизведено на стенде 2026-08-10).
 *
 * Excel в русской локали по умолчанию сохраняет CSV в CP1251 — то есть это не
 * экзотика, а самый вероятный путь. CsvParser перекодировку не делал вовсе:
 * поле $encoding в нём было, сеттер setEncoding() был, а использования — нет.
 *
 * Что получалось при импорте такого файла:
 *   отчёт: «Создано: 1, Ошибок: 0»
 *   в БД:  first_name = «ϸ» (2 байта, 1 символ) вместо «Пётр» (8 байт, 4 символа)
 * Четыре буквы уничтожены, ни одного предупреждения.
 *
 * КРАЕВОЙ СЛУЧАЙ, ради которого половина этого теста. Кодировка определяется по
 * куску начала файла. Если резать кусок по фиксированной длине, многобайтовый
 * символ UTF-8 может разорваться на границе — проверка «валидный UTF-8?» тогда
 * скажет «нет», и ВАЛИДНЫЙ UTF-8 файл будет перекодирован как CP1251, то есть
 * фикс сам испортит данные. Поэтому ниже отдельно проверяется большой UTF-8
 * файл с кириллицей: он обязан пройти нетронутым.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/CsvParser.php';

$failures = 0;
$passed   = 0;

/**
 * Фиксирует результат проверки.
 *
 * @param string $name Что проверяли
 * @param bool $ok Прошло ли
 * @param string $detail Подробности при провале
 * @return void
 */
function ceCheck($name, $ok, $detail = '')
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Пишет байты во временный файл и разбирает его настоящим CsvParser.
 *
 * @param string $bytes Содержимое файла как есть
 * @return array Разобранные строки
 */
function ceParse($bytes)
{
    $file = tempnam(sys_get_temp_dir(), 'csvenc');
    file_put_contents($file, $bytes);
    $parser = new CsvParser();
    try {
        $rows = $parser->parse($file);
    } catch (Exception $e) {
        unlink($file);
        throw $e;
    }
    unlink($file);
    return $rows;
}

echo "\n=== CSV: кодировка файла (PHP " . PHP_VERSION . ") ===\n\n";

$header = "login;status;first_name;last_name\n";

// ── 1. CP1251 — то, что сохраняет Excel в русской локали ──
$utf8Row  = "u1;new;Пётр;Кириллов\n";
$cp1251   = mb_convert_encoding($header . $utf8Row, 'Windows-1251', 'UTF-8');
ceCheck(
    'подготовленный файл действительно НЕ валидный UTF-8',
    !mb_check_encoding($cp1251, 'UTF-8'),
    'тест бессмысленен, если файл случайно оказался валидным UTF-8'
);

$rows = ceParse($cp1251);
ceCheck('CP1251: строка прочитана', count($rows) === 1, 'получили ' . count($rows));
ceCheck(
    'CP1251: «Пётр» доехал целиком',
    isset($rows[0]['first_name']) && $rows[0]['first_name'] === 'Пётр',
    isset($rows[0]['first_name'])
        ? 'получили ' . json_encode($rows[0]['first_name'], JSON_UNESCAPED_UNICODE)
            . ' (' . mb_strlen($rows[0]['first_name'], 'UTF-8') . ' символов)'
        : 'поля нет'
);
ceCheck(
    'CP1251: «Кириллов» доехал целиком',
    isset($rows[0]['last_name']) && $rows[0]['last_name'] === 'Кириллов',
    isset($rows[0]['last_name']) ? 'получили ' . json_encode($rows[0]['last_name'], JSON_UNESCAPED_UNICODE) : 'поля нет'
);
ceCheck(
    'CP1251: результат — валидный UTF-8',
    isset($rows[0]['first_name']) && mb_check_encoding($rows[0]['first_name'], 'UTF-8')
);

// ── 2. UTF-8 не должен пострадать ──
$rows = ceParse($header . $utf8Row);
ceCheck(
    'UTF-8: кириллица не тронута',
    isset($rows[0]['first_name']) && $rows[0]['first_name'] === 'Пётр',
    isset($rows[0]['first_name']) ? 'получили ' . json_encode($rows[0]['first_name'], JSON_UNESCAPED_UNICODE) : 'поля нет'
);

// ── 3. UTF-8 с BOM (Excel ставит его в наши же выгрузки) ──
$rows = ceParse("\xEF\xBB\xBF" . $header . $utf8Row);
ceCheck(
    'UTF-8 с BOM: кириллица не тронута',
    isset($rows[0]['first_name']) && $rows[0]['first_name'] === 'Пётр',
    isset($rows[0]['first_name']) ? 'получили ' . json_encode($rows[0]['first_name'], JSON_UNESCAPED_UNICODE) : 'поля нет'
);

// ── 4. Только ASCII — тоже валидный UTF-8, трогать нечего ──
$rows = ceParse("login;status\nplain;new\n");
ceCheck('ASCII: читается как обычно', isset($rows[0]['login']) && $rows[0]['login'] === 'plain');

// ── 5. КРАЕВОЙ: большой UTF-8 файл, где кириллица попадает на границу
//        куска, по которому определяется кодировка. Файл обязан пройти
//        нетронутым — иначе фикс сам портит валидные данные.
$big = $header;
for ($i = 0; $i < 4000; $i++) {
    $big .= "u{$i};new;Пётр;Кириллов\n";
}
// Проба кодировки читает 64 КБ от начала файла — файл обязан быть заметно
// длиннее, иначе граница куска не проверяется и тест ничего не доказывает.
ceCheck(
    'подготовленный большой файл заведомо длиннее пробы кодировки (64 КБ)',
    strlen($big) > 65536 * 2,
    'размер ' . strlen($big) . ' байт'
);
$rows = ceParse($big);
ceCheck('большой UTF-8: все строки прочитаны', count($rows) === 4000, 'получили ' . count($rows));
$bad = 0;
foreach ($rows as $r) {
    if (!isset($r['first_name']) || $r['first_name'] !== 'Пётр') {
        $bad++;
    }
}
ceCheck(
    'большой UTF-8: ни одна строка не испорчена перекодировкой',
    $bad === 0,
    "испорчено строк: $bad — определение кодировки сработало ложно"
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

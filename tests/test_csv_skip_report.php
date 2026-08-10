<?php
/**
 * Тест: парсер CSV сообщает, что именно он пропустил.
 *
 * Запуск:  php tests/test_csv_skip_report.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10).
 *
 * CsvParser молча выбрасывал целые строки, и импорт рапортовал успех:
 *
 *  1. Строка, первая колонка которой начинается с «#», считалась комментарием.
 *     А первая колонка — это login. Файл с логином «#neo» терял эту запись,
 *     отчёт говорил «Ошибок: 0».
 *  2. Файл длиннее maxRows (по умолчанию 10 000) обрезался: цикл просто
 *     останавливался, и хвост не читался вовсе. Ни счётчика, ни предупреждения —
 *     пользователь уверен, что залил весь файл.
 *  3. Строки с другим числом колонок молча подгонялись по длине.
 *
 * Счётчики для (1) и (3) в парсере были, но уходили только в Logger и до
 * пользователя не доезжали; для (2) счётчика не было вообще.
 *
 * Решение — не менять правила пропуска, а перестать молчать: парсер отдаёт
 * статистику, импорт показывает её пользователю.
 *
 * Почему правило «#» оставлено как есть. Соблазн — считать данными всё после
 * заголовка. Но тогда строка-комментарий превратилась бы в аккаунт с логином
 * «#…», то есть вместо потери строки мы получили бы мусор в базе. Это хуже.
 * Поэтому строка по-прежнему пропускается, но теперь об этом сообщается.
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
function csrCheck($name, $ok, $detail = '')
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
 * Разбирает содержимое и возвращает [строки, статистика].
 *
 * @param string $content Содержимое CSV
 * @param int $maxRows Предел строк
 * @return array{0:array,1:array}
 */
function csrParse($content, $maxRows = 10000)
{
    $file = tempnam(sys_get_temp_dir(), 'csrp');
    file_put_contents($file, $content);
    $parser = new CsvParser($maxRows);
    try {
        $rows = $parser->parse($file);
        $stats = $parser->getLastStats();
    } catch (Exception $e) {
        unlink($file);
        throw $e;
    }
    unlink($file);
    return array($rows, $stats);
}

echo "\n=== Импорт CSV: пропуски больше не молчат ===\n\n";

csrCheck('CsvParser::getLastStats() существует', method_exists('CsvParser', 'getLastStats'));
if (!method_exists('CsvParser', 'getLastStats')) {
    echo "\nБез неё остальные проверки бессмысленны.\n";
    exit(1);
}

// ── 1. Строка-«комментарий» ──
$content = "login;status\nuser1;new\n#neo;active\nuser2;new\n";
list($rows, $stats) = csrParse($content);
csrCheck('разобрано 2 строки, третья пропущена как комментарий', count($rows) === 2, 'получили ' . count($rows));
csrCheck(
    'пропуск комментария посчитан',
    isset($stats['skipped_comments']) && $stats['skipped_comments'] === 1,
    'статистика: ' . json_encode($stats)
);

// ── 2. Обрезание по пределу строк ──
$lines = array('login;status');
for ($i = 1; $i <= 10; $i++) {
    $lines[] = "u{$i};new";
}
list($rows, $stats) = csrParse(implode("\n", $lines) . "\n", 3);
csrCheck('прочитано ровно 3 строки при пределе 3', count($rows) === 3, 'получили ' . count($rows));
csrCheck(
    'обрезание файла отмечено флагом',
    isset($stats['truncated']) && $stats['truncated'] === true,
    'статистика: ' . json_encode($stats)
);
csrCheck(
    'в статистике указан предел, на котором остановились',
    isset($stats['max_rows']) && $stats['max_rows'] === 3,
    'статистика: ' . json_encode($stats)
);

// Файл, который РОВНО в предел — обрезанием считаться не должен
$lines = array('login;status', 'u1;new', 'u2;new', 'u3;new');
list($rows, $stats) = csrParse(implode("\n", $lines) . "\n", 3);
csrCheck('файл ровно в предел не считается обрезанным', $stats['truncated'] === false,
    'статистика: ' . json_encode($stats));
csrCheck('и все его строки прочитаны', count($rows) === 3, 'получили ' . count($rows));

// ── 3. Несовпадение числа колонок ──
$content = "login;status;email\nu1;new;a@b.invalid\nu2;new\nu3;new;c@d.invalid;лишнее\n";
list($rows, $stats) = csrParse($content);
csrCheck('строки с другим числом колонок всё равно разобраны', count($rows) === 3, 'получили ' . count($rows));
csrCheck(
    'подгонка колонок посчитана',
    isset($stats['adjusted_columns']) && $stats['adjusted_columns'] === 2,
    'статистика: ' . json_encode($stats)
);

// ── 4. Пустые строки ──
list($rows, $stats) = csrParse("login;status\nu1;new\n\n;\nu2;new\n");
csrCheck('пустые строки посчитаны', isset($stats['skipped_empty']) && $stats['skipped_empty'] >= 1,
    'статистика: ' . json_encode($stats));

// ── 5. Чистый файл — нечего сообщать ──
list($rows, $stats) = csrParse("login;status\nu1;new\nu2;new\n");
csrCheck('на чистом файле пропусков нет', $stats['skipped_comments'] === 0
    && $stats['skipped_empty'] === 0 && $stats['adjusted_columns'] === 0
    && $stats['truncated'] === false, 'статистика: ' . json_encode($stats));
csrCheck('число разобранных строк в статистике верное',
    isset($stats['rows_parsed']) && $stats['rows_parsed'] === 2, 'статистика: ' . json_encode($stats));

// ── 6. Импорт обязан показывать это пользователю ──
$imp = file_get_contents(__DIR__ . '/../import_accounts.php');
csrCheck(
    'import_accounts.php читает статистику парсера',
    strpos($imp, 'getLastStats') !== false,
    'иначе пропуски снова останутся только в логе'
);
csrCheck(
    'import_accounts.php возвращает предупреждения в ответе',
    strpos($imp, 'warnings') !== false,
    'пользователь должен увидеть, что часть файла не доехала'
);

$js = file_get_contents(__DIR__ . '/../assets/js/modules/dashboard-upload.js');
csrCheck(
    'модуль загрузки показывает предупреждения',
    strpos($js, 'warnings') !== false,
    'иначе ответ с предупреждениями никто не выведет'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

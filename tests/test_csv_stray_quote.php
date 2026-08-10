<?php
/**
 * Тест: одиночная кавычка в незакавыченном поле не съедает остаток CSV-файла.
 *
 * Запуск:  php tests/test_csv_stray_quote.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10, воспроизведено на обеих
 * границах PHP).
 *
 * CsvParser читает файл двумя разными путями: на PHP >= 7.4 это fgetcsv
 * с escape='', на PHP < 7.4 — собственный ридер readCsvRowManual(). Пути вели
 * себя по-разному, и расходились они молча:
 *
 *   файл из 10 строк данных, в третьей поле вида `user3"` (одна лишняя кавычка)
 *   PHP 8.2 → распознано 10 строк
 *   PHP 7.3 → распознано 3 строки
 *
 * Прод работает на PHP старее 7.4 (см. «Проверенные факты» в CLAUDE.md), то есть
 * баг был именно боевым: при импорте любого CSV, где в поле затесалась кавычка,
 * все строки после неё ПРОПАДАЛИ, а импорт рапортовал «Создано: 3» и не сообщал
 * ни о какой ошибке. Кавычка в данных — не экзотика: имена, заметки, extra_info.
 *
 * Причина: сканер в readCsvRowManual() считал кавычку в ЛЮБОЙ позиции началом
 * закавыченного поля и решал, что строка не дочитана, — и склеивал строки до
 * следующей кавычки или до конца файла. По RFC 4180 (и так же ведёт себя fgetcsv)
 * кавычка открывает поле, только если стоит в НАЧАЛЕ поля; внутри
 * незакавыченного поля это обычный символ. Разбор полей (parseCsvLine) это
 * правило соблюдал, а склейка строк — нет.
 *
 * Тест гоняется на обеих версиях PHP и обязан давать одинаковый результат.
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
function csqCheck($name, $ok, $detail = '')
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
 * Пишет временный CSV и разбирает его настоящим CsvParser.
 *
 * @param string[] $lines Строки файла (первая — заголовок)
 * @return array Разобранные строки
 */
function csqParse(array $lines)
{
    $file = tempnam(sys_get_temp_dir(), 'csvq');
    file_put_contents($file, implode("\n", $lines) . "\n");
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

echo "\n=== CSV: лишняя кавычка не должна съедать файл (PHP " . PHP_VERSION . ") ===\n\n";

// ── 1. Главный случай: одна лишняя кавычка в середине файла ──
$lines = array('login;status');
for ($i = 1; $i <= 10; $i++) {
    $lines[] = ($i === 3) ? "user{$i}\";active" : "user{$i};active";
}
$rows = csqParse($lines);

csqCheck(
    'все 10 строк прочитаны, несмотря на лишнюю кавычку в третьей',
    count($rows) === 10,
    'распознано ' . count($rows) . ' из 10 — остаток файла проглочен'
);

$logins = array();
foreach ($rows as $r) {
    $logins[] = isset($r['login']) ? $r['login'] : '(нет)';
}
csqCheck(
    'последняя строка файла доехала',
    in_array('user10', $logins, true),
    'логины: ' . implode(', ', $logins)
);
csqCheck(
    'кавычка осталась внутри значения, а не съела разделитель',
    in_array('user3"', $logins, true),
    'логины: ' . implode(', ', $logins)
);

// ── 2. Не сломать нормальные закавыченные поля ──
$rows = csqParse(array(
    'login;status',
    '"quoted_user";"active"',
    'plain;new',
));
csqCheck('обычные закавыченные поля читаются', count($rows) === 2, 'распознано ' . count($rows));
csqCheck(
    'кавычки вокруг значения снимаются',
    isset($rows[0]['login']) && $rows[0]['login'] === 'quoted_user',
    isset($rows[0]['login']) ? "получили '{$rows[0]['login']}'" : 'поля нет'
);

// ── 3. Не сломать перенос строки внутри закавыченного поля ──
$rows = csqParse(array(
    'login;status',
    '"multi',
    'line";active',
    'after;new',
));
csqCheck(
    'перенос строки внутри закавыченного поля сохранён',
    count($rows) === 2 && isset($rows[0]['login']) && strpos($rows[0]['login'], "\n") !== false,
    'распознано ' . count($rows) . ', первое значение: '
        . (isset($rows[0]['login']) ? json_encode($rows[0]['login']) : 'нет')
);
csqCheck(
    'строка после многострочного поля не потеряна',
    isset($rows[1]['login']) && $rows[1]['login'] === 'after',
    isset($rows[1]['login']) ? "получили '{$rows[1]['login']}'" : 'строки нет'
);

// ── 4. Экранированная кавычка "" внутри закавыченного поля ──
$rows = csqParse(array(
    'login;status',
    '"say ""hi""";active',
    'tail;new',
));
csqCheck(
    'экранированная кавычка "" разбирается как одна',
    isset($rows[0]['login']) && $rows[0]['login'] === 'say "hi"',
    isset($rows[0]['login']) ? "получили " . json_encode($rows[0]['login']) : 'поля нет'
);
csqCheck('строка после экранированных кавычек не потеряна', count($rows) === 2, 'распознано ' . count($rows));

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

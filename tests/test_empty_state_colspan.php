<?php
/**
 * Тест партиала пустой выдачи таблицы (templates/partials/table/empty-state.php).
 *
 * Запуск:  php tests/test_empty_state_colspan.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД — рендерим партиал в буфер и проверяем:
 *  - рендер не бросает ошибку (регрессия: `count()` без аргумента — на PHP 7.4
 *    это Warning, на PHP 8 — фатальная ArgumentCountError, и пустая таблица
 *    роняет всю страницу дашборда);
 *  - colspan == count($ALL_COLUMNS) + 3, где +3 — это служебные колонки
 *    checkbox, favorite и actions (см. colgroup в partials/table/columns.php).
 */

// Warning/Notice тоже считаем провалом: на проде error_reporting=E_ALL,
// а кастомный обработчик ошибок конвертирует их в ErrorException и обрывает рендер.
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$failures = 0;
$passed   = 0;

/**
 * Рендерит партиал с заданным набором колонок.
 *
 * @param array $columns значение $ALL_COLUMNS, видимое партиалу
 * @return string HTML
 */
function renderEmptyState(array $columns): string
{
    $ALL_COLUMNS = $columns; // партиал читает эту переменную из области видимости include
    ob_start();
    try {
        include __DIR__ . '/../templates/partials/table/empty-state.php';
    } finally {
        $html = ob_get_clean();
    }
    return $html;
}

/**
 * @param string   $name
 * @param callable $fn возвращает [ok, сообщение при провале]
 */
function check(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        [$ok, $msg] = $fn();
    } catch (Throwable $e) {
        $ok  = false;
        $msg = get_class($e) . ': ' . $e->getMessage();
    }
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name — $msg\n";
    }
}

/** Достаёт число из colspan="N". */
function colspanOf(string $html): ?int
{
    return preg_match('/colspan="(\d+)"/', $html, $m) ? (int)$m[1] : null;
}

echo "\n=== empty-state.php — рендер и colspan ===\n\n";

$cases = [
    'нет колонок'        => [],
    '1 колонка'          => ['id' => 'ID'],
    '5 колонок'          => ['id' => 'ID', 'login' => 'Логин', 'email' => 'Email', 'status' => 'Статус', 'token' => 'Token'],
];

foreach ($cases as $label => $columns) {
    $expected = count($columns) + 3; // checkbox + favorite + actions
    check("$label → colspan=$expected, без ошибок", static function () use ($columns, $expected) {
        $html = renderEmptyState($columns);
        $got  = colspanOf($html);
        if ($got === null) {
            return [false, 'в HTML нет colspan'];
        }
        return [$got === $expected, "ожидали colspan=$expected, получили $got"];
    });
}

check('партиал не падает, если $ALL_COLUMNS вообще не определён', static function () {
    // Отдельный include без предварительного объявления переменной:
    // партиал обязан быть самодостаточным, как и rows.php.
    ob_start();
    try {
        include __DIR__ . '/../templates/partials/table/empty-state.php';
    } finally {
        $html = ob_get_clean();
    }
    $got = colspanOf($html);
    return [$got === 3, 'ожидали colspan=3 (только служебные колонки), получили ' . var_export($got, true)];
});

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

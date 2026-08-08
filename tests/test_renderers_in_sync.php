<?php
/**
 * Тест: серверный и клиентский рендереры строк таблицы не разъехались.
 *
 * Запуск:  php tests/test_renderers_in_sync.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД: читаем исходники и сравниваем контракт разметки.
 *
 * Зачем этот тест существует. Строку таблицы рисуют ДВА независимых места:
 *   1. templates/partials/table/rows.php — первая отрисовка страницы;
 *   2. assets/js/table-module.js        — обновление по AJAX (refresh.php
 *      отдаёт данные, а не готовый HTML).
 * Объединить их в одно нельзя, не пожертвовав виртуализацией: она работает по
 * данным (enableFromData), а не по готовому HTML. Значит, оба должны выдавать
 * одинаковую разметку — иначе таблица после AJAX-обновления выглядит иначе, чем
 * после перезагрузки страницы, и замечают это не сразу.
 *
 * Так уже было: правка colspan пустой выдачи и удаление дублирующих data-*
 * требовали синхронного изменения в обоих файлах, и ничто этого не проверяло.
 * Третий рендерер (класс Dashboard в dashboard.js) удалён 2026-08-08 как
 * недостижимый — см. комментарий в конце того файла.
 *
 * Тест намеренно проверяет КОНТРАКТ (набор атрибутов и маркеров), а не побайтовое
 * совпадение: разметка в PHP и JS пишется по-разному, и требовать идентичности
 * текста бессмысленно.
 */

$failures = 0;
$passed   = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Читает исходник и вырезает строки-комментарии.
 *
 * Без этого тест ловит сам себя: в rows.php и dashboard.js есть комментарии,
 * которые ЦИТИРУЮТ проверяемые строки («не пишем data-field-type="text"»,
 * «строки рисует table-module.js — 50 вызовов renderRow»). Проверять надо
 * разметку, а не прозу вокруг неё.
 *
 * @param string $path
 * @return string исходник без строк, начинающихся с //, * или #
 */
function sourceWithoutComments(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Не удалось прочитать $path\n");
        exit(1);
    }

    $kept = [];
    foreach (explode("\n", $raw) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0
            || strpos($trimmed, '/*') === 0 || strpos($trimmed, '#') === 0
        ) {
            continue;
        }
        $kept[] = $line;
    }

    return implode("\n", $kept);
}

$php         = sourceWithoutComments(__DIR__ . '/../templates/partials/table/rows.php');
$js          = sourceWithoutComments(__DIR__ . '/../assets/js/table-module.js');
$dashboardJs = sourceWithoutComments(__DIR__ . '/../assets/js/dashboard.js');

echo "\n=== Служебные ячейки помечены одинаково ===\n\n";

foreach (['checkbox', 'favorite', 'actions'] as $service) {
    $marker = 'data-column="' . $service . '"';
    ok(
        "служебная ячейка $service помечена в обоих рендерерах",
        strpos($php, $marker) !== false && strpos($js, $marker) !== false,
        'в rows.php: ' . (strpos($php, $marker) !== false ? 'есть' : 'НЕТ')
            . ', в table-module.js: ' . (strpos($js, $marker) !== false ? 'есть' : 'НЕТ')
    );
}

echo "\n=== Ячейки данных ===\n\n";

// Обе реализации обязаны помечать ячейку данных через data-col.
ok('rows.php ставит data-col ячейкам данных', strpos($php, 'data-col="<?= e($k) ?>"') !== false);
ok('table-module.js ставит data-col ячейкам данных', strpos($js, 'data-col="${col}"') !== false);

// И ни одна не должна возвращать дубль data-column на ячейках данных:
// он был удалён 2026-08-08, потребитель (cell-actions.js) читает data-col.
$phpDataCells = preg_match_all('/<td class="ac-cell[^"]*"[^>]*data-col=[^>]*data-column=/', $php);
$jsDataCells  = preg_match_all('/<td class="ac-cell[^"]*"[^>]*data-col=[^>]*data-column=/', $js);
ok('rows.php не дублирует data-column на ячейках данных', $phpDataCells === 0, "найдено: $phpDataCells");
ok('table-module.js не дублирует data-column на ячейках данных', $jsDataCells === 0, "найдено: $jsDataCells");

echo "\n=== Редактируемое поле ===\n\n";

foreach ([['rows.php', $php], ['table-module.js', $js]] as [$name, $src]) {
    ok("$name использует обёртку editable-field-wrap", strpos($src, 'editable-field-wrap') !== false);
    ok("$name передаёт data-row-id", strpos($src, 'data-row-id') !== false);
    ok("$name передаёт data-field", strpos($src, 'data-field=') !== false);
}

// data-field-type пишется только для числовых полей: JS сравнивает строго с
// 'numeric', а лишний data-field-type="text" — это ~45 пустых атрибутов на строку.
foreach ([['rows.php', $php], ['table-module.js', $js]] as [$name, $src]) {
    ok(
        "$name не пишет data-field-type=\"text\"",
        strpos($src, 'data-field-type="text"') === false
    );
}

echo "\n=== Строка таблицы ===\n\n";

foreach ([['rows.php', $php], ['table-module.js', $js]] as [$name, $src]) {
    ok("$name помечает строку классом ac-row", strpos($src, 'class="ac-row') !== false);
    ok("$name кладёт id строки в data-id", (bool)preg_match('/<tr[^>]*data-id=/', $src));
}

echo "\n=== Третий рендерер не вернулся ===\n\n";

ok(
    'dashboard.js больше не строит строки таблицы',
    strpos($dashboardJs, 'renderRow') === false
        && strpos($dashboardJs, 'editable-field-wrap') === false
        && strpos($dashboardJs, 'class Dashboard') === false,
    'если правка нужна осознанно — обновите и этот тест, и комментарий в конце dashboard.js'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

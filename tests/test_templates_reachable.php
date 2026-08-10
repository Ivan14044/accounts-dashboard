<?php
/**
 * Тест: в templates/ нет файлов, которые не доходят ни до одной страницы.
 *
 * Запуск:  php tests/test_templates_reachable.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (2026-08-10, за одну сессию — три случая подряд).
 *
 * Неподключённый партиал — это не безобидный мусор, а активная ловушка: он
 * выглядит как рабочая разметка, его правят, и правка молча ни на что не влияет.
 * Что случилось на практике:
 *
 *  1. modals/add-account-modal.php дублировал форму импорта. В копии были
 *     радиокнопки выбора режима дубликатов, которых в рабочей форме не было, —
 *     то есть режим «Обновить» выглядел реализованным, а пользователю был
 *     недоступен. Я потратил время, правя подсказку в копии, и заметил подмену
 *     только по отрендеренной странице.
 *  2. Там же лежала единственная разметка предпросмотра CSV и прогресс-бара.
 *     dashboard-upload.js искал эти элементы, не находил и молча ничего не
 *     показывал — без ошибок в консоли и без записей в лог.
 *  3. Ещё шесть модалок в том же каталоге дублировали разметку, уже вставленную
 *     в dashboard.php.
 *
 * Инвариант: каждый файл в templates/ обязан быть достижим по цепочке
 * include/require хотя бы от одной точки входа. Точки входа определяются из кода
 * (кто вообще подключает что-то из templates/), а не задаются списком, — чтобы
 * тест не устарел при добавлении новой страницы.
 *
 * Тест намеренно НЕ проверяет содержимое: дубликат разметки, который всё-таки
 * подключён, — это другая проблема, и ловить её надо иначе.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * Файлы, про которые известно, что они не подключены, и решение по ним ещё
 * не принято владельцем. Список обязан стремиться к пустому: каждая запись —
 * незакрытый долг, а не разрешение плодить мёртвые файлы.
 *
 * templates/partials/dashboard/toolbar.php — расходящаяся копия живого
 * templates/partials/table/toolbar.php (различие 65 строк). Живой подключается
 * из partials/table/table.php, этот — ниоткуда. Найден 2026-08-10.
 *
 * @var string[]
 */
$KNOWN_ORPHANS = array(
    'templates/partials/dashboard/toolbar.php',
);

/**
 * Фиксирует результат проверки.
 *
 * @param string $name Что проверяли
 * @param bool $ok Прошло ли
 * @param string $detail Подробности при провале
 * @return void
 */
function trCheck($name, $ok, $detail = '')
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
 * Все *.php под каталогом, пути относительно корня проекта.
 *
 * @param string $root Корень проекта
 * @param string $dir Каталог относительно корня
 * @return string[]
 */
function trPhpFiles($root, $dir)
{
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $out[] = ltrim(str_replace($root, '', $f->getPathname()), '/');
        }
    }
    sort($out);
    return $out;
}

/**
 * Пути к шаблонам, которые подключает код ВНЕ каталога templates/ —
 * это и есть точки входа в шаблоны.
 *
 * @param string $root Корень проекта
 * @return string[] Пути относительно корня
 */
function trEntryTemplates($root)
{
    $sources = array();
    foreach (array('', 'includes', 'api') as $dir) {
        $path = $root . ($dir === '' ? '' : '/' . $dir);
        if (!is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
            // Сами шаблоны и тесты точками входа не считаем
            if (strpos($rel, 'templates/') === 0 || strpos($rel, 'tests/') === 0) {
                continue;
            }
            $sources[] = $f->getPathname();
        }
    }

    $entries = array();
    foreach ($sources as $src) {
        $code = file_get_contents($src);
        if (preg_match_all('~[\'"]([^\'"]*templates/[^\'"]+\.php)[\'"]~', $code, $m)) {
            foreach ($m[1] as $hit) {
                $pos = strpos($hit, 'templates/');
                $entries[] = substr($hit, $pos);
            }
        }
    }
    return array_values(array_unique($entries));
}

/**
 * Транзитивно раскрывает include/require внутри шаблонов.
 *
 * @param string $root Корень проекта
 * @param string[] $entries Стартовые шаблоны относительно корня
 * @return array<string, true> Достижимые пути относительно корня
 */
function trReachable($root, array $entries)
{
    $seen = array();
    $queue = $entries;
    while ($queue) {
        $rel = array_shift($queue);
        $abs = realpath($root . '/' . $rel);
        if ($abs === false || !is_file($abs)) {
            continue;
        }
        $norm = ltrim(str_replace($root, '', $abs), '/');
        if (isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;

        $code = file_get_contents($abs);
        if (preg_match_all('~(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]~', $code, $m)) {
            foreach ($m[1] as $r) {
                $child = realpath(dirname($abs) . '/' . ltrim($r, '/'));
                if ($child !== false) {
                    $queue[] = ltrim(str_replace($root, '', $child), '/');
                }
            }
        }
    }
    return $seen;
}

echo "\n=== Шаблоны: каждый файл доходит хотя бы до одной страницы ===\n\n";

$entries = trEntryTemplates($ROOT);
trCheck(
    'точки входа в шаблоны найдены',
    count($entries) >= 3,
    'нашли ' . count($entries) . ' — способ подключения шаблонов изменился, тест надо пересмотреть'
);

$reachable = trReachable($ROOT, $entries);
$all = trPhpFiles($ROOT, 'templates');

trCheck('файлы шаблонов найдены', count($all) > 5, 'нашли ' . count($all));

$orphans = array();
foreach ($all as $rel) {
    if (!isset($reachable[$rel])) {
        $orphans[] = $rel;
    }
}

$unexpected = array_values(array_diff($orphans, $KNOWN_ORPHANS));
trCheck(
    'новых неподключённых шаблонов нет',
    $unexpected === array(),
    'не подключены ниоткуда: ' . implode(', ', $unexpected)
        . '. Такой файл выглядит рабочим, но правки в нём ни на что не влияют —'
        . ' либо подключите его, либо удалите'
);

// Список известных долгов обязан оставаться правдой: если файл уже удалили или
// подключили, запись надо убрать, иначе список превратится в свалку.
$staleAllow = array();
foreach ($KNOWN_ORPHANS as $rel) {
    if (!in_array($rel, $orphans, true)) {
        $staleAllow[] = $rel;
    }
}
trCheck(
    'список известных долгов не протух',
    $staleAllow === array(),
    'уже не сироты, уберите из $KNOWN_ORPHANS: ' . implode(', ', $staleAllow)
);

echo "\n  (известных долгов: " . count($KNOWN_ORPHANS) . ", всего шаблонов: " . count($all) . ")\n";

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

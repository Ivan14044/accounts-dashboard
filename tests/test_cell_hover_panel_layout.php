<?php
/**
 * Тест: hover-панель действий ячейки не участвует в геометрии таблицы.
 *
 * Запуск:  php tests/test_cell_hover_panel_layout.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10 замером на стенде,
 * 182 021 строка, 50 колонок, вьюпорт 1280×800).
 *
 * Жалоба владельца: «навожу на строку — она скачет, панель с кнопками
 * появляется неправильно». Замер показал ровно две причины.
 *
 * ПРИЧИНА 1 — панель лежала В ПОТОКЕ ячейки.
 * `placePanel()` вставлял панель внутрь `.editable-field-wrap` / `.pw-mask`
 * и ставил ей `display: contents`, из-за чего кнопки становились flex-элементами
 * обёртки. Таблица при этом `table-layout: auto` (assets/css/core-tables.css) —
 * то есть ширина колонки считается по содержимому. Каждое наведение меняло
 * max-content ячейки, браузер пересчитывал раскладку ВСЕЙ таблицы 50×50.
 * Замерено при проводке мыши вдоль одной строки:
 *   сдвигалось 35–42 ячейки, максимум на 73,7 px, 2,6–12,6 мс layout на каждый
 *   переход между ячейками (бюджет кадра 60 fps — 16,7 мс).
 * После фикса на том же стенде: 0 сдвинутых ячеек, дельта ширины таблицы 0 px.
 *
 * ПРИЧИНА 2 — редакторы ЗАПЕКАЛИ панель в ячейку.
 * `inline-edit.js` и ветка `.pw-edit` в `dashboard-init.js` сохраняют
 * `originalContent = wrap.innerHTML`, чтобы восстановить ячейку после
 * сохранения/отмены. Панель в этот момент — ребёнок этой самой обёртки,
 * поэтому в снимок попадала и она, а восстановление вклеивало её КОПИЮ
 * статической разметкой. Копию никто не убирал: `removePanel()` знает только
 * про живой узел. Замерено на стенде:
 *   ячейка last_name  108,9 → 174,3 px (навсегда, +65,4);
 *   ячейка password   102,8 → 213,5 px (навсегда, +110,7);
 *   в ячейке оставался второй комплект кнопок .field-edit-btn / .cha-copy.
 * Эффект накапливался: каждая отредактированная ячейка «толстела» ещё раз.
 *
 * Поэтому тест стережёт четыре инварианта:
 *   1) панель не возвращается в поток (`display: contents` запрещён);
 *   2) у панели есть CSS `position: absolute`, и он реально уезжает на прод
 *      (файл с правилом входит в бандл core.css);
 *   3) у ячеек-хозяев есть контекст позиционирования (`position: relative`);
 *   4) оба редактора отцепляют панель ДО снятия снимка innerHTML.
 *
 * Тест смотрит только на реальный код: комментарии и строковые литералы
 * вырезаются — иначе он ловил бы сам себя в описании выше.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// JIT PCRE упирается в стек на шаблонах строковых литералов — отключаем.
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * Вырезает из JS комментарии и строковые литералы, чтобы проверки не срабатывали
 * на тексте внутри строк и docblock'ов.
 *
 * @param string $code Исходный текст файла
 * @param string $label Имя файла для сообщения об ошибке
 * @return string Код без комментариев и содержимого строк
 */
function chpStripJs($code, $label)
{
    $steps = array(
        '~/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s' => '',
        '~(^|[^:\\\\])//[^\n]*~m'           => '$1',
        '~`(?:\\\\.|[^`\\\\])*`~s'          => '``',
        "~'(?:\\\\.|[^'\\\\\n])*'~"         => "''",
        '~"(?:\\\\.|[^"\\\\\n])*"~'         => '""',
    );
    foreach ($steps as $re => $repl) {
        $out = preg_replace($re, $repl, $code);
        if ($out === null) {
            // Молчаливый null от preg_replace однажды уже превратил сбой проверки
            // в «всё хорошо» — поэтому падаем громко.
            throw new RuntimeException("PCRE не справился с $label (код " . preg_last_error() . ')');
        }
        $code = $out;
    }
    return $code;
}

/**
 * Вырезает из JS только комментарии, оставляя строковые литералы.
 *
 * Нужна там, где искомое значение живёт именно в строке (`display: contents`),
 * но не должно ловиться в тексте комментария — иначе тест падает на описании
 * бага, который он стережёт.
 *
 * @param string $code Исходный текст файла
 * @param string $label Имя файла для сообщения об ошибке
 * @return string Код без комментариев
 */
function chpStripJsComments($code, $label)
{
    $steps = array(
        '~/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s' => '',
        '~(^|[^:\\\\])//[^\n]*~m'           => '$1',
    );
    foreach ($steps as $re => $repl) {
        $out = preg_replace($re, $repl, $code);
        if ($out === null) {
            throw new RuntimeException("PCRE не справился с $label (код " . preg_last_error() . ')');
        }
        $code = $out;
    }
    return $code;
}

/**
 * Вырезает комментарии из CSS.
 *
 * @param string $css Исходный текст стилей
 * @return string Стили без комментариев
 */
function chpStripCss($css)
{
    $out = preg_replace('~/\*.*?\*/~s', '', $css);
    if ($out === null) {
        throw new RuntimeException('PCRE не справился с CSS (код ' . preg_last_error() . ')');
    }
    return $out;
}

/**
 * Фиксирует результат проверки.
 *
 * @param bool $ok Прошла ли проверка
 * @param string $okText Что печатать при успехе
 * @param string $failText Что печатать при провале
 * @return void
 */
function chpCheck($ok, $okText, $failText)
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        echo "  [OK]   $okText\n";
    } else {
        $failures++;
        echo "  [FAIL] $failText\n";
    }
}

echo "\n=== Hover-панель ячейки: вне потока и не запекается в ячейку ===\n\n";

// ---------------------------------------------------------------------------
// 1. Панель не возвращается в поток ячейки
// ---------------------------------------------------------------------------
$cellActionsPath = $ROOT . '/assets/js/modules/cell-actions.js';
$cellActionsRaw  = file_get_contents($cellActionsPath);
$cellActions     = chpStripJs($cellActionsRaw, 'assets/js/modules/cell-actions.js');

// display:contents делает кнопки flex-элементами обёртки — ровно то, что
// пересчитывало ширину колонки. Строковые литералы здесь НЕ вырезаем (значение
// живёт как раз в строке), а комментарии — вырезаем, иначе проверка поймает
// описание бага в шапке файла.
$cellActionsNoComments = str_replace(
    ' ',
    '',
    chpStripJsComments($cellActionsRaw, 'assets/js/modules/cell-actions.js')
);
chpCheck(
    strpos($cellActionsNoComments, 'contents') === false,
    'cell-actions.js не возвращает панель в поток (нет display:contents)',
    'cell-actions.js снова ставит панели display:contents — панель попадёт'
        . ' в flex-поток ячейки и вернёт скачки колонок'
);

// ---------------------------------------------------------------------------
// 2-3. CSS: панель позиционирована абсолютно, у ячеек есть контекст
// ---------------------------------------------------------------------------
$cssPath = $ROOT . '/assets/css/core-tables.css';
$css     = chpStripCss(file_get_contents($cssPath));

// Правило .cell-hover-actions { ... position: absolute ... }
$panelRuleHasAbsolute = false;
if (preg_match('~\.cell-hover-actions[^{]*\{([^}]*)\}~s', $css, $m)) {
    $panelRuleHasAbsolute = (bool)preg_match('~position\s*:\s*absolute~i', $m[1]);
}
chpCheck(
    $panelRuleHasAbsolute,
    'core-tables.css: у .cell-hover-actions есть position: absolute',
    'core-tables.css: у .cell-hover-actions нет правила position: absolute —'
        . ' панель снова будет занимать место в ячейке'
);

// Контекст позиционирования у ячеек данных. Без него absolute уедет к ближайшему
// позиционированному предку (в этой вёрстке — к секции таблицы), и панель
// улетит в угол экрана вместо своей ячейки.
$hostHasRelative = false;
if (preg_match_all('~([^{}]*td[^{}]*)\{([^}]*)\}~s', $css, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $rule) {
        if (strpos($rule[1], 'ac-cell') === false) {
            continue;
        }
        if (preg_match('~position\s*:\s*relative~i', $rule[2])) {
            $hostHasRelative = true;
            break;
        }
    }
}
chpCheck(
    $hostHasRelative,
    'core-tables.css: у ячеек .ac-cell есть position: relative (якорь для панели)',
    'core-tables.css: ни одно правило для td.ac-cell не даёт position: relative —'
        . ' абсолютной панели не к чему прицепиться'
);

// Правило обязано доехать на прод: файл должен быть в бандле core.css.
require_once $ROOT . '/includes/AssetBundles.php';
$cssBundle = AssetBundles::files('core.css');
chpCheck(
    in_array('assets/css/core-tables.css', $cssBundle, true),
    'core-tables.css входит в бандл core.css — правило уедет на прод',
    'core-tables.css выпал из бандла core.css — стили панели не доедут до прода'
);

// ---------------------------------------------------------------------------
// 4. Редакторы отцепляют панель ДО снятия снимка innerHTML
// ---------------------------------------------------------------------------

// Публичный API отцепления должен существовать.
chpCheck(
    preg_match('~window\.CellActions\s*=~', $cellActions) === 1
        && preg_match('~detach\s*[:(]~', $cellActions) === 1,
    'cell-actions.js публикует window.CellActions с detach()',
    'cell-actions.js не публикует window.CellActions.detach() — редакторам нечем'
        . ' отцепить панель перед снимком innerHTML'
);

/**
 * Проверяет, что перед снятием снимка innerHTML панель отцепляют.
 *
 * @param string $rel Путь файла относительно корня (для сообщений)
 * @param string $abs Абсолютный путь файла
 * @param string $captureRe Регексп, ловящий строку снятия снимка
 * @return void
 */
function chpAssertDetachBeforeSnapshot($rel, $abs, $captureRe)
{
    $code = chpStripJs(file_get_contents($abs), $rel);

    if (!preg_match($captureRe, $code, $m, PREG_OFFSET_CAPTURE)) {
        chpCheck(false, '', "$rel: не нашёл снятие снимка innerHTML ($captureRe) —"
            . ' тест устарел или код переписан, проверь руками');
        return;
    }
    $captureAt = $m[0][1];

    // Отцепление обязано стоять в том же обработчике ВЫШЕ снимка.
    // 2500 символов — с запасом на тело ветки, но не на весь файл.
    $windowStart = max(0, $captureAt - 2500);
    $before      = substr($code, $windowStart, $captureAt - $windowStart);

    chpCheck(
        preg_match('~CellActions[\s\S]{0,40}?detach\s*\(~', $before) === 1,
        "$rel: панель отцепляется до снятия снимка innerHTML",
        "$rel: снимок innerHTML снимается, пока панель ещё внутри обёртки —"
            . ' её копия запечётся в ячейку и та навсегда станет шире'
    );
}

chpAssertDetachBeforeSnapshot(
    'assets/js/modules/inline-edit.js',
    $ROOT . '/assets/js/modules/inline-edit.js',
    '~originalContent\s*=\s*wrap\.innerHTML~'
);

chpAssertDetachBeforeSnapshot(
    'assets/js/dashboard-init.js',
    $ROOT . '/assets/js/dashboard-init.js',
    '~originalContent\s*=\s*pwWrap\.innerHTML~'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

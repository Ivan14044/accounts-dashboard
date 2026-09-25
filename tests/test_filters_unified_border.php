<?php
/**
 * Тест: у всех полей панели фильтров одна обводка.
 *
 * Запуск:  php tests/test_filters_unified_border.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем (жалоба владельца 2026-09-25: «обводка в быстрых и дополнительных
 * фильтрах отображается криво»). Замер на стенде до правки:
 *   - рамки 1.5px — на экране 1× такая линия размывается;
 *   - два оттенка рамки в светлой теме, три в тёмной;
 *   - пять разных высот полей (32/33/35/40/44);
 *   - у <select> «Телефон» и «Статус БМ» не было стрелки: шорткат
 *     `background: … !important` в core-theme.css / core-dark.css стирал
 *     background-image, которым рисуется шеврон.
 * После правки на стенде: 27 полей, у всех 1px, один цвет и один фон в обеих
 * темах, высоты 40 (первая строка) и 34 (остальное; на телефоне 40/44).
 *
 * Тест смотрит на CSS (комментарии вырезаются) и стережёт:
 *   1) в core-design-v2.css есть правило, которое ставит всем пяти видам
 *      полей панели рамку 1px;
 *   2) у <select> в панели шеврон задан с !important (иначе его снова сотрёт
 *      core-theme.css / core-dark.css);
 *   3) правило специфичнее тёмной темы: начинается с `.filters-modern `,
 *      потому что core-dark.css грузится позже;
 *   4) в разметке фильтров не осталось инлайнового `border-width: 1.5px`
 *      (инлайн перебил бы любое правило из файла);
 *   5) на телефоне быстрые фильтры остаются 44px под палец.
 *
 * TEST_ROOT в окружении — прогнать тест на другом дереве (так проверялось,
 * что на коде до правки он красный).
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = getenv('TEST_ROOT') ? getenv('TEST_ROOT') : dirname(__DIR__);
$failures = 0;
$passed = 0;

/**
 * Отмечает результат проверки.
 *
 * @param bool   $ok   Прошла ли проверка.
 * @param string $name Что проверяли.
 * @return void
 */
function check($ok, $name)
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

/**
 * Разбирает CSS на пары [селекторы, тело] без комментариев.
 *
 * @param string $css Текст CSS.
 * @return array<int, array{0:string,1:string}>
 */
function cssRules($css)
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $out = [];
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $r) {
            $out[] = [trim($r[1]), $r[2]];
        }
    }
    return $out;
}

$css = file_get_contents($ROOT . '/assets/css/core-design-v2.css');
$rules = cssRules($css);

// 1) рамка 1px у всех пяти видов полей
$kinds = [
    '.toggle-switch-wrapper',
    '.search-input-modern',
    '.range-input-modern',
    '.form-select',
    '.dropdown-toggle.btn-outline-secondary',
];
$borderRule = null;
foreach ($rules as $r) {
    if (preg_match('/border-width\s*:\s*1px/', $r[1])) {
        $all = true;
        foreach ($kinds as $k) {
            if (strpos($r[0], '.filters-modern-body ' . $k) === false) {
                $all = false;
                break;
            }
        }
        if ($all) {
            $borderRule = $r;
            break;
        }
    }
}
check($borderRule !== null, 'нет общего правила border-width:1px для всех полей панели фильтров');

// 3) специфичность выше тёмной темы
if ($borderRule !== null) {
    $ok = true;
    foreach (explode(',', $borderRule[0]) as $sel) {
        if (strpos(trim($sel), '.filters-modern ') !== 0) {
            $ok = false;
        }
    }
    check($ok, 'селекторы общего правила должны начинаться с `.filters-modern ` (иначе core-dark.css перебьёт)');
}

// 2) шеврон у <select> с !important
$chevron = false;
foreach ($rules as $r) {
    if (strpos($r[0], '.filters-modern-body .form-select') !== false
        && preg_match('/background-image\s*:\s*url\([^)]*\)\s*!important/', $r[1])) {
        $chevron = true;
    }
}
check($chevron, 'у <select> в панели фильтров нет шеврона с !important');

// 4) нет инлайнового border-width: 1.5px в разметке фильтров
$tpl = file_get_contents($ROOT . '/templates/partials/dashboard/filters.php');
check(strpos($tpl, 'border-width: 1.5px') === false, 'в filters.php остался инлайновый border-width: 1.5px');

// 5) на телефоне быстрые фильтры 44px
check(
    (bool)preg_match('/@media\s*\(max-width:\s*767px\)\s*\{[^@]*\.quick-filters-grid\s*>\s*\.toggle-switch-wrapper\s*\{[^}]*min-height\s*:\s*44px/s',
        preg_replace('#/\*.*?\*/#s', '', $css)),
    'на телефоне у быстрых фильтров должна остаться высота 44px'
);

echo "passed: {$passed}, failed: {$failures}\n";
exit($failures ? 1 : 0);

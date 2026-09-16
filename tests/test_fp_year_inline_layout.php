<?php
/**
 * Вёрстка инлайн-диапазона «Год создания Fan Page» и крестика очистки в поиске
 * по статусам.
 *
 * Зачем тест. 16.09.2026 владелец показал, что при включении фильтра Fan Page
 * панель года НАКРЫВАЕТ соседний тумблер, а вся таблица под фильтрами прыгает
 * вниз. Замеры на стенде (1280px) объяснили обе беды:
 *   - открытая ячейка занимала две колонки (328px), из них панели доставалось
 *     170px при минимальной ширине 206px — 36px вылезали на соседа;
 *   - подпись при этом переносилась на вторую строку, панель становилась 69px
 *     против 33px у тумблера, и строка сетки вырастала на 36px.
 * Тест стережёт инварианты починки, чтобы они не ушли при будущих правках CSS.
 *
 * Запуск: php tests/test_fp_year_inline_layout.php (код выхода 0 = успех).
 */

$root = dirname(__DIR__);
$css  = file_get_contents($root . '/assets/css/core-plugins.css');
$js   = file_get_contents($root . '/assets/js/filters-modern.js');
$init = file_get_contents($root . '/assets/js/dashboard-init.js');
$tpl  = file_get_contents($root . '/templates/partials/dashboard/filters.php');

$checks = array();

// ── Панель года не должна вылезать за ячейку ────────────────────────────────
$checks['панель года сжимается (min-width: 0)'] = (bool)preg_match(
    '/\.fp-quick-cell\.expanded\s*>\s*\.fp-year-inline\s*\{[^}]*min-width:\s*0/s', $css);
$checks['поля года сжимаются (flex + min-width)'] = (bool)preg_match(
    '/\.fp-year-inline-inputs\s+\.range-input-modern\s*\{[^}]*min-width:\s*4\d px?|\.fp-year-inline-inputs\s+\.range-input-modern\s*\{[^}]*min-width:\s*4\dpx/s', $css);
$checks['блок полей года без жёсткого min-width 180px'] = strpos($css, 'min-width: 180px') === false
    || !preg_match('/\.fp-year-inline-inputs\s*\{[^}]*min-width:\s*180px/s', $css);

// ── Высота панели не больше высоты тумблера ─────────────────────────────────
$checks['поле года низкое (26px)'] = (bool)preg_match(
    '/\.fp-year-inline-inputs\s+\.range-input-modern\s*\{[^}]*height:\s*26px/s', $css);
$checks['у панели года маленький вертикальный отступ'] = (bool)preg_match(
    '/\.fp-year-inline\s*\{[^}]*padding:\s*1px/s', $css);
$checks['в узком режиме текст подписи прячется'] = strpos($css, '.fp-year-inline.compact .fp-year-inline-label-text') !== false;
$checks['подпись обёрнута в отдельный span'] = strpos($tpl, 'fp-year-inline-label-text') !== false;

// ── Ширина подбирается под остаток строки ──────────────────────────────────
$checks['ширина ячейки считается в JS'] = strpos($js, 'function applyFpYearSpan') !== false;
$checks['ширина применяется при каждом показе'] = strpos($js, 'applyFpYearSpan(cell, inline, show)') !== false;
$checks['колонка меряется у СЛОЖЕННОЙ ячейки'] = (bool)preg_match(
    "/classList\.remove\('expanded'\);\s*\n\s*var left = cell\.offsetLeft/", $js);
$checks['ширина не больше трёх и не меньше двух колонок'] = (bool)preg_match(
    '/Math\.min\(3,\s*Math\.max\(2,/', $js);
$checks['рост сетки анимируется'] = strpos($js, '_fpGrow') !== false;

// ── Крестик очистки в поиске по статусам ───────────────────────────────────
$checks['в поиске статусов есть кнопка очистки'] = strpos($tpl, 'id="statusSearchClear"') !== false;
$checks['у кнопки очистки есть подпись для скринридера'] = strpos($tpl, 'aria-label="Очистить поиск по статусам"') !== false;
$checks['поле поиска статусов сохранило placeholder'] = strpos($tpl, 'id="statusSearch" placeholder=') !== false;
$checks['крестик показывается только при непустом поле'] = strpos(
    $css, '.status-search-input:not(:placeholder-shown) ~ .status-search-clear') !== false;
$checks['под крестик оставлено место в поле'] = (bool)preg_match(
    '/\.status-search-input\s*\{[^}]*padding-right:/s', $css);
$checks['крестик чистит поле и не закрывает список'] = strpos($init, 'statusSearchClear') !== false
    && (bool)preg_match("/statusSearchClear\.addEventListener\('click'.{0,400}stopPropagation.{0,400}statusSearch\.value = ''/s", $init);

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) { $failed++; }
}

if ($failed > 0) {
    echo "Провалено проверок: $failed\n";
    exit(1);
}
echo 'Все проверки пройдены (' . count($checks) . ")\n";
exit(0);

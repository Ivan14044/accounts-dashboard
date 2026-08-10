<?php
/**
 * Тест: шапка страницы не вылезает за экран на узких вьюпортах.
 *
 * Запуск:  php tests/test_page_nav_no_overflow.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (замерено в браузере 2026-08-10, вьюпорт 375).
 *
 * У страниц корзины и избранного шапка сделана так:
 *   <nav class="navbar navbar-expand ..." style="height: 64px;">
 *     <div class="container-fluid px-4"> [бренд] [имя пользователя + кнопки] </div>
 *
 * Два свойства вместе давали горизонтальный вылет:
 *  1. `navbar-expand` БЕЗ брейкпоинта означает «никогда не сворачиваться»,
 *     и Bootstrap ставит контейнеру flex-wrap: nowrap;
 *  2. высота прибита инлайновым стилем, то есть содержимому некуда переноситься.
 *
 * Замер на 375 px: контейнеру доступно 343 px, а его дети требуют 143 + 296 = 439.
 * Итог — `document.body.scrollWidth - clientWidth` = 108 на корзине и 110 на
 * избранном; если спрятать шапку, вылет становится 0. Таблица тут ни при чём:
 * она лежит в контейнере с overflow-x: auto и скроллится внутри себя.
 *
 * Горизонтальный скролл у body — прямое нарушение критериев приёмки UI из
 * CLAUDE.md, и на телефоне он утаскивает вбок всю страницу.
 *
 * Инвариант: шапке нельзя прибивать высоту инлайном (инлайн победит любой CSS),
 * а её содержимое обязано уметь переноситься на вторую строку.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
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
function pnoCheck($name, $ok, $detail = '')
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

echo "\n=== Шапка страницы: без горизонтального вылета ===\n\n";

// ── 1. Ни один шаблон не прибивает высоту шапки инлайном ──
$templates = glob($ROOT . '/templates/*.php');
sort($templates);
pnoCheck('шаблоны найдены', count($templates) >= 3, 'нашли ' . count($templates));

$withInlineHeight = array();
foreach ($templates as $path) {
    $src = file_get_contents($path);
    if (preg_match_all('~<nav\b[^>]*>~i', $src, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('~style\s*=\s*"[^"]*\bheight\s*:\s*\d~i', $tag)) {
                $withInlineHeight[] = basename($path);
            }
        }
    }
}
pnoCheck(
    'ни одна шапка не задаёт height инлайном',
    $withInlineHeight === array(),
    'инлайновая высота в: ' . implode(', ', array_unique($withInlineHeight))
        . ' — инлайн победит любой CSS, и содержимому некуда переноситься'
);

// ── 2. В CSS есть правило, разрешающее содержимому шапки переноситься ──
$cssPath = $ROOT . '/assets/css/core-components.css';
$css = preg_replace('~/\*.*?\*/~s', '', file_get_contents($cssPath));

$hasWrap = false;
if (preg_match_all('~([^{}]*navbar[^{}]*)\{([^}]*)\}~s', $css, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $rule) {
        if (preg_match('~flex-wrap\s*:\s*wrap~i', $rule[2])) {
            $hasWrap = true;
            break;
        }
    }
}
pnoCheck(
    'core-components.css разрешает содержимому шапки переноситься (flex-wrap: wrap)',
    $hasWrap,
    'без переноса бренд и правая группа не помещаются в один ряд на узком экране'
);

$hasMinHeight = (bool)preg_match('~\.navbar[^{}]*\{[^}]*min-height\s*:~s', $css);
pnoCheck(
    'высота шапки задана через min-height, а не фиксированно',
    $hasMinHeight,
    'иначе вторая строка содержимого вылезет за пределы шапки'
);

// ── 3. Правило обязано доехать на прод ──
require_once $ROOT . '/includes/AssetBundles.php';
pnoCheck(
    'core-components.css входит в бандл core.css',
    in_array('assets/css/core-components.css', AssetBundles::files('core.css'), true),
    'правило не уедет на прод'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

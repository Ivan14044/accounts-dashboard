<?php
/**
 * Тест: у КАЖДОЙ кнопки плавающей панели ячейки есть оформление.
 *
 * Запуск:  php tests/test_cell_hover_buttons_styled.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем (жалоба владельца 2026-09-25, скриншот ячейки «Пароль»): рядом с
 * аккуратными кнопками «глаз» и «редактировать» висела кнопка «копировать»
 * в виде серого квадрата с чёрной выпуклой рамкой. Панель собирается в
 * assets/js/modules/cell-actions.js, и у кнопки копирования классы
 * `cha-copy copy-cell-btn` — ни один из них не упоминался ни в одном CSS,
 * поэтому браузер рисовал её своим стилем по умолчанию. Замер на стенде:
 * у соседей `border: 1px solid`, у неё `2px outset` и фон rgb(107,107,107).
 *
 * Инвариант: для каждой кнопки из разметки панели хотя бы один её класс
 * встречается в правиле, которое задаёт рамку (`border:`), — и в базовом
 * стиле таблицы (core-tables.css), и в стиле страницы (templates/dashboard.php),
 * который его перекрывает.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
$failures = 0;
$passed = 0;

$js = file_get_contents($ROOT . '/assets/js/modules/cell-actions.js');
if (!preg_match('/function buildPanel\(\)\s*\{(.*?)\n  \}/s', $js, $m)) {
    fwrite(STDERR, "FAIL: не нашёл buildPanel() в cell-actions.js\n");
    exit(1);
}
preg_match_all('/<button[^>]*class="([^"]+)"/', $m[1], $bm);
$buttons = $bm[1];
if (count($buttons) < 2) {
    fwrite(STDERR, "FAIL: в панели найдено кнопок: " . count($buttons) . "\n");
    exit(1);
}

/**
 * Возвращает список селекторов правил, в теле которых задан `border:`.
 *
 * @param string $css Текст CSS (комментарии вырезаются).
 * @return string[]
 */
function selectorsWithBorder($css)
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $out = [];
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $r) {
            if (preg_match('/(^|[;\s])border\s*:/', $r[2])) {
                $out[] = $r[1];
            }
        }
    }
    return $out;
}

$sources = [
    'assets/css/core-tables.css' => file_get_contents($ROOT . '/assets/css/core-tables.css'),
    'templates/dashboard.php'    => file_get_contents($ROOT . '/templates/dashboard.php'),
];

foreach ($sources as $name => $css) {
    $selectors = implode("\n", selectorsWithBorder($css));
    foreach ($buttons as $classList) {
        $found = false;
        foreach (preg_split('/\s+/', trim($classList)) as $cls) {
            if (preg_match('/\.' . preg_quote($cls, '/') . '(?![\w-])/', $selectors)) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $passed++;
        } else {
            $failures++;
            fwrite(STDERR, "FAIL: кнопка «{$classList}» без оформления рамки в {$name}\n");
        }
    }
}

echo "passed: {$passed}, failed: {$failures}\n";
exit($failures ? 1 : 0);

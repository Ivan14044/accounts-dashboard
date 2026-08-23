<?php
/**
 * Тест: обновление таблицы после снятия фильтра не зависит от прихода кадра,
 * и крестик на chip вызывает обработчик ровно один раз.
 *
 * Запуск:  php tests/test_filter_chip_refresh.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * ── Зачем этот тест существует (найдено 2026-08-23 замером на стенде) ──
 *
 * Симптом: клик по крестику на chip активного фильтра менял URL и снимал
 * галочку в форме, но таблица и список чипов оставались от прошлого фильтра.
 *
 * Причина оказалась НЕ в «гонке поколений» refresh, как считалось раньше.
 * В refreshDashboardData() синхронно выполнялись только счётчики и пагинация,
 * а вся содержательная часть — window.tableModule.updateRows(),
 * renderActiveFiltersFromUrl() и снятие прелоадера setTableLoadingState(false) —
 * лежала внутри requestAnimationFrame(). Кадр браузер обязан выдать далеко не
 * всегда: скрытая вкладка, полностью перекрытое окно, задросселированный
 * рендер — и rAF не вызывается вовсе. Данные с сервера уже пришли, а DOM
 * не обновляется НИКОГДА, пока не случится кадр.
 *
 * Замер, доказавший это (локальный стенд, счётчик кадров window.__rafCount
 * крутился самоподдерживающимся rAF-циклом):
 *   до клика                 raf=1  url=?has_token=1  строк=1  чипы=has_token
 *   сразу после клика        raf=1  url=?page=1       строк=1  чипы=has_token  ← баг
 *   после форсирования кадра raf=6  url=?page=1       строк=5  чипы=—          ← само починилось
 * За 5 секунд после клика браузер не выдал НИ ОДНОГО кадра, и обновление
 * висело неприменённым; оно применилось ровно в тот момент, когда кадр пришёл.
 *
 * Инвариант 1: применение данных к DOM не должно лежать внутри
 * requestAnimationFrame(...). В rAF допустимо оставлять только层 layout-догон
 * (пересчёт ширин, виртуализация) — его пропуск косметичен, а пропуск
 * updateRows() означает, что пользователь смотрит на устаревшие данные.
 *
 * Инвариант 2: у кнопок .filter-chip-remove в серверном партиале не должно быть
 * inline onclick. Делегированный обработчик в filters-modern.js уже ловит клики
 * по .filter-chip-remove и берёт фильтр из data-filter, поэтому inline onclick
 * давал ВТОРОЙ вызов removeFilterChip() на тот же клик: два запроса refresh.php,
 * первый из которых тут же отменялся abort'ом второго. Чипы, отрисованные из JS
 * (renderActiveFiltersFromUrl), inline onclick не имеют и всегда работали через
 * делегирование — то есть серверная разметка расходилась с клиентской.
 * (Проверено 2026-08-23: в трейсе клика два вызова removeFilterChip — один из
 * HTMLButtonElement.onclick, второй из HTMLDocument делегированного слушателя.)
 *
 * Тест смотрит только на реальный код: комментарии и строковые литералы вырезаются.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * Вырезает из JS комментарии и строковые литералы, сохраняя длину невозможно,
 * поэтому смещения считаются уже по очищенному коду — сравниваем их только
 * между собой, наружу отдаём номер строки очищенного текста.
 *
 * @param string $code исходник JS
 * @param string $label имя файла для сообщения об ошибке
 * @return string очищенный код
 */
function chipStripJs($code, $label)
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
            // Молчаливый null от preg_replace превратил бы провал проверки
            // в «всё хорошо» — падаем громко.
            throw new RuntimeException("PCRE не справился с $label (код " . preg_last_error() . ')');
        }
        $code = $out;
    }
    return $code;
}

/**
 * Диапазоны [начало, конец] всех вызовов requestAnimationFrame(...) в коде,
 * считая вложенные. Границы определяются подсчётом круглых скобок; вызывать
 * только на коде без строк и комментариев, иначе скобка из строки собьёт счёт.
 *
 * @param string $code очищенный JS
 * @return array список array(startOffset, endOffset)
 */
function chipRafRanges($code)
{
    $ranges = array();
    $needle = 'requestAnimationFrame(';
    $from = 0;
    while (($pos = strpos($code, $needle, $from)) !== false) {
        $open = $pos + strlen($needle) - 1; // индекс самой '('
        $depth = 0;
        $end = null;
        for ($i = $open, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end === null) {
            throw new RuntimeException('Не нашёл закрывающую скобку requestAnimationFrame на смещении ' . $pos);
        }
        $ranges[] = array($pos, $end);
        $from = $pos + strlen($needle);
    }
    return $ranges;
}

/** Смещение внутри хотя бы одного диапазона? */
function chipInsideAny($offset, array $ranges)
{
    foreach ($ranges as $r) {
        if ($offset > $r[0] && $offset < $r[1]) {
            return true;
        }
    }
    return false;
}

echo "\n=== Обновление таблицы после снятия фильтра ===\n\n";

// ── Инвариант 1: применение данных к DOM вне requestAnimationFrame ──────────
$refreshRel = 'assets/js/modules/dashboard-refresh.js';
$refreshCode = chipStripJs(file_get_contents($ROOT . '/' . $refreshRel), $refreshRel);
$rafRanges = chipRafRanges($refreshCode);

if (!$rafRanges) {
    // Не провал: rAF мог быть убран целиком — тогда инвариант выполнен тем более.
    echo "  [OK]   в $refreshRel вообще нет requestAnimationFrame\n";
    $passed++;
} else {
    // Что обязано применяться независимо от кадра.
    $mustBeFrameIndependent = array(
        'updateRows'                 => '~\bupdateRows\s*\(~',
        'renderActiveFiltersFromUrl' => '~\brenderActiveFiltersFromUrl\s*\(~',
        'setTableLoadingState(false)'=> '~\bsetTableLoadingState\s*\(\s*false\s*\)~',
    );
    foreach ($mustBeFrameIndependent as $label => $re) {
        $trapped = array();
        if (preg_match_all($re, $refreshCode, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                if (chipInsideAny($hit[1], $rafRanges)) {
                    $trapped[] = 'строка ' . (substr_count(substr($refreshCode, 0, $hit[1]), "\n") + 1);
                }
            }
        }
        if ($trapped === array()) {
            $passed++;
            echo "  [OK]   $label применяется независимо от прихода кадра\n";
        } else {
            $failures++;
            echo "  [FAIL] $label заперт внутри requestAnimationFrame ($refreshRel: "
                . implode(', ', $trapped) . ") — без кадра DOM не обновится вовсе\n";
        }
    }
}

// ── Инвариант 2: крестик на chip обрабатывается ровно одним путём ───────────
$filtersRel = 'templates/partials/dashboard/filters.php';
$filtersHtml = file_get_contents($ROOT . '/' . $filtersRel);

$inlineHandlers = array();
if (preg_match_all('~<button[^>]*class="[^"]*filter-chip-remove[^"]*"[^>]*>~i', $filtersHtml, $m, PREG_OFFSET_CAPTURE)) {
    foreach ($m[0] as $hit) {
        if (stripos($hit[0], 'onclick') !== false) {
            $inlineHandlers[] = 'строка ' . (substr_count(substr($filtersHtml, 0, $hit[1]), "\n") + 1);
        }
    }
}
if ($inlineHandlers === array()) {
    $passed++;
    echo "  [OK]   у .filter-chip-remove в $filtersRel нет inline onclick\n";
} else {
    $failures++;
    echo "  [FAIL] inline onclick на .filter-chip-remove (" . count($inlineHandlers) . " шт., "
        . implode(', ', array_slice($inlineHandlers, 0, 5))
        . (count($inlineHandlers) > 5 ? ', ...' : '')
        . ") — вместе с делегированным обработчиком даёт двойной вызов removeFilterChip\n";
}

// Без inline onclick единственный путь — делегирование по data-filter,
// поэтому каждый серверный chip обязан этот атрибут иметь.
$chipsWithoutFilter = array();
if (preg_match_all('~<div[^>]*class="filter-chip"[^>]*>~i', $filtersHtml, $m, PREG_OFFSET_CAPTURE)) {
    foreach ($m[0] as $hit) {
        if (stripos($hit[0], 'data-filter=') === false) {
            $chipsWithoutFilter[] = 'строка ' . (substr_count(substr($filtersHtml, 0, $hit[1]), "\n") + 1);
        }
    }
}
if ($chipsWithoutFilter === array()) {
    $passed++;
    echo "  [OK]   у всех chip в $filtersRel есть data-filter\n";
} else {
    $failures++;
    echo "  [FAIL] chip без data-filter (" . implode(', ', $chipsWithoutFilter)
        . ") — делегированный обработчик такой крестик не обслужит\n";
}

// И сам делегированный обработчик обязан существовать: без него снятие inline
// onclick осиротило бы крестики. Ищем по исходнику — селектор живёт в строковом
// литерале, а chipStripJs() строки вырезает.
$modernRel = 'assets/js/filters-modern.js';
$rawModern = file_get_contents($ROOT . '/' . $modernRel);
if (strpos($rawModern, "closest('.filter-chip-remove')") !== false
    || strpos($rawModern, 'closest(".filter-chip-remove")') !== false) {
    $passed++;
    echo "  [OK]   делегированный обработчик .filter-chip-remove на месте ($modernRel)\n";
} else {
    $failures++;
    echo "  [FAIL] в $modernRel не найден делегированный обработчик .filter-chip-remove —\n"
        . "         без него крестики без inline onclick перестанут работать\n";
}

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

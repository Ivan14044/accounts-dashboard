<?php
/**
 * Тест: свайп по карточке статистики опирается на РЕАЛЬНУЮ разметку.
 *
 * Запуск:  php tests/test_card_swipe_contract.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД: рендерим партиал карточек в буфер и сверяем его с исходником
 * assets/js/modules/touch-gestures.js.
 *
 * Зачем этот тест существует (найдено 2026-08-09). Свайп по карточке на мобильном
 * молча не работал, и причин было ДВЕ независимых, каждая сама по себе фатальная:
 *
 *  1. Жесты вешались на `document.querySelectorAll('.touch-card')`, а класса
 *     `touch-card` нет ни в одном шаблоне, ни в одном CSS — цикл крутился по
 *     пустому списку, ни одного слушателя не создавалось. Так было с самого
 *     первого коммита: класс существовал только внутри JS.
 *  2. `handleCardSwipe` ветвился по `data-card-type` со значениями 'total' и
 *     'status', а stats-cards.php этот атрибут не рендерит вовсе. Серверные
 *     карточки помечены иначе: data-card="total" и data-card="status:<key>"
 *     плюс data-status="<имя статуса>". `data-card-type` ставит только
 *     custom-cards.js — и только кастомным карточкам (значение 'custom').
 *
 * Оба бага молчаливые: ни ошибки в консоли, ни визуального следа. Поэтому тест
 * проверяет не «код красивый», а стык: атрибуты, по которым JS принимает решение,
 * обязаны присутствовать в разметке, которую отдаёт PHP.
 *
 * Тест намеренно проверяет КОНТРАКТ (набор атрибутов и маркеров), а не побайтовое
 * совпадение — как и tests/test_renderers_in_sync.php.
 */

// Warning/Notice тоже провал: на проде error_reporting=E_ALL, и Warning в шаблоне
// реально обрывает рендер страницы.
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$failures = 0;
$passed   = 0;

/**
 * @param string $name   что проверяем
 * @param bool   $cond   результат проверки
 * @param string $detail подробность, печатается только при провале
 */
function ok($name, $cond, $detail = '')
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
 * Без этого тест ловит сам себя: в touch-gestures.js есть комментарий, который
 * ЦИТИРУЕТ старые сломанные селекторы («раньше было .touch-card»). Проверять надо
 * код, а не прозу вокруг него.
 *
 * @param string $path
 * @return string исходник без строк-комментариев
 */
function jsWithoutComments($path)
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
            || strpos($trimmed, '/*') === 0
        ) {
            continue;
        }
        $kept[] = $line;
    }

    return implode("\n", $kept);
}

/**
 * Экранирование как в приложении (includes/helpers.php).
 * В тесте своё, чтобы партиал рендерился без загрузки всего конфига.
 *
 * @param mixed $s
 * @return string
 */
function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Рендерит партиал карточек статистики с заведомо фейковыми данными.
 *
 * @return string HTML
 */
function renderStatsCards()
{
    $totals          = ['all' => 1234];
    $recentAll       = 12;
    $dailyTotals     = [10, 20, 15, 30, 25, 40, 50];
    $emptyStatusCount = 3;
    $countEmailTwoFa = 7;
    $byStatus        = ['Активен' => 100, 'Забанен' => 20, 'На проверке' => 5, '' => 3];
    $recentByStatus  = ['Активен' => 4];

    ob_start();
    try {
        include __DIR__ . '/../templates/partials/dashboard/stats-cards.php';
    } finally {
        $html = ob_get_clean();
    }
    return $html;
}

$html = renderStatsCards();
$js   = jsWithoutComments(__DIR__ . '/../assets/js/modules/touch-gestures.js');

echo "\n=== Разметка карточек (stats-cards.php) ===\n\n";

ok(
    'карточки помечены классом stat-card',
    strpos($html, 'class="stat-card') !== false
);

ok(
    'карточка «Всего» помечена data-card="total"',
    strpos($html, 'data-card="total"') !== false
);

// Статусные карточки: data-card="status:<безопасный ключ>" + data-status="<имя>".
// Именно data-status читают dashboard-refresh.js и dashboard-stats.js при
// обновлении чисел, и по нему же строится фильтр при свайпе.
preg_match_all('/data-card="status:[^"]*"\s+data-status="([^"]*)"/', $html, $statusCards);
ok(
    'статусные карточки несут data-card="status:…" и непустой data-status',
    count($statusCards[1]) === 3 && !in_array('', $statusCards[1], true),
    'нашли: ' . var_export($statusCards[1], true)
);

echo "\n=== Классификация карточки в touch-gestures.js ===\n\n";

// Селектор, по которому вешаются жесты, обязан совпадать с реальной разметкой.
ok(
    'жесты цепляются за .stat-card',
    strpos($js, '.stat-card') !== false
);
ok(
    'исчез мёртвый селектор .touch-card (класса нет ни в шаблонах, ни в CSS)',
    strpos($js, '.touch-card') === false,
    'слушатели вешаются на пустой список — свайп не работает вообще'
);

// Ветвление по типу карточки опирается на атрибуты, которые реально рендерятся.
ok(
    'классификатор читает data-card',
    strpos($js, "getAttribute('data-card')") !== false
);
ok(
    'классификатор читает data-status',
    strpos($js, "getAttribute('data-status')") !== false
);
ok(
    'узнаёт статусную карточку по префиксу "status:"',
    strpos($js, "'status:'") !== false
);

// Регрессия №2: значение data-card-type сравнивают с 'total'/'status'. Такое
// условие не выполнится никогда — PHP этот атрибут не пишет. Ищем переменные,
// в которые кладут data-card-type, и смотрим, с чем их сравнивают: проверять
// надо именно значение атрибута, а не любую переменную с похожим именем
// (значение из resolveCardKind сравнивать с 'total'/'status' как раз законно).
preg_match_all(
    "/(?:const|let|var)\\s+(\\w+)\\s*=\\s*[\\w.]+\\.getAttribute\\('data-card-type'\\)/",
    $js,
    $attrVars
);
$badCompares = [];
foreach ($attrVars[1] as $varName) {
    if (preg_match("/\\b" . preg_quote($varName, '/') . "\\s*===?\\s*'(total|status)'/", $js, $m)) {
        $badCompares[] = "$varName === '{$m[1]}'";
    }
}
ok(
    "значение data-card-type не сравнивают с 'total'/'status' (PHP такой атрибут не рендерит)",
    $badCompares === [],
    'найдено: ' . implode(', ', $badCompares)
);
ok(
    'проверка выше вообще нашла, что анализировать (иначе она бесполезна)',
    count($attrVars[1]) > 0,
    'в touch-gestures.js нет чтения data-card-type — кастомные карточки перестанут работать'
);

// А вот 'custom' по data-card-type — законно: его ставит custom-cards.js.
$customCardsJs = jsWithoutComments(__DIR__ . '/../assets/js/modules/custom-cards.js');
ok(
    "custom-cards.js по-прежнему ставит data-card-type=\"custom\"",
    strpos($customCardsJs, "setAttribute('data-card-type', 'custom')") !== false
);
ok(
    "touch-gestures.js обрабатывает кастомные карточки ('custom')",
    strpos($js, "'custom'") !== false
);

echo "\n=== Действия свайпа ===\n\n";

ok(
    'свайп по статусной карточке добавляет status[] в URL',
    strpos($js, "append('status[]'") !== false
);
ok(
    'свайп обновляет данные без перезагрузки (history.replaceState + refreshDashboardData)',
    strpos($js, 'history.replaceState') !== false && strpos($js, 'refreshDashboardData()') !== false
);
ok(
    'свайп по карточке «Всего» показывает тост',
    strpos($js, 'showToast(') !== false
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

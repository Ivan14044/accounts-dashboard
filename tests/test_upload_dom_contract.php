<?php
/**
 * Тест: все элементы, которые ищет модуль загрузки, реально есть в разметке.
 *
 * Запуск:  php tests/test_upload_dom_contract.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10 сверкой удаляемого партиала
 * с рабочей формой).
 *
 * assets/js/modules/dashboard-upload.js обращается к элементам по id через
 * cache.getById(). Все обращения аккуратно защищены проверками на существование —
 * и именно поэтому пропажа элемента НЕ даёт ни ошибки в консоли, ни записи в лог:
 * функция просто молча ничего не делает.
 *
 * Так и вышло: разметка предпросмотра CSV и прогресс-бара импорта
 * (csvPreviewContainer, importProgressContainer, importProgressBar,
 * importProgressPercent, cancelImportBtn) существовала ТОЛЬКО в партиале
 * templates/partials/dashboard/modals/add-account-modal.php, который не был
 * подключён ни одним шаблоном. В рабочей форме её не было. Пользователь при
 * импорте не видел ни предпросмотра файла, ни прогресса, ни кнопки отмены —
 * и ничто в системе об этом не сообщало.
 *
 * Инвариант: каждый id, который модуль ищет, обязан присутствовать в шаблонах,
 * реально доходящих до страницы дашборда. Защитная проверка в JS — это страховка
 * от гонок, а не разрешение потерять разметку.
 *
 * Тест намеренно собирает список id ИЗ КОДА, а не из захардкоженного перечня:
 * добавят новое обращение — тест сам потребует разметку под него.
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
function udcCheck($name, $ok, $detail = '')
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
 * Шаблоны, реально доходящие до страницы: сам dashboard.php и всё, что он
 * подключает (в том числе через подключённые партиалы).
 *
 * Именно «реально доходящие» — ключевое условие. Разметка в неподключённом
 * партиале не считается существующей, на этом и погорел прошлый раз.
 *
 * @param string $root Корень проекта
 * @param string $entry Точка входа относительно корня
 * @return string[] Абсолютные пути шаблонов
 */
function udcReachableTemplates($root, $entry)
{
    $seen  = array();
    $queue = array($root . '/' . $entry);

    while ($queue) {
        $file = array_shift($queue);
        $real = realpath($file);
        if ($real === false || isset($seen[$real]) || !is_file($real)) {
            continue;
        }
        $seen[$real] = true;
        $src = file_get_contents($real);

        // require_once __DIR__ . '/partials/...php'  и  include ... той же формы
        if (preg_match_all('~(?:require|include)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]~', $src, $m)) {
            foreach ($m[1] as $rel) {
                $queue[] = dirname($real) . '/' . ltrim($rel, '/');
            }
        }
    }

    return array_keys($seen);
}

echo "\n=== Модуль загрузки: разметка под каждый искомый элемент ===\n\n";

$jsPath = $ROOT . '/assets/js/modules/dashboard-upload.js';
$js     = file_get_contents($jsPath);

// Собираем id из кода, а не из захардкоженного списка.
preg_match_all('~getById\(\s*[\'"]([A-Za-z0-9_-]+)[\'"]\s*\)~', $js, $m);
$ids = array_values(array_unique($m[1]));
sort($ids);

udcCheck(
    'обращения getById() в модуле найдены',
    count($ids) >= 5,
    'нашли всего ' . count($ids) . ' — модуль переписали? тест надо пересмотреть'
);

$templates = udcReachableTemplates($ROOT, 'templates/dashboard.php');
udcCheck(
    'список доходящих до страницы шаблонов собран',
    count($templates) > 1,
    'собрано файлов: ' . count($templates)
);

$markup = '';
foreach ($templates as $t) {
    $markup .= file_get_contents($t) . "\n";
}

foreach ($ids as $id) {
    udcCheck(
        "элемент #$id есть в подключённых шаблонах",
        strpos($markup, 'id="' . $id . '"') !== false,
        'модуль его ищет, но разметки нет — функция молча не сработает,'
            . ' ошибки в консоли при этом НЕ будет'
    );
}

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

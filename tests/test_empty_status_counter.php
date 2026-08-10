<?php
/**
 * Тест: счётчик «Без статуса» не пропадает при активном фильтре.
 *
 * Запуск:  php tests/test_empty_status_counter.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (воспроизведено на стенде 2026-08-10).
 *
 * 36 записей с пустым статусом. Карточка «Без статуса»:
 *   /index.php                → 36
 *   /index.php?status=banned  → «-»
 *
 * Причина — в SQL. Нефильтрованная статистика бралась запросом
 *   SELECT ..., COALESCE(status,'') AS status, COUNT(*) ... GROUP BY status WITH ROLLUP
 * и разбиралась правилом «если status пустой — это итоговая строка ROLLUP».
 * Но COALESCE делает пустыми ОБЕ строки: и итоговую (у неё status = NULL), и
 * настоящую группу пустых статусов. Проверено запросом на стенде — обе приходят
 * со значением '', со счётчиками 182021 и 36. В результате настоящая группа
 * никогда не попадала в список, и карточка оставалась без значения.
 *
 * Почему решение не в GROUPING(). Оно есть только в MySQL 8, а версия прода
 * неизвестна. И главное: GROUPING() не помог бы с группой, где status IS NULL —
 * её строка тоже приходит с NULL и тоже неотличима от итоговой.
 * Поэтому WITH ROLLUP убран совсем: обычный GROUP BY даёт все группы без
 * неоднозначности, а общее число складывается из них в PHP. Запрос остаётся
 * один, лишней работы для БД нет.
 *
 * Тест проверяет чистую функцию разбора — без БД и без сети.
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
function escCheck($name, $ok, $detail = '')
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

require_once $ROOT . '/includes/StatisticsService.php';

echo "\n=== Счётчик «Без статуса» при активном фильтре ===\n\n";

escCheck(
    'StatisticsService::splitStatusGroups() существует',
    method_exists('StatisticsService', 'splitStatusGroups'),
    'разбор строк GROUP BY должен быть отдельной проверяемой функцией'
);
if (!method_exists('StatisticsService', 'splitStatusGroups')) {
    echo "\nБез неё остальные проверки бессмысленны.\n";
    exit(1);
}

// ── Главный случай: есть настоящая группа пустых статусов ──
$rows = array(
    array('status' => 'valid',  'status_count' => '100'),
    array('status' => 'banned', 'status_count' => '50'),
    array('status' => '',       'status_count' => '36'),
);
$r = StatisticsService::splitStatusGroups($rows);

escCheck('общее число — сумма групп', $r['total'] === 186, 'получили ' . var_export($r['total'], true));
escCheck(
    'группа пустых статусов не потерялась',
    isset($r['byStatus']['']) && $r['byStatus'][''] === 36,
    'получили ' . json_encode($r['byStatus'])
);
escCheck('обычные статусы на месте', ($r['byStatus']['valid'] ?? 0) === 100 && ($r['byStatus']['banned'] ?? 0) === 50);

// ── NULL и '' — оба «без статуса», должны сложиться в одну группу ──
$rows = array(
    array('status' => 'valid', 'status_count' => '10'),
    array('status' => null,    'status_count' => '4'),
    array('status' => '',      'status_count' => '6'),
);
$r = StatisticsService::splitStatusGroups($rows);
escCheck('NULL и пустая строка сложены вместе', ($r['byStatus'][''] ?? null) === 10, 'получили ' . json_encode($r['byStatus']));
escCheck('общее число учитывает и NULL-группу', $r['total'] === 20, 'получили ' . var_export($r['total'], true));

// ── Краевые ──
$r = StatisticsService::splitStatusGroups(array());
escCheck('пустой результат → total 0 и пустой список', $r['total'] === 0 && $r['byStatus'] === array());

$r = StatisticsService::splitStatusGroups(array(array('status' => 'only', 'status_count' => '7')));
escCheck('единственная группа', $r['total'] === 7 && $r['byStatus']['only'] === 7);

// ── Запрос больше не должен опираться на ROLLUP+COALESCE ──
$src = '';
foreach (token_get_all(file_get_contents($ROOT . '/includes/StatisticsService.php')) as $tok) {
    if (is_array($tok)) {
        $src .= ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) ? "\n" : $tok[1];
        continue;
    }
    $src .= $tok;
}
escCheck(
    'нефильтрованная статистика больше не использует WITH ROLLUP',
    stripos($src, 'WITH ROLLUP') === false,
    'ROLLUP-строка неотличима от настоящей группы пустых статусов'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

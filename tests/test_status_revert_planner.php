<?php
/**
 * Тесты планировщика отката массовой смены статуса
 * ({@see StatusRevertPlanner::plan()}).
 *
 * Почему это покрыто тестом: планировщик решает, какие аккаунты ПЕРЕЗАПИСАТЬ
 * на проде. Ошибка здесь = молча испорченные статусы у тысяч записей, причём
 * откатывать будет уже нечего. Главный инвариант — «трогаем только те строки,
 * что до сих пор стоят в статусе, который мы сами и проставили»: если после
 * нашей операции кто-то перевёл аккаунт дальше, его правку затирать нельзя.
 *
 * Запуск: php tests/test_status_revert_planner.php   (код выхода 0 = успех)
 */

// На проде error_reporting=E_ALL, и Warning в шаблоне обрывает рендер.
// Поэтому в тесте любой Warning/Notice — это падение.
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../includes/StatusRevertPlanner.php';

$failures = 0;
$checks   = 0;

function check($condition, $message) {
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  ✓ $message\n";
    } else {
        echo "  ✗ $message\n";
        $failures++;
    }
}

function row($id, $status, $deletedAt = null) {
    return ['account_id' => $id, 'current_status' => $status, 'deleted_at' => $deletedAt];
}

const CUR = 'perechek_new';

echo "── Пустой вход\n";
$p = StatusRevertPlanner::plan([], CUR, false);
check($p['revert'] === [], 'пустой вход → нечего откатывать');
check($p['skipped_changed'] === 0 && $p['skipped_trashed'] === 0, 'пустой вход → счётчики нулевые');

echo "── Обычный случай: статус не трогали после нашей операции\n";
$p = StatusRevertPlanner::plan([row(1, CUR), row(2, CUR)], CUR, false);
check($p['revert'] === [1, 2], 'обе строки попадают в откат');
check($p['skipped_changed'] === 0, 'ничего не пропущено');

echo "── ГЛАВНОЕ: строку, которую после нас изменили, откатывать НЕЛЬЗЯ\n";
$p = StatusRevertPlanner::plan([row(1, CUR), row(2, 'sale_2'), row(3, 'perechec_new_ivan')], CUR, false);
check($p['revert'] === [1], 'откатывается только строка со «своим» статусом');
check($p['skipped_changed'] === 2, 'две поздние правки пропущены, а не затёрты');

echo "── NULL/пустой статус — это не «наш» статус\n";
$p = StatusRevertPlanner::plan([row(1, null), row(2, ''), row(3, CUR)], CUR, false);
check($p['revert'] === [3], 'NULL и пустая строка не откатываются');
check($p['skipped_changed'] === 2, 'NULL и пустая строка учтены как изменённые');

echo "── Регистр: сравнение строгое (в MySQL коллация ci могла бы «склеить»)\n";
$p = StatusRevertPlanner::plan([row(1, 'PERECHEK_NEW'), row(2, 'Perechek_New'), row(3, CUR)], CUR, false);
check($p['revert'] === [3], 'отличия в регистре не считаются совпадением');
check($p['skipped_changed'] === 2, 'строки с другим регистром пропущены');

echo "── Корзина: по умолчанию не трогаем\n";
$rows = [row(1, CUR), row(2, CUR, '2026-08-26 14:14:39')];
$p = StatusRevertPlanner::plan($rows, CUR, false);
check($p['revert'] === [1], 'аккаунт в корзине пропущен при includeTrashed=false');
check($p['skipped_trashed'] === 1, 'пропуск по корзине посчитан отдельно');

echo "── Корзина: по явному запросу — откатываем\n";
$p = StatusRevertPlanner::plan($rows, CUR, true);
check($p['revert'] === [1, 2], 'при includeTrashed=true корзина тоже откатывается');
check($p['skipped_trashed'] === 0, 'счётчик корзины обнуляется');

echo "── Изменённый статус важнее корзины (не трогаем в любом случае)\n";
$p = StatusRevertPlanner::plan([row(1, 'sale_2', '2026-08-26 14:14:39')], CUR, true);
check($p['revert'] === [], 'изменённая строка не откатывается даже с includeTrashed=true');
check($p['skipped_changed'] === 1 && $p['skipped_trashed'] === 0, 'учтена как изменённая, а не как корзинная');

echo "── Дубли account_id схлопываются (одна строка = один UPDATE)\n";
$p = StatusRevertPlanner::plan([row(7, CUR), row(7, CUR), row(8, CUR)], CUR, false);
check($p['revert'] === [7, 8], 'повторный account_id не удваивается');
check($p['duplicates'] === 1, 'дубль посчитан');

echo "── Пустая строка deleted_at = не в корзине\n";
$p = StatusRevertPlanner::plan([row(1, CUR, ''), row(2, CUR, '0000-00-00 00:00:00')], CUR, false);
check($p['revert'] === [1, 2], 'пустой и нулевой deleted_at не считаются корзиной');

echo "── ID приводятся к int (из БД приходят строками)\n";
$p = StatusRevertPlanner::plan([row('42', CUR)], CUR, false);
check($p['revert'] === [42], 'строковый id стал int');

echo "\n";
if ($failures === 0) {
    echo "OK: все проверки пройдены ($checks)\n";
    exit(0);
}
echo "FAIL: провалено $failures из $checks\n";
exit(1);

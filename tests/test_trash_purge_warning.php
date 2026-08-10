<?php
/**
 * Тест: автоочистка корзины предупреждает заранее и отчитывается постфактум.
 *
 * Запуск:  php tests/test_trash_purge_warning.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10).
 *
 * Автоочистка корзины включена ПО УМОЛЧАНИЮ (TrashSettings: enabled=true,
 * days=30) и запускается сама при заходе на страницу корзины, не чаще раза в
 * сутки. Удаление физическое: purgeOlderThan() не пишет ни в account_history,
 * ни в журнал отмены — восстановить нечем. Единственным следом была строчка
 * Logger::info, которую пользователь не видит.
 *
 * То есть человек, ни разу не заходивший в настройки retention, при первом же
 * визите в корзину безвозвратно терял всё, что пролежало там больше 30 дней, —
 * и никак об этом не узнавал.
 *
 * Что теперь: страница корзины показывает, сколько записей будет удалено
 * навсегда, ДО того как это произойдёт, и сообщает результат прошлой очистки.
 *
 * ГЛАВНЫЙ ИНВАРИАНТ, который стережёт тест: условие «что попадёт под удаление»
 * должно быть ОДНО на предупреждение и на само удаление. Если счётчик считает по
 * одному правилу, а удаляет по другому, предупреждение становится враньём —
 * а это хуже, чем его отсутствие. Поэтому оба обязаны звать
 * retentionWhereClause() и не собирать условие сами.
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
function tpwCheck($name, $ok, $detail = '')
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
 * Тело метода из исходника с вырезанными комментариями.
 *
 * @param string $file Путь к файлу
 * @param string $needle Сигнатура метода
 * @return string Тело в фигурных скобках или ''
 */
function tpwBody($file, $needle)
{
    $src = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok)) {
            $src .= ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) ? "\n" : $tok[1];
            continue;
        }
        $src .= $tok;
    }
    $at = strpos($src, $needle);
    if ($at === false) {
        return '';
    }
    $open = strpos($src, '{', $at);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }
    return '';
}

echo "\n=== Корзина: автоочистка предупреждает, а не удаляет молча ===\n\n";

$deleteTrait = $ROOT . '/includes/repositories/AccountsRepoDeleteTrait.php';

// ── 1. Условие удержания — одно на всех ──
require_once $ROOT . '/includes/AccountsRepository.php';
tpwCheck(
    'AccountsRepository::retentionWhereClause() существует',
    method_exists('AccountsRepository', 'retentionWhereClause'),
    'условие «что удалять» должно быть отдельной общей функцией'
);

if (method_exists('AccountsRepository', 'retentionWhereClause')) {
    $c30 = AccountsRepository::retentionWhereClause(30);
    tpwCheck(
        'условие для 30 дней ссылается на deleted_at и INTERVAL 30 DAY',
        strpos($c30, 'deleted_at') !== false && strpos($c30, 'INTERVAL 30 DAY') !== false,
        'получили: ' . $c30
    );
    tpwCheck(
        'условие берёт только удалённые (deleted_at IS NOT NULL)',
        strpos($c30, 'IS NOT NULL') !== false,
        'иначе под очистку попадут живые записи'
    );
    // Ноль и отрицательные дни не должны означать «удалить всё прямо сейчас»
    tpwCheck(
        '0 дней зажимается до 1 (не «удалить всё сегодня»)',
        strpos(AccountsRepository::retentionWhereClause(0), 'INTERVAL 1 DAY') !== false,
        'получили: ' . AccountsRepository::retentionWhereClause(0)
    );
    tpwCheck(
        'отрицательные дни зажимаются до 1',
        strpos(AccountsRepository::retentionWhereClause(-5), 'INTERVAL 1 DAY') !== false
    );
}

// ── 2. И счётчик, и удаление обязаны пользоваться именно им ──
$purge = tpwBody($deleteTrait, 'function purgeOlderThan');
$count = tpwBody($deleteTrait, 'function countOlderThan');

tpwCheck('purgeOlderThan() найден', $purge !== '');
tpwCheck('countOlderThan() существует', $count !== '', 'без него страница не сможет предупредить');

tpwCheck(
    'purgeOlderThan() строит условие через retentionWhereClause()',
    strpos($purge, 'retentionWhereClause') !== false,
    'иначе удаление и предупреждение разъедутся'
);
tpwCheck(
    'countOlderThan() строит условие через retentionWhereClause()',
    strpos($count, 'retentionWhereClause') !== false,
    'счётчик обязан считать ровно то, что будет удалено'
);
tpwCheck(
    'ни один из них не собирает INTERVAL сам',
    strpos($purge, 'INTERVAL') === false && strpos($count, 'INTERVAL') === false,
    'своё условие рядом с общим — это будущее расхождение'
);

// ── 3. Результат прошлой очистки сохраняется, а не только пишется в лог ──
$settings = file_get_contents($ROOT . '/includes/TrashSettings.php');
tpwCheck(
    'TrashSettings хранит число удалённых за прошлый проход',
    strpos($settings, 'last_purge_deleted') !== false,
    'иначе сообщить пользователю постфактум будет нечем: очистка идёт'
        . ' после отрисовки страницы и на текущей уже не покажется'
);
$markBody = tpwBody($ROOT . '/includes/TrashSettings.php', 'function markPurged');
tpwCheck(
    'markPurged() принимает число удалённых',
    $markBody !== '' && strpos($markBody, 'last_purge_deleted') !== false,
    'вызывающий код обязан иметь возможность передать результат'
);

// ── 4. Страница корзины действительно показывает предупреждение ──
$tpl = file_get_contents($ROOT . '/templates/trash.php');
tpwCheck(
    'шаблон корзины показывает, сколько записей будет удалено',
    strpos($tpl, 'purgeDueCount') !== false,
    'без этого пользователь снова узнает о потере только по факту'
);
tpwCheck(
    'шаблон корзины сообщает об уже выполненной очистке',
    strpos($tpl, 'lastPurgeDeleted') !== false
);
tpwCheck(
    'страница корзины передаёт эти данные в шаблон',
    strpos(file_get_contents($ROOT . '/trash.php'), 'purgeDueCount') !== false
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

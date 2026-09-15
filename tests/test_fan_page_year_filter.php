<?php
/**
 * Тест фильтра «Год создания Fan Page» (fp_year_from / fp_year_to).
 *
 * Запуск:  php tests/test_fan_page_year_filter.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения. Без сети и БД.
 *
 * Откуда данные. Год создания каждой фан-страницы пишет в базу чекер софта
 * fb_automation (с 15.09.2026): колонки `year_fan_page_1..10`, год страницы из
 * слота N (рядом с `id_fan_page_N`). Колонки заводит сам софт, в эталонной
 * схеме дашборда их нет — значит фильтр обязан молча выключаться на базе без них.
 *
 * Смысл фильтра: у аккаунта есть ХОТЯ БЫ ОДНА фан-страница, созданная в
 * указанном диапазоне лет. Поэтому условия по слотам соединяются через OR, а
 * «от» и «до» проверяются у ОДНОЙ и той же страницы (иначе аккаунт со страницами
 * 2019 и 2026 прошёл бы диапазон 2020–2021, где у него нет ни одной).
 *
 * Вторая часть — инвентаризация: фильтр в этом проекте живёт в восьми местах,
 * забыть одно — типовой баг (см. tests/test_presence_filters.php).
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/FilterBuilder.php';

$failures = 0;
$passed   = 0;

function check($name, callable $fn)
{
    global $failures, $passed;
    try {
        $res = $fn();
        $ok  = $res[0];
        $msg = isset($res[1]) ? $res[1] : '';
    } catch (Throwable $e) {
        $ok  = false;
        $msg = get_class($e) . ': ' . $e->getMessage();
    }
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name — $msg\n";
    }
}

function src($rel)
{
    $path = __DIR__ . '/../' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("нет файла $rel");
    }
    return (string)file_get_contents($path);
}

/**
 * @param int $slots сколько колонок year_fan_page_N есть в таблице
 * @return array
 */
function columnsWithFpYears($slots)
{
    $cols = array('id' => 'ID', 'login' => 'Логин', 'deleted_at' => 'Удалён', 'quantity_fp' => 'FP');
    for ($n = 1; $n <= $slots; $n++) {
        $cols['year_fan_page_' . $n] = 'Год FP ' . $n;
    }
    return $cols;
}

echo "\nFilterBuilder::addFanPageYearFilter\n";

check('диапазон: у одной и той же страницы и «от», и «до», слоты через OR', static function () {
    $f = new FilterBuilder(columnsWithFpYears(2));
    $f->addFanPageYearFilter('2020', '2022');
    $where = $f->getWhereClause();
    $ok = strpos($where, '((`year_fan_page_1` >= ? AND `year_fan_page_1` <= ?) OR (`year_fan_page_2` >= ? AND `year_fan_page_2` <= ?))') !== false;
    return array($ok, "получили: $where");
});

check('параметры: пары лет на каждый слот, числами', static function () {
    $f = new FilterBuilder(columnsWithFpYears(2));
    $f->addFanPageYearFilter('2020', '2022');
    return array($f->getParams() === array(2020, 2022, 2020, 2022), 'получили: ' . json_encode($f->getParams()));
});

check('только «от»', static function () {
    $f = new FilterBuilder(columnsWithFpYears(1));
    $f->addFanPageYearFilter('2023', '');
    $where = $f->getWhereClause();
    return array(strpos($where, '((`year_fan_page_1` >= ?))') !== false && $f->getParams() === array(2023), "получили: $where");
});

check('только «до» не ловит пустые (NULL) годы', static function () {
    $f = new FilterBuilder(columnsWithFpYears(1));
    $f->addFanPageYearFilter(null, '2021');
    $where = $f->getWhereClause();
    $ok = strpos($where, '((`year_fan_page_1` > 0 AND `year_fan_page_1` <= ?))') !== false;
    return array($ok, "получили: $where");
});

check('перепутанные «от» и «до» меняются местами', static function () {
    $f = new FilterBuilder(columnsWithFpYears(1));
    $f->addFanPageYearFilter('2026', '2020');
    return array($f->getParams() === array(2020, 2026), 'получили: ' . json_encode($f->getParams()));
});

check('пусто / мусор → фильтр выключен', static function () {
    $f = new FilterBuilder(columnsWithFpYears(3));
    $f->addFanPageYearFilter('', '');
    $f->addFanPageYearFilter('abc', 'x');
    $f->addFanPageYearFilter('0', '-5');
    $where = $f->getWhereClause();
    return array(strpos($where, 'year_fan_page') === false && $f->getParams() === array(), "получили: $where");
});

check('колонок года нет в таблице → условие не строится', static function () {
    $f = new FilterBuilder(columnsWithFpYears(0));
    $f->addFanPageYearFilter('2020', '2022');
    $where = $f->getWhereClause();
    return array(strpos($where, 'year_fan_page') === false, "получили: $where");
});

check('берутся все 10 слотов, если они есть', static function () {
    $f = new FilterBuilder(columnsWithFpYears(10));
    $f->addFanPageYearFilter('2024', '');
    $where = $f->getWhereClause();
    return array(strpos($where, '`year_fan_page_10` >= ?') !== false && count($f->getParams()) === 10, "получили: $where");
});

check('цепочка вызовов не рвётся', static function () {
    $f = new FilterBuilder(columnsWithFpYears(1));
    return array($f->addFanPageYearFilter('2020', '2021') === $f, 'обязан возвращать $this');
});

echo "\nРегистрация параметров во всех местах\n";

$places = array(
    array('includes/RequestHandler.php',                       'year_created_from', 'счёт активных фильтров'),
    array('includes/services/AccountsServiceFiltersTrait.php', 'year_created_from', 'маппинг на FilterBuilder'),
    array('includes/DashboardController.php',                  'year_created_from', 'get_param + передача в шаблон'),
    array('templates/partials/dashboard/filters.php',          'year_created_from', 'контрол и chip в форме фильтров'),
    array('assets/js/filters-modern.js',                       'year_created_from', 'chips, сброс, синхронизация формы'),
    array('assets/js/dashboard-init.js',                       'year_created_from', 'автоприменение при вводе'),
    array('export.php',                                        'year_created_from', 'защита «экспорт без фильтров»'),
);
foreach (array('fp_year_from', 'fp_year_to') as $param) {
    foreach ($places as $place) {
        $file = $place[0];
        $neighbour = $place[1];
        $what = $place[2];
        check("$param упомянут в $file ($what)", static function () use ($file, $param, $neighbour) {
            $code = src($file);
            if (strpos($code, $neighbour) === false) {
                return array(false, "эталонный сосед «{$neighbour}» пропал — тест устарел");
            }
            return array(strpos($code, $param) !== false, "параметр «{$param}» не найден");
        });
    }
}

check('fp_year_* сбрасываются кнопкой «Сбросить все»', static function () {
    $code = src('assets/js/filters-modern.js');
    if (!preg_match('/ALL_FILTER_PARAMS\s*=\s*\[(.*?)\];/s', $code, $m)) {
        return array(false, 'не нашёл ALL_FILTER_PARAMS');
    }
    return array(strpos($m[1], "'fp_year_from'") !== false && strpos($m[1], "'fp_year_to'") !== false, 'нет в ALL_FILTER_PARAMS');
});

check('контрол в шаблоне закрыт проверкой наличия колонки года', static function () {
    $code = src('templates/partials/dashboard/filters.php');
    return array(strpos($code, "isset(\$ALL_COLUMNS['year_fan_page_1'])") !== false, 'без проверки колонки контрол покажется на базе, где её нет');
});

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

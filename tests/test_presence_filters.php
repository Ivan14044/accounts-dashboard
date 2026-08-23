<?php
/**
 * Тест фильтров «по наличию значения в колонке»: `phone_removed` и `passkey`.
 *
 * Запуск:  php tests/test_presence_filters.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД.
 *
 * Что здесь проверяется и зачем.
 *
 * 1. FilterBuilder::addPresenceFilter() — SQL трёхпозиционного фильтра.
 *    Колонки `phone_removed` и `passkey` есть на проде, но их НЕТ в эталоне
 *    DatabaseSchemaManager::getRequiredSchema() (ровно как status_rk/geo/currency).
 *    Значит фильтр обязан молча выключаться на базе, где колонки нет, —
 *    иначе стенд, поднятый эталоном, падал бы с Unknown column.
 *
 * 2. Регистрация параметров во ВСЕХ местах. Один фильтр в этом проекте живёт
 *    в восьми файлах (белый список параметров, маппинг на FilterBuilder,
 *    контроллер, шаблон, три JS-модуля, защита экспорта). Забыть одно место —
 *    типовой баг: фильтр «работает», но, например, экспорт с ним одним отдаёт
 *    400 «no filters provided», а кнопка «Сбросить все» его не сбрасывает.
 *    Поэтому здесь не логика, а инвентаризация: параметр упомянут везде, где
 *    упомянут его давно работающий сосед.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/FilterBuilder.php';

$failures = 0;
$passed   = 0;

/**
 * @param string   $name
 * @param callable $fn возвращает [ok, сообщение при провале]
 */
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

/**
 * Читает файл проекта целиком.
 *
 * @param string $rel путь относительно корня репозитория
 * @return string
 */
function src($rel)
{
    $path = __DIR__ . '/../' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("нет файла $rel");
    }
    return (string)file_get_contents($path);
}

/**
 * Колонки для FilterBuilder. `deleted_at` обязателен: без него getWhereClause()
 * лезет в ColumnMetadata и Database, а тест должен работать без БД.
 *
 * @return array
 */
function columnsWithPresenceCols()
{
    return array(
        'id'            => 'ID',
        'login'         => 'Логин',
        'deleted_at'    => 'Удалён',
        'phone_removed' => 'Phone removed',
        'passkey'       => 'Passkey',
    );
}

echo "\nFilterBuilder::addPresenceFilter\n";

check('«удалён» → NOT NULL AND <> \'\'', static function () {
    $f = new FilterBuilder(columnsWithPresenceCols());
    $f->addPresenceFilter('phone_removed', 'yes');
    $where = $f->getWhereClause();
    $ok = strpos($where, "(`phone_removed` IS NOT NULL AND `phone_removed` <> '')") !== false;
    return array($ok, "получили: $where");
});

check('«не удалён» → IS NULL OR = \'\'', static function () {
    $f = new FilterBuilder(columnsWithPresenceCols());
    $f->addPresenceFilter('phone_removed', 'no');
    $where = $f->getWhereClause();
    $ok = strpos($where, "(`phone_removed` IS NULL OR `phone_removed` = '')") !== false;
    return array($ok, "получили: $where");
});

check('фильтр не добавляет параметров в prepared statement', static function () {
    $f = new FilterBuilder(columnsWithPresenceCols());
    $f->addPresenceFilter('phone_removed', 'yes');
    $f->addPresenceFilter('passkey', 'no');
    return array($f->getParams() === array(), 'ожидали пустой массив параметров');
});

check('неизвестный режим игнорируется (фильтр выключен)', static function () {
    $f = new FilterBuilder(columnsWithPresenceCols());
    $f->addPresenceFilter('phone_removed', '');
    $f->addPresenceFilter('phone_removed', 'any');
    $f->addPresenceFilter('phone_removed', '1');
    $where = $f->getWhereClause();
    $ok = strpos($where, 'phone_removed') === false;
    return array($ok, "условие не должно упоминать колонку, получили: $where");
});

check('колонки нет в таблице → условие не строится (стенд на эталонной схеме)', static function () {
    $columns = array('id' => 'ID', 'login' => 'Логин', 'deleted_at' => 'Удалён');
    $f = new FilterBuilder($columns);
    $f->addPresenceFilter('phone_removed', 'yes');
    $f->addPresenceFilter('passkey', 'yes');
    $where = $f->getWhereClause();
    $ok = strpos($where, 'phone_removed') === false && strpos($where, 'passkey') === false;
    return array($ok, "ожидали отсутствие условий, получили: $where");
});

check('цепочка вызовов не рвётся (возвращается self)', static function () {
    $f = new FilterBuilder(columnsWithPresenceCols());
    $same = $f->addPresenceFilter('phone_removed', 'yes')->addPresenceFilter('passkey', 'no');
    return array($same === $f, 'addPresenceFilter обязан возвращать $this');
});

echo "\nРегистрация параметров во всех местах\n";

/**
 * Места, где обязан быть упомянут параметр фильтра. Эталон — уже работающий
 * сосед: если он там есть, а новый параметр нет, значит место забыли.
 *
 * @var array<int, array{0:string,1:string,2:string}> [файл, эталонный сосед, пояснение]
 */
$places = array(
    array('includes/RequestHandler.php',                    'has_token',  'белый список $allowedFilters'),
    array('includes/services/AccountsServiceFiltersTrait.php', 'has_token', 'маппинг на FilterBuilder'),
    array('includes/DashboardController.php',               'has_token',  'get_param + передача в шаблон'),
    array('templates/partials/dashboard/filters.php',       'has_token',  'контрол и chip в форме фильтров'),
    array('assets/js/filters-modern.js',                    'has_token',  'chips, сброс, применение формы'),
    array('assets/js/modules/custom-cards.js',              'has_token',  'кастомные карточки'),
    array('assets/js/modules/touch-gestures.js',            'has_token',  'перенос фильтров карточки в URL'),
    array('export.php',                                     'has_token',  'защита «экспорт без фильтров»'),
);

foreach (array('phone_removed', 'has_passkey') as $param) {
    foreach ($places as $place) {
        $file = $place[0];
        $neighbour = $place[1];
        $what = $place[2];
        check("$param упомянут в $file ($what)", static function () use ($file, $param, $neighbour) {
            $code = src($file);
            if (strpos($code, $neighbour) === false) {
                return array(false, "эталонный сосед «{$neighbour}» пропал из файла — тест устарел, проверь вручную");
            }
            return array(strpos($code, $param) !== false, "параметр «{$param}» не найден");
        });
    }
}

check('в filters-modern.js параметры сбрасываются кнопкой «Сбросить все»', static function () {
    $code = src('assets/js/filters-modern.js');
    // Закрывающая скобка ищется вместе с «;»: внутри списка есть элемент
    // 'status[]', и нежадный поиск просто «до первой ]» обрывал бы захват на нём.
    if (!preg_match('/ALL_FILTER_PARAMS\s*=\s*\[(.*?)\];/s', $code, $m)) {
        return array(false, 'не нашёл ALL_FILTER_PARAMS');
    }
    $list = $m[1];
    $missing = array();
    foreach (array('phone_removed', 'has_passkey') as $p) {
        if (strpos($list, "'" . $p . "'") === false) {
            $missing[] = $p;
        }
    }
    return array(empty($missing), 'нет в ALL_FILTER_PARAMS: ' . implode(', ', $missing));
});

check('has_passkey объявлен в QUICK_FILTER_PARAMS (иначе снятие галочки не чистит URL)', static function () {
    $code = src('assets/js/filters-modern.js');
    if (!preg_match('/QUICK_FILTER_PARAMS\s*=\s*\[(.*?)\]/s', $code, $m)) {
        return array(false, 'не нашёл QUICK_FILTER_PARAMS');
    }
    return array(strpos($m[1], "'has_passkey'") !== false, 'has_passkey не в QUICK_FILTER_PARAMS');
});

check('phone_removed НЕ попал в QUICK_FILTER_PARAMS (это select, а не чекбокс)', static function () {
    $code = src('assets/js/filters-modern.js');
    if (!preg_match('/QUICK_FILTER_PARAMS\s*=\s*\[(.*?)\]/s', $code, $m)) {
        return array(false, 'не нашёл QUICK_FILTER_PARAMS');
    }
    // Список чистится ПЕРЕД чтением FormData: select с непустым значением
    // переживёт это, но смысла в удалении нет, а путаницы прибавится.
    return array(strpos($m[1], "'phone_removed'") === false, 'phone_removed лишний в QUICK_FILTER_PARAMS');
});

check('select phone_removed синхронизируется из URL (syncFormFromUrl)', static function () {
    $code = src('assets/js/filters-modern.js');
    if (strpos($code, 'function syncFormFromUrl') === false) {
        return array(false, 'не нашёл syncFormFromUrl — тест устарел');
    }
    // Крестик на chip и «Сбросить все» правят URL напрямую, минуя форму.
    // Если select не синхронизировать, он останется показывать снятый фильтр.
    $ok = preg_match('/syncFormFromUrl.*select\[name="phone_removed"\]/s', $code) === 1;
    return array($ok, 'syncFormFromUrl не трогает select[name="phone_removed"]');
});

check('контролы в шаблоне закрыты проверкой наличия колонки', static function () {
    $code = src('templates/partials/dashboard/filters.php');
    $ok = strpos($code, "isset(\$ALL_COLUMNS['phone_removed'])") !== false
       && strpos($code, "isset(\$ALL_COLUMNS['passkey'])") !== false;
    return array($ok, 'без проверки колонки контрол покажется на базе, где колонки нет');
});

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

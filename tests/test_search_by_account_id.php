<?php
/**
 * Тест поиска по внутреннему ID аккаунта (первичный ключ `accounts.id`).
 *
 * Запуск:  php tests/test_search_by_account_id.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД.
 *
 * Что здесь проверяется и зачем.
 *
 * 1. Числовой запрос ищет и по `id`. Колонка ID видна в таблице дашборда и
 *    в журнале изменений, но поиском не находилась: фаза 1 знала только про
 *    login / id_soc_account / id_fan_page_*. Плейсхолдер строки поиска при
 *    этом обещал «id».
 *
 * 2. Гейт правдоподобия. `id` — INT UNSIGNED, поэтому в условие он попадает
 *    только для запроса без ведущего нуля и не больше 4294967295. Проверено на
 *    mysql:8.0: `id = '007'` выполняется как `id = 7` и находит ЧУЖУЮ строку —
 *    это и есть опасный случай. Числа больше потолка типа не находят ничего
 *    (обрезки до максимума нет), их условие просто бесполезно.
 *
 * 3. Двухфазность не сломана. Фаза 2 (LIKE по cookies/token и т.д.) вырезает
 *    ровно свои параметры через array_splice: лишний параметр в фазе 1,
 *    не учтённый в $searchParamsCount, сдвинул бы ВСЕ последующие параметры
 *    prepared statement. Поэтому здесь сверяется число `?` в WHERE с числом
 *    параметров — до и после fallback, и в связке с другими фильтрами.
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
 * Колонки для FilterBuilder. `deleted_at` обязателен: без него getWhereClause()
 * лезет в ColumnMetadata и Database, а тест должен работать без БД.
 *
 * @return array
 */
function searchColumns()
{
    return array(
        'id'             => 'ID',
        'login'          => 'Логин',
        'email'          => 'Email',
        'social_url'     => 'Соцсеть URL',
        'id_soc_account' => 'ID соц. аккаунта',
        'id_fan_page_1'  => 'ID Fan Page 1',
        'cookies'        => 'Cookies',
        'token'          => 'Token',
        'first_name'     => 'Имя',
        'last_name'      => 'Фамилия',
        'status'         => 'Статус',
        'deleted_at'     => 'Удалён',
    );
}

/**
 * Число плейсхолдеров `?` в строке WHERE.
 *
 * @param string $where
 * @return int
 */
function countPlaceholders($where)
{
    return substr_count($where, '?');
}

echo "\nПоиск по внутреннему id\n";

check('числовой запрос ищет по `id`', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('12345');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`id` = ?') !== false && in_array('12345', $f->getParams(), true);
    return array($ok, "получили: $where; params=" . json_encode($f->getParams()));
});

check('старые поля фазы 1 на месте (login, id_soc_account, id_fan_page_1)', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('12345');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`login` = ?') !== false
        && strpos($where, '`id_soc_account` = ?') !== false
        && strpos($where, '`id_fan_page_1` = ?') !== false;
    return array($ok, "получили: $where");
});

check('число параметров совпадает с числом `?`', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('12345');
    $where = $f->getWhereClause();
    $ok = countPlaceholders($where) === count($f->getParams());
    return array($ok, "плейсхолдеров " . countPlaceholders($where) . ", параметров " . count($f->getParams()));
});

check('ведущий ноль → по `id` не ищем (MySQL привёл бы 007 к 7)', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('007');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`id` = ?') === false && strpos($where, '`login` = ?') !== false;
    return array($ok, "получили: $where");
});

check('16-значный FB ID → по `id` не ищем (не влезает в INT UNSIGNED)', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('1000123456789012');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`id` = ?') === false && strpos($where, '`id_soc_account` = ?') !== false;
    return array($ok, "получили: $where");
});

check('граница INT UNSIGNED: 4294967295 ищется, 4294967296 — нет', static function () {
    $in = new FilterBuilder(searchColumns());
    $in->addSearchFilter('4294967295');
    $out = new FilterBuilder(searchColumns());
    $out->addSearchFilter('4294967296');
    $ok = strpos($in->getWhereClause(), '`id` = ?') !== false
        && strpos($out->getWhereClause(), '`id` = ?') === false;
    return array($ok, 'в границе: ' . $in->getWhereClause() . ' | вне: ' . $out->getWhereClause());
});

check('«0» — валидный запрос, но такой строки быть не может; условие всё равно корректно', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('0');
    $where = $f->getWhereClause();
    $ok = countPlaceholders($where) === count($f->getParams());
    return array($ok, "получили: $where; params=" . json_encode($f->getParams()));
});

check('текстовый запрос по `id` не ищет', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('ivan');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`id` = ?') === false && strpos($where, '`login` LIKE ?') !== false;
    return array($ok, "получили: $where");
});

check('ID из facebook-ссылки — это соц. ID, а не наш внутренний', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('https://facebook.com/profile.php?id=12345');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`id` = ?') === false && strpos($where, '`id_soc_account` = ?') !== false;
    return array($ok, "получили: $where");
});

check('нет колонки `id` в таблице → условие не строится', static function () {
    $columns = array('login' => 'Логин', 'email' => 'Email', 'deleted_at' => 'Удалён');
    $f = new FilterBuilder($columns);
    $f->addSearchFilter('12345');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`id` = ?') === false && countPlaceholders($where) === count($f->getParams());
    return array($ok, "получили: $where");
});

echo "\nДвухфазный поиск не сломан\n";

check('fallback на LIKE доступен и после добавления `id`', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('12345');
    return array($f->canFallbackToLikeSearch(), 'фаза 2 должна оставаться доступной');
});

check('после fallback параметры и `?` сходятся, `id` из условия ушёл', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('12345');
    $f->fallbackToLikeSearch();
    $where = $f->getWhereClause();
    $ok = countPlaceholders($where) === count($f->getParams())
        && strpos($where, '`id` = ?') === false
        && strpos($where, '`cookies` LIKE ?') !== false;
    return array($ok, "получили: $where; params=" . json_encode($f->getParams()));
});

check('fallback не сдвигает параметры соседних фильтров', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('12345');
    $f->addEqualFilter('status', 'Активен');
    $f->fallbackToLikeSearch();
    $params = $f->getParams();
    $where  = $f->getWhereClause();
    // Статус добавлен ПОСЛЕ поиска, значит его параметр обязан остаться последним
    $ok = countPlaceholders($where) === count($params) && end($params) === 'Активен';
    return array($ok, "получили: $where; params=" . json_encode($params));
});

check('поиск по id вместе с другим фильтром: порядок параметров сохранён', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addEqualFilter('status', 'Активен');
    $f->addSearchFilter('12345');
    $params = $f->getParams();
    $where  = $f->getWhereClause();
    $ok = countPlaceholders($where) === count($params) && $params[0] === 'Активен';
    return array($ok, "получили: $where; params=" . json_encode($params));
});

echo "\nПлейсхолдер строки поиска\n";

check('текстовый запрос ищет по имени и фамилии (это обещает плейсхолдер)', static function () {
    $f = new FilterBuilder(searchColumns());
    $f->addSearchFilter('ivan');
    $where = $f->getWhereClause();
    $ok = strpos($where, '`first_name` LIKE ?') !== false
        && strpos($where, '`last_name` LIKE ?') !== false
        && countPlaceholders($where) === count($f->getParams());
    return array($ok, "получили: $where");
});

check('подсказка в UI обещает ровно то, что ищется', static function () {
    $path = __DIR__ . '/../templates/partials/dashboard/filters.php';
    if (!is_file($path)) {
        return array(false, 'нет файла templates/partials/dashboard/filters.php');
    }
    $html = (string)file_get_contents($path);
    if (!preg_match('/name="q"(.*?)>/s', $html, $m)) {
        return array(false, 'не нашли поле поиска name="q"');
    }
    if (!preg_match('/placeholder="([^"]*)"/', $m[1], $p)) {
        return array(false, 'у поля поиска нет placeholder');
    }
    $placeholder = $p[1];
    // Плейсхолдер перечисляет поля поиска — каждое из них обязано реально искаться.
    // Проверяем именно «id»: до этой правки он был обещан и не работал.
    $ok = stripos($placeholder, 'id') !== false;
    return array($ok, "placeholder: «{$placeholder}»");
});

echo "\nИтого: пройдено $passed, провалено $failures\n";
exit($failures > 0 ? 1 : 0);

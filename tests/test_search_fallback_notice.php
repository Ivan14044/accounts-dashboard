<?php
/**
 * Тест честного предупреждения о расширенном поиске (фаза 2) и режима
 * «только точное совпадение».
 *
 * Запуск:  php tests/test_search_fallback_notice.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД.
 *
 * Зачем это вообще появилось (реальный случай 2026-08-28, прод).
 *
 *   Владелец искал аккаунт по внутреннему номеру `4054` и получил 433 строки.
 *   Аккаунта с таким `id` в базе нет вообще (проверено на проде фильтром
 *   `?ids[]=4054` → 0 записей, корзина пуста), поэтому фаза 1 дала ноль и
 *   молча сработал откат на LIKE '%4054%': совпадения нашлись ВНУТРИ длинных
 *   значений — `id_soc_account`, `social_url`, `id_fan_page_1`. Со стороны это
 *   неотличимо от «панель нашла 433 аккаунта с номером 4054».
 *
 * Отсюда два требования, которые здесь и проверяются:
 *
 * 1. Откат должен быть виден пользователю. За текст отвечает шаблон, за
 *    РЕШЕНИЕ «что показать» — {@see SearchNotice::build()}: чистая функция без
 *    БД, поэтому её краевые случаи закрываются тестом, а не глазами.
 *
 * 2. Должен существовать способ запретить откат («искать только точное
 *    совпадение»). Запрет живёт в самом {@see FilterBuilder}, а не в двух
 *    вызывающих местах (index.php через DashboardController и refresh.php):
 *    условие `if ($filteredTotal === 0 && $filter->canFallbackToLikeSearch())`
 *    написано в обоих файлах, и любое дублирование правила разошлось бы.
 *
 * Отдельно проверяется инвариант prepared statement: число `?` в WHERE обязано
 * совпадать с числом параметров и после запрещённого отката тоже.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/FilterBuilder.php';
require_once __DIR__ . '/../includes/SearchNotice.php';

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
function noticeColumns()
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
 * @param string $query
 * @param bool   $exactOnly
 * @return FilterBuilder
 */
function noticeFilter($query, $exactOnly = false)
{
    $f = new FilterBuilder(noticeColumns(), array('id'), array(), 'accounts');
    $f->setExactSearchOnly($exactOnly);
    $f->addSearchFilter($query);
    return $f;
}

/**
 * Число плейсхолдеров в WHERE должно совпадать с числом параметров, иначе
 * bind_param молча сдвинет значения соседних фильтров.
 *
 * @param FilterBuilder $f
 * @return array [ok, msg]
 */
function paramsMatch(FilterBuilder $f)
{
    $where  = $f->getWhereClause();
    $marks  = substr_count($where, '?');
    $params = count($f->getParams());
    return array($marks === $params, "плейсхолдеров $marks, параметров $params; WHERE: $where");
}

echo "\n=== Режим «только точное совпадение» (FilterBuilder) ===\n";

check('по умолчанию откат на LIKE разрешён', function () {
    $f = noticeFilter('4054');
    return array($f->canFallbackToLikeSearch() === true, 'canFallbackToLikeSearch() вернул false');
});

check('setExactSearchOnly(true) запрещает откат', function () {
    $f = noticeFilter('4054', true);
    return array($f->canFallbackToLikeSearch() === false, 'откат остался разрешён');
});

check('запрет виден снаружи через isExactSearchOnly()', function () {
    $f = noticeFilter('4054', true);
    return array($f->isExactSearchOnly() === true, 'isExactSearchOnly() вернул не true');
});

check('в exact-режиме WHERE остаётся точным поиском, без LIKE', function () {
    $f = noticeFilter('4054', true);
    $f->fallbackToLikeSearch(); // вызов вхолостую — должен быть проигнорирован
    $where = $f->getWhereClause();
    return array(strpos($where, 'LIKE') === false, "в WHERE появился LIKE: $where");
});

check('в exact-режиме число плейсхолдеров не разъезжается после холостого отката', function () {
    $f = noticeFilter('4054', true);
    $f->addStatusFilter(array('Активен'));
    $f->fallbackToLikeSearch();
    return paramsMatch($f);
});

check('до отката isLikeFallbackApplied() === false', function () {
    $f = noticeFilter('4054');
    return array($f->isLikeFallbackApplied() === false, 'флаг взведён раньше времени');
});

check('после отката isLikeFallbackApplied() === true', function () {
    $f = noticeFilter('4054');
    $f->fallbackToLikeSearch();
    return array($f->isLikeFallbackApplied() === true, 'флаг не взведён');
});

check('после отката в WHERE действительно LIKE, параметры сходятся', function () {
    $f = noticeFilter('4054');
    $f->addStatusFilter(array('Активен'));
    $f->fallbackToLikeSearch();
    $where = $f->getWhereClause();
    if (strpos($where, 'LIKE') === false) {
        return array(false, "LIKE не появился: $where");
    }
    return paramsMatch($f);
});

check('exact на числовом запросе реально ограничивает поиск', function () {
    $f = noticeFilter('4054', true);
    return array($f->isExactSearchEffective() === true, 'режим объявлен недействующим');
});

check('exact на ТЕКСТОВОМ запросе ничего не ограничивает', function () {
    // Текстовый поиск и так идёт по подстроке — фазы 1 у него нет, ограничивать
    // нечего. Иначе плашка соврала бы: «показаны только точные совпадения».
    $f = noticeFilter('Иван', true);
    return array($f->isExactSearchEffective() === false, 'режим объявлен действующим на LIKE-поиске');
});

check('exact без поискового запроса недействующий', function () {
    $f = noticeFilter('', true);
    return array($f->isExactSearchEffective() === false, 'режим действует на пустом поиске');
});

check('без exact режим недействующий даже на числовом запросе', function () {
    $f = noticeFilter('4054');
    return array($f->isExactSearchEffective() === false, 'режим действует без запроса пользователя');
});

check('текстовый запрос не считается откатом (LIKE был сразу)', function () {
    $f = noticeFilter('Иван');
    return array(
        $f->canFallbackToLikeSearch() === false && $f->isLikeFallbackApplied() === false,
        'текстовый поиск ошибочно помечен как двухфазный'
    );
});

echo "\n=== Что показать пользователю (SearchNotice) ===\n";

check('пустой запрос — сообщения нет', function () {
    $n = SearchNotice::build('', 100, false, false, array());
    return array($n === null, 'вернулось сообщение на пустом запросе');
});

check('запрос из одних пробелов — сообщения нет', function () {
    $n = SearchNotice::build('   ', 100, true, false, array('q' => '   '));
    return array($n === null, 'вернулось сообщение на пробелах');
});

check('обычный поиск с результатами — сообщения нет', function () {
    $n = SearchNotice::build('4054', 12, false, false, array('q' => '4054'));
    return array($n === null, 'сообщение показано без причины');
});

check('откат сработал и что-то нашлось — тип fallback', function () {
    $n = SearchNotice::build('4054', 433, true, false, array('q' => '4054'));
    if ($n === null) return array(false, 'сообщения нет');
    return array($n['type'] === 'fallback', "тип: " . $n['type']);
});

check('откат сработал, но не нашлось ничего — сообщения нет', function () {
    $n = SearchNotice::build('4054', 0, true, false, array('q' => '4054'));
    return array($n === null, 'показано сообщение поверх пустой выдачи');
});

check('ссылка из fallback включает exact=1 и сам запрос', function () {
    $n = SearchNotice::build('4054', 433, true, false, array('q' => '4054'));
    $url = $n['url'];
    return array(
        strpos($url, 'exact=1') !== false && strpos($url, 'q=4054') !== false,
        "url: $url"
    );
});

check('ссылка из fallback возвращает на первую страницу', function () {
    $n = SearchNotice::build('4054', 433, true, false, array('q' => '4054', 'page' => '7'));
    return array(strpos($n['url'], 'page=1') !== false, 'url: ' . $n['url']);
});

check('ссылка из fallback сохраняет остальные фильтры', function () {
    $params = array('q' => '4054', 'status' => array('Активен', 'Бан'), 'geo' => 'UA');
    $n = SearchNotice::build('4054', 433, true, false, $params);
    $url = $n['url'];
    return array(
        strpos($url, 'geo=UA') !== false && strpos(urldecode($url), 'status[0]=') !== false,
        "url: $url"
    );
});

check('служебные параметры обновления в ссылку не попадают', function () {
    $params = array('q' => '4054', 'light' => '1', 'debug' => '1', '_' => '123');
    $n = SearchNotice::build('4054', 433, true, false, $params);
    $url = $n['url'];
    return array(
        strpos($url, 'light') === false && strpos($url, 'debug') === false && strpos($url, '_=') === false,
        "url: $url"
    );
});

check('exact-режим без результатов — тип exact_empty', function () {
    $n = SearchNotice::build('4054', 0, false, true, array('q' => '4054', 'exact' => '1'));
    if ($n === null) return array(false, 'сообщения нет');
    return array($n['type'] === 'exact_empty', 'тип: ' . $n['type']);
});

check('exact-режим с результатами — тип exact_active', function () {
    $n = SearchNotice::build('3277', 1, false, true, array('q' => '3277', 'exact' => '1'));
    if ($n === null) return array(false, 'сообщения нет');
    return array($n['type'] === 'exact_active', 'тип: ' . $n['type']);
});

check('ссылка из exact-режима снимает exact', function () {
    $n = SearchNotice::build('4054', 0, false, true, array('q' => '4054', 'exact' => '1'));
    return array(strpos($n['url'], 'exact') === false, 'url: ' . $n['url']);
});

check('запрос возвращается очищенным от пробелов по краям', function () {
    $n = SearchNotice::build('  4054  ', 433, true, false, array('q' => '  4054  '));
    return array($n['query'] === '4054', 'query: ' . var_export($n['query'], true));
});

check('в ссылку уходит очищенный запрос, а не исходный с пробелами', function () {
    $n = SearchNotice::build('  4054  ', 433, true, false, array('q' => '  4054  ', 'page' => '3'));
    $url = urldecode($n['url']);
    return array(strpos($url, 'q=4054&') !== false || substr($url, -7) === 'q=4054', "url: $url");
});

check('массив вместо строки в q страницу не роняет', function () {
    // ?q[]=z собирается только руками, но 500-я из-за него уже случалась
    $n = SearchNotice::build('4054', 433, true, false, array('q' => array('z')));
    return array(is_array($n) && isset($n['url']), 'вернулось: ' . var_export($n, true));
});

echo "\n";
echo "Пройдено: $passed, провалено: $failures\n";
exit($failures > 0 ? 1 : 0);

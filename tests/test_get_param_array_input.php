<?php
/**
 * Тест: get_param() не ломается, когда GET-параметр пришёл массивом.
 *
 * Запуск:  php tests/test_get_param_array_input.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (воспроизведено на стенде 2026-08-23).
 *
 * Фронт отправляет статусы КАНОНИЧЕСКИ как `status[]=A&status[]=B`
 * (assets/js/filters-modern.js: `url.searchParams.append('status[]', ...)`),
 * то есть `$_GET['status']` на каждом заходе с фильтром по статусу — массив.
 * А get_param() делал `trim((string)$_GET[$key])`, и приведение массива к
 * строке давало на 7.x `Notice: Array to string conversion`, на 8.x — Warning,
 * плюс бессмысленное значение "Array".
 *
 *     GET /index.php?status%5B%5D=Активен
 *     → php_errors.log: PHP Notice: Array to string conversion in
 *       includes/Utils.php on line 55
 *
 * На проде error_reporting=E_ALL, поэтому это был шум в логе на КАЖДОМ таком
 * заходе. Сама фильтрация не ломалась: DashboardController читает
 * `$_GET['status']` в обход get_param(). Но RequestHandler::countActiveFilters()
 * считал «1 активный фильтр» вместо реального числа статусов — по случайности,
 * из-за непустой строки "Array".
 *
 * Зафиксированная семантика:
 *   - get_param() возвращает СТРОКУ. Массив строкой не является ⇒ отдаём
 *     $default (та же семантика, что у давно живущего export_param() в
 *     export.php). Первый элемент не берём: `?sort[]=id` — это ошибка клиента
 *     или попытка что-то подсунуть, и молча её «понимать» вредно.
 *   - get_param_array() — отдельная функция для честно многозначных
 *     параметров (сейчас это только status).
 *
 * Тест чистый: без БД, без сети, без сессии.
 */

// Строгий режим: Notice/Warning становятся исключением. Именно из-за них тест
// и написан — молча пройти мимо «Array to string conversion» нельзя.
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
function gpCheck($name, $ok, $detail = '')
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
 * Выполняет колбэк и превращает любое исключение/ошибку в описание провала.
 * Нужно, чтобы одна упавшая проверка не обрывала весь файл.
 *
 * @param callable $fn Что выполнить
 * @return array [значение|null, текст ошибки|'']
 */
function gpTry($fn)
{
    try {
        return array(call_user_func($fn), '');
    } catch (Throwable $e) {
        return array(null, get_class($e) . ': ' . $e->getMessage());
    }
}

require_once $ROOT . '/includes/Utils.php';
require_once $ROOT . '/includes/RequestHandler.php';

echo "== get_param(): массив на входе ==\n";

$_GET = array('status' => array('Активен', 'Бан'));
list($val, $err) = gpTry(function () { return get_param('status'); });
gpCheck('get_param("status") на массиве не поднимает Notice/Warning', $err === '', $err);
gpCheck('get_param("status") на массиве отдаёт default ""', $val === '', 'получили ' . var_export($val, true));

$_GET = array('sort' => array('id', 'login'));
list($val, $err) = gpTry(function () { return get_param('sort', 'id'); });
gpCheck('get_param("sort", "id") на массиве отдаёт свой default', $err === '' && $val === 'id',
    $err !== '' ? $err : 'получили ' . var_export($val, true));

$_GET = array('q' => array(array('вложенный')));
list($val, $err) = gpTry(function () { return get_param('q'); });
gpCheck('get_param() на вложенном массиве тоже молчит и отдаёт default', $err === '' && $val === '',
    $err !== '' ? $err : 'получили ' . var_export($val, true));

echo "== get_param(): скаляры работают как раньше ==\n";

$_GET = array('q' => '  текст  ', 'zero' => '0', 'empty' => '');
gpCheck('строка тримится', get_param('q') === 'текст');
gpCheck('"0" не превращается в default', get_param('zero', 'D') === '0');
gpCheck('пустая строка остаётся пустой строкой, а не default', get_param('empty', 'D') === '');
gpCheck('отсутствующий ключ даёт default', get_param('нет-такого', 'D') === 'D');

echo "== get_param_array(): многозначные параметры ==\n";

if (!function_exists('get_param_array')) {
    gpCheck('функция get_param_array() существует', false, 'не объявлена в includes/Utils.php');
} else {
    gpCheck('функция get_param_array() существует', true);

    $_GET = array('status' => array(' Активен ', 'Бан', '', '   '));
    list($val, $err) = gpTry(function () { return get_param_array('status'); });
    gpCheck('массив: элементы тримятся, пустые отбрасываются',
        $err === '' && $val === array('Активен', 'Бан'),
        $err !== '' ? $err : var_export($val, true));

    $_GET = array('status' => 'Активен, Бан');
    list($val, $err) = gpTry(function () { return get_param_array('status'); });
    gpCheck('строка через запятую разбирается в список',
        $err === '' && $val === array('Активен', 'Бан'),
        $err !== '' ? $err : var_export($val, true));

    $_GET = array('status' => 'Активен');
    list($val, $err) = gpTry(function () { return get_param_array('status'); });
    gpCheck('одиночная строка даёт список из одного элемента',
        $err === '' && $val === array('Активен'),
        $err !== '' ? $err : var_export($val, true));

    $_GET = array();
    list($val, $err) = gpTry(function () { return get_param_array('status'); });
    gpCheck('отсутствующий ключ даёт пустой массив',
        $err === '' && $val === array(), $err !== '' ? $err : var_export($val, true));

    $_GET = array('status' => array('Активен', array('вложенный'), 'Бан'));
    list($val, $err) = gpTry(function () { return get_param_array('status'); });
    gpCheck('вложенные массивы отбрасываются без Notice',
        $err === '' && $val === array('Активен', 'Бан'),
        $err !== '' ? $err : var_export($val, true));

    $_GET = array('status' => array('А', 'Б'));
    list($val, $err) = gpTry(function () { return get_param_array('status'); });
    gpCheck('ключи результата — сплошная нумерация с нуля',
        $err === '' && array_keys((array)$val) === array(0, 1),
        $err !== '' ? $err : var_export($val, true));
}

echo "== RequestHandler: фильтры и счётчик ==\n";

$_GET = array('status' => array('Активен', 'Бан'));
list($params, $err) = gpTry(function () { return RequestHandler::getFilterParams(); });
gpCheck('getFilterParams() не поднимает Notice на status[]', $err === '', $err);
gpCheck('getFilterParams()["status"] — массив статусов, а не "Array"',
    is_array($params) && isset($params['status']) && $params['status'] === array('Активен', 'Бан'),
    isset($params['status']) ? var_export($params['status'], true) : 'ключа нет');

if (is_array($params)) {
    list($cnt, $err2) = gpTry(function () use ($params) { return RequestHandler::countActiveFilters($params); });
    gpCheck('countActiveFilters() считает два выбранных статуса как 2',
        $err2 === '' && $cnt === 2, $err2 !== '' ? $err2 : 'получили ' . var_export($cnt, true));
}

$_GET = array('q' => array('мусор'), 'geo' => array('UA'));
list($params, $err) = gpTry(function () { return RequestHandler::getFilterParams(); });
gpCheck('одиночные фильтры на массиве молчат и становятся ""',
    $err === '' && isset($params['q']) && $params['q'] === '' && $params['geo'] === '',
    $err !== '' ? $err : var_export(array($params['q'], $params['geo']), true));

list($cnt, $err) = gpTry(function () use ($params) { return RequestHandler::countActiveFilters($params); });
gpCheck('countActiveFilters() не засчитывает мусорные массивы', $err === '' && $cnt === 0,
    $err !== '' ? $err : 'получили ' . var_export($cnt, true));

echo "== RequestHandler: пагинация и сортировка на массивах ==\n";

$_GET = array('page' => array('5'), 'per_page' => array('100'));
list($val, $err) = gpTry(function () { return RequestHandler::getPaginationParams(); });
gpCheck('getPaginationParams() молчит и падает на дефолты',
    $err === '' && $val['page'] === 1 && $val['perPage'] === 50,
    $err !== '' ? $err : var_export($val, true));

$_GET = array('sort' => array('login'), 'dir' => array('desc'));
list($val, $err) = gpTry(function () { return RequestHandler::getSortParams(array('id', 'login')); });
gpCheck('getSortParams() молчит и падает на дефолты',
    $err === '' && $val['sort'] === 'id' && $val['dir'] === 'ASC',
    $err !== '' ? $err : var_export($val, true));

echo "== sort_link(): ссылки сортировки на массивах ==\n";

$_SERVER['REQUEST_URI'] = '/index.php?status%5B%5D=A';
$_GET = array('status' => array('A'), 'sort' => array('login'));
list($val, $err) = gpTry(function () { return sort_link('login'); });
gpCheck('sort_link() не поднимает Notice при status[] и sort[]', $err === '', $err);

echo "\nИтого: OK=$passed, FAIL=$failures\n";
exit($failures > 0 ? 1 : 0);

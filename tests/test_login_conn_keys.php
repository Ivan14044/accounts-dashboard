<?php
/**
 * Тест: разбор строки подключения на логине не разошёлся с серверным.
 *
 * Запуск:  php tests/test_login_conn_keys.php
 * Код выхода: 0 — прошло, 1 — упало. Без сети и БД.
 *
 * Зачем этот тест существует.
 * Страница входа показывает «показания» — хост, порт, базу и пользователя,
 * разобранные из строки подключения прямо в браузере
 * (assets/js/login.js, KEY_MAP). Настоящий разбор живёт в
 * parseConnectionString() в auth.php. Это ВТОРАЯ копия одного знания, а в этом
 * проекте такие копии уже расходились молча (схема accounts в трёх местах).
 *
 * Опасность конкретная: добавит кто-нибудь в PHP синоним ключа — скажем,
 * `case 'host':` — и панель на логине начнёт показывать прочерк там, где
 * сервер прекрасно понял строку. Человек решит, что строка неверная, и полезет
 * её «чинить». Обратный случай хуже: уберут ключ из PHP, а браузер продолжит
 * бодро показывать хост, которого сервер уже не видит.
 *
 * Поэтому здесь сравниваются НАБОРЫ ключей, а не поведение: все ключи из
 * switch в parseConnectionString() обязаны быть либо в KEY_MAP (показываем),
 * либо в IGNORED_KEYS (осознанно не показываем — пароль и кодировка).
 */

// Warning/Notice — тоже провал: на проде error_reporting=E_ALL.
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$failures = 0;
$passed   = 0;

$ROOT = dirname(__DIR__);

/**
 * @param string $name  что проверяем
 * @param bool   $cond  результат проверки
 * @param string $extra подробности для вывода при падении
 * @return void
 */
function check($name, $cond, $extra = '')
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "  ok  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n";
    }
}

/**
 * Ключи из switch внутри parseConnectionString() в auth.php.
 *
 * @param string $php исходник auth.php
 * @return string[] уникальные ключи в нижнем регистре
 */
function phpConnectionKeys($php)
{
    $start = strpos($php, 'function parseConnectionString');
    if ($start === false) {
        return array();
    }
    // Функция заканчивается там, где начинается следующая — этого достаточно,
    // вложенных функций внутри неё нет.
    $end = strpos($php, "\nfunction ", $start + 1);
    $body = $end === false ? substr($php, $start) : substr($php, $start, $end - $start);

    preg_match_all("/case\s+'([^']+)'\s*:/", $body, $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
}

/**
 * Ключи, которые знает login.js: показываемые (KEY_MAP) и намеренно
 * игнорируемые (IGNORED_KEYS).
 *
 * @param string $js исходник assets/js/login.js
 * @return array{mapped: string[], ignored: string[]}
 */
function jsConnectionKeys($js)
{
    $mapped = array();
    $ignored = array();

    if (preg_match('/var\s+KEY_MAP\s*=\s*\{(.*?)\};/s', $js, $m)) {
        preg_match_all("/'([^']+)'\s*:/", $m[1], $keys);
        $mapped = array_map('strtolower', $keys[1]);
    }

    if (preg_match('/var\s+IGNORED_KEYS\s*=\s*\[(.*?)\];/s', $js, $m)) {
        preg_match_all("/'([^']+)'/", $m[1], $keys);
        $ignored = array_map('strtolower', $keys[1]);
    }

    return array('mapped' => $mapped, 'ignored' => $ignored);
}

// ── Проверки ────────────────────────────────────────────────────────────────

$authPath = $ROOT . '/auth.php';
$jsPath   = $ROOT . '/assets/js/login.js';

check('auth.php на месте', is_file($authPath));
check('assets/js/login.js на месте', is_file($jsPath));

if ($failures > 0) {
    echo "\nИтог: $passed ок, $failures упало\n";
    exit(1);
}

$phpKeys = phpConnectionKeys(file_get_contents($authPath));
$js      = jsConnectionKeys(file_get_contents($jsPath));
$jsKeys  = array_merge($js['mapped'], $js['ignored']);

check('ключи parseConnectionString() найдены', count($phpKeys) > 0, 'разбор auth.php не дал ни одного case');
check('KEY_MAP в login.js найден и не пуст', count($js['mapped']) > 0);
check('IGNORED_KEYS в login.js найден и не пуст', count($js['ignored']) > 0);

$missingInJs = array_values(array_diff($phpKeys, $jsKeys));
check(
    'все ключи из PHP известны login.js',
    count($missingInJs) === 0,
    'нет в login.js: ' . implode(', ', $missingInJs)
);

$extraInJs = array_values(array_diff($jsKeys, $phpKeys));
check(
    'login.js не выдумывает ключей, которых нет в PHP',
    count($extraInJs) === 0,
    'нет в auth.php: ' . implode(', ', $extraInJs)
);

// Пароль не должен попадать в показания ни под каким видом.
$passwordShown = array_intersect(array('password', 'pwd'), $js['mapped']);
check(
    'пароль не выводится в показаниях',
    count($passwordShown) === 0,
    'в KEY_MAP есть: ' . implode(', ', $passwordShown)
);

// Порт по умолчанию должен совпадать: PHP подставляет 3306, панель — тоже,
// иначе пустой порт в строке показывался бы прочерком при живом подключении.
$jsSource = file_get_contents($jsPath);
check(
    'порт по умолчанию 3306 подставляется и в браузере',
    strpos($jsSource, "out.port = '3306'") !== false
);

echo "\nИтог: $passed ок, $failures упало\n";
exit($failures > 0 ? 1 : 0);

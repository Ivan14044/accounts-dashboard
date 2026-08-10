<?php
/**
 * Тест: маршруты API работают с выбранной таблицей, а не с таблицей по умолчанию.
 *
 * Запуск:  php tests/test_api_routes_table_scope.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (воспроизведено на стенде 2026-08-10).
 *
 * Вход в панель — это строка подключения, и одна установка обслуживает несколько
 * таблиц: текущая приходит в `$tableName`, который api/index.php отдаёт каждому
 * файлу маршрутов. Забыть его легко, а последствие тихое и неприятное:
 * и AccountsService, и FilterBuilder по умолчанию берут 'accounts'.
 *
 * Так и было в api/routes/check-fb.php: `new AccountsService()` без аргумента.
 * Пробник на двух таблицах с одинаковым id показал, что именно происходит:
 *
 *   Запрошена таблица accounts2, id=7001
 *     как сейчас (без $tableName) → login: in_accounts_only     ← ЧУЖАЯ таблица
 *     как должно (с $tableName)   → login: in_accounts2_only
 *
 * То есть проверка аккаунтов брала данные (токен, cookies) НЕ ТОГО аккаунта и
 * возвращала статусы, привязанные к id из другой таблицы.
 *
 * Инвариант: в api/routes/* нельзя создавать AccountsService и FilterBuilder
 * без явной таблицы. Проверяется по всем файлам маршрутов сразу, а не по списку
 * известных мест, — чтобы новый маршрут не завёл ту же ошибку заново.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

ini_set('pcre.jit', '0');

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
function artCheck($name, $ok, $detail = '')
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
 * Исходник без комментариев — чтобы примеры в докблоках не считались кодом.
 *
 * Комментарий заменяется на СТОЛЬКО ЖЕ переводов строк, сколько в нём было:
 * иначе номера строк в сообщении об ошибке съедут относительно настоящего файла,
 * и тест будет отправлять читателя не туда.
 *
 * @param string $file Путь к файлу
 * @return string
 */
function artStrip($file)
{
    $out = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

echo "\n=== API-маршруты: работа с выбранной таблицей ===\n\n";

$routeDir = $ROOT . '/api/routes';
$routes = glob($routeDir . '/*.php');
sort($routes);

artCheck('файлы маршрутов найдены', count($routes) >= 3, 'нашли ' . count($routes));

foreach ($routes as $path) {
    $rel  = 'api/routes/' . basename($path);
    $code = artStrip($path);

    // AccountsService без аргументов — молча возьмёт 'accounts'
    if (preg_match_all('~new\s+AccountsService\s*\(([^)]*)\)~', $code, $m, PREG_OFFSET_CAPTURE)) {
        $bad = array();
        foreach ($m[1] as $i => $args) {
            if (trim($args[0]) === '') {
                $bad[] = substr_count(substr($code, 0, $m[0][$i][1]), "\n") + 1;
            }
        }
        artCheck(
            "$rel: AccountsService создаётся с таблицей",
            $bad === array(),
            'без аргумента в строках: ' . implode(', ', $bad)
                . ' — маршрут будет читать таблицу по умолчанию вместо выбранной'
        );
    }

    // FilterBuilder без имени таблицы — четвёртый аргумент
    if (preg_match_all('~new\s+FilterBuilder\s*\(([^;]*?)\)\s*;~s', $code, $m, PREG_OFFSET_CAPTURE)) {
        $bad = array();
        foreach ($m[1] as $i => $args) {
            // Считаем аргументы верхнего уровня: вложенные вызовы в скобках не в счёт
            $depth = 0;
            $count = 1;
            $s = $args[0];
            for ($k = 0, $n = strlen($s); $k < $n; $k++) {
                $ch = $s[$k];
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $count++;
                }
            }
            if (trim($s) === '') {
                $count = 0;
            }
            if ($count < 4) {
                $bad[] = (substr_count(substr($code, 0, $m[0][$i][1]), "\n") + 1) . " (аргументов: $count)";
            }
        }
        artCheck(
            "$rel: FilterBuilder получает имя таблицы (4-й аргумент)",
            $bad === array(),
            'неполный вызов в строках: ' . implode(', ', $bad)
                . ' — по умолчанию подставится «accounts»'
        );
    }
}

// Страховка от «тихо зелёного» теста: убеждаемся, что регекспы вообще что-то видят.
$all = '';
foreach ($routes as $p) {
    $all .= artStrip($p);
}
artCheck(
    'в маршрутах вообще встречается AccountsService',
    strpos($all, 'new AccountsService') !== false,
    'проверка выше ничего не проверяла бы'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

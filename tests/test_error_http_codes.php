<?php
/**
 * Тест выбора HTTP-кода и формата ответа в ErrorHandler.
 *
 * Запуск:  php tests/test_error_http_codes.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД: дёргаем чистые решающие функции, ничего не отправляя клиенту.
 *
 * Регрессия, ради которой тест написан: ошибка ввода (кривой JSON, отсутствующий
 * CSRF) отдавалась как HTTP 500 + HTML-страница, если клиент не прислал заголовок
 * X-Requested-With. То есть ошибка клиента маскировалась под отказ сервера, а
 * JSON-клиент получал HTML.
 */

require_once __DIR__ . '/../includes/ErrorHandler.php';

$failures = 0;
$passed   = 0;

function check(string $name, bool $ok, string $msg = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($msg !== '' ? " — $msg" : '') . "\n";
    }
}

/** Подменяет $_SERVER на время одной проверки. */
function withServer(array $server, callable $fn)
{
    $saved = $_SERVER;
    $_SERVER = $server + $_SERVER;
    try {
        return $fn();
    } finally {
        $_SERVER = $saved;
    }
}

echo "\n=== resolveHttpCode: тип исключения → код ===\n\n";

$cases = [
    'InvalidArgumentException → 400' => [new InvalidArgumentException('Status is required'), null, 400],
    'явно переданный код побеждает'  => [new RuntimeException('что угодно'), 422, 422],
    'RuntimeException → 500'         => [new RuntimeException('boom'), null, 500],
    'ошибка БД → 500'                => [new Exception('mysqli failed to prepare'), null, 500],
    'неавторизован → 401'            => [new Exception('Unauthorized'), null, 401],
    'необходима авторизация → 401'   => [new Exception('Необходима авторизация'), null, 401],
    'прочее → 500'                   => [new Exception('нечто странное'), null, 500],
];

foreach ($cases as $name => [$e, $explicit, $expected]) {
    $got = ErrorHandler::resolveHttpCode($e, $explicit);
    check($name, $got === $expected, "ожидали $expected, получили $got");
}

echo "\n=== isApiRequest: когда отвечаем JSON ===\n\n";

$apiCases = [
    'AJAX-заголовок'                 => [['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], true],
    'Content-Type: application/json' => [['CONTENT_TYPE' => 'application/json'], true],
    'Content-Type с charset'         => [['CONTENT_TYPE' => 'application/json; charset=utf-8'], true],
    'Accept: application/json'       => [['HTTP_ACCEPT' => 'application/json, text/plain, */*'], true],
    'путь /api/...'                  => [['SCRIPT_NAME' => '/api/index.php'], true],
    'обычная страница'               => [['HTTP_ACCEPT' => 'text/html', 'SCRIPT_NAME' => '/index.php'], false],
    'форма без заголовков'           => [['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'SCRIPT_NAME' => '/index.php', 'HTTP_ACCEPT' => 'text/html'], false],
];

foreach ($apiCases as $name => [$server, $expected]) {
    $base = ['HTTP_X_REQUESTED_WITH' => '', 'HTTP_ACCEPT' => '', 'CONTENT_TYPE' => '', 'SCRIPT_NAME' => '/page.php'];
    $got = withServer($server + $base, static function () {
        return ErrorHandler::isApiRequest();
    });
    check($name, $got === $expected, 'ожидали ' . var_export($expected, true) . ', получили ' . var_export($got, true));
}

echo "\n=== read_json_input: текст ошибки ===\n\n";

require_once __DIR__ . '/../includes/Utils.php';

check('кривой JSON → InvalidArgumentException с настоящей причиной', (static function () {
    // Проверяем именно то, что ломалось: json_last_error() сбрасывался вызовом
    // Logger (внутри него json_encode контекста), и в сообщение попадало
    // бессмысленное «No error» вместо «Syntax error».
    try {
        decode_json_body('{не json', 1048576);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        return stripos($msg, 'no error') === false && stripos($msg, 'syntax') !== false;
    } catch (Throwable $e) {
        echo "        (получили " . get_class($e) . ": " . $e->getMessage() . ")\n";
        return false;
    }
    return false;
})());

check('пустое тело → null, без исключения', decode_json_body('', 1048576) === null);
check('валидный JSON → массив', decode_json_body('{"a":1}', 1048576) === ['a' => 1]);
check('слишком большое тело → InvalidArgumentException', (static function () {
    try {
        decode_json_body(str_repeat('x', 20), 10);
    } catch (InvalidArgumentException $e) {
        return true;
    } catch (Throwable $e) {
        return false;
    }
    return false;
})());

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

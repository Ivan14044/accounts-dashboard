<?php
/**
 * Долгие операции отпускают сессию — другие вкладки того же браузера не висят.
 *
 * Зачем. PHP держит файл сессии под замком до конца запроса. Пока он
 * заперт, ЛЮБОЙ другой запрос из того же браузера (открыть панель в новой
 * вкладке, нажать F5, фоновое обновление таблицы) ждёт — страница «просто
 * грузится», без ошибки. На стенде: массовый перенос шёл 18 с, и index.php
 * из того же браузера ответил через 18,35 с, а из другого — через 0,05 с.
 * На проде 21.10.2026 повторное нажатие переноса пришло в 10:23:26, но
 * начало работать только в 10:25:02 — когда закончился первый запрос
 * (в журнале +332 972 мс против +253 408 мс у первого при одинаковой работе).
 *
 * Проверяется:
 *  1. SessionManager::releaseLock() действительно снимает замок с файла
 *     (второй процесс может его взять) и оставляет $_SESSION читаемым;
 *  2. отметка «я только что менял данные» (по ней счётчики панели
 *     показывают свежие числа сразу) доживает до конца запроса:
 *     finishReleasedRequest() на миг открывает сессию и пишет её;
 *  3. json_response() сам вызывает finishReleasedRequest() — забыть нельзя;
 *  4. каждая долгая точка входа отпускает сессию сразу после проверки входа.
 */
require_once __DIR__ . '/../includes/Config.php';
require_once __DIR__ . '/../includes/SessionManager.php';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// В CLI любой вывод = «заголовки отправлены», а сессию тогда не открыть заново
ob_start();

$failures = [];
$check = function ($cond, $msg) use (&$failures) {
    if (!$cond) {
        $failures[] = $msg;
    }
};

$dir = sys_get_temp_dir() . '/dashboard-lock-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
session_save_path($dir);
session_id('dashboardlocktest' . bin2hex(random_bytes(4)));
$file = $dir . '/sess_' . session_id();

/** Можно ли прямо сейчас взять замок на файл сессии (как это сделал бы второй запрос). */
$lockFree = function () use ($file) {
    $h = fopen($file, 'c+');
    $free = flock($h, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return $free;
};

try {
    $check(method_exists('SessionManager', 'releaseLock'), 'есть SessionManager::releaseLock()');
    $check(method_exists('SessionManager', 'finishReleasedRequest'), 'есть SessionManager::finishReleasedRequest()');
    if ($failures) {
        throw new RuntimeException('нет методов');
    }

    SessionManager::start();
    $_SESSION['username'] = 'tester';
    $_SESSION['csrf_token'] = 'tok';
    $check(!$lockFree(), 'пока сессия открыта, второй запрос ждал бы');

    SessionManager::releaseLock();
    $check(session_status() === PHP_SESSION_NONE, 'после releaseLock сессия закрыта');
    $check($lockFree(), 'после releaseLock второй запрос берёт сессию сразу');
    $check(($_SESSION['csrf_token'] ?? null) === 'tok', 'после releaseLock данные сессии читаются (проверка CSRF работает)');
    $check(SessionManager::isLockReleased(), 'запрос помнит, что отпустил сессию');

    // Долгая работа... затем ответ
    SessionManager::finishReleasedRequest();
    $check(session_status() === PHP_SESSION_NONE, 'после finishReleasedRequest сессия снова закрыта');
    $check($lockFree(), 'после finishReleasedRequest замок свободен');

    session_start();
    $until = (int)($_SESSION[Config::STATS_SELF_WRITE_FLAG] ?? 0);
    $check($until >= time() + Config::STATS_FINGERPRINT_BUCKET - 2,
        'отметка «сам менял данные» записана в сессию КОНЦОМ операции (свежие счётчики сразу)');
    $check(($_SESSION['username'] ?? null) === 'tester', 'остальные данные сессии не потерялись');
    session_write_close();

    // Повторный вызов безвреден, а releaseLock(false) — для операций только на чтение
    SessionManager::finishReleasedRequest();
    session_start();
    unset($_SESSION[Config::STATS_SELF_WRITE_FLAG]);
    session_write_close();
    session_start();
    SessionManager::releaseLock(false);
    SessionManager::finishReleasedRequest();
    session_start();
    $check(!isset($_SESSION[Config::STATS_SELF_WRITE_FLAG]), 'releaseLock(false) не ставит отметку записи (выгрузка — чтение)');
    session_write_close();
} catch (RuntimeException $e) {
    // уже записано в $failures
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    rmdir($dir);
}

// 2б. Повторное открытие не должно слать новую cookie (затёрла бы «Запомнить меня»).
// Заголовки в CLI не видны (headers_list() пуст), поэтому проверка по коду;
// живьём — на стенде: у delete.php нет Set-Cookie.
$sm = file_get_contents(__DIR__ . '/../includes/SessionManager.php');
$fin = substr($sm, strpos($sm, 'public static function finishReleasedRequest'), 2500);
$check(strpos($fin, "ini_set('session.use_cookies', '0')") !== false,
    'finishReleasedRequest() открывает сессию без отправки cookie');
$check(strpos($fin, 'self::start()') === false,
    'finishReleasedRequest() не зовёт start(): тот выставляет cookie-параметры и шлёт cookie заново');

// 3. json_response сам закрывает отпущенную сессию
$utils = file_get_contents(__DIR__ . '/../includes/Utils.php');
$body = substr($utils, strpos($utils, 'function json_response'), 900);
$check(strpos($body, 'finishReleasedRequest') !== false, 'json_response() вызывает SessionManager::finishReleasedRequest()');

// 4. Долгие точки входа отпускают сессию после проверки входа и CSRF-снимка
$endpoints = [
    'delete.php'           => true,  // 1000 строк до 57 с на проде
    'mass_transfer.php'    => true,  // до 333 с
    'status_update.php'    => true,  // 45 231 строка — 74 с
    'bulk_update_field.php'=> true,
    'undo.php'             => true,
    'import_accounts.php'  => true,  // до 8 с
    'restore.php'          => true,
    'delete_permanent.php' => true,
    'empty_trash.php'      => true,
    'purge_old.php'        => true,  // фоновая автоочистка корзины
    'export.php'           => false, // только чтение
    'export_chunk.php'     => false,
];
foreach ($endpoints as $name => $writes) {
    $src = file_get_contents(__DIR__ . '/../' . $name);
    $auth = strpos($src, 'requireAuth()');
    $call = $writes ? 'SessionManager::releaseLock()' : 'SessionManager::releaseLock(false)';
    $pos = strpos($src, $call);
    $check($pos !== false, "$name отпускает сессию ($call)");
    if ($pos !== false && $auth !== false) {
        $check($pos > $auth, "$name отпускает сессию ПОСЛЕ requireAuth()");
        $timeout = strpos($src, 'checkSessionTimeout()');
        if ($timeout !== false) {
            $check($pos > $timeout, "$name отпускает сессию ПОСЛЕ checkSessionTimeout() (иначе не сохранится время активности)");
        }
    }
}

ob_end_flush();
foreach ($failures as $f) {
    echo "FAIL: $f\n";
}
echo $failures ? count($failures) . " failures\n" : "PASS: долгие операции отпускают сессию\n";
exit($failures ? 1 : 0);

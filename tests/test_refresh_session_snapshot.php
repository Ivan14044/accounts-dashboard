<?php
/** Read-only requests may unlock the session without losing their write marker. */
require_once __DIR__ . '/../includes/Config.php';
require_once __DIR__ . '/../includes/StatisticsService.php';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$sessionDir = sys_get_temp_dir() . '/dashboard-session-test-' . bin2hex(random_bytes(8));
mkdir($sessionDir, 0700);
session_save_path($sessionDir);
session_id('dashboard-refresh-snapshot');
session_start();
$marker = Config::STATS_SELF_WRITE_FLAG;
$readMarker = new ReflectionMethod(StatisticsService::class, 'viewerJustWrote');
$readMarker->setAccessible(true);
$failures = [];
$check = function ($condition, $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};

try {
    $_SESSION[$marker] = time() + 60;
    $_SESSION['last_activity'] = time();
    $activity = $_SESSION['last_activity'];
    $check($readMarker->invoke(null), 'active session retains immediate statistics freshness');
    session_write_close();
    $check(session_status() === PHP_SESSION_NONE, 'session lock is released');
    $check($readMarker->invoke(null), 'closed session snapshot retains immediate statistics freshness');

    session_start();
    $check($_SESSION['last_activity'] === $activity, 'activity was persisted before releasing the lock');
    $check($_SESSION[$marker] > time(), 'write marker was persisted');
    $_SESSION[$marker] = time() - 60;
    session_write_close();
    $check(!$readMarker->invoke(null), 'expired closed-session marker uses shared statistics cache');
    unset($_SESSION[$marker]);
    $check(!$readMarker->invoke(null), 'missing marker uses shared statistics cache');

    session_start();
    session_destroy();
    $_SESSION = [];
    $check(!$readMarker->invoke(null), 'destroyed authentication session has no freshness override');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    foreach (glob($sessionDir . '/*') as $file) unlink($file);
    rmdir($sessionDir);
}

foreach ($failures as $failure) echo "FAIL: $failure\n";
echo $failures ? count($failures) . " failures\n" : "PASS: session snapshot freshness and persistence\n";
exit($failures ? 1 : 0);

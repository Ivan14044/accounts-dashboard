<?php
/**
 * Диагностика импорта CSV — открой в браузере: /diag_import.php
 * УДАЛИТЬ ПОСЛЕ ОТЛАДКИ!
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== IMPORT DIAGNOSTICS ===\n\n";

// 1. PHP version
echo "1. PHP Version: " . PHP_VERSION . "\n";
echo "   PHP_VERSION_ID: " . PHP_VERSION_ID . "\n";
echo "   empty escape support (>=70400): " . (PHP_VERSION_ID >= 70400 ? 'YES' : 'NO') . "\n\n";

// 2. Check critical files exist
$files = [
    'config.php',
    'includes/TableResolver.php',
    'includes/CsvParser.php',
    'includes/AccountValidationService.php',
    'includes/AccountsService.php',
    'includes/AccountsRepository.php',
    'import_accounts.php',
    'export.php',
];

echo "2. Critical files:\n";
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $exists = file_exists($path);
    $size = $exists ? filesize($path) : 0;
    $mtime = $exists ? date('Y-m-d H:i:s', filemtime($path)) : 'N/A';
    echo "   " . ($exists ? '✓' : '✗ MISSING') . " $f ($size bytes, $mtime)\n";
}

// 3. Check CsvParser has readCsvRow method
echo "\n3. CsvParser check:\n";
require_once __DIR__ . '/includes/CsvParser.php';
$ref = new ReflectionClass('CsvParser');
$methods = array_map(function($m) { return $m->getName(); }, $ref->getMethods());
echo "   Methods: " . implode(', ', $methods) . "\n";
echo "   Has readCsvRow: " . (in_array('readCsvRow', $methods) ? 'YES (new version)' : 'NO (old version!)') . "\n";

// 4. Check if fgetcsv with empty escape works
echo "\n4. fgetcsv empty escape test:\n";
$tmpFile = tempnam(sys_get_temp_dir(), 'csv_diag_');
file_put_contents($tmpFile, "a;b\n\"hello \\\"world\\\"\";test\n");
$h = fopen($tmpFile, 'r');

// Try empty escape
$result = @fgetcsv($h, 0, ';', '"', '');
$warning = error_get_last();
echo "   fgetcsv with escape='': " . ($result === false ? 'FALSE' : 'OK (' . count($result) . ' fields)') . "\n";
if ($result !== false) {
    echo "   Values: " . json_encode($result) . "\n";
}

fclose($h);
unlink($tmpFile);

// 5. Check config.php loads without fatal
echo "\n5. Config check:\n";
try {
    // Check if TableResolver is loadable
    if (file_exists(__DIR__ . '/includes/TableResolver.php')) {
        echo "   TableResolver.php exists\n";
        $trRef = new ReflectionClass('TableResolver');
        echo "   TableResolver class loaded OK\n";
    } else {
        echo "   ✗ TableResolver.php MISSING — config.php will FATAL ERROR!\n";
    }
} catch (Throwable $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 6. Upload settings
echo "\n6. Upload settings:\n";
echo "   upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "   post_max_size: " . ini_get('post_max_size') . "\n";
echo "   max_file_uploads: " . ini_get('max_file_uploads') . "\n";
echo "   max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "   memory_limit: " . ini_get('memory_limit') . "\n";
echo "   sys_temp_dir: " . sys_get_temp_dir() . "\n";
echo "   is_writable(tmp): " . (is_writable(sys_get_temp_dir()) ? 'YES' : 'NO') . "\n";

// 7. Error log tail
echo "\n7. Recent error log:\n";
$logFile = __DIR__ . '/php_errors.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $last = array_slice($lines, -30);
    foreach ($last as $line) {
        echo "   " . rtrim($line) . "\n";
    }
} else {
    echo "   php_errors.log not found\n";
}

// 8. App log
echo "\n8. Recent app log (Logger):\n";
$appLogs = glob(__DIR__ . '/logs/*.log');
if (empty($appLogs)) {
    $appLogs = glob(__DIR__ . '/*.log');
}
foreach ($appLogs as $logPath) {
    if (basename($logPath) === 'php_errors.log') continue;
    $lines = file($logPath);
    $last = array_slice($lines, -20);
    echo "   --- " . basename($logPath) . " ---\n";
    foreach ($last as $line) {
        echo "   " . rtrim($line) . "\n";
    }
}

echo "\n=== END ===\n";

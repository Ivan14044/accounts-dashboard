<?php
// Проверяем авторизацию
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
requireAuth();

// Cleanup: remove orphaned old CSS lines from dashboard.php
$file = __DIR__ . '/templates/dashboard.php';
$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (!$lines) {
    die("Error reading file");
}

// Read all lines including empty ones
$allLines = file($file, FILE_IGNORE_NEW_LINES);
$total = count($allLines);

// Keep lines 0-285 (= first 286 lines) and lines 2243+ (= from line 2244 onward, 1-indexed)
$before = array_slice($allLines, 0, 286);   // Lines 1-286
$after  = array_slice($allLines, 2243);      // Lines 2244+

$result = array_merge($before, $after);

file_put_contents($file, implode("\n", $result) . "\n");

echo "SUCCESS: Removed " . ($total - count($result)) . " orphaned lines.\n";
echo "File had " . $total . " lines, now has " . count($result) . " lines.\n";

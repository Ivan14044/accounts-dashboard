<?php
/**
 * Тест: анализ повреждения cookies при CSV импорте
 *
 * Запуск: php tests/test_csv_cookies.php <path_to_csv>
 */

$csvFile = $argv[1] ?? 'C:/Users/Knysh/OneDrive/Desktop/account_template_2026-04-04.csv';

if (!file_exists($csvFile)) {
    echo "File not found: $csvFile\n";
    exit(1);
}

echo "=== COOKIE CSV ANALYSIS ===\n";
echo "File: $csvFile\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Supports empty escape: " . (PHP_VERSION_ID >= 70400 ? 'YES' : 'NO') . "\n\n";

// Find cookies column
$handle = fopen($csvFile, 'r');
$rawHeaders = fgetcsv($handle, 0, ';');
$cookieIdx = null;
foreach ($rawHeaders as $i => $h) {
    $clean = strtolower(trim(str_replace(['*', "\xEF\xBB\xBF"], '', $h)));
    if ($clean === 'cookies') { $cookieIdx = $i; break; }
}
fclose($handle);

if ($cookieIdx === null) {
    echo "ERROR: 'cookies' column not found!\n";
    exit(1);
}

echo "Cookies column index: $cookieIdx\n\n";

// ============================================================
// TEST 1: Compare default escape vs empty escape parsing
// ============================================================
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║ TEST 1: fgetcsv DEFAULT escape vs escape=''         ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// Parse with default escape
$handle = fopen($csvFile, 'r');
fgetcsv($handle, 0, ';'); // skip headers
$defaultRows = [];
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $defaultRows[] = $row[$cookieIdx] ?? '';
}
fclose($handle);

// Parse with empty escape
$handle = fopen($csvFile, 'r');
fgetcsv($handle, 0, ';', '"', ''); // skip headers
$emptyEscRows = [];
while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
    $emptyEscRows[] = $row[$cookieIdx] ?? '';
}
fclose($handle);

echo sprintf("%-5s %-10s %-12s %-12s %-8s %-8s %s\n",
    'Row', 'alsfid?', 'Default len', 'Empty len', 'Def.JSON', 'Emp.JSON', 'Difference');
echo str_repeat('-', 90) . "\n";

$problems = [];

for ($i = 0; $i < count($defaultRows); $i++) {
    $def = $defaultRows[$i];
    $emp = $emptyEscRows[$i];

    $hasAlsfid = strpos($def, 'alsfid') !== false || strpos($emp, 'alsfid') !== false;
    $defJson = json_decode($def, true) !== null;
    $empJson = json_decode($emp, true) !== null;
    $same = ($def === $emp);

    $diff = $same ? 'SAME' : 'DIFFERENT';
    if (!$same) {
        $diff = 'DIFFERENT <<<';
        $problems[] = $i + 1;
    }

    echo sprintf("%-5d %-10s %-12d %-12d %-8s %-8s %s\n",
        $i + 1,
        $hasAlsfid ? 'YES' : 'no',
        strlen($def),
        strlen($emp),
        $defJson ? 'OK' : 'FAIL',
        $empJson ? 'OK' : 'FAIL',
        $diff
    );
}

echo "\n";
if (empty($problems)) {
    echo "✓ All rows parsed identically with both escape modes.\n";
} else {
    echo "✗ Rows with DIFFERENT parsing: " . implode(', ', $problems) . "\n";
}

// ============================================================
// TEST 2: Show exact corruption in problematic rows
// ============================================================
if (!empty($problems)) {
    echo "\n╔══════════════════════════════════════════════════════╗\n";
    echo "║ TEST 2: Exact corruption details                    ║\n";
    echo "╚══════════════════════════════════════════════════════╝\n\n";

    foreach ($problems as $rowNum) {
        $idx = $rowNum - 1;
        $def = $defaultRows[$idx];
        $emp = $emptyEscRows[$idx];

        echo "--- Row $rowNum ---\n";

        // Find first difference
        $minLen = min(strlen($def), strlen($emp));
        $firstDiff = -1;
        for ($j = 0; $j < $minLen; $j++) {
            if ($def[$j] !== $emp[$j]) {
                $firstDiff = $j;
                break;
            }
        }

        if ($firstDiff >= 0) {
            $start = max(0, $firstDiff - 30);
            echo "First difference at byte $firstDiff:\n";
            echo "  DEFAULT : ..." . substr($def, $start, 80) . "...\n";
            echo "  EMPTY   : ..." . substr($emp, $start, 80) . "...\n";
            echo "  DEFAULT hex: ";
            for ($j = $firstDiff; $j < min($firstDiff + 20, strlen($def)); $j++) {
                echo sprintf('%02X ', ord($def[$j]));
            }
            echo "\n";
            echo "  EMPTY   hex: ";
            for ($j = $firstDiff; $j < min($firstDiff + 20, strlen($emp)); $j++) {
                echo sprintf('%02X ', ord($emp[$j]));
            }
            echo "\n";
        }

        // Show alsfid area specifically
        $alsfidPos = strpos($emp, 'alsfid');
        if ($alsfidPos !== false) {
            echo "\n  alsfid area (escape=''):\n";
            echo "  " . substr($emp, $alsfidPos, 100) . "\n";
        }

        $alsfidPos = strpos($def, 'alsfid');
        if ($alsfidPos !== false) {
            echo "  alsfid area (default):\n";
            echo "  " . substr($def, $alsfidPos, 100) . "\n";
        }
        echo "\n";
    }
}

// ============================================================
// TEST 3: Round-trip test (export → import)
// ============================================================
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║ TEST 3: Round-trip fputcsv → fgetcsv                ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// Simulate cookie values as they would exist in DB
$testValues = [
    'Simple JSON (no backslashes)' => '[{"name":"c_user","value":"123"},{"name":"xs","value":"abc:def"}]',
    'JSON with backslash-quotes (alsfid)' => '[{"name":"alsfid","value":"{\"id\":\"f2a5cd5ab\",\"timestamp\":1774209718197.7}"}]',
    'Mixed cookies (real-world)' => '[{"name":"c_user","value":"123"},{"name":"alsfid","value":"{\"id\":\"abc\",\"ts\":1.7}"}]',
];

foreach ($testValues as $label => $original) {
    echo "--- $label ---\n";
    echo "Original:        $original\n";
    echo "Original length: " . strlen($original) . "\n\n";

    // Round-trip with DEFAULT escape
    $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
    $out = fopen($tmpFile, 'w');
    fputcsv($out, ['cookies'], ';');
    fputcsv($out, [$original], ';');
    fclose($out);

    $rawCsv = file_get_contents($tmpFile);

    $in = fopen($tmpFile, 'r');
    fgetcsv($in, 0, ';'); // skip header
    $row = fgetcsv($in, 0, ';');
    $roundtrip_default = $row[0] ?? '';
    fclose($in);
    unlink($tmpFile);

    // Round-trip with EMPTY escape
    $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
    $out = fopen($tmpFile, 'w');
    fputcsv($out, ['cookies'], ';', '"', '');
    fputcsv($out, [$original], ';', '"', '');
    fclose($out);

    $rawCsvEmpty = file_get_contents($tmpFile);

    $in = fopen($tmpFile, 'r');
    fgetcsv($in, 0, ';', '"', ''); // skip header
    $row = fgetcsv($in, 0, ';', '"', '');
    $roundtrip_empty = $row[0] ?? '';
    fclose($in);
    unlink($tmpFile);

    $defaultOk = ($roundtrip_default === $original);
    $emptyOk = ($roundtrip_empty === $original);

    echo "  DEFAULT escape:\n";
    echo "    Raw CSV: " . trim($rawCsv) . "\n";
    echo "    Result:  $roundtrip_default\n";
    echo "    Match:   " . ($defaultOk ? '✓ PASS' : '✗ FAIL — DATA CORRUPTED') . "\n";
    if (!$defaultOk) {
        echo "    Diff:    original=" . strlen($original) . " result=" . strlen($roundtrip_default) . "\n";
    }

    echo "  EMPTY escape:\n";
    echo "    Raw CSV: " . trim($rawCsvEmpty) . "\n";
    echo "    Result:  $roundtrip_empty\n";
    echo "    Match:   " . ($emptyOk ? '✓ PASS' : '✗ FAIL — DATA CORRUPTED') . "\n";
    echo "\n";
}

// ============================================================
// TEST 4: Multiple round-trips (degradation test)
// ============================================================
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║ TEST 4: Multiple round-trips (data degradation)     ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

$original = '[{"name":"alsfid","value":"{\"id\":\"f2a5cd5ab\",\"timestamp\":1774209718197.7}"}]';

echo "Original: $original\n\n";

// DEFAULT escape — multiple passes
$current = $original;
echo "DEFAULT escape — 5 export→import cycles:\n";
for ($pass = 1; $pass <= 5; $pass++) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'csv_pass_');
    $out = fopen($tmpFile, 'w');
    fputcsv($out, [$current], ';');
    fclose($out);

    $in = fopen($tmpFile, 'r');
    $row = fgetcsv($in, 0, ';');
    $current = $row[0] ?? '';
    fclose($in);
    unlink($tmpFile);

    $match = ($current === $original);
    echo "  Pass $pass: len=" . str_pad(strlen($current), 4) . " " .
         ($match ? '✓ OK' : '✗ CHANGED') . " → " . substr($current, 0, 100) . "\n";
}

echo "\n";

// EMPTY escape — multiple passes
$current = $original;
echo "EMPTY escape — 5 export→import cycles:\n";
for ($pass = 1; $pass <= 5; $pass++) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'csv_pass_');
    $out = fopen($tmpFile, 'w');
    fputcsv($out, [$current], ';', '"', '');
    fclose($out);

    $in = fopen($tmpFile, 'r');
    $row = fgetcsv($in, 0, ';', '"', '');
    $current = $row[0] ?? '';
    fclose($in);
    unlink($tmpFile);

    $match = ($current === $original);
    echo "  Pass $pass: len=" . str_pad(strlen($current), 4) . " " .
         ($match ? '✓ OK' : '✗ CHANGED') . " → " . substr($current, 0, 100) . "\n";
}

// ============================================================
// TEST 5: Cross-mode compatibility
// ============================================================
echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║ TEST 5: Cross-mode compatibility                    ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

$original = '[{"name":"alsfid","value":"{\"id\":\"f2a5cd5ab\",\"timestamp\":1774209718197.7}"}]';

// Write with DEFAULT, read with EMPTY
$tmpFile = tempnam(sys_get_temp_dir(), 'csv_cross_');
$out = fopen($tmpFile, 'w');
fputcsv($out, [$original], ';');  // DEFAULT write
fclose($out);
$raw = file_get_contents($tmpFile);

$in = fopen($tmpFile, 'r');
$row = fgetcsv($in, 0, ';', '"', '');  // EMPTY read
$result = $row[0] ?? '';
fclose($in);
unlink($tmpFile);

echo "Write DEFAULT → Read EMPTY:\n";
echo "  Raw CSV:  " . trim($raw) . "\n";
echo "  Result:   $result\n";
echo "  Match:    " . ($result === $original ? '✓ PASS' : '✗ FAIL') . "\n\n";

// Write with EMPTY, read with DEFAULT
$tmpFile = tempnam(sys_get_temp_dir(), 'csv_cross_');
$out = fopen($tmpFile, 'w');
fputcsv($out, [$original], ';', '"', '');  // EMPTY write
fclose($out);
$raw = file_get_contents($tmpFile);

$in = fopen($tmpFile, 'r');
$row = fgetcsv($in, 0, ';');  // DEFAULT read
$result = $row[0] ?? '';
fclose($in);
unlink($tmpFile);

echo "Write EMPTY → Read DEFAULT:\n";
echo "  Raw CSV:  " . trim($raw) . "\n";
echo "  Result:   $result\n";
echo "  Match:    " . ($result === $original ? '✓ PASS' : '✗ FAIL') . "\n\n";

// ============================================================
// TEST 6: Actual file import comparison
// ============================================================
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║ TEST 6: Parse actual CSV — what gets stored in DB   ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// Parse with empty escape (new code)
$handle = fopen($csvFile, 'r');
fgetcsv($handle, 0, ';', '"', '');
$row1 = fgetcsv($handle, 0, ';', '"', '');
$cookiesNew = $row1[$cookieIdx] ?? '';
fclose($handle);

echo "Row 1 cookies (escape='', NEW CODE):\n";
echo "  Length: " . strlen($cookiesNew) . "\n";

// Find alsfid
$alsfidPos = strpos($cookiesNew, 'alsfid');
if ($alsfidPos !== false) {
    $area = substr($cookiesNew, $alsfidPos, 120);
    echo "  alsfid area: $area\n";
}

$decoded = json_decode($cookiesNew, true);
echo "  Valid JSON: " . ($decoded !== null ? 'YES' : 'NO — error: ' . json_last_error_msg()) . "\n";

if ($decoded !== null) {
    // Find alsfid in decoded
    foreach ($decoded as $cookie) {
        if ($cookie['name'] === 'alsfid') {
            echo "  alsfid.value: " . $cookie['value'] . "\n";
            $innerJson = json_decode($cookie['value'], true);
            echo "  alsfid inner JSON valid: " . ($innerJson !== null ? 'YES' : 'NO') . "\n";
            if ($innerJson !== null) {
                echo "  alsfid.id: " . $innerJson['id'] . "\n";
                echo "  alsfid.timestamp: " . $innerJson['timestamp'] . "\n";
            }
        }
    }
}

echo "\n=== SUMMARY ===\n";
echo "The root cause: PHP's fgetcsv/fputcsv with default escape='\\' treats \n";
echo "backslash as an escape character. When cookie JSON contains \\\" (backslash-quote),\n";
echo "PHP interprets \\ as escaping the \", causing data corruption.\n\n";
echo "Solution: Use escape='' (PHP 7.4+) in both fgetcsv and fputcsv.\n";
echo "This disables PHP's proprietary escape and uses RFC 4180 standard\n";
echo "(quote doubling \"\" only).\n";

<?php
$files = [
    'd:\project\dashboard\templates\dashboard.php',
    'd:\project\dashboard\templates\favorites.php',
    'd:\project\dashboard\templates\trash.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    $lines = explode("\n", $content);
    $outLines = [];
    $cssAdded = false;
    
    foreach ($lines as $line) {
        if (strpos($line, 'assets/css/') !== false) {
            if (!$cssAdded) {
                $outLines[] = '  <!-- CSS Bundles -->';
                $outLines[] = '  <link href="assets/css/core-base.css?v=<?= time() ?>" rel="stylesheet">';
                $outLines[] = '  <link href="assets/css/core-components.css?v=<?= time() ?>" rel="stylesheet">';
                $outLines[] = '  <link href="assets/css/core-plugins.css?v=<?= time() ?>" rel="stylesheet">';
                $outLines[] = '  <link href="assets/css/core-theme.css?v=<?= time() ?>" rel="stylesheet">';
                $outLines[] = '  <link href="assets/css/core-tables.css?v=<?= time() ?>" rel="stylesheet">';
                $cssAdded = true;
            }
            continue;
        }
        if (trim($line) === '<!-- CSS Bundles -->') {
            continue;
        }
        if (trim($line) === '<!-- Единая дизайн-система (в правильном порядке) -->' || 
            trim($line) === '<!-- Единая тема для всех элементов -->' || trim($line) === '<!-- Новая таблица -->') {
            continue;
        }
        $outLines[] = $line;
    }
    
    file_put_contents($file, implode("\n", $outLines));
}
echo "Cleaned up properly!\n";

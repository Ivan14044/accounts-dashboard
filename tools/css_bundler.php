<?php
$cssDir = __DIR__ . '/assets/css/';

$bundles = [
    'core-base.css' => [
        'minimal-design-system.css',
        'minimal-components.css',
        'minimal-layout.css',
        'minimal-overrides.css',
        'minimal-performance.css'
    ],
    'core-components.css' => [
        'design-system.css',
        'components-unified.css'
    ],
    'core-plugins.css' => [
        'filters-modern.css',
        'toast.css',
        'csv-preview.css',
        'modern-header.css',
        'sticky-scrollbar.css'
    ],
    'core-tables.css' => [
        'table-core.css',
        'table-theme.css'
    ],
    'core-theme.css' => [
        'unified-theme.css'
    ]
];

// Step 1: Create bundles
foreach ($bundles as $bundleName => $files) {
    $content = "/* Bundle: $bundleName */\n\n";
    foreach ($files as $file) {
        $filePath = $cssDir . $file;
        if (file_exists($filePath)) {
            $content .= "/* --- Source: $file --- */\n";
            $content .= file_get_contents($filePath) . "\n\n";
        }
    }
    file_put_contents($cssDir . $bundleName, $content);
    echo "Created bundle: $bundleName\n";
}

// Step 2: Delete old files
foreach ($bundles as $bundleName => $files) {
    foreach ($files as $file) {
        $filePath = $cssDir . $file;
        if (file_exists($filePath)) {
            unlink($filePath);
            echo "Deleted: $file\n";
        }
    }
}

// Step 3: Delete unused old CSS
$unused = [
    'dashboard-critical.css',
    'dashboard-inline.css',
    'dashboard-non-critical.css',
    'dashboard-page.css',
    'filter-dropdowns.css',
    'table-layout.css'
];

foreach ($unused as $file) {
    $filePath = $cssDir . $file;
    if (file_exists($filePath)) {
        unlink($filePath);
        echo "Deleted unused: $file\n";
    }
}

echo "\nDone!\n";

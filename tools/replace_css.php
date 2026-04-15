<?php
$files = [
    'd:\project\dashboard\templates\dashboard.php',
    'd:\project\dashboard\templates\favorites.php',
    'd:\project\dashboard\templates\trash.php',
    'd:\project\dashboard\view.php',
    'd:\project\dashboard\log.php',
    'd:\project\dashboard\history.php',
    'd:\project\dashboard\build_assets.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // For view/log/history: replace unified-theme with core-theme
    if (strpos($file, 'view.php') !== false || strpos($file, 'log.php') !== false || strpos($file, 'history.php') !== false) {
        $content = str_replace('assets/css/unified-theme.css', 'assets/css/core-theme.css', $content);
        file_put_contents($file, $content);
        continue;
    }
    
    // For build_assets.php
    if (strpos($file, 'build_assets.php') !== false) {
        $oldCssArray = "/\\\$cssFiles = \\[[^\]]+\\];/s";
        $newCssArray = "\$cssFiles = [\n    'assets/css/core-base.css',\n    'assets/css/core-components.css',\n    'assets/css/core-plugins.css',\n    'assets/css/core-theme.css',\n    'assets/css/core-tables.css',\n];";
        $content = preg_replace($oldCssArray, $newCssArray, $content);
        file_put_contents($file, $content);
        continue;
    }
    
    // For dashboard/favorites/trash: Replace block of CSS
    if (preg_match('/(?:[ \t]*<link href="assets\/css\/.*\.css\?.*" rel="stylesheet">\r?\n)+/', $content, $matches)) {
        $newBlock = "  <link href=\"assets/css/core-base.css?v=<?= time() ?>\" rel=\"stylesheet\">\n  <link href=\"assets/css/core-components.css?v=<?= time() ?>\" rel=\"stylesheet\">\n  <link href=\"assets/css/core-plugins.css?v=<?= time() ?>\" rel=\"stylesheet\">\n  <link href=\"assets/css/core-theme.css?v=<?= time() ?>\" rel=\"stylesheet\">\n  <link href=\"assets/css/core-tables.css?v=<?= time() ?>\" rel=\"stylesheet\">\n";
        
        $content = str_replace($matches[0], $newBlock, $content);
        file_put_contents($file, $content);
    }
}
echo "Done replacing CSS references!\n";

<?php
$inputFile = __DIR__ . '/templates/partials/dashboard/init-script.php';
$outputJs = __DIR__ . '/assets/js/dashboard-init.js';
$newInitPhp = __DIR__ . '/templates/partials/dashboard/init-script.php';

$content = file_get_contents($inputFile);

// Strip <script> tags at start and end
$content = preg_replace('/^\s*<script>\s*/', '', $content);
$content = preg_replace('/\s*<\/script>\s*$/', '', $content);

// Replace PHP tags with window.DashboardConfig variables
$content = str_replace('<?= (int)$activeFiltersCount ?>', 'window.DashboardConfig.activeFiltersCount', $content);
$content = str_replace('<?= json_encode((string)($csrfToken ?? \'\'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>', 'window.DashboardConfig.csrfToken', $content);
$content = str_replace('<?= (int)($filteredTotal ?? 0) ?>', 'window.DashboardConfig.filteredTotal', $content);
$content = str_replace('<?= e($csrfToken) ?>', 'window.DashboardConfig.csrfToken', $content);
$content = str_replace('<?= json_encode((string)($sort ?? \'id\'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>', 'window.DashboardConfig.currentSort', $content);
$content = str_replace('<?= json_encode((string)($dir ?? \'ASC\'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>', 'window.DashboardConfig.currentDir', $content);
$content = str_replace('<?= json_encode(array_keys($ALL_COLUMNS)) ?>', 'window.DashboardConfig.allColKeys', $content);

file_put_contents($outputJs, $content);

$newPhpContent = "<script>\n" .
"// Глобальный объект для конфигурации Dashboard\n" .
"window.DashboardConfig = Object.assign(window.DashboardConfig || {}, {\n" .
"    activeFiltersCount: <?= (int)(\$activeFiltersCount ?? 0) ?>,\n" .
"    csrfToken: <?= json_encode((string)(\$csrfToken ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,\n" .
"    filteredTotal: <?= (int)(\$filteredTotal ?? 0) ?>,\n" .
"    currentSort: <?= json_encode((string)(\$sort ?? 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,\n" .
"    currentDir: <?= json_encode((string)(\$dir ?? 'ASC'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,\n" .
"    allColKeys: <?= json_encode(array_keys(\$ALL_COLUMNS ?? [])) ?>\n" .
"});\n" .
"</script>\n" .
"<script src=\"assets/js/dashboard-init.js\"></script>\n";

file_put_contents($newInitPhp, $newPhpContent);
echo "Extraction completed safely in UTF-8.\n";

<script>
// Глобальный объект для конфигурации Dashboard
window.DashboardConfig = Object.assign(window.DashboardConfig || {}, {
    activeFiltersCount: <?= (int)($activeFiltersCount ?? 0) ?>,
    csrfToken: <?= json_encode((string)($csrfToken ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    filteredTotal: <?= (int)($filteredTotal ?? 0) ?>,
    currentSort: <?= json_encode((string)($sort ?? 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    currentDir: <?= json_encode((string)($dir ?? 'ASC'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    allColKeys: <?= json_encode(array_keys($ALL_COLUMNS ?? [])) ?>,
    currentTable: <?= json_encode((string)($currentTable ?? 'accounts'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
});
</script>
<script src="assets/js/dashboard-init.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>

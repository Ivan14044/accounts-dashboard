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
<!-- Раньше здесь же подключались constants.js, custom-cards.js, inline-edit.js,
     columns-cards-settings.js, auto-refresh.js, touch-gestures.js и dashboard-init.js.
     Теперь весь внешний JS дашборда объявлен одним списком в includes/AssetBundles.php
     и подключается из templates/dashboard.php сразу после этого инлайна — порядок
     выполнения тот же, а список перестал жить в двух шаблонах сразу.
     Файл остался инлайн-блоком с конфигом: его значения приходят из PHP. -->

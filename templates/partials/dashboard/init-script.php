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
<!-- Ключи localStorage и константы конфига. Первым: их читают все остальные. -->
<script src="assets/js/modules/constants.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<!-- Кастомные карточки статистики. Строго ДО dashboard-init.js: тот вызывает
     initializeCustomCards() и другие функции модуля по имени через window. -->
<script src="assets/js/modules/custom-cards.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/inline-edit.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/columns-cards-settings.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/auto-refresh.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/touch-gestures.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/dashboard-init.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>

<?php
/**
 * Конфигурация дашборда для JavaScript.
 * Должен быть включён до dashboard-init.js и других модулей.
 */
$cfg = [
  'csrfToken' => $csrfToken ?? '',
  'activeFiltersCount' => (int)($activeFiltersCount ?? 0),
  'sort' => $sort ?? '',
  'dir' => $dir ?? '',
  'allColumnKeys' => isset($ALL_COLUMNS) && is_array($ALL_COLUMNS) ? array_keys($ALL_COLUMNS) : [],
  'filteredTotal' => (int)($filteredTotal ?? 0),
  'currentTable' => $currentTable ?? 'accounts',
];
?>
<script>
window.__DASHBOARD_CONFIG__ = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
</script>

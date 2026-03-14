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
];
?>
<script>
window.__DASHBOARD_CONFIG__ = <?= json_encode($cfg) ?>;
</script>

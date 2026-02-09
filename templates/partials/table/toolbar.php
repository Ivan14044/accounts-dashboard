<?php
/**
 * Toolbar для таблицы аккаунтов
 */
?>
<div class="dashboard-table__toolbar" id="rowsCounterBar">
  <div class="dashboard-table__toolbar-main">
    <div class="dashboard-table__counter" aria-live="polite">
      <span class="text-muted">Показано:</span>
      <span class="dashboard-table__counter-value" id="showingCountTop"><?= count($rows) ?></span>
      <span class="text-muted">из</span>
      <span class="dashboard-table__counter-total" id="foundTotalTop"><?= (int)$filteredTotal ?></span>
    </div>
    <div class="dashboard-table__counter" aria-live="polite">
      <span class="text-muted">•</span>
      <span class="text-muted">Отмечено:</span>
      <span class="dashboard-table__counter-value" id="selectedOnPageCount">0</span>
      <span class="text-muted">из</span>
      <span class="dashboard-table__counter-total" id="showingOnPageTop"><?= count($rows) ?></span>
    </div>
  </div>
</div>

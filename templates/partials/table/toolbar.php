<?php
/**
 * Toolbar для таблицы аккаунтов
 * Включает счётчики и уведомление о выборе строк
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
  
  <!-- Уведомление о выборе всех строк (интегрировано в toolbar) -->
  <div class="dashboard-table__selection-notice" id="selectAllNotice" style="display: none;">
    <i class="fas fa-info-circle"></i>
    <span class="dashboard-table__selection-text"></span>
  </div>
</div>

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

  <!-- Density toggle (3 положения: comfortable / cozy / compact) -->
  <div class="density-toggle" role="group" aria-label="Плотность строк">
    <button type="button" class="density-toggle__btn" data-density="comfortable" aria-pressed="true" title="Просторно">
      <i data-lucide="rows-3" aria-hidden="true"></i>
      <span class="sr-only">Просторно</span>
    </button>
    <button type="button" class="density-toggle__btn" data-density="cozy" aria-pressed="false" title="Средне">
      <i data-lucide="menu" aria-hidden="true"></i>
      <span class="sr-only">Средне</span>
    </button>
    <button type="button" class="density-toggle__btn" data-density="compact" aria-pressed="false" title="Компактно">
      <i data-lucide="align-justify" aria-hidden="true"></i>
      <span class="sr-only">Компактно</span>
    </button>
  </div>

  <!-- Уведомление о выборе всех строк (интегрировано в toolbar) -->
  <div class="dashboard-table__selection-notice d-none" id="selectAllNotice">
    <i class="fas fa-info-circle"></i>
    <span class="dashboard-table__selection-text"></span>
  </div>
</div>

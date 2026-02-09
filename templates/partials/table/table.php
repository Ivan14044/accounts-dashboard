<?php
/**
 * Основной контейнер таблицы
 */
?>
<section class="dashboard-table card" id="accountsTableSection" data-module="accounts-table">
  <div class="dashboard-table__inner card-body p-0">
    <?php include __DIR__ . '/toolbar.php'; ?>
    <?php include __DIR__ . '/header.php'; ?>
    <div class="dashboard-table__body" role="region" aria-label="Список аккаунтов">
      <div class="dashboard-table__scroll" id="tableWrap">
        <table class="ac-table table-hover" id="accountsTable" role="grid" aria-rowcount="<?= (int)$filteredTotal ?>">
          <?php include __DIR__ . '/columns.php'; ?>
          <?php include __DIR__ . '/rows.php'; ?>
        </table>
      </div>
      <div class="dashboard-table__loader" id="tableLoading" role="status" aria-live="polite">
        <div class="text-center">
          <span class="loader loader-primary mb-2"></span>
          <div class="loading-text">Загрузка данных...</div>
        </div>
      </div>
    </div>
    <?php include __DIR__ . '/footer.php'; ?>
  </div>
</section>

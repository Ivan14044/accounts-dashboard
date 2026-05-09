<!-- Панель инструментов — один ряд -->
<div class="toolbar">
  <h2 class="toolbar-title"><?= e(ucfirst($currentTable ?? 'accounts')) ?></h2>

  <!-- Bulk-actions: ghost-кнопки. Они активны только при выделении строк → CTA не конкурирует с ними. -->
  <div class="toolbar-actions__bulk">
    <button class="btn btn-sm btn-ghost" id="exportSelectedCsv" disabled>
      <i class="fas fa-file-csv"></i> CSV
    </button>
    <button class="btn btn-sm btn-ghost" id="exportSelectedTxt" disabled>
      <i class="fas fa-file-alt"></i> TXT
    </button>
    <button class="btn btn-sm btn-ghost btn-ghost--danger" id="deleteSelected" disabled>
      <i class="fas fa-trash"></i> Удалить
    </button>
    <button class="btn btn-sm btn-ghost" id="changeStatusSelected" disabled>
      <i class="fas fa-tag"></i> Статус
    </button>
    <button class="btn btn-sm btn-ghost" id="bulkEditFieldBtn" disabled>
      <i class="fas fa-edit"></i> Поле
    </button>
  </div>

  <!-- Main-actions: только ОДНА filled CTA на странице ("Добавить аккаунт"). Остальные — ghost. -->
  <div class="toolbar-actions__main">
    <button class="btn btn-sm btn-primary btn-cta" id="addAccountBtn" data-bs-toggle="modal" data-bs-target="#addAccountModal">
      <i class="fas fa-plus"></i> Добавить аккаунт
    </button>
    <button class="btn btn-sm btn-ghost btn-ghost--primary" id="validateAccountsBtn" disabled title="Проверка аккаунтов на валидность (NPPR Services)">
      <i class="fas fa-check-double"></i> Проверка
    </button>
    <button class="btn btn-sm btn-ghost" id="transferAccountsBtn">
      <i class="fas fa-exchange-alt"></i> Перенос
    </button>
  </div>

  <div class="toolbar-selected" id="toolbarSelected">
    <span class="toolbar-selected__label">Выбрано:</span>
    <span class="toolbar-selected__count" id="selectedCount">0</span>
    <button class="btn btn-sm btn-outline-dark toolbar-selected__clear" id="clearAllSelectedBtn" style="display: none;">
      <i class="fas fa-times-circle"></i> Сбросить
    </button>
  </div>
</div>

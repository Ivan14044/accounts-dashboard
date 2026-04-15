<!-- Панель инструментов — один ряд -->
<div class="toolbar">
  <h2 class="toolbar-title"><?= e(ucfirst($currentTable ?? 'accounts')) ?></h2>

  <div class="toolbar-actions__bulk">
    <button class="btn btn-sm btn-outline-secondary" id="exportSelectedCsv" disabled>
      <i class="fas fa-file-csv"></i> CSV
    </button>
    <button class="btn btn-sm btn-outline-secondary" id="exportSelectedTxt" disabled>
      <i class="fas fa-file-alt"></i> TXT
    </button>
    <button class="btn btn-sm btn-outline-danger" id="deleteSelected" disabled>
      <i class="fas fa-trash"></i> Удалить
    </button>
    <button class="btn btn-sm btn-outline-secondary" id="changeStatusSelected" disabled>
      <i class="fas fa-tag"></i> Статус
    </button>
    <button class="btn btn-sm btn-outline-secondary" id="bulkEditFieldBtn" disabled>
      <i class="fas fa-edit"></i> Поле
    </button>
  </div>

  <div class="toolbar-actions__main">
    <button class="btn btn-sm btn-primary" id="addAccountBtn" data-bs-toggle="modal" data-bs-target="#addAccountModal">
      <i class="fas fa-plus"></i> Добавить аккаунт
    </button>
    <button class="btn btn-sm btn-outline-primary" id="validateAccountsBtn" disabled title="Проверка аккаунтов на валидность (getuid.live)">
      <i class="fas fa-check-double"></i> Проверка на валидность
    </button>
    <button class="btn btn-sm btn-outline-secondary" id="transferAccountsBtn">
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

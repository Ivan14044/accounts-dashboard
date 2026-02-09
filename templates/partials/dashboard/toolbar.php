<!-- Панель инструментов -->
<div class="toolbar">
  <div class="toolbar-header">
    <h2 class="toolbar-title">Управление аккаунтами</h2>
    <div class="toolbar-actions">
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted">Выбрано:</span>
        <span class="badge bg-primary" id="selectedCount">0</span>
      </div>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-outline-success" id="exportSelectedCsv" disabled>
          <i class="fas fa-file-csv"></i>
          CSV
        </button>
        <button class="btn btn-outline-info" id="exportSelectedTxt" disabled>
          <i class="fas fa-file-alt"></i>
          TXT
        </button>
        <button class="btn btn-outline-danger" id="deleteSelected" disabled>
          <i class="fas fa-trash"></i>
          Удалить
        </button>
        <button class="btn btn-outline-primary" id="changeStatusSelected" disabled>
          <i class="fas fa-tag"></i>
          Статус
        </button>
        <button class="btn btn-outline-secondary" id="bulkEditFieldBtn" disabled>
          <i class="fas fa-edit"></i>
          Поле
        </button>
        <button class="btn btn-success" id="addAccountBtn" data-bs-toggle="modal" data-bs-target="#addAccountModal">
          <i class="fas fa-plus"></i>
          Добавить аккаунт
        </button>
        <button class="btn btn-outline-warning" id="transferAccountsBtn">
          <i class="fas fa-exchange-alt"></i>
          Перенос
        </button>
        <button class="btn btn-outline-dark" id="clearAllSelectedBtn" style="display: none;">
          <i class="fas fa-times-circle"></i>
          Сбросить все
        </button>
      </div>
    </div>
  </div>
</div>

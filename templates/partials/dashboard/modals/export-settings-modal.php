<!-- Модальное окно настроек экспорта -->
<div class="modal fade" id="exportSettingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-success text-white border-0">
        <h5 class="modal-title">
          <i class="fas fa-file-export me-2"></i>Настройки экспорта
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <form id="exportSettingsForm">
          <input type="hidden" id="exportFormat" name="format" value="csv">
          
          <div class="mb-4">
            <label class="form-label fw-bold mb-3">Что выгружаем?</label>
            
            <div class="export-options-list">
              <!-- Опция: Все по фильтру -->
              <div class="form-check custom-option-check p-3 border rounded mb-2 transition-all hover-bg-light cursor-pointer" id="exportOptionAllContainer">
                <input class="form-check-input mt-1" type="radio" name="export_scope" id="exportScopeAll" value="all" checked>
                <label class="form-check-label w-100 cursor-pointer" for="exportScopeAll">
                  <div class="fw-bold">Все по фильтру</div>
                  <div class="small text-muted">Выгрузить все найденные записи (<span id="exportFilteredTotal">0</span> шт.)</div>
                </label>
              </div>
              
              <!-- Опция: Выбранные -->
              <div class="form-check custom-option-check p-3 border rounded mb-2 transition-all hover-bg-light cursor-pointer" id="exportOptionSelectedContainer">
                <input class="form-check-input mt-1" type="radio" name="export_scope" id="exportScopeSelected" value="selected">
                <label class="form-check-label w-100 cursor-pointer" for="exportScopeSelected">
                  <div class="fw-bold">Выбранные строки</div>
                  <div class="small text-muted">Выгрузить только отмеченные галочками (<span id="exportSelectedModalCount">0</span> шт.)</div>
                </label>
              </div>
              
              <!-- Опция: Указать количество -->
              <div class="form-check custom-option-check p-3 border rounded transition-all hover-bg-light cursor-pointer" id="exportOptionCustomContainer">
                <input class="form-check-input mt-1" type="radio" name="export_scope" id="exportScopeCustom" value="custom">
                <label class="form-check-label w-100 cursor-pointer" for="exportScopeCustom">
                  <div class="fw-bold">Указать количество</div>
                  <div class="small text-muted mb-2">Выгрузить первые N записей по текущему фильтру</div>
                  <div class="input-group input-group-sm mt-2" id="customLimitWrapper" style="max-width: 150px; display: none;">
                    <input type="number" class="form-control" id="exportCustomLimit" name="limit" min="1" placeholder="Напр: 50" value="50">
                    <span class="input-group-text">шт.</span>
                  </div>
                </label>
              </div>
            </div>
          </div>
          
          <div class="alert alert-info border-0 shadow-sm small py-2 px-3">
            <i class="fas fa-info-circle me-1 text-primary"></i>
            Файл будет сформирован с учетом текущей сортировки и видимых колонок (для TXT).
          </div>
        </form>
      </div>
      <div class="modal-footer border-0 p-4 pt-0">
        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-success px-4 fw-bold" id="confirmExportBtn">
           <i class="fas fa-download me-2"></i>Скачать файл
        </button>
      </div>
    </div>
  </div>
</div>

<style>
.custom-option-check:hover {
    background-color: var(--gray-50);
}
.custom-option-check input:checked + label .fw-bold {
    color: var(--primary);
}
.transition-all {
    transition: all 0.2s ease;
}
.cursor-pointer {
    cursor: pointer;
}
</style>

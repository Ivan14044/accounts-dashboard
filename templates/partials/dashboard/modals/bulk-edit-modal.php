<!-- Модалка массового редактирования поля -->
<div class="modal fade" id="bulkFieldModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Массовое изменение поля</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Поле</label>
          <select class="form-select" id="bulkFieldSelect">
            <?php foreach ($ALL_COLUMNS as $k => $title): if (in_array($k, ['id'])) continue; ?>
              <option value="<?= e($k) ?>"><?= e($title) ?> (<?= e($k) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Значение</label>
          <input type="text" class="form-control" id="bulkFieldValue" placeholder="Введите значение">
        </div>
        <div class="alert alert-warning small" id="bulkGlobalWarning" style="display: none;">
          <div class="fw-semibold mb-1">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Вы собираетесь изменить поле <span class="bulk-global-field">—</span> для всех записей (без фильтров)
          </div>
          <p class="mb-2">
            Будут обновлены <strong><span class="bulk-global-count">0</span></strong> строк. Это действие нельзя отменить.
          </p>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="bulkGlobalConfirm">
            <label class="form-check-label" for="bulkGlobalConfirm">
              Я понимаю последствия и подтверждаю массовое изменение
            </label>
          </div>
        </div>
        <div class="form-text">Будет применено ко всем выбранным записям<?= isset($filteredTotal) ? ' или ко всем по фильтру' : '' ?>.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="applyBulkFieldBtn"><i class="fas fa-save me-2"></i>Применить</button>
      </div>
    </div>
  </div>
</div>

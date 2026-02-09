<!-- Модалка смены статуса -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-tag me-2"></i>Изменить статус
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Выберите статус</label>
          <select class="form-select" id="statusSelect">
            <option value="">— Выберите —</option>
            <?php foreach ($statuses as $st): ?>
              <option value="<?= e($st) ?>"><?= e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="text-center text-muted my-2">или</div>
        <div class="mb-2">
          <label class="form-label">Новый статус</label>
          <input type="text" class="form-control" id="statusNewInput" placeholder="Введите новый статус">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="applyStatusBtn">
          <i class="fas fa-save me-2"></i>Применить
        </button>
      </div>
    </div>
  </div>
</div>

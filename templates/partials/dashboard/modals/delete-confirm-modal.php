<!-- Модалка подтверждения удаления -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="fas fa-exclamation-triangle me-2"></i>Подтверждение удаления
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Вы действительно хотите удалить <strong id="deleteCount">0</strong> выбранных аккаунтов?</p>
        <p class="text-muted small">Это действие нельзя отменить.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-danger" id="confirmDelete">
          <i class="fas fa-trash me-2"></i>Удалить
        </button>
      </div>
    </div>
  </div>
</div>

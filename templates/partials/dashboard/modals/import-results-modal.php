<!-- Модальное окно результатов импорта -->
<div class="ui-modal" hidden id="importResultsModal" tabindex="-1" aria-hidden="true">
  <div class="ui-modal__box" style="max-width:760px">
    <div class="ui-modal__inner">
      <div class="ui-modal__head">
        <h5 class="ui-modal__title">
          <i class="fas fa-check-circle text-success me-2"></i>
          Результаты импорта аккаунтов
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="importResultsBody">
        <!-- Контент загружается через JS -->
      </div>
      <div class="ui-modal__foot">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
          <i class="fas fa-check me-2"></i>Закрыть
        </button>
      </div>
    </div>
  </div>
</div>

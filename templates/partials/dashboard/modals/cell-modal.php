<!-- Модалка полного значения -->
<div class="modal fade" id="cellModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cellModalTitle">Полное значение</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="mono bg-light p-3 rounded" id="cellModalBody" 
             style="white-space: pre-wrap; word-break: break-word;">—</pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="cellCopyBtn">
          <i class="fas fa-copy me-2"></i>Скопировать
        </button>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Закрыть</button>
      </div>
    </div>
  </div>
</div>

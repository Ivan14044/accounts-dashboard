<!-- Модалка настроек -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-cog me-2"></i>Настройки дашборда
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6">
            <h6 class="mb-3">
              <i class="fas fa-columns me-2"></i>Видимые колонки
            </h6>
            <div class="column-settings">
              <?php foreach ($ALL_COLUMNS as $k => $title): ?>
              <div class="form-check">
                <input class="form-check-input column-toggle" type="checkbox" 
                       value="<?= e($k) ?>" id="col_<?= e($k) ?>" 
                       data-col="<?= e($k) ?>" checked>
                <label class="form-check-label" for="col_<?= e($k) ?>">
                  <?= e($title) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-md-6">
            <h6 class="mb-3">
              <i class="fas fa-eye me-2"></i>Видимые карточки статистики
            </h6>
            <div class="card-settings">
              <div class="form-check">
                <input class="form-check-input card-toggle" type="checkbox" 
                       value="total" id="card_total" data-card="total" checked>
                <label class="form-check-label" for="card_total">
                  Общее количество
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input card-toggle" type="checkbox"
                       value="custom:email_twofa" id="card_email_twofa" data-card="custom:email_twofa" checked>
                <label class="form-check-label" for="card_email_twofa">
                  Email + 2FA
                </label>
              </div>
              <?php foreach ($byStatus as $stName => $cnt): $safeKey = preg_replace('~[^a-z0-9_]+~i','_', $stName); ?>
              <div class="form-check">
                <input class="form-check-input card-toggle" type="checkbox" 
                       value="status:<?= e($safeKey) ?>" id="card_<?= e($safeKey) ?>" 
                       data-card="status:<?= e($safeKey) ?>" checked>
                <label class="form-check-label" for="card_<?= e($safeKey) ?>">
                  <?= e($stName) ?> <span class="badge bg-secondary ms-2"><?= number_format((int)$cnt) ?></span>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        
        <!-- Секция кастомных карточек -->
        <div class="row mt-4">
          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">
                <i class="fas fa-magic me-2"></i>Кастомные карточки статистики
              </h6>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#customCardModal" id="addCustomCardBtn">
                <i class="fas fa-plus me-1"></i>Создать карточку
              </button>
            </div>
            <div id="customCardsList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
              <div class="text-muted text-center">Загрузка...</div>
            </div>
          </div>
        </div>
        
        <!-- Секция управления названиями блоков -->
        <div class="row mt-4">
          
        </div>

        
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" onclick="saveSettings()">
          <i class="fas fa-save me-2"></i>Сохранить настройки
        </button>
      </div>
    </div>
  </div>
</div>

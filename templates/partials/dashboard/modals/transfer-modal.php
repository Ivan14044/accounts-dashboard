<!-- Модалка переноса аккаунтов -->
<!-- Модальное окно: Массовый перенос аккаунтов (V3.0) -->
<div class="modal fade" id="transferAccountsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-10">
        <h5 class="modal-title">
          <i class="fas fa-exchange-alt me-2 text-warning"></i>
          Массовый перенос аккаунтов
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        
        <!-- Информация о лимитах -->
        <div class="alert alert-info small mb-3">
          <i class="fas fa-info-circle me-2"></i>
          <strong>Рекомендации:</strong><br>
          • Оптимально: <strong>1,000-2,000 строк</strong> за один запрос (обработка ~5-15 сек)<br>
          • Максимум: 50,000 строк или 20MB текста<br>
          • Для больших объёмов (10,000+) разбейте данные на части по 1,000-2,000 строк
        </div>
        
        <!-- Поле для ввода текста с ID -->
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <i class="fas fa-paste me-1"></i>
            Вставьте текст с ID аккаунтов
          </label>
          <textarea 
            class="form-control font-monospace" 
            id="transferText" 
            rows="10" 
            placeholder="10abc123def456&#10;61xyz789qwe012&#10;&#10;Или строки содержащие ID:&#10;account_10abc123def456_some_data&#10;user: 61xyz789qwe012, status: active"
            style="resize: vertical; min-height: 150px;"></textarea>
          <small class="text-muted mt-1 d-block">
            <strong>Поддерживаемые форматы:</strong><br>
            • <strong>ID аккаунтов:</strong> Начинаются с <code>10</code> или <code>61</code>, затем 10-23 символа (буквы/цифры)<br>
            • <strong>Примеры:</strong> <code>61582480965170</code>, <code>61560904628043</code>, <code>10abc123def456789</code><br>
            • <strong>Числовые ID:</strong> Чистые числа будут искаться по полю <code>id</code><br>
            • Система автоматически извлечет все валидные ID из любого текста (даже из строк с разделителями)
          </small>
        </div>
        
        <!-- Выбор статуса -->
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <i class="fas fa-tag me-1"></i>
            Новый статус
          </label>
          <div class="row g-2">
            <div class="col-md-6">
              <select class="form-select" id="transferStatusSelect">
                <option value="">— Выберите из существующих —</option>
                <?php foreach ($statuses as $st): ?>
                  <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <input 
                type="text" 
                class="form-control" 
                id="transferStatusCustom" 
                placeholder="Или введите новый статус"
                maxlength="100">
            </div>
          </div>
          <div class="form-text">
            <i class="fas fa-search me-1"></i>
            Поиск выполняется по колонке <strong>id_soc_account</strong> (точное совпадение). 
            При включённом расширенном поиске — дополнительно по <strong>social_url</strong> (вхождение ID).
          </div>
        </div>
        
        <!-- Дополнительные опции поиска -->
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <i class="fas fa-cog me-1"></i>
            Опции поиска
          </label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="transferEnableLike">
            <label class="form-check-label" for="transferEnableLike">
              Использовать расширенный поиск (LIKE) по <code>social_url</code> и <code>cookies</code>
              <small class="text-muted d-block">
                <strong>⚠️ Медленно!</strong> Если точное совпадение по <code>id_soc_account</code> не найдено, 
                искать вхождение ID в полях <code>social_url</code> и <code>cookies</code>. 
                Может значительно замедлить обработку больших объёмов.
              </small>
            </label>
          </div>
        </div>
        
        <!-- Предупреждение -->
        <div class="alert alert-warning small mb-0">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <strong>Внимание:</strong> Операция необратима. Статусы всех найденных аккаунтов будут изменены.
        </div>
        
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>Отмена
        </button>
        <button type="button" class="btn btn-warning" id="applyTransferBtn">
          <i class="fas fa-check me-2"></i>Применить перенос
        </button>
      </div>
    </div>
  </div>
</div>

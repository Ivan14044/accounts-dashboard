<!-- Модальное окно добавления аккаунта -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-plus-circle me-2"></i>Добавить новый аккаунт
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="addAccountErrors" class="alert alert-danger d-none" role="alert"></div>
        <div id="addAccountSuccess" class="alert alert-success d-none" role="alert"></div>
        
        <!-- Инструкция -->
        <div class="alert alert-info mb-4">
          <i class="fas fa-info-circle me-2"></i>
          <strong>Как использовать:</strong>
          <ol class="mb-0 mt-2">
            <li>Нажмите кнопку <strong>"Скачать шаблон CSV"</strong> ниже</li>
            <li>Откройте скачанный файл в Excel или Google Sheets</li>
            <li>Заполните данные аккаунтов (обязательные: <strong>login</strong>, <strong>status</strong>; первые куки — в колонку <strong>first_cookie</strong>)</li>
            <li>Сохраните файл и загрузите его через форму ниже</li>
          </ol>
        </div>
        
        <!-- Кнопка скачивания шаблона -->
        <div class="text-center mb-4">
          <a href="download_account_template.php" class="btn btn-primary btn-lg" id="downloadTemplateBtn">
            <i class="fas fa-download me-2"></i>
            Скачать шаблон CSV
          </a>
        </div>
        
        <hr>
        
        <!-- Форма загрузки файла -->
        <form id="uploadAccountsForm" method="POST" enctype="multipart/form-data" action="import_accounts.php" onsubmit="return false;">
          <?php 
          require_once __DIR__ . '/../../../auth.php';
          $csrfToken = getCsrfToken();
          ?>
          <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="format" value="csv">
          
          <div class="mb-3">
            <label for="accountsFile" class="form-label">
              <i class="fas fa-file-csv me-2"></i>
              <strong>Выберите заполненный CSV файл:</strong>
            </label>
            <input 
              type="file" 
              class="form-control" 
              id="accountsFile" 
              name="import_file" 
              accept=".csv,.txt"
              required
            >
            <div class="form-text">
              Поддерживаются файлы CSV. Максимальный размер: 20 MB
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label fw-semibold">
              <i class="fas fa-copy me-2"></i>
              Действие при обнаружении дубликатов логинов:
            </label>
            
            <div class="form-check">
              <input 
                class="form-check-input" 
                type="radio" 
                name="duplicate_action" 
                id="dupSkip" 
                value="skip" 
                checked
              >
              <label class="form-check-label" for="dupSkip">
                <strong>Пропустить</strong> — не добавлять аккаунты с существующим логином (рекомендуется)
              </label>
            </div>
            
            <div class="form-check">
              <input 
                class="form-check-input" 
                type="radio" 
                name="duplicate_action" 
                id="dupUpdate" 
                value="update"
              >
              <label class="form-check-label" for="dupUpdate">
                <strong>Обновить</strong> — заменить данные существующих аккаунтов новыми из файла
              </label>
            </div>
            
            <div class="form-check">
              <input 
                class="form-check-input" 
                type="radio" 
                name="duplicate_action" 
                id="dupError" 
                value="error"
              >
              <label class="form-check-label" for="dupError">
                <strong>Ошибка</strong> — показать ошибку для дубликатов
              </label>
            </div>
            
            <div class="form-text mt-2">
              <i class="fas fa-info-circle me-1"></i>
              Дубликаты определяются по полю <code>login</code>. При выборе "Обновить" будут изменены все поля, кроме <code>login</code> и системных полей.
            </div>
          </div>
          
          <!-- Контейнер для предпросмотра CSV -->
          <div id="csvPreviewContainer" class="d-none">
            <!-- Здесь будет отображаться предпросмотр CSV -->
          </div>
          
          <!-- Прогресс-бар импорта (скрыт по умолчанию) -->
          <div id="importProgressContainer" class="d-none mt-3">
            <div class="alert alert-info">
              <i class="fas fa-spinner fa-spin me-2"></i>
              <strong>Импорт в процессе...</strong>
            </div>
            <div class="progress" style="height: 30px;">
              <div 
                id="importProgressBar" 
                class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                role="progressbar" 
                style="width: 0%"
                aria-valuenow="0" 
                aria-valuemin="0" 
                aria-valuemax="100"
              >
                <span id="importProgressPercent">0%</span>
              </div>
            </div>
            <div class="mt-2 text-center">
              <small class="text-muted">
                Обработано: <strong id="importProgressText">0 / 0</strong> аккаунтов
              </small>
            </div>
            <div class="mt-2 text-center">
              <button type="button" class="btn btn-sm btn-outline-danger" id="cancelImportBtn">
                <i class="fas fa-times me-1"></i>Отменить импорт
              </button>
            </div>
          </div>
          
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="submit" form="uploadAccountsForm" class="btn btn-success" id="uploadAccountsBtn">
          <i class="fas fa-upload me-2"></i>Загрузить аккаунты
        </button>
      </div>
    </div>
  </div>
</div>

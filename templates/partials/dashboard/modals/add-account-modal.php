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
            <li>Заполните данные аккаунтов (обязательные поля: <strong>login</strong> и <strong>status</strong>)</li>
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
          <input type="hidden" name="duplicate_action" value="skip">
          
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

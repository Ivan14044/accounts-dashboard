<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Accounts Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="alternate icon" href="assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- CSS Bundles -->
  <link href="assets/css/core-base.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-components.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-plugins.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-theme.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-tables.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link rel="preload" href="assets/css/core-base.css?v=<?= ASSETS_VERSION ?>" as="style">
  <link rel="preload" href="assets/js/dashboard-init.js?v=<?= ASSETS_VERSION ?>" as="script">













  <!-- cards-hide-sync.js перенесён в конец body (defer) — убрали блокировку парсинга HTML -->

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    /* ================================================================
       DASHBOARD-SPECIFIC STYLES
       Only styles unique to the dashboard page.
       All base/component styles live in assets/css/core-*.css
       ================================================================ */

    /* ── Performance: Low-end devices ── */
    .low-end-device * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
    }

    /* ── Stat card per-type gradient bars ── */
    .stat-card[data-card="total"]::before             { background: linear-gradient(90deg, #6366f1, #4f46e5); }
    .stat-card[data-card="custom:email_twofa"]::before { background: linear-gradient(90deg, #818cf8, #6366f1); }
    .stat-card[data-status="INVALID_EMAIL"]::before    { background: linear-gradient(90deg, #ef4444, #f97316); }
    .stat-card[data-status="NEW_TAR"]::before          { background: linear-gradient(90deg, #10b981, #059669); }
    .stat-card[data-card="empty_status"]::before       { background: linear-gradient(90deg, #f59e0b, #ef4444); }
    .stat-card[data-card^="custom:"][style*="--card-color"]::before {
      background: linear-gradient(90deg, var(--card-color), var(--card-color-dark, var(--card-color)));
    }

    /* ── Custom card modal select ── */
    #customCardStatuses {
      border: 1px solid var(--gray-200);
      border-radius: 0.375rem;
    }
    #customCardStatuses option { padding: 0.5rem; border-bottom: 1px solid var(--gray-100); }
    #customCardStatuses option:checked { background: linear-gradient(90deg, #6366f1, #4f46e5); color: white; font-weight: 600; }

    /* ── Dropdown checkbox items (status, currency, geo, etc.) ── */
    .status-dropdown-menu,
    .currency-dropdown-menu,
    .geo-dropdown-menu,
    .status-rk-dropdown-menu,
    .status-marketplace-dropdown-menu {
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
      border: 1px solid var(--gray-200);
      border-radius: 12px;
      padding: 0.5rem;
    }

    .status-checkbox-item,
    .currency-item,
    .geo-item,
    .status-rk-item,
    .status-marketplace-item {
      padding: 8px 12px;
      margin: 1px 0;
      border-radius: 6px;
      transition: background-color 150ms ease;
      cursor: pointer;
      min-height: 36px;
      display: flex;
      align-items: center;
      color: var(--gray-900);
    }
    .status-checkbox-item:hover,
    .currency-item:hover,
    .geo-item:hover,
    .status-rk-item:hover,
    .status-marketplace-item:hover {
      background-color: var(--gray-50);
    }

    /* Dropdown badges always indigo */
    .status-checkbox-item .badge,
    .status-checkbox-item .status-count,
    .currency-item .badge,
    .geo-item .badge,
    .status-rk-item .badge,
    .status-marketplace-item .badge {
      background-color: var(--primary-600, #4f46e5) !important;
      color: white !important;
      font-size: 0.7rem;
      padding: 0.15rem 0.4rem;
      border-radius: 10px;
      min-width: 20px;
      text-align: center;
    }

    .status-checkbox-item .form-check-input {
      cursor: pointer;
      margin-right: 10px;
      margin-left: 0;
      flex-shrink: 0;
      width: 16px;
      height: 16px;
    }
    .status-checkbox-item .form-check-label {
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      flex: 1;
      line-height: 1.3;
      color: var(--gray-900);
    }
    .status-checkbox-item .form-check-input:checked ~ .form-check-label {
      font-weight: 500;
      color: var(--primary-600);
    }

    /* Dropdown button unified style */
    #statusDropdown, #currencyDropdown, #geoDropdown, #statusRkDropdown, #statusMarketplaceDropdown {
      min-height: 31px;
      border-color: var(--gray-300);
      font-size: 0.875rem;
      color: var(--gray-900);
    }

    .currency-item label, .geo-item label, .status-rk-item label, .status-marketplace-item label {
      cursor: pointer; user-select: none; font-size: 0.875rem; color: var(--gray-900);
    }

    .currency-item.active, .geo-item.active, .status-rk-item.active, .status-marketplace-item.active {
      background-color: rgba(79, 70, 229, 0.08);
      font-weight: 500;
    }

    /* Scrollbar for dropdown */
    .status-dropdown-menu::-webkit-scrollbar { width: 5px; }
    .status-dropdown-menu::-webkit-scrollbar-track { background: var(--gray-50); border-radius: 3px; }
    .status-dropdown-menu::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 3px; }
    .status-dropdown-menu::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }

    /* ── Password field ── */
    .pw-mask { display: flex; align-items: center; gap: var(--space-2); }
    .pw-dots { letter-spacing: 0.1em; font-weight: 600; color: var(--gray-500); }
    .pw-text:empty::before { content: '(пусто)'; color: var(--gray-400); font-style: italic; }

    .pw-toggle, .pw-edit {
      border: 1px solid var(--gray-300);
      background: var(--gray-50);
      padding: 2px 6px;
      border-radius: 6px;
      cursor: pointer;
      color: var(--gray-500);
      font-size: 0.875rem;
      opacity: 0.6;
    }
    .pw-mask:hover .pw-toggle, .pw-mask:hover .pw-edit { opacity: 1; }
    .pw-toggle:hover { background: var(--primary-50); color: var(--primary-600); border-color: var(--primary-300); }
    .pw-edit:hover   { background: var(--success-50); color: var(--success-600); border-color: var(--success-500); }

    /* ── Editable fields ── */
    .editable-field-wrap { display: flex; align-items: center; gap: var(--space-2); }
    .field-edit-btn {
      border: 1px solid var(--gray-300);
      background: var(--gray-50);
      padding: 2px 6px;
      border-radius: 6px;
      cursor: pointer;
      color: var(--gray-500);
      font-size: 0.875rem;
      opacity: 0.5;
    }
    .editable-field-wrap:hover .field-edit-btn { opacity: 1; }
    .field-edit-btn:hover { background: var(--success-50); color: var(--success-600); border-color: var(--success-500); }

    .copy-btn {
      border: none;
      background: rgba(79, 70, 229, 0.08);
      color: var(--primary-600);
      padding: 2px 6px;
      border-radius: 6px;
      font-size: 0.75rem;
      cursor: pointer;
    }
    .copy-btn:hover { background: rgba(79, 70, 229, 0.15); }

    .truncate { max-width: 200px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
    .truncate:hover { color: var(--primary-600); }

    /* ── Selected rows ── */
    .table tbody tr[data-id] { cursor: pointer; }
    .table tbody tr[data-id]:hover:not(.row-selected) { background: var(--gray-100) !important; }
    .table tbody tr[data-id].row-selected { background-color: var(--primary-50) !important; border-left: 4px solid var(--primary-600) !important; }
    .table tbody tr[data-id].row-selected td { background-color: var(--primary-50) !important; }
    .table tbody tr[data-id].row-selected:hover { background-color: var(--primary-100) !important; }
    .table tbody tr[data-id].row-selected:hover td { background-color: var(--primary-100) !important; }

    /* ── Compact filters ── */
    .filters-compact .card-body { padding: 0.75rem; }
    .filters-compact .row { --bs-gutter-x: 0.75rem; --bs-gutter-y: 0.5rem; }
    .filters-compact .form-label { font-size: 0.75rem; margin-bottom: 0.125rem; font-weight: 500; }
    .filters-compact .form-control, .filters-compact .form-select { padding: 0.25rem 0.5rem; font-size: 0.8125rem; }
    .filters-compact .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

    /* ── Loading overlays ── */
    .loading-overlay {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      background: rgba(255,255,255,0.92);
      z-index: 1000;
      opacity: 0; pointer-events: none;
      transition: opacity 300ms ease;
    }
    .loading-overlay.show { opacity: 1; pointer-events: all; }
    .loading-overlay .loader { width: 48px; height: 48px; }
    .loading-overlay .loading-text { font-size: 0.875rem; color: var(--gray-600); margin-top: 0.5rem; }

    .stats-loading-overlay { display: none !important; }

    .force-hidden { display: none !important; visibility: hidden !important; }

    /* ── Scroll-to-top ── */
    .scroll-to-top {
      position: fixed; bottom: 2rem; right: 2rem;
      width: 3rem; height: 3rem;
      background: var(--primary-600);
      color: white; border: none; border-radius: 50%;
      box-shadow: 0 4px 16px rgba(79, 70, 229, 0.3);
      cursor: pointer; z-index: 1000;
      opacity: 0; visibility: hidden; transform: translateY(20px);
      transition: all 300ms ease;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.125rem;
    }
    .scroll-to-top.show { opacity: 1; visibility: visible; transform: translateY(0); }
    .scroll-to-top:hover { background: var(--primary-700); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(79, 70, 229, 0.4); }

    /* ── Page loader ── */
    .page-loader {
      position: fixed; inset: 0;
      background: var(--bg-primary);
      z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      transition: opacity 300ms ease, visibility 300ms ease;
    }
    .page-loader.hidden { opacity: 0; visibility: hidden; }
    .page-loader .middle { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    /* ── Textarea inside table ── */
    #accountsTable td textarea { min-height: 80px; font-family: var(--font-family-mono); font-size: 0.875rem; }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .scroll-to-top { bottom: 1rem; right: 1rem; width: 2.5rem; height: 2.5rem; font-size: 1rem; }
    }
  </style>
</head>
<body>
  <!-- Прелоадер страницы -->
  <div class="page-loader" id="pageLoader">
    <div class="middle">
      <span class="loader loader-primary"></span>
    </div>
  </div>
  <!-- Современный хедер -->
  <?php include __DIR__ . '/partials/dashboard/header.php'; ?>

  <!-- Основной контент -->
  <main class="container-fluid">
    <!-- Статистические карточки -->
    <?php include __DIR__ . '/partials/dashboard/stats-cards.php'; ?>
    


  <!-- Фильтры (Современный дизайн) -->
  <?php include __DIR__ . '/partials/dashboard/filters.php'; ?>

    <!-- Панель инструментов — один ряд -->
    <div class="toolbar">
      <h2 class="toolbar-title">Управление аккаунтами</h2>

      <div class="toolbar-actions__bulk">
        <button class="btn btn-sm btn-outline-secondary" id="exportSelectedCsv" disabled>
          <i class="fas fa-file-csv"></i> CSV
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="exportSelectedTxt" disabled>
          <i class="fas fa-file-alt"></i> TXT
        </button>
        <button class="btn btn-sm btn-outline-danger" id="deleteSelected" disabled>
          <i class="fas fa-trash"></i> Удалить
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="changeStatusSelected" disabled>
          <i class="fas fa-tag"></i> Статус
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="bulkEditFieldBtn" disabled>
          <i class="fas fa-edit"></i> Поле
        </button>
      </div>

      <div class="toolbar-actions__main">
        <button class="btn btn-sm btn-primary" id="addAccountBtn" data-bs-toggle="modal" data-bs-target="#addAccountModal">
          <i class="fas fa-plus"></i> Добавить аккаунт
        </button>
        <button class="btn btn-sm btn-outline-primary" id="validateAccountsBtn" disabled title="Проверка аккаунтов на валидность (acctool.top)">
          <i class="fas fa-check-double"></i> Проверка на валидность
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="transferAccountsBtn">
          <i class="fas fa-exchange-alt"></i> Перенос
        </button>
      </div>

      <div class="toolbar-selected" id="toolbarSelected">
        <span class="toolbar-selected__label">Выбрано:</span>
        <span class="toolbar-selected__count" id="selectedCount">0</span>
        <button class="btn btn-sm btn-outline-dark toolbar-selected__clear" id="clearAllSelectedBtn" style="display: none;">
          <i class="fas fa-times-circle"></i> Сбросить
        </button>
      </div>
    </div>

  <!-- Таблица -->
  <?php include __DIR__ . '/partials/table/table.php'; ?>
  </main>

  <!-- Кнопка "Наверх" -->
  <button id="scrollToTop" class="scroll-to-top" title="Наверх">
    <i class="fas fa-chevron-up"></i>
  </button>

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

<?php require_once __DIR__ . '/partials/dashboard/modals/settings-modal.php'; ?>

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
            <li>Откройте скачанный файл в Excel, Google Sheets или текстовом редакторе</li>
            <li>Заполните данные аккаунтов:
              <ul>
                <li><span class="text-danger">*</span> <strong>login</strong> и <strong>status</strong> — обязательные поля</li>
                <li>Остальные поля заполняйте по необходимости</li>
              </ul>
            </li>
            <li>Выберите действие при обнаружении дубликатов:
              <ul>
                <li><strong>Пропустить</strong> — не добавлять аккаунты с существующим логином (рекомендуется)</li>
                <li><strong>Обновить</strong> — заменить данные существующих аккаунтов</li>
                <li><strong>Ошибка</strong> — показать ошибку для дубликатов</li>
              </ul>
            </li>
            <li>Сохраните файл в формате CSV и загрузите через форму ниже</li>
          </ol>
          <div class="mt-2">
            <small class="text-muted">
              <i class="fas fa-lightbulb me-1"></i>
              <strong>Совет:</strong> Для больших файлов (>500 строк) импорт может занять несколько минут. 
              Дождитесь завершения процесса.
            </small>
          </div>
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
          require_once __DIR__ . '/../auth.php';
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

<?php require_once __DIR__ . '/partials/dashboard/modals/import-results-modal.php'; ?>
<?php require_once __DIR__ . '/partials/dashboard/modals/export-settings-modal.php'; ?>

<!-- Модалка предварительного просмотра отключена -->

<!-- Модальное окно создания/редактирования кастомной карточки -->
<div class="modal fade" id="customCardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-magic me-2"></i>Создать кастомную карточку статистики
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="customCardForm">
          <!-- Название карточки -->
          <div class="mb-3">
            <label class="form-label fw-semibold small">Название карточки</label>
            <input type="text" class="form-control form-control-sm" id="customCardName" placeholder="Например: Готовые к продаже с Email" required>
          </div>

          <!-- Фильтры -->
          <div class="mb-3">
            <h6 class="mb-2 small">
              <i class="fas fa-filter me-1"></i>Фильтры для подсчета
            </h6>
            
            <!-- Статусы и булевы фильтры -->
            <div class="row mb-2">
              <!-- Статусы (множественный выбор) -->
              <div class="col-md-6 mb-2">
                <label class="form-label small">Статусы</label>
                <select class="form-select form-select-sm" id="customCardStatuses" multiple size="5" style="font-size: 0.875rem;">
                  <?php foreach ($statuses as $st): 
                    $statusCount = isset($byStatus[$st]) ? (int)$byStatus[$st] : 0;
                  ?>
                  <option value="<?= e($st) ?>">
                    <?= e($st) ?> (<?= number_format($statusCount) ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted" style="font-size: 0.7rem;">
                  Ctrl/Cmd+Click для множественного выбора
                </small>
              </div>
              
              <!-- Булевы фильтры -->
              <div class="col-md-6 mb-2">
                <label class="form-label small">Дополнительные условия</label>
                <div class="row g-1">
                  <div class="col-6">
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasEmail">
                      <label class="form-check-label small" for="customHasEmail">Есть Email</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasTwoFa">
                      <label class="form-check-label small" for="customHasTwoFa">Есть 2FA</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasToken">
                      <label class="form-check-label small" for="customHasToken">Есть Token</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasAvatar">
                      <label class="form-check-label small" for="customHasAvatar">Есть Аватар</label>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasCover">
                      <label class="form-check-label small" for="customHasCover">Есть Обложка</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasPassword">
                      <label class="form-check-label small" for="customHasPassword">Есть Пароль</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasFanPage">
                      <label class="form-check-label small" for="customHasFanPage">Есть Fan Page</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customFullFilled">
                      <label class="form-check-label small" for="customFullFilled">Полностью заполненные</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Диапазоны -->
            <div class="row g-2 mb-2">
              <?php if (isset($ALL_COLUMNS['scenario_pharma'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Сценарий фарма (от)</label>
                <input type="number" class="form-control form-control-sm" id="customPharmaFrom" min="0" max="50" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Сценарий фарма (до)</label>
                <input type="number" class="form-control form-control-sm" id="customPharmaTo" min="0" max="50" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['quantity_friends'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Друзья (от)</label>
                <input type="number" class="form-control form-control-sm" id="customFriendsFrom" min="0" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Друзья (до)</label>
                <input type="number" class="form-control form-control-sm" id="customFriendsTo" min="0" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['year_created'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Год (от)</label>
                <input type="number" class="form-control form-control-sm" id="customYearCreatedFrom" min="2000" max="2100" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Год (до)</label>
                <input type="number" class="form-control form-control-sm" id="customYearCreatedTo" min="2000" max="2100" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['limit_rk'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Limit RK (от)</label>
                <input type="number" class="form-control form-control-sm" id="customLimitRkFrom" min="0" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Limit RK (до)</label>
                <input type="number" class="form-control form-control-sm" id="customLimitRkTo" min="0" placeholder="До">
              </div>
              <?php endif; ?>
            </div>
            
            <!-- Одиночные фильтры -->
            <div class="row g-2 mb-2">
              <?php if (isset($ALL_COLUMNS['status_marketplace'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Marketplace</label>
                <select class="form-select form-select-sm" id="customStatusMarketplace">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($statusesMarketplace) && is_array($statusesMarketplace)): ?>
                    <?php foreach ($statusesMarketplace as $st => $count): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_rk']) && (!empty($statusRkList) || $emptyStatusRkCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Status RK</label>
                <select class="form-select form-select-sm" id="customStatusRk">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($statusRkList)): ?>
                    <?php foreach ($statusRkList as $st => $count): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['currency']) && (!empty($currenciesList) || $emptyCurrencyCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Валюта</label>
                <select class="form-select form-select-sm" id="customCurrency">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($currenciesList)): ?>
                    <?php foreach ($currenciesList as $code => $count): ?>
                    <option value="<?= e($code) ?>"><?= e($code) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['geo']) && (!empty($geosList) || $emptyGeoCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Гео аккаунта</label>
                <select class="form-select form-select-sm" id="customGeo">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($geosList)): ?>
                    <?php foreach ($geosList as $geo => $count): ?>
                    <option value="<?= e($geo) ?>"><?= e($geo) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Целевой статус -->
          <div class="mb-2">
            <h6 class="mb-2 small">
              <i class="fas fa-tag me-1"></i>Действие при клике
            </h6>
            <div class="mb-2">
              <label class="form-label small">Установить статус</label>
              <select class="form-select form-select-sm" id="customCardTargetStatus">
                <option value="">Не изменять статус</option>
                <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
                <option value="__new__">+ Создать новый статус</option>
              </select>
              <div id="newStatusInputGroup" class="mt-1" style="display: none;">
                <input type="text" class="form-control form-control-sm" id="customCardNewStatus" placeholder="Введите название нового статуса">
              </div>
            </div>
          </div>

          <!-- Цвет карточки -->
          <div class="mb-2">
            <label class="form-label small">Цвет карточки</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" class="form-control form-control-color" id="customCardColor" value="#3b82f6" style="width: 60px; height: 35px;">
              <span class="text-muted" style="font-size: 0.75rem;">Выберите цвет для карточки</span>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="saveCustomCardBtn">
          <i class="fas fa-save me-2"></i>Сохранить карточку
        </button>
      </div>
    </div>
  </div>
</div>

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

<!-- Модалка проверки аккаунтов на валидность -->
<div class="modal fade" id="validateAccountsModal" tabindex="-1" aria-hidden="true">
  <style>
    /* ─── Validate Modal Styles ─── */
    #validateAccountsModal .modal-content {
      border-radius: 12px;
    }
    #validateAccountsModal .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: nowrap;
    }
    #validateAccountsModal .modal-header .btn-close {
      opacity: 0.65;
      margin: 0;
      padding: 0.5rem;
      background-size: 1em;
    }
    #validateAccountsModal .modal-header .btn-close:hover,
    #validateAccountsModal .modal-header .btn-close:focus {
      opacity: 1;
    }
    #validateAccountsModal .vld-indeterminate .progress-bar {
      width: 100% !important;
      background: linear-gradient(90deg, #0d6efd 0%, #6ea8fe 50%, #0d6efd 100%) !important;
      background-size: 200% 100% !important;
      animation: vld-shimmer 1.5s ease-in-out infinite !important;
    }
    @keyframes vld-shimmer {
      0%   { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }
    /* ── Stepper: pipeline indicator ── */
    .vld-steps {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: .82rem;
    }
    .vld-step {
      display: flex;
      align-items: center;
      gap: 6px;
      color: #adb5bd;
      transition: color .25s ease;
      flex-shrink: 0;
    }
    .vld-step-num {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #e9ecef;
      color: #adb5bd;
      font-weight: 600;
      font-size: .75rem;
      transition: background .25s ease, color .25s ease;
    }
    .vld-step.active .vld-step-num {
      background: #0d6efd;
      color: #fff;
      box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.18);
    }
    .vld-step.active { color: #0d6efd; font-weight: 600; }
    .vld-step.done .vld-step-num {
      background: #198754;
      color: #fff;
    }
    .vld-step.done { color: #198754; }
    .vld-step-line {
      flex: 1;
      height: 2px;
      background: #e9ecef;
      border-radius: 1px;
      min-width: 12px;
    }
    .vld-ratio-track {
      height: 8px;
      border-radius: 8px;
      background: #e9ecef;
      overflow: hidden;
      display: flex;
    }
    .vld-ratio-seg {
      height: 100%;
      transition: width .5s cubic-bezier(.4, 0, .2, 1);
    }
    .vld-ratio-valid { background: #198754; border-radius: 8px 0 0 8px; }
    .vld-ratio-invalid { background: #dc3545; border-radius: 0 8px 8px 0; }
    .vld-summary-card {
      border-radius: 12px;
      padding: 20px 24px;
      display: flex;
      align-items: center;
      gap: 16px;
      border: none;
    }
    .vld-summary-card .vld-icon {
      width: 48px; height: 48px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; flex-shrink: 0;
    }
    .vld-summary-card .vld-num {
      font-size: 28px; font-weight: 700; line-height: 1;
      font-variant-numeric: tabular-nums;
    }
    .vld-summary-card .vld-label { font-size: 13px; opacity: .8; margin-top: 2px; }
    .vld-res-ratio-track .vld-ratio-seg {
      transition: width .6s cubic-bezier(.4, 0, .2, 1);
    }
    .vld-card-valid { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; }
    .vld-card-valid .vld-icon { background: rgba(5,150,105,.15); color: #059669; }
    .vld-card-invalid { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; }
    .vld-card-invalid .vld-icon { background: rgba(220,38,38,.12); color: #dc2626; }
    .vld-card-skipped { background: linear-gradient(135deg, #f3f4f6, #e5e7eb); color: #374151; }
    .vld-card-skipped .vld-icon { background: rgba(107,114,128,.12); color: #6b7280; }
    .vld-res-ratio-track {
      height: 12px; border-radius: 12px; background: #e9ecef; overflow: hidden; display: flex;
    }
    .vld-actions-panel {
      background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px 20px;
    }
  </style>
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">

      <!-- Header: заголовок и крестик закрытия -->
      <div class="modal-header border-0 px-4 pt-4 pb-0">
        <h5 class="modal-title fw-bold" style="font-size: 1.15rem;">
          <i class="fas fa-shield-alt me-2 text-primary"></i>Проверка на валидность
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
      </div>

      <div class="modal-body px-4 pb-4 pt-3">

        <!-- Шаг 1: Выбор scope -->
        <div id="vldScopePane">
          <p class="text-muted mb-3 small">
            Проверка аккаунтов через <strong>acctool.top</strong> — определяет, активен аккаунт или заблокирован.
          </p>
          <div class="mb-3">
            <label class="form-label fw-semibold small" for="vldScopeSelect">Что проверяем</label>
            <select class="form-select" id="vldScopeSelect">
              <option value="selected">Выбранные строки</option>
              <option value="page">Все на текущей странице</option>
              <option value="filter">Все по текущему фильтру</option>
            </select>
          </div>
          <button type="button" class="btn btn-primary px-4" id="vldStartBtn">
            <i class="fas fa-play me-2"></i>Запустить проверку
          </button>
        </div>

        <!-- ═══ Шаг 2: Прогресс ═══ -->
        <div id="vldProgressPane" class="d-none">
          <!-- Stepper: visual pipeline → видно где сейчас процесс -->
          <div class="vld-steps mb-3">
            <div class="vld-step" data-step="count">
              <span class="vld-step-num">1</span>
              <span class="vld-step-label">Подсчёт</span>
            </div>
            <div class="vld-step-line"></div>
            <div class="vld-step" data-step="load">
              <span class="vld-step-num">2</span>
              <span class="vld-step-label">Загрузка списка</span>
            </div>
            <div class="vld-step-line"></div>
            <div class="vld-step" data-step="check">
              <span class="vld-step-num">3</span>
              <span class="vld-step-label">Проверка</span>
            </div>
          </div>

          <!-- Spinner + label -->
          <div class="d-flex align-items-center gap-2 mb-3">
            <span id="vldSpinner"><i class="fas fa-spinner fa-spin text-primary" style="font-size: 1.1rem;"></i></span>
            <span id="vldProgressLabel" class="fw-semibold" style="font-size: .95rem;">Считаем количество записей…</span>
            <span id="vldEta" class="ms-auto text-muted small d-none"></span>
          </div>

          <!-- Progress bar -->
          <div class="progress mb-3" style="height: 10px; border-radius: 10px;" id="vldProgressWrap">
            <div class="progress-bar bg-primary"
                 id="vldProgressBar" role="progressbar"
                 style="width: 0%; border-radius: 10px; transition: width .5s cubic-bezier(.4,0,.2,1);">0%</div>
          </div>

          <!-- Ratio bar -->
          <div id="vldRatioWrap" class="d-none mb-3">
            <div class="vld-ratio-track mb-1">
              <div id="vldRatioValid" class="vld-ratio-seg vld-ratio-valid" style="width: 0%;"></div>
              <div id="vldRatioInvalid" class="vld-ratio-seg vld-ratio-invalid" style="width: 0%;"></div>
            </div>
            <div id="vldRatioLabel" class="text-center small fw-semibold" style="font-size: .8rem;"></div>
          </div>

          <button type="button" class="btn btn-outline-danger btn-sm" id="vldCancelBtn">
            <i class="fas fa-stop me-1"></i>Остановить
          </button>
        </div>

        <!-- ═══ Шаг 3: Результаты ═══ -->
        <div id="vldResultPane" class="d-none">

          <!-- Summary cards -->
          <div class="d-flex flex-column flex-sm-row flex-wrap gap-3 mb-4">
            <div class="flex-fill flex-grow-1" style="flex-basis: 30%;">
              <div class="vld-summary-card vld-card-valid h-100">
                <div class="vld-icon"><i class="fas fa-check-circle"></i></div>
                <div>
                  <div class="vld-num" id="vldResValidNum">0</div>
                  <div class="vld-label">Валидные <span id="vldResValidPct" class="fw-bold"></span></div>
                </div>
              </div>
            </div>
            <div class="flex-fill flex-grow-1" style="flex-basis: 30%;">
              <div class="vld-summary-card vld-card-invalid h-100">
                <div class="vld-icon"><i class="fas fa-times-circle"></i></div>
                <div>
                  <div class="vld-num" id="vldResInvalidNum">0</div>
                  <div class="vld-label">Невалидные <span id="vldResInvalidPct" class="fw-bold"></span></div>
                </div>
              </div>
            </div>
            <div class="flex-fill flex-grow-1 d-none" id="vldResSkippedCard" style="flex-basis: 30%;">
              <div class="vld-summary-card vld-card-skipped h-100">
                <div class="vld-icon"><i class="fas fa-minus-circle"></i></div>
                <div>
                  <div class="vld-num" id="vldResSkippedNum">0</div>
                  <div class="vld-label" id="vldResSkippedLabel">Пропущено (нет FB ID)</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Ratio bar -->
          <div class="mb-4">
            <div class="vld-res-ratio-track">
              <div id="vldResRatioValid" class="vld-ratio-seg vld-ratio-valid" style="width: 0%;"></div>
              <div id="vldResRatioInvalid" class="vld-ratio-seg vld-ratio-invalid" style="width: 0%;"></div>
            </div>
          </div>

          <!-- Действия с невалидными -->
          <div id="vldActionsBlock" class="vld-actions-panel d-none">
            <div class="fw-semibold mb-2" style="font-size: .95rem;">
              <i class="fas fa-exclamation-triangle text-danger me-1"></i>
              Действия с невалидными аккаунтами
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <select class="form-select form-select-sm" id="vldActionStatusSelect" style="max-width: 200px;">
                <option value="">— Выберите статус —</option>
                <?php foreach ($statuses as $st): ?>
                  <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-sm btn-primary" id="vldActionSetStatusBtn">
                <i class="fas fa-tag me-1"></i>Применить
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger" id="vldActionDeleteBtn">
                <i class="fas fa-trash-alt me-1"></i>Удалить в корзину
              </button>
            </div>
          </div>

        </div>
      </div>

      <!-- Footer -->
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
      </div>

    </div>
  </div>
</div>

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
            Поиск выполняется в 3 колонках: <strong>id_soc_account</strong> (точное совпадение), <strong>social_url</strong> и <strong>cookies</strong> (вхождение)
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/nouislider@15.7.1/dist/nouislider.min.js" defer></script>
<?php include __DIR__ . '/partials/dashboard/config-script.php'; ?>
<?php include __DIR__ . '/partials/dashboard/init-script.php'; ?>
<!-- Core модули для оптимизации производительности -->
<script src="assets/js/core/logger.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/core/dom-cache.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/core/performance.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-refresh.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/pagination.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<!-- Модули дашборда -->
<script src="assets/js/modules/dashboard-selection.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-export.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-filters.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-stats.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-modals.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-validate.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-main.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<!-- Основные модули -->
<script src="assets/js/sticky-scrollbar.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/table-module.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/toast.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/filters-modern.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/modules/dashboard-upload.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
<script src="assets/js/dashboard.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
<script src="assets/js/validation.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
<script src="assets/js/quick-search.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
<script src="assets/js/saved-filters.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
<script src="assets/js/favorites.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
<script src="assets/js/modules/cards-hide-sync.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
</body>
</html>



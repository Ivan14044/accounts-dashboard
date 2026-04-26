<?php
// view.php — полная карточка аккаунта с современным дизайном
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/AccountsService.php';

// Проверяем авторизацию
requireAuth();
checkSessionTimeout();

$id = (int)get_param('id', '0');
if ($id <= 0) { http_response_code(400); exit('Bad id'); }

// Получаем всю строку через сервис
$service = new AccountsService($tableName);
$row = $service->getAccountById($id);

if (!$row) { http_response_code(404); exit('Not found'); }

// Упорядочим поля: сначала самые важные, затем остальные по алфавиту
$priority = ['id','login','email','first_name','last_name','status','password','email_password','birth_day','birth_month','birth_year','social_url','ads_id','user_agent','two_fa','token','cookies','extra_info_1','extra_info_2','extra_info_3','extra_info_4'];
$keys = array_keys($row);
$rest = array_values(array_diff($keys, $priority));
sort($rest, SORT_NATURAL|SORT_FLAG_CASE);
$ordered = array_values(array_unique(array_merge($priority, $rest)));

// Грубая попытка распознать "длинные" поля
$longLike = ['token','cookies','first_cookie','user_agent','extra_info_1','extra_info_2','extra_info_3','extra_info_4','social_url'];

// Определяем класс статуса для бейджа
function getStatusClass($status) {
    $status = strtolower((string)($status ?? ''));
    if (strpos($status, 'new') !== false) return 'bg-secondary';
    if (strpos($status, 'add_selphi_true') !== false) return 'bg-success';
    if (strpos($status, 'error') !== false) return 'bg-danger';
    if (strpos($status, 'warning') !== false) return 'bg-warning';
    if (strpos($status, 'info') !== false) return 'bg-info';
    return 'bg-secondary';
}

?><!doctype html>
<html lang="ru" data-bs-theme="auto">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Account #<?= (int)$row['id'] ?> - Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="assets/css/core-base.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-components.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-plugins.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-theme.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <style>
    /* Premium View Layout */
    body { 
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: var(--bg-body);
      min-height: 100vh;
    }
    
    .account-header {
      background: var(--glass-bg);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-2xl);
      padding: var(--space-8);
      margin-bottom: var(--space-6);
      text-align: center;
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-xl);
    }
    
    .account-header::before {
      content: '';
      position: absolute;
      top: -50%; left: -50%;
      width: 200%; height: 200%;
      background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.08) 0%, transparent 50%);
      pointer-events: none;
      z-index: 0;
    }
    
    .account-id {
      position: relative;
      z-index: 1;
      font-size: 3.5rem;
      font-weight: 800;
      letter-spacing: -0.03em;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: var(--space-2);
      filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.2));
    }
    
    .account-title {
      position: relative;
      z-index: 1;
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--gray-800);
      margin-bottom: var(--space-2);
    }
    
    .account-subtitle {
      position: relative;
      z-index: 1;
      color: var(--gray-500);
      font-size: 1.1rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-3);
    }
    
    .toolbar-view {
      background: rgba(255, 255, 255, 0.6);
      backdrop-filter: blur(12px);
      border-radius: var(--radius-xl);
      padding: var(--space-4) var(--space-6);
      margin-bottom: var(--space-6);
      border: 1px solid var(--border-light);
      box-shadow: var(--shadow-md);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: var(--space-4);
    }
    
    .details-card {
      background: var(--glass-bg);
      backdrop-filter: blur(16px);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-2xl);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
    }
    
    .field-row {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: var(--space-4);
      padding: var(--space-4) var(--space-6);
      border-bottom: 1px solid var(--border-light);
      transition: all 0.2s ease;
    }
    
    .field-row:hover {
      background: var(--primary-50);
    }
    
    .field-row:last-child {
      border-bottom: none;
    }
    
    .field-label {
      color: var(--gray-600);
      font-weight: 600;
      font-size: 0.8125rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    
    .field-label i {
      color: var(--primary-400);
      font-size: 1.1rem;
      width: 20px;
      text-align: center;
    }
    
    .field-value {
      color: var(--gray-800);
      font-weight: 500;
      word-break: break-word;
      line-height: 1.6;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: var(--space-3);
    }
    
    .field-value .mono {
      font-family: 'JetBrains Mono', 'Fira Code', monospace;
      background: rgba(244, 244, 245, 0.8);
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-md);
      border: 1px solid var(--border-medium);
      font-size: 0.85rem;
      color: var(--gray-700);
      white-space: pre-wrap;
      word-break: break-all;
      box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
    }
    
    .copy-btn {
      border: 1px solid var(--primary-200);
      background: var(--primary-50);
      color: var(--primary-600);
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-md);
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      font-weight: 600;
      white-space: nowrap;
    }
    
    .copy-btn:hover {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(99, 102, 241, 0.2);
    }
    
    .pw-mask {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    
    .pw-dots {
      font-family: monospace;
      letter-spacing: 3px;
      font-size: 1.2rem;
      margin-top: 4px;
      color: var(--gray-500);
    }
    
    .pw-toggle {
      border: none;
      background: transparent;
      color: var(--gray-400);
      cursor: pointer;
      padding: var(--space-1);
      border-radius: var(--radius-sm);
      transition: 0.2s;
    }
    
    .pw-toggle:hover {
      color: var(--primary);
      background: var(--primary-50);
    }
    
    .status-badge {
      font-size: 0.75rem;
      font-weight: 700;
      padding: var(--space-2) var(--space-4);
      border-radius: var(--radius-full);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .empty-value {
      color: var(--gray-400);
      font-style: italic;
    }

    @media (max-width: 768px) {
      .field-row {
        grid-template-columns: 1fr;
        gap: var(--space-2);
        padding: var(--space-4);
      }
      .account-header { padding: var(--space-5) var(--space-4); }
      .account-id { font-size: 2.5rem; }
      .toolbar-view { flex-direction: column; align-items: stretch; text-align: center; }
      .toolbar-view .btn-group { flex-direction: column; width: 100%; gap: var(--space-2); }
      .toolbar-view .btn-group .btn { border-radius: var(--radius-lg) !important; width: 100%; }
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand bg-white border-bottom shadow-sm mb-4" style="height: 64px;">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
      <i class="fas fa-chart-line text-primary"></i>
      Dashboard
    </a>
    <div class="ms-auto d-flex gap-2 gap-md-3 align-items-center">
      <span class="text-muted small fw-medium d-none d-sm-inline-block">
        <i class="fas fa-user-circle me-1 text-primary"></i>
        <?= htmlspecialchars(getCurrentUser()) ?>
      </span>
      <div class="vr mx-1 d-none d-sm-block"></div>
      <button class="btn btn-sm btn-outline-secondary rounded-pill" id="copyJsonBtn" title="Скопировать JSON">
        <i class="fas fa-copy"></i>
      </button>
      <a class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm d-none d-sm-inline-flex align-items-center gap-1" id="downloadJsonBtn" download="account_<?= (int)$row['id'] ?>.json">
        <i class="fas fa-download"></i> JSON
      </a>
      <div class="vr mx-1"></div>
      <form method="POST" action="logout.php" style="margin:0;display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="Выйти из системы" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
          <i class="fas fa-sign-out-alt"></i>
        </button>
      </form>
    </div>
  </div>
</nav>

<main class="container py-4">
  <!-- Заголовок аккаунта -->
  <div class="account-header">
    <div class="d-flex align-items-center gap-3 mb-2">
      <div class="account-id">#<?= (int)$row['id'] ?></div>
      <button 
        type="button" 
        class="btn btn-sm btn-outline-warning favorite-btn" 
        data-account-id="<?= (int)$row['id'] ?>"
        title="Избранное"
        style="font-size: 1.2rem; padding: 0.5rem 0.75rem;"
      >
        <i class="far fa-star"></i>
      </button>
    </div>
    <div class="account-title">
      <?php if (!empty($row['first_name']) || !empty($row['last_name'])): ?>
        <?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?>
      <?php else: ?>
        <?= e($row['login'] ?? 'Аккаунт') ?>
      <?php endif; ?>
    </div>
    <div class="account-subtitle">
      <?= e($row['email'] ?? 'Без email') ?>
      <?php if (!empty($row['status'])): ?>
        • <span class="badge <?= getStatusClass($row['status']) ?> status-badge"><?= e($row['status']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="toolbar-view">
    <div class="d-flex align-items-center gap-2">
      <div style="width: 40px; height: 40px; border-radius: 12px; background: var(--primary-50); color: var(--primary); display: flex; align-items: center; justify-content: center;">
        <i class="fas fa-info-circle fs-5"></i>
      </div>
      <h5 class="mb-0 fw-bold" style="color: var(--gray-800);">Детальная информация</h5>
    </div>
    <div class="btn-group shadow-sm rounded-pill" role="group">
      <a href="history.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-white border px-3 rounded-start-pill text-gray-700 hover-bg-gray-50">
        <i class="fas fa-history me-1 text-gray-500"></i> История
      </a>
      <button class="btn btn-sm btn-white border-top border-bottom border-end-0 px-3 text-warning hover-bg-gray-50" id="duplicateAccountBtn" onclick="duplicateAccount()">
        <i class="fas fa-copy me-1"></i> Дублировать
      </button>
      <button class="btn btn-sm btn-white border px-3 text-success hover-bg-gray-50" onclick="copyAllPasswords()">
        <i class="fas fa-key me-1"></i> Все пароли
      </button>
      <button class="btn btn-sm btn-primary rounded-end-pill px-3 fw-semibold" onclick="copyAllData()">
        <i class="fas fa-clipboard-list me-1"></i> Копировать всё
      </button>
    </div>
  </div>

  <!-- Детали аккаунта -->
  <div class="details-card">
    <div class="p-0">
      <?php foreach ($ordered as $k): if (!array_key_exists($k, $row)) continue;
          $v = $row[$k];
          $isLong = in_array($k, $longLike, true) || (is_string($v) && strlen($v) > 200);
      ?>
        <div class="field-row">
          <div class="field-label">
            <i class="fas fa-<?= getFieldIcon($k) ?> me-2"></i>
            <?= getFieldTitle($k) ?>
          </div>
          <div class="field-value">
            <?php if ($v === null || $v === ''): ?>
              <span class="empty-value">—</span>
            <?php elseif ($k === 'id'): ?>
              <span class="fw-bold text-primary">#<?= (int)$v ?></span>
            <?php elseif ($k === 'email'): ?>
              <div class="d-flex align-items-center">
                <a href="mailto:<?= e($v) ?>" class="text-decoration-none">
                  <?= e($v) ?>
                </a>
                <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                  <i class="fas fa-copy me-1"></i>Копировать
                </button>
              </div>
            <?php elseif ($k === 'login'): ?>
              <div class="d-flex align-items-center">
                <span class="fw-semibold"><?= e((string)$v) ?></span>
                <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                  <i class="fas fa-copy me-1"></i>Копировать
                </button>
              </div>
                         <?php elseif ($k === 'password' || $k === 'email_password'): ?>
               <div class="pw-mask">
                 <span class="pw-dots">••••••••</span>
                 <span class="pw-text d-none mono"><?= e((string)$v) ?></span>
                 <button type="button" class="pw-toggle" title="Показать пароль">
                   <i class="fas fa-eye"></i>
                 </button>
                 <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать пароль">
                   <i class="fas fa-copy me-1"></i>Копировать
                 </button>
               </div>
            <?php elseif ($k === 'token'): ?>
              <div class="d-flex align-items-center">
                <span class="mono"><?= e((string)$v) ?></span>
                <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                  <i class="fas fa-copy me-1"></i>Копировать
                </button>
              </div>
            <?php elseif ($k === 'status'): ?>
              <span class="badge <?= getStatusClass($v) ?> status-badge"><?= e((string)$v) ?></span>
            <?php elseif ($k === 'social_url' && preg_match('~^https?://~i', $v)): ?>
              <div class="d-flex align-items-center">
                <a href="<?= e($v) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                  <i class="fas fa-external-link-alt me-2"></i><?= e($v) ?>
                </a>
                <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                  <i class="fas fa-copy me-1"></i>Копировать
                </button>
              </div>
            <?php elseif ($isLong): ?>
              <div class="mb-2">
                <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                  <i class="fas fa-copy me-1"></i>Копировать
                </button>
              </div>
              <pre class="mono"><?= e($v) ?></pre>
            <?php else: ?>
              <span><?= e((string)$v) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Подготовим JSON данных для копирования/скачивания
const data = <?= json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) ?>;

// ===== Основные функции =====
function copyToClipboard(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => {
      showToast('Скопировано в буфер обмена', 'success');
    }).catch(() => {
      fallbackCopyTextToClipboard(text);
    });
  } else {
    fallbackCopyTextToClipboard(text);
  }
}

function fallbackCopyTextToClipboard(text) {
  const textArea = document.createElement('textarea');
  textArea.value = String(text || '');
  // Для Firefox: элемент должен быть видимым, но можно сделать его очень маленьким
  textArea.style.position = 'fixed';
  textArea.style.top = '0';
  textArea.style.left = '0';
  textArea.style.width = '2px';
  textArea.style.height = '2px';
  textArea.style.padding = '0';
  textArea.style.border = 'none';
  textArea.style.outline = 'none';
  textArea.style.boxShadow = 'none';
  textArea.style.background = 'transparent';
  textArea.setAttribute('readonly', '');
  document.body.appendChild(textArea);
  
  // Для Firefox: используем setSelectionRange вместо select()
  textArea.focus();
  textArea.setSelectionRange(0, textArea.value.length);
  
  try {
    const successful = document.execCommand('copy');
    if (successful) {
      showToast('Скопировано в буфер обмена', 'success');
    } else {
      showToast('Ошибка копирования', 'error');
    }
  } catch (err) {
    showToast('Ошибка копирования', 'error');
  } finally {
    document.body.removeChild(textArea);
  }
}

function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0 position-fixed`;
  toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">
        <i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'} me-2"></i>
        ${message}
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  `;
  document.body.appendChild(toast);
  const bsToast = new bootstrap.Toast(toast);
  bsToast.show();
  toast.addEventListener('hidden.bs.toast', () => {
    document.body.removeChild(toast);
  });
}

function copyAllPasswords() {
  const passwords = [];
  if (data.password) passwords.push(`Пароль: ${data.password}`);
  if (data.email_password) passwords.push(`Email пароль: ${data.email_password}`);
  
  if (passwords.length === 0) {
    showToast('Пароли не найдены', 'error');
    return;
  }
  
  const text = passwords.join('\n');
  copyToClipboard(text);
}

function copyAllData() {
  const text = Object.entries(data)
    .filter(([key, value]) => value !== null && value !== '')
    .map(([key, value]) => `${key}: ${value}`)
    .join('\n');
  
  copyToClipboard(text);
}

// ===== Обработчики событий =====
document.addEventListener('DOMContentLoaded', function() {
  // Copy JSON button
  document.getElementById('copyJsonBtn').addEventListener('click', function() {
    const jsonText = JSON.stringify(data, null, 2);
    copyToClipboard(jsonText);
  });
  
  // Download JSON button
  const downloadBtn = document.getElementById('downloadJsonBtn');
  if (downloadBtn) {
    const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
    downloadBtn.href = URL.createObjectURL(blob);
  }
  
  // Обработчик для всех кнопок копирования (совместимость с Firefox)
  document.addEventListener('click', function(e) {
    const copyBtn = e.target.closest('.copy-btn');
    if (!copyBtn) return;
    
    // Получаем текст для копирования из data-атрибута или из ближайшего элемента
    let textToCopy = copyBtn.getAttribute('data-copy-text');
    
    // Если data-атрибут не задан, пытаемся найти значение из контекста
    if (!textToCopy) {
      // Для паролей - берем из .pw-text
      const pwMask = copyBtn.closest('.pw-mask');
      if (pwMask) {
        const pwText = pwMask.querySelector('.pw-text');
        if (pwText) {
          textToCopy = pwText.textContent || pwText.innerText || '';
        }
      }
      
      // Для других полей - берем из соседних элементов
      if (!textToCopy) {
        const parent = copyBtn.parentElement;
        if (parent) {
          const span = parent.querySelector('span.mono, span.fw-semibold');
          if (span) {
            textToCopy = span.textContent || span.innerText || '';
          }
          // Если это ссылка, берем текст ссылки
          const link = parent.querySelector('a');
          if (link && !textToCopy) {
            textToCopy = link.textContent || link.innerText || link.href.replace('mailto:', '');
          }
        }
      }
    }
    
    if (textToCopy) {
      copyToClipboard(textToCopy);
    }
  });
  
  // Password toggle
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.pw-toggle');
    if (!btn) return;
    
    const wrap = btn.closest('.pw-mask');
    const dots = wrap.querySelector('.pw-dots');
    const text = wrap.querySelector('.pw-text');
    const icon = btn.querySelector('i');
    
    if (text.classList.contains('d-none')) {
      text.classList.remove('d-none');
      dots.classList.add('d-none');
      icon.className = 'fas fa-eye-slash';
      btn.title = 'Скрыть пароль';
    } else {
      text.classList.add('d-none');
      dots.classList.remove('d-none');
      icon.className = 'fas fa-eye';
      btn.title = 'Показать пароль';
    }
  });
});

// Дублирование аккаунта
async function duplicateAccount() {
  const accountId = <?= (int)$row['id'] ?>;
  const csrfToken = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

  if (!confirm('Создать копию этого аккаунта?')) {
    return;
  }

  // Блокируем кнопку от повторных кликов
  const btn = document.getElementById('duplicateAccountBtn');
  if (btn) { btn.disabled = true; btn.classList.add('disabled'); }

  try {
    const response = await fetch('duplicate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({ id: accountId, csrf_token: csrfToken })
    });
    
    if (!response.ok) {
      throw new Error('Ошибка при копировании');
    }
    
    const data = await response.json();
    
    if (data.success) {
      if (typeof showToast === 'function') {
        showToast('Аккаунт успешно скопирован', 'success');
      }
      
      // Перенаправляем на новый аккаунт
      if (data.new_id) {
        setTimeout(() => {
          window.location.href = 'view.php?id=' + data.new_id;
        }, 1000);
      }
    } else {
      throw new Error(data.error || 'Ошибка при копировании');
    }
  } catch (error) {
    console.error('Duplicate error:', error);
    showToast('Ошибка при копировании аккаунта: ' + error.message, 'error');
  } finally {
    // Разблокируем кнопку
    if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }
  }
}
</script>
<script src="assets/js/favorites.js?v=<?= ASSETS_VERSION ?>"></script>
</body>
</html>

<?php
// Вспомогательные функции для иконок и заголовков полей
function getFieldIcon($field) {
    $icons = [
        'id' => 'hashtag',
        'login' => 'user',
        'password' => 'key',
        'email' => 'envelope',
        'email_password' => 'key',
        'first_name' => 'user',
        'last_name' => 'user',
        'social_url' => 'link',
        'birth_day' => 'calendar-day',
        'birth_month' => 'calendar-month',
        'birth_year' => 'calendar',
        'token' => 'shield-alt',
        'ads_id' => 'ad',
        'cookies' => 'cookie-bite',
        'user_agent' => 'desktop',
        'two_fa' => 'lock',
        'extra_info_1' => 'info-circle',
        'extra_info_2' => 'info-circle',
        'extra_info_3' => 'info-circle',
        'extra_info_4' => 'info-circle',
        'status' => 'flag',
    ];
    
    return $icons[$field] ?? 'circle';
}

function getFieldTitle($field) {
    $titles = [
        'id' => 'ID',
        'login' => 'Логин',
        'password' => 'Пароль',
        'email' => 'Email',
        'email_password' => 'Пароль Email',
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'social_url' => 'Соцсеть URL',
        'birth_day' => 'День рождения',
        'birth_month' => 'Месяц рождения',
        'birth_year' => 'Год рождения',
        'token' => 'Token',
        'ads_id' => 'Ads ID',
        'cookies' => 'Cookies',
        'user_agent' => 'User-Agent',
        'two_fa' => '2FA',
        'extra_info_1' => 'Дополнительная информация 1',
        'extra_info_2' => 'Дополнительная информация 2',
        'extra_info_3' => 'Дополнительная информация 3',
        'extra_info_4' => 'Дополнительная информация 4',
        'status' => 'Статус',
    ];
    
    return $titles[$field] ?? ucfirst(str_replace('_', ' ', $field));
}
?>

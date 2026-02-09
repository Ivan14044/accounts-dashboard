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
$service = new AccountsService();
$row = $service->getAccountById($id);

if (!$row) { http_response_code(404); exit('Not found'); }

// Упорядочим поля: сначала самые важные, затем остальные по алфавиту
$priority = ['id','login','email','first_name','last_name','status','password','email_password','birth_day','birth_month','birth_year','social_url','ads_id','user_agent','two_fa','token','cookies','extra_info_1','extra_info_2','extra_info_3','extra_info_4'];
$keys = array_keys($row);
$rest = array_values(array_diff($keys, $priority));
sort($rest, SORT_NATURAL|SORT_FLAG_CASE);
$ordered = array_values(array_unique(array_merge($priority, $rest)));

// Грубая попытка распознать "длинные" поля
$longLike = ['token','cookies','user_agent','extra_info_1','extra_info_2','extra_info_3','extra_info_4','social_url'];

// Определяем класс статуса для бейджа
function getStatusClass($status) {
    $status = strtolower($status);
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
  <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
  <style>
    :root {
      --bs-primary-rgb: 13, 110, 253;
      --bs-secondary-rgb: 108, 117, 125;
      --bs-success-rgb: 25, 135, 84;
      --bs-danger-rgb: 220, 53, 69;
      --bs-warning-rgb: 255, 193, 7;
      --bs-info-rgb: 13, 202, 240;
    }
    
    body { 
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
      min-height: 100vh;
    }
    
    .navbar {
      background: rgba(255, 255, 255, 0.95) !important;
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(0,0,0,0.1);
      box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    }
    
         .card {
       border: 1px solid #e9ecef;
       border-radius: 8px;
       box-shadow: 0 2px 8px rgba(0,0,0,0.06);
       transition: box-shadow 0.2s ease;
     }
     
     .card:hover {
       box-shadow: 0 4px 12px rgba(0,0,0,0.1);
     }
    
         .field-row {
       display: grid;
       grid-template-columns: 280px 1fr;
       gap: 1rem;
       padding: 1rem;
       border-bottom: 1px solid #f1f3f4;
       transition: background-color 0.2s ease;
     }
     
     .field-row:hover {
       background: rgba(13, 110, 253, 0.01);
     }
    
    .field-row:last-child {
      border-bottom: none;
    }
    
    .field-label {
      color: #6c757d;
      font-weight: 600;
      font-size: 0.875rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .field-value {
      word-break: break-word;
      line-height: 1.5;
    }
    
         .field-value .mono {
       font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
       background: #f8f9fa;
       padding: 0.5rem;
       border-radius: 8px;
       border: 1px solid #e9ecef;
       font-size: 0.875rem;
       white-space: pre-wrap;
       word-break: break-word;
       max-width: 100%;
       overflow-wrap: break-word;
     }
    
    .btn {
      border-radius: 12px;
      font-weight: 600;
      transition: all 0.3s ease;
      padding: 0.5rem 1.25rem;
    }
    
         .btn:hover {
       box-shadow: 0 2px 8px rgba(0,0,0,0.15);
     }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--bs-primary), #6366f1);
      border: none;
    }
    
    .btn-outline-primary {
      border: 2px solid var(--bs-primary);
      color: var(--bs-primary);
    }
    
    .btn-outline-primary:hover {
      background: linear-gradient(135deg, var(--bs-primary), #6366f1);
      border-color: transparent;
    }
    
    .copy-btn {
      border: none;
      background: rgba(13, 110, 253, 0.1);
      color: var(--bs-primary);
      padding: 0.375rem 0.75rem;
      border-radius: 8px;
      font-size: 0.875rem;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-left: 0.5rem;
    }
    
         .copy-btn:hover {
       background: rgba(13, 110, 253, 0.2);
     }
    
    .pw-mask {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .pw-dots {
      letter-spacing: 0.2em;
      font-weight: 600;
      color: #6c757d;
      font-size: 1.1rem;
    }
    
    .pw-toggle {
      border: none;
      background: transparent;
      padding: 0.5rem;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      color: #6c757d;
    }
    
    .pw-toggle:hover {
      background: rgba(0,0,0,0.1);
      color: var(--bs-primary);
    }
    
    .status-badge {
      font-size: 0.875rem;
      font-weight: 600;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .empty-value {
      color: #adb5bd;
      font-style: italic;
    }
    
         .account-header {
       background: #fff;
       border-radius: 8px;
       padding: 2rem;
       margin-bottom: 2rem;
       text-align: center;
       border: 1px solid #e9ecef;
       box-shadow: 0 2px 8px rgba(0,0,0,0.06);
     }
    
    .account-id {
      font-size: 3rem;
      font-weight: 700;
      background: linear-gradient(135deg, var(--bs-primary), #6366f1);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 1rem;
    }
    
    .account-title {
      font-size: 1.5rem;
      font-weight: 600;
      color: #495057;
      margin-bottom: 0.5rem;
    }
    
    .account-subtitle {
      color: #6c757d;
      font-size: 1.1rem;
    }
    
         .toolbar {
       background: white;
       border-radius: 8px;
       padding: 1.5rem;
       margin-bottom: 2rem;
       border: 1px solid #e9ecef;
       box-shadow: 0 2px 8px rgba(0,0,0,0.06);
     }
    
    .toolbar .btn-group {
      gap: 0.5rem;
    }
    
    @media (max-width: 768px) {
      .field-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
      }
      
      .field-label {
        font-size: 0.8rem;
      }
      
      .account-header {
        padding: 1.5rem;
      }
      
      .account-id {
        font-size: 2rem;
      }
      
      .toolbar {
        padding: 1rem;
      }
      
      .toolbar .btn-group {
        flex-direction: column;
        width: 100%;
      }
      
      .toolbar .btn {
        width: 100%;
        margin-bottom: 0.5rem;
      }
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
      <i class="fas fa-chart-line text-primary"></i>
      Dashboard
    </a>
         <div class="ms-auto d-flex gap-2 align-items-center">
       <span class="text-muted me-3">
         <i class="fas fa-user me-1"></i><?= htmlspecialchars(getCurrentUser()) ?>
       </span>
       <button class="btn btn-outline-secondary" id="copyJsonBtn">
         <i class="fas fa-copy me-2"></i>Скопировать JSON
       </button>
       <a class="btn btn-primary" id="downloadJsonBtn" download="account_<?= (int)$row['id'] ?>.json">
         <i class="fas fa-download me-2"></i>Скачать JSON
       </a>
       <a class="btn btn-outline-danger" href="logout.php" title="Выйти из системы">
         <i class="fas fa-sign-out-alt"></i>
       </a>
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
  <div class="toolbar">
    <div class="row align-items-center">
      <div class="col-md-6">
        <h5 class="mb-0">
          <i class="fas fa-info-circle me-2 text-primary"></i>
          Детальная информация
        </h5>
      </div>
      <div class="col-md-6 text-end">
        <div class="btn-group" role="group">
          <a href="history.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline-secondary">
            <i class="fas fa-history me-2"></i>История
          </a>
          <button class="btn btn-outline-warning" id="duplicateAccountBtn" onclick="duplicateAccount()">
            <i class="fas fa-copy me-2"></i>Дублировать
          </button>
          <button class="btn btn-outline-success" onclick="copyAllPasswords()">
            <i class="fas fa-key me-2"></i>Копировать пароли
          </button>
          <button class="btn btn-outline-info" onclick="copyAllData()">
            <i class="fas fa-copy me-2"></i>Копировать всё
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Детали аккаунта -->
  <div class="card">
    <div class="card-body p-0">
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
  
  if (!confirm('Создать копию этого аккаунта?')) {
    return;
  }
  
  try {
    const response = await fetch('duplicate.php?id=' + accountId, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
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
    if (typeof showToast === 'function') {
      showToast('Ошибка при копировании аккаунта: ' + error.message, 'error');
    } else {
      alert('Ошибка при копировании аккаунта: ' + error.message);
    }
  }
}
</script>
<script src="assets/js/favorites.js?v=<?= time() ?>"></script>
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

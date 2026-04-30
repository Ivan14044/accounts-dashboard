<?php
/**
 * log.php — страница просмотра логов системы и истории действий пользователей
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/AuditLogger.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Validator.php';

// Проверяем авторизацию
requireAuth();
checkSessionTimeout();

// Определяем тип логов (системные или действия пользователей)
$logType = $_GET['type'] ?? 'system'; // 'system' или 'actions'

// Обработка действия очистки логов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'clear') {
    header('Content-Type: application/json');

    // CSRF защита (токен из POST body или из заголовка)
    $postToken = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    try {
        Validator::validateCsrfToken($postToken);
    } catch (Throwable $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
        exit;
    }

    // Строгая валидация даты — только формат YYYY-MM-DD и настоящая дата.
    // Это защита от path traversal ("../users") и null-byte injection.
    $clearDate = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$clearDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid date format']);
        exit;
    }
    $parts = explode('-', $clearDate);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid date']);
        exit;
    }

    Logger::ensureLogDir();
    $logDir = Logger::getLogDir();
    $logFile = $logDir . '/' . $clearDate . '.log';

    // Двойная защита: realpath должен остаться внутри $logDir.
    $realLogDir = realpath($logDir);
    $realLogFile = realpath($logFile);
    if ($realLogDir === false || $realLogFile === false ||
        strpos($realLogFile, $realLogDir . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid log path']);
        exit;
    }

    if (file_exists($realLogFile)) {
        @unlink($realLogFile);
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Файл не найден']);
        exit;
    }
}

// Получаем параметры
$date = $_GET['date'] ?? date('Y-m-d');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
$level = $_GET['level'] ?? null;
$search = $_GET['search'] ?? '';
$accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$changedBy = $_GET['changed_by'] ?? '';

// Валидация
$limit = max(100, min(5000, $limit)); // От 100 до 5000
if ($level !== null && !in_array($level, ['DEBUG', 'INFO', 'WARNING', 'ERROR'], true)) {
    $level = null;
}

// Получаем логи в зависимости от типа
$logs = [];
$availableDates = [];
$auditLogs = [];
$totalAuditCount = 0;

if ($logType === 'actions') {
    // Получаем историю действий пользователей (audit log)
    require_once __DIR__ . '/includes/Database.php';
    $mysqli = Database::getInstance()->getConnection();
    $auditLogger = AuditLogger::getInstance();
    
    // Формируем SQL запрос с фильтрами
    $whereConditions = [];
    $params = [];
    $types = '';
    
    if ($accountId > 0) {
        $whereConditions[] = "account_id = ?";
        $params[] = $accountId;
        $types .= 'i';
    }
    
    if (!empty($changedBy)) {
        $whereConditions[] = "changed_by LIKE ?";
        $params[] = "%{$changedBy}%";
        $types .= 's';
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(field_name LIKE ? OR old_value LIKE ? OR new_value LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }
    
    // Фильтр по дате
    $dateFrom = $date . ' 00:00:00';
    $dateTo = $date . ' 23:59:59';
    $whereConditions[] = "changed_at >= ? AND changed_at <= ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;
    $types .= 'ss';
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Получаем общее количество
    $countSql = "SELECT COUNT(*) as total FROM account_history $whereClause";
    $countStmt = $mysqli->prepare($countSql);
    if ($countStmt && !empty($params)) {
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalAuditCount = $countResult->fetch_assoc()['total'] ?? 0;
        $countStmt->close();
    } elseif ($countStmt) {
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalAuditCount = $countResult->fetch_assoc()['total'] ?? 0;
        $countStmt->close();
    }
    
    // Получаем записи
    $sql = "SELECT * FROM account_history $whereClause ORDER BY changed_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';
    
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $auditLogs[] = $row;
        }
        $stmt->close();
    }
    
    // Получаем доступные даты из audit log
    $datesSql = "SELECT DISTINCT DATE(changed_at) as log_date FROM account_history ORDER BY log_date DESC LIMIT 30";
    $datesResult = $mysqli->query($datesSql);
    if ($datesResult) {
        while ($row = $datesResult->fetch_assoc()) {
            $availableDates[] = $row['log_date'];
        }
    }
} else {
    // Получаем системные логи
    $logs = Logger::getLogs($date, $limit, $level);
    $availableDates = Logger::getAvailableDates();
    
    // Фильтрация по поиску
    if (!empty($search)) {
        $logs = array_filter($logs, function($line) use ($search) {
            return stripos($line, $search) !== false;
        });
    }
}

// Функция для экранирования
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Функция для выделения уровня в логе
function highlightLevel($line) {
    $levels = [
        'ERROR' => '<span class="log-level log-error">[ERROR]</span>',
        'WARNING' => '<span class="log-level log-warning">[WARNING]</span>',
        'INFO' => '<span class="log-level log-info">[INFO]</span>',
        'DEBUG' => '<span class="log-level log-debug">[DEBUG]</span>',
    ];
    
    foreach ($levels as $level => $replacement) {
        $line = str_replace("[$level]", $replacement, $line);
    }
    
    return $line;
}
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Логи системы - Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="assets/css/core-theme.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/core-mobile.css?v=<?= time() ?>" rel="stylesheet">

  <style>
    .log-container {
      background: var(--bg-primary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: var(--space-4);
      margin-top: var(--space-4);
      max-height: 70vh;
      overflow-y: auto;
      font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
      font-size: 0.875rem;
      line-height: 1.6;
    }
    
    .log-line {
      padding: var(--space-1) var(--space-2);
      border-bottom: 1px solid var(--border-light);
      word-break: break-all;
    }
    
    .log-line:hover {
      background: var(--bg-secondary);
    }
    
    .log-level {
      font-weight: 600;
      padding: 2px 6px;
      border-radius: 4px;
      display: inline-block;
      margin-right: 8px;
    }
    
    .log-error {
      background: rgba(220, 38, 38, 0.2);
      color: #dc2626;
    }
    
    .log-warning {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .log-info {
      background: rgba(37, 99, 235, 0.2);
      color: #2563eb;
    }
    
    .log-debug {
      background: rgba(107, 114, 128, 0.2);
      color: #6b7280;
    }
    
    .filters-panel {
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      padding: var(--space-4);
      margin-bottom: var(--space-4);
    }
    
    .stats-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: var(--radius-sm);
      font-size: 0.875rem;
      font-weight: 500;
      margin-left: var(--space-2);
    }
    
    .stats-error { background: rgba(220, 38, 38, 0.1); color: #dc2626; }
    .stats-warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
    .stats-info { background: rgba(37, 99, 235, 0.1); color: #2563eb; }
    .stats-debug { background: rgba(107, 114, 128, 0.1); color: #6b7280; }
  </style>
</head>
<body>
  <div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h3 mb-0">
        <i class="fas fa-file-alt me-2"></i>
        Логи системы
      </h1>
      <a href="index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-2"></i>
        Назад к дашборду
      </a>
    </div>
    
    <!-- Вкладки для переключения между типами логов -->
    <ul class="nav nav-tabs mb-4" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $logType === 'system' ? 'active' : '' ?>" 
                onclick="location.href='?type=system&date=<?= e($date) ?>&limit=<?= $limit ?>'" 
                type="button">
          <i class="fas fa-server me-2"></i>
          Системные логи
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $logType === 'actions' ? 'active' : '' ?>" 
                onclick="location.href='?type=actions&date=<?= e($date) ?>&limit=<?= $limit ?>'" 
                type="button">
          <i class="fas fa-history me-2"></i>
          История действий
        </button>
      </li>
    </ul>
    
    <!-- Панель фильтров -->
    <div class="filters-panel">
      <form method="get" class="row g-3">
        <input type="hidden" name="type" value="<?= e($logType) ?>">
        
        <div class="col-md-2">
          <label class="form-label">Дата</label>
          <select name="date" class="form-select">
            <?php if (empty($availableDates)): ?>
              <option value="<?= e($date) ?>"><?= e($date) ?></option>
            <?php else: ?>
              <?php foreach ($availableDates as $availableDate): ?>
                <option value="<?= e($availableDate) ?>" <?= $date === $availableDate ? 'selected' : '' ?>>
                  <?= e($availableDate) ?>
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        
        <?php if ($logType === 'system'): ?>
          <div class="col-md-2">
            <label class="form-label">Уровень</label>
            <select name="level" class="form-select">
              <option value="">Все уровни</option>
              <option value="ERROR" <?= $level === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
              <option value="WARNING" <?= $level === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
              <option value="INFO" <?= $level === 'INFO' ? 'selected' : '' ?>>INFO</option>
              <option value="DEBUG" <?= $level === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
            </select>
          </div>
        <?php else: ?>
          <div class="col-md-2">
            <label class="form-label">ID аккаунта</label>
            <input type="number" name="account_id" class="form-control" 
                   placeholder="Фильтр по ID" value="<?= $accountId > 0 ? $accountId : '' ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Пользователь</label>
            <input type="text" name="changed_by" class="form-control" 
                   placeholder="Фильтр по пользователю" value="<?= e($changedBy) ?>">
          </div>
        <?php endif; ?>
        
        <div class="col-md-2">
          <label class="form-label">Лимит строк</label>
          <select name="limit" class="form-select">
            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
            <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
            <option value="1000" <?= $limit === 1000 ? 'selected' : '' ?>>1000</option>
            <option value="2000" <?= $limit === 2000 ? 'selected' : '' ?>>2000</option>
            <option value="5000" <?= $limit === 5000 ? 'selected' : '' ?>>5000</option>
          </select>
        </div>
        
        <div class="col-md-3">
          <label class="form-label">Поиск</label>
          <input type="text" name="search" class="form-control" 
                 placeholder="Поиск в логах..." value="<?= e($search) ?>">
        </div>
        
        <div class="col-md-1 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">
            <i class="fas fa-filter me-2"></i>
            Применить
          </button>
        </div>
      </form>
      
      <!-- Статистика -->
      <div class="mt-3">
        <strong>Статистика:</strong>
        <?php if ($logType === 'system'): ?>
          <?php
          $errorCount = 0;
          $warningCount = 0;
          $infoCount = 0;
          $debugCount = 0;
          
          foreach ($logs as $log) {
            if (strpos($log, '[ERROR]') !== false) $errorCount++;
            elseif (strpos($log, '[WARNING]') !== false) $warningCount++;
            elseif (strpos($log, '[INFO]') !== false) $infoCount++;
            elseif (strpos($log, '[DEBUG]') !== false) $debugCount++;
          }
          ?>
          <span class="stats-badge stats-error">ERROR: <?= $errorCount ?></span>
          <span class="stats-badge stats-warning">WARNING: <?= $warningCount ?></span>
          <span class="stats-badge stats-info">INFO: <?= $infoCount ?></span>
          <span class="stats-badge stats-debug">DEBUG: <?= $debugCount ?></span>
          <span class="stats-badge">Всего: <?= count($logs) ?></span>
        <?php else: ?>
          <span class="stats-badge stats-info">Всего записей: <?= $totalAuditCount ?></span>
          <span class="stats-badge">Показано: <?= count($auditLogs) ?></span>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Логи -->
    <div class="log-container">
      <?php if ($logType === 'system'): ?>
        <?php if (empty($logs)): ?>
          <div class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <p>Логи не найдены для выбранных параметров</p>
          </div>
        <?php else: ?>
          <?php foreach ($logs as $log): ?>
            <div class="log-line">
              <?= highlightLevel(e($log)) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php else: ?>
        <?php if (empty($auditLogs)): ?>
          <div class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <p>История действий не найдена для выбранных параметров</p>
          </div>
        <?php else: ?>
          <?php foreach ($auditLogs as $item): ?>
            <div class="log-line" style="padding: 1rem; border-left: 3px solid #0d6efd; margin-bottom: 0.5rem;">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                  <strong class="text-primary">Аккаунт #<?= e($item['account_id']) ?></strong>
                  <span class="badge bg-secondary ms-2"><?= e($item['field_name']) ?></span>
                  <small class="text-muted ms-2">
                    <i class="fas fa-user me-1"></i>
                    <?= e($item['changed_by']) ?>
                  </small>
                  <?php if (!empty($item['ip_address'])): ?>
                    <small class="text-muted ms-2">
                      <i class="fas fa-network-wired me-1"></i>
                      <?= e($item['ip_address']) ?>
                    </small>
                  <?php endif; ?>
                </div>
                <small class="text-muted">
                  <?= date('d.m.Y H:i:s', strtotime($item['changed_at'])) ?>
                </small>
              </div>
              
              <div class="row g-2">
                <?php if (!empty($item['old_value'])): ?>
                  <div class="col-md-6">
                    <div style="background: #fff3cd; padding: 0.5rem; border-radius: 4px;">
                      <small class="text-muted d-block mb-1"><strong>Было:</strong></small>
                      <code style="word-break: break-all; white-space: pre-wrap;"><?= e($item['old_value']) ?></code>
                    </div>
                  </div>
                <?php endif; ?>
                
                <?php if (!empty($item['new_value'])): ?>
                  <div class="col-md-6">
                    <div style="background: #d1e7dd; padding: 0.5rem; border-radius: 4px;">
                      <small class="text-muted d-block mb-1"><strong>Стало:</strong></small>
                      <code style="word-break: break-all; white-space: pre-wrap;"><?= e($item['new_value']) ?></code>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
              
              <div class="mt-2">
                <a href="view.php?id=<?= $item['account_id'] ?>" class="btn btn-sm btn-outline-primary">
                  <i class="fas fa-eye me-1"></i>
                  Просмотр аккаунта
                </a>
                <a href="history.php?id=<?= $item['account_id'] ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="fas fa-history me-1"></i>
                  История аккаунта
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    
    <!-- Кнопка обновления -->
    <div class="mt-3 text-center">
      <button onclick="location.reload()" class="btn btn-outline-primary">
        <i class="fas fa-sync-alt me-2"></i>
        Обновить
      </button>
      <?php if ($logType === 'system'): ?>
        <button onclick="clearLogs()" class="btn btn-outline-danger ms-2">
          <i class="fas fa-trash me-2"></i>
          Очистить логи за сегодня
        </button>
      <?php endif; ?>
    </div>
  </div>
  
  <script>
    // Автообновление каждые 10 секунд
    let autoRefresh = false;
    
    function toggleAutoRefresh() {
      autoRefresh = !autoRefresh;
      if (autoRefresh) {
        setInterval(() => {
          location.reload();
        }, 10000);
      }
    }
    
    function clearLogs() {
      if (confirm('Вы уверены, что хотите очистить логи за сегодня?')) {
        const csrf = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const body = new URLSearchParams({ csrf: csrf });
        fetch('log.php?action=clear&date=<?= date('Y-m-d') ?>', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrf
          },
          body: body.toString()
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            location.reload();
          } else {
            alert('Ошибка при очистке логов: ' + (data.error || 'Неизвестная ошибка'));
          }
        })
        .catch(error => {
          alert('Ошибка: ' + error.message);
        });
      }
    }
    
    // Прокрутка вниз при загрузке
    window.addEventListener('load', () => {
      const container = document.querySelector('.log-container');
      if (container) {
        container.scrollTop = 0; // Показываем новые логи сверху
      }
    });
  </script>
</body>
</html>


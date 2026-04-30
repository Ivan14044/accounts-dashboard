<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Корзина - Dashboard</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- CSS Bundles -->
  <link href="assets/css/core-base.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-components.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-plugins.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-theme.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-tables.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-mobile.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">

  <style>
    /* Премиальный заголовок для страницы Корзины */
    .trash-header {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(185, 28, 28, 0.15) 100%);
      border: 1px solid rgba(239, 68, 68, 0.3);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      color: var(--danger-700);
      padding: var(--space-6) var(--space-8);
      border-radius: var(--radius-2xl);
      margin-bottom: var(--space-6);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: var(--space-4);
      box-shadow: 
        0 4px 6px -1px rgba(239, 68, 68, 0.05),
        0 10px 15px -3px rgba(239, 68, 68, 0.05),
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }
    .trash-header-main {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }
    .trash-icon-wrap {
      width: 64px;
      height: 64px;
      background: linear-gradient(135deg, var(--danger-500), var(--danger-700));
      border-radius: var(--radius-xl);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 8px 16px rgba(239, 68, 68, 0.3);
    }
    .trash-icon-wrap i {
      font-size: 2rem;
      color: white;
    }
    .trash-header h1 {
      margin: 0;
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: -0.02em;
    }
    .trash-subtitle {
      font-size: 0.875rem;
      opacity: 0.8;
      margin-top: 4px;
    }
    .trash-count {
      font-size: 1.125rem;
      font-weight: 500;
      background: rgba(255, 255, 255, 0.6);
      padding: var(--space-2) var(--space-4);
      border-radius: var(--radius-full);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }
    .trash-count strong {
      color: var(--danger-700);
      font-weight: 700;
      font-size: 1.25rem;
    }
    
    .trash-warning {
      background: rgba(254, 243, 199, 0.5); /* amber-50 / 50% */
      backdrop-filter: blur(8px);
      border: 1px solid rgba(245, 158, 11, 0.3);
      border-radius: var(--radius-xl);
      padding: var(--space-4) var(--space-5);
      margin-bottom: var(--space-6);
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      color: var(--warning-800);
      box-shadow: var(--shadow-sm);
    }
  </style>
</head>
<body class="bg-light">

  <!-- Навигация -->
  <nav class="navbar navbar-expand bg-white border-bottom shadow-sm mb-4" style="height: 64px;">
    <div class="container-fluid px-4">
      <a class="navbar-brand fw-bold" href="index.php">
        <i class="fas fa-chart-line text-primary me-2"></i>
        Dashboard
      </a>
      <div class="d-flex align-items-center gap-3">
        <span class="text-muted small fw-medium">
          <i class="fas fa-user-circle me-1 text-primary"></i>
          <?php 
          $username = 'Пользователь';
          if (function_exists('getCurrentUser')) {
              try {
                  $username = getCurrentUser();
              } catch (Exception $e) {
                  $username = $_SESSION['username'] ?? 'Пользователь';
              }
          } else {
              $username = $_SESSION['username'] ?? 'Пользователь';
          }
          echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
          ?>
        </span>
        <div class="vr mx-1"></div>
        <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill">
          <i class="fas fa-arrow-left me-1"></i> Назад
        </a>
        <form method="POST" action="logout.php" style="margin:0;display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="Выйти из системы" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
            <i class="fas fa-sign-out-alt"></i>
          </button>
        </form>
      </div>
    </div>
  </nav>

  <!-- Основной контент -->
  <main class="container-fluid px-4 pb-5">
    
    <?php if (isset($errorMessage)): ?>
    <div class="alert alert-danger shadow-sm rounded-xl" role="alert">
      <i class="fas fa-exclamation-circle me-2"></i>
      <strong>Ошибка:</strong> <?= htmlspecialchars($errorMessage) ?>
    </div>
    <?php endif; ?>
    
    <!-- Заголовок корзины -->
    <div class="trash-header">
      <div class="trash-header-main">
        <div class="trash-icon-wrap">
          <i class="fas fa-trash-alt"></i>
        </div>
        <div>
          <h1>Корзина</h1>
          <div class="trash-subtitle">Хранилище удалённых аккаунтов (Soft Delete)</div>
        </div>
      </div>
      <div class="trash-count">
        Всего записей: <strong><?= number_format(isset($deletedCount) ? $deletedCount : 0) ?></strong>
      </div>
    </div>
    
    <!-- Предупреждение -->
    <div class="trash-warning">
      <i class="fas fa-exclamation-triangle fs-5 text-warning mt-1"></i>
      <div>
        <strong>Внимание!</strong> В корзине отображаются аккаунты, которые были удалены, но всё ещё хранятся в базе данных. 
        Вы можете <strong><span class="text-success">восстановить</span></strong> их обратно или <strong><span class="text-danger">удалить навсегда</span></strong>.
      </div>
    </div>

    <!-- Поиск и Фильтр -->
    <div class="card card-modern mb-4">
      <div class="card-body p-4">
        <form method="get" action="trash.php" id="trashSearchForm">
          <div class="row g-3 align-items-end">
            <div class="col-md-5">
              <label class="form-label text-muted small fw-semibold mb-1">Поиск по корзине</label>
              <div class="position-relative">
                <i class="fas fa-search position-absolute text-muted" style="top: 50%; left: 16px; transform: translateY(-50%);"></i>
                <input 
                  type="search" 
                  name="q" 
                  class="form-control" 
                  placeholder="Логин, email, ID..." 
                  value="<?= htmlspecialchars(isset($q) ? $q : '', ENT_QUOTES, 'UTF-8') ?>"
                  style="padding-left: 40px; border-radius: var(--radius-lg);"
                >
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label text-muted small fw-semibold mb-1">Сортировка</label>
              <select name="sort" class="form-select" style="border-radius: var(--radius-lg);">
                <option value="id" <?= ($sort ?? 'deleted_at') === 'id' ? 'selected' : '' ?>>По ID аккаунта</option>
                <option value="login" <?= ($sort ?? 'deleted_at') === 'login' ? 'selected' : '' ?>>По логину (А-Я)</option>
                <option value="email" <?= ($sort ?? 'deleted_at') === 'email' ? 'selected' : '' ?>>По Email (А-Я)</option>
                <option value="deleted_at" <?= ($sort ?? 'deleted_at') === 'deleted_at' ? 'selected' : '' ?>>По дате удаления (сначала новые)</option>
              </select>
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-primary w-100" style="border-radius: var(--radius-lg);">
                <i class="fas fa-filter me-2"></i> Применить
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php if (isset($deletedCount) && $deletedCount > 0): ?>
    
    <!-- Панель инструментов (Массовые действия) -->
    <div class="toolbar-modern mb-4">
      <div class="d-flex align-items-center gap-3">
        <div class="toolbar-modern-title d-flex align-items-center gap-2">
          <i class="fas fa-tasks text-muted"></i> Опции корзины
        </div>
        <div class="vr"></div>
        <span class="text-muted small">Выбрано аккаунтов: <strong id="selectedCount" class="text-primary fs-6">0</strong></span>
      </div>
      <div class="toolbar-modern-actions">
        <button class="btn btn-sm btn-outline-success rounded-pill px-3" id="restoreSelectedBtn" disabled>
          <i class="fas fa-undo me-1"></i> Восстановить выбранное
        </button>
        <button class="btn btn-sm btn-outline-danger rounded-pill px-3" id="deletePermanentlyBtn" disabled>
          <i class="fas fa-minus-circle me-1"></i> Удалить навсегда
        </button>
        <div class="vr mx-1"></div>
        <button class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm" id="emptyTrashBtn">
          <i class="fas fa-dumpster-fire me-1"></i> Очистить корзину полностью
        </button>
      </div>
    </div>

    <!-- Таблица (Premium Glassmorphism) -->
    <div class="dashboard-table">
      <div class="dashboard-table__inner">
        <div class="dashboard-table__scroll">
          <table class="ac-table" id="trashTable">
            <thead>
              <tr>
                <th class="ac-cell--checkbox text-center" style="width: 50px;">
                  <div class="form-check justify-content-center m-0">
                    <input class="form-check-input" type="checkbox" id="selectAllTrash" title="Выбрать все">
                  </div>
                </th>
                <th>ID</th>
                <th>Логин</th>
                <th>Email</th>
                <th>Статус</th>
                <th>Дата удаления</th>
                <th class="ac-cell--actions text-center">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $r): ?>
                  <tr data-id="<?= (int)$r['id'] ?>">
                    <td class="ac-cell--checkbox text-center">
                      <div class="form-check justify-content-center m-0">
                        <input class="form-check-input trash-checkbox" type="checkbox" value="<?= (int)$r['id'] ?>">
                      </div>
                    </td>
                    <td class="fw-bold text-muted">#<?= (int)$r['id'] ?></td>
                    <td class="fw-medium text-dark"><?= htmlspecialchars(isset($r['login']) ? $r['login'] : '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="text-muted"><i class="fas fa-envelope me-2 opacity-50"></i><?= htmlspecialchars(isset($r['email']) ? $r['email'] : '', ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td>
                      <?php if (!empty($r['status'])): ?>
                        <span class="badge bg-secondary px-3 py-2 rounded-pill shadow-sm"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php else: ?>
                        <span class="badge badge-empty-status px-3 py-2 rounded-pill shadow-sm">Пустой статус</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['deleted_at'])): ?>
                        <span class="text-danger fw-medium" style="font-size: 0.8125rem;">
                          <i class="fas fa-clock me-1 opacity-50"></i>
                          <?= date('d.m.Y H:i', strtotime($r['deleted_at'])) ?>
                        </span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="ac-cell--actions text-center">
                      <div class="btn-group btn-group-sm rounded-pill shadow-sm">
                        <button type="button" class="btn btn-outline-success restore-btn border-end-0" data-id="<?= (int)$r['id'] ?>" title="Восстановить">
                          <i class="fas fa-undo"></i> Восстановить
                        </button>
                        <button type="button" class="btn btn-outline-danger delete-permanent-btn border-start-0" data-id="<?= (int)$r['id'] ?>" title="Удалить навсегда">
                          <i class="fas fa-times"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <!-- This shouldn't be reached due to empty check above, but keeping for safety -->
                <tr>
                  <td colspan="7">
                    <div class="empty-state border-0 shadow-none my-2">
                       <h3 class="empty-state-title">Нет совпадений</h3>
                       <p class="empty-state-desc">По вашему запросу ничего не найдено в корзине.</p>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <?php if (isset($pages) && $pages > 1): ?>
        <div class="dashboard-table__footer">
          <div class="dashboard-table__counter">
            Страница <span class="dashboard-table__counter-value"><?= $page ?? 1 ?></span> из <?= $pages ?>
          </div>
          <nav>
            <ul class="pagination pagination-modern m-0">
              <?php
              $baseUrl = 'trash.php?' . http_build_query(array_merge($_GET, ['page' => '']));
              for ($i = 1; $i <= $pages; $i++):
              ?>
                <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
                  <a class="page-link" href="<?= $baseUrl . $i ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    </div>
    
    <?php else: ?>
    
    <!-- Пустая корзина -->
    <div class="empty-state">
      <i class="fas fa-dumpster empty-state-icon" style="background: linear-gradient(135deg, var(--gray-300), var(--gray-500)); -webkit-background-clip: text;"></i>
      <h3 class="empty-state-title" style="font-size: 1.5rem;">Корзина пуста</h3>
      <p class="empty-state-desc" style="font-size: 1rem;">Здесь будут храниться удаленные аккаунты. В данный момент корзина абсолютно чиста.</p>
      <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-2">
        <i class="fas fa-arrow-left me-2"></i> Вернуться к дашборду
      </a>
    </div>
    
    <?php endif; ?>
    
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/toast.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
  <script>
    window.DashboardConfig = window.DashboardConfig || {};
    window.DashboardConfig.csrfToken = <?= json_encode((string)getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
    // На странице корзины не подключается dashboard-init.js, поэтому
    // даём минимальное определение getTableAwareUrl — иначе trash.js падает
    // с "window.getTableAwareUrl is not a function" при empty/restore.
    if (typeof window.getTableAwareUrl !== 'function') {
      window.getTableAwareUrl = function (url) {
        var table = (window.DashboardConfig && window.DashboardConfig.currentTable) ||
                    (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.currentTable) || '';
        if (!table || table === 'accounts') return url;
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        return url + sep + 'table=' + encodeURIComponent(table);
      };
    }
  </script>
  <script src="assets/js/trash.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
</body>
</html>

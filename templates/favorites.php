<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Избранные аккаунты - Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- CSS Bundles -->
  <link href="assets/css/core-base.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-components.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-plugins.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-theme.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
  <link href="assets/css/core-tables.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">

  <style>
    /* Премиальный заголовок для страницы Избранного */
    .favorites-header {
      background: linear-gradient(135deg, rgba(251, 191, 36, 0.15) 0%, rgba(245, 158, 11, 0.15) 100%);
      border: 1px solid rgba(245, 158, 11, 0.3);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      color: var(--warning-700);
      padding: var(--space-6) var(--space-8);
      border-radius: var(--radius-2xl);
      margin-bottom: var(--space-6);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: var(--space-4);
      box-shadow: 
        0 4px 6px -1px rgba(245, 158, 11, 0.05),
        0 10px 15px -3px rgba(245, 158, 11, 0.05),
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }
    .favorites-header h1 {
      margin: 0;
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    .favorites-header h1 i {
      color: var(--warning-500);
      filter: drop-shadow(0 2px 4px rgba(245, 158, 11, 0.3));
    }
    .favorites-count {
      font-size: 1.125rem;
      font-weight: 500;
      background: rgba(255, 255, 255, 0.5);
      padding: var(--space-2) var(--space-4);
      border-radius: var(--radius-full);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }
    .favorites-count strong {
      color: var(--warning-600);
      font-weight: 700;
    }
  </style>
</head>
<body class="favorites-page bg-light">

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
    
    <!-- Заголовок -->
    <div class="favorites-header">
      <h1>
        <i class="fas fa-star"></i>
        Избранные аккаунты
      </h1>
      <div class="favorites-count">
        Всего: <strong><?= number_format($filteredTotal) ?></strong>
      </div>
    </div>
    
    <?php if (isset($errorMessage)): ?>
    <div class="alert alert-danger shadow-sm rounded-xl">
      <i class="fas fa-exclamation-triangle me-2"></i>
      <?= htmlspecialchars($errorMessage) ?>
    </div>
    <?php endif; ?>
    
    <?php if (empty($rows)): ?>
    <div class="empty-state">
      <i class="fas fa-star empty-state-icon" style="background: linear-gradient(135deg, var(--warning-300), var(--warning-500)); -webkit-background-clip: text;"></i>
      <h3 class="empty-state-title">Нет избранных аккаунтов</h3>
      <p class="empty-state-desc">У вас пока нет любимых аккаунтов. Добавьте их в избранное, нажав на звездочку в главной таблице дашборда.</p>
      <a href="index.php" class="btn btn-primary rounded-pill">
        <i class="fas fa-arrow-left me-2"></i> Вернуться к дашборду
      </a>
    </div>
    <?php else: ?>
    
    <!-- Поиск -->
    <div class="card card-modern mb-4">
      <div class="card-body p-3">
        <form method="get" class="d-flex gap-2">
          <div class="flex-grow-1 position-relative">
            <i class="fas fa-search position-absolute text-muted" style="top: 50%; left: 16px; transform: translateY(-50%);"></i>
            <input 
              type="search" 
              name="q" 
              class="form-control" 
              placeholder="Поиск по логину, email, имени..." 
              value="<?= htmlspecialchars($q) ?>"
              style="padding-left: 40px; border-radius: var(--radius-lg);"
            >
          </div>
          <button type="submit" class="btn btn-primary" style="border-radius: var(--radius-lg); padding: 0 24px;">
            Найти
          </button>
          <?php if ($q !== ''): ?>
          <a href="favorites.php" class="btn btn-outline-secondary" title="Сбросить поиск" style="border-radius: var(--radius-lg);">
            <i class="fas fa-times"></i>
          </a>
          <?php endif; ?>
        </form>
      </div>
    </div>
    
    <!-- Таблица (Premium Glassmorphism) -->
    <div class="dashboard-table">
      <div class="dashboard-table__inner">
        <div class="dashboard-table__scroll" style="max-height: 60vh;">
          <table class="ac-table" id="accountsTable">
            <thead>
              <tr>
                <th style="min-width: 80px;">ID</th>
                <th class="text-center ac-cell--checkbox" style="width:60px;" title="Избранное"><i class="fas fa-star text-warning"></i></th>
                <th style="min-width: 150px;">Логин</th>
                <th style="min-width: 200px;">Email</th>
                <th style="min-width: 120px;">Имя</th>
                <th style="min-width: 120px;">Фамилия</th>
                <th style="min-width: 140px;">Статус</th>
                <th class="ac-cell--actions text-center">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr data-id="<?= (int)$r['id'] ?>">
                <td class="fw-bold text-primary">#<?= (int)$r['id'] ?></td>
                <td class="favorite-cell text-center ac-cell--checkbox" data-account-id="<?= (int)$r['id'] ?>">
                  <button
                    type="button"
                    class="btn btn-link favorite-btn p-0 active"
                    data-account-id="<?= (int)$r['id'] ?>"
                    title="Удалить из избранного"
                    style="font-size: 1.25rem;">
                    <i class="fas fa-star text-warning filter-drop-shadow"></i>
                  </button>
                </td>
                <td class="fw-medium"><?= htmlspecialchars($r['login'] ?? '') ?></td>
                <td><span class="text-muted"><i class="fas fa-envelope me-2 opacity-50"></i><?= htmlspecialchars($r['email'] ?? '') ?></span></td>
                <td><?= htmlspecialchars($r['first_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['last_name'] ?? '') ?></td>
                <td>
                  <?php $st = $r['status'] ?? ''; ?>
                  <span class="badge <?= $st !== '' ? 'bg-secondary' : 'badge-empty-status' ?> px-3 py-2 rounded-pill shadow-sm">
                    <?= $st !== '' ? htmlspecialchars($st) : 'Пустой статус' ?>
                  </span>
                </td>
                <td class="ac-cell--actions text-center">
                  <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn-table-open">
                    <i class="fas fa-arrow-right"></i> Открыть
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <?php if ($pages > 1): ?>
      <div class="dashboard-table__footer">
        <div class="dashboard-table__counter">
          Найдено: <span class="dashboard-table__counter-value"><?= number_format($filteredTotal) ?></span>
          <span class="ms-2">Стр. <strong><?= $page ?></strong> из <?= $pages ?></span>
        </div>
        <nav>
          <ul class="pagination pagination-modern m-0">
            <li class="page-item <?= $page==1?'disabled':'' ?>">
              <a class="page-link" href="?page=1<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <i class="fas fa-angle-double-left"></i>
              </a>
            </li>
            <li class="page-item <?= $page==1?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $prev ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <i class="fas fa-angle-left"></i>
              </a>
            </li>
            <?php foreach ($pageNumbers as $pnum): ?>
            <li class="page-item <?= $pnum==$page?'active':'' ?>">
              <a class="page-link" href="?page=<?= $pnum ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <?= $pnum ?>
              </a>
            </li>
            <?php endforeach; ?>
            <li class="page-item <?= $page==$pages?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $next ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <i class="fas fa-angle-right"></i>
              </a>
            </li>
            <li class="page-item <?= $page==$pages?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $pages ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <i class="fas fa-angle-double-right"></i>
              </a>
            </li>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>
    
    <?php endif; ?>
  </main>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/toast.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
  <script>
    window.DashboardConfig = window.DashboardConfig || {};
    window.DashboardConfig.csrfToken = <?= json_encode((string)getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
  </script>
  <script src="assets/js/favorites.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
</body>
</html>

<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Избранные аккаунты - Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="assets/css/design-system.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/components-unified.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/filters-modern.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/toast.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
  
  <style>
    .favorites-header {
      background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
      color: white;
      padding: 2rem;
      border-radius: 8px;
      margin-bottom: 2rem;
    }
    .favorites-header h1 {
      margin: 0;
      font-size: 2rem;
    }
    .favorites-count {
      font-size: 1.2rem;
      opacity: 0.9;
    }
  </style>
</head>
<body>

  <!-- Навигация -->
  <nav class="navbar">
    <div class="container-fluid">
      <a class="navbar-brand" href="index.php">
        <i class="fas fa-chart-line text-primary me-2"></i>
        Dashboard
      </a>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small">
          <i class="fas fa-user me-1"></i>
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
        <a href="index.php" class="btn btn-sm btn-outline-primary">
          <i class="fas fa-arrow-left me-1"></i>
          Назад
        </a>
        <a href="logout.php" class="btn btn-sm btn-outline-danger" title="Выйти из системы">
          <i class="fas fa-sign-out-alt"></i>
        </a>
      </div>
    </div>
  </nav>

  <!-- Основной контент -->
  <main class="container-fluid" style="padding: 2rem;">
    
    <!-- Заголовок -->
    <div class="favorites-header">
      <h1>
        <i class="fas fa-star me-2"></i>
        Избранные аккаунты
      </h1>
      <div class="favorites-count">
        Всего избранных: <strong><?= number_format($filteredTotal) ?></strong>
      </div>
    </div>
    
    <?php if (isset($errorMessage)): ?>
    <div class="alert alert-danger">
      <i class="fas fa-exclamation-triangle me-2"></i>
      <?= htmlspecialchars($errorMessage) ?>
    </div>
    <?php endif; ?>
    
    <?php if (empty($rows)): ?>
    <div class="card">
      <div class="card-body text-center py-5">
        <i class="fas fa-star text-warning" style="font-size: 4rem; opacity: 0.3;"></i>
        <h3 class="mt-3">Нет избранных аккаунтов</h3>
        <p class="text-muted">Добавьте аккаунты в избранное, нажав на звездочку в таблице.</p>
        <a href="index.php" class="btn btn-primary">
          <i class="fas fa-arrow-left me-2"></i>
          Вернуться к дашборду
        </a>
      </div>
    </div>
    <?php else: ?>
    
    <!-- Поиск -->
    <div class="card mb-3">
      <div class="card-body">
        <form method="get" class="d-flex gap-2">
          <div class="flex-grow-1">
            <input 
              type="search" 
              name="q" 
              class="form-control" 
              placeholder="Поиск по логину, email, имени..." 
              value="<?= htmlspecialchars($q) ?>"
            >
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search me-1"></i>
            Поиск
          </button>
          <?php if ($q !== ''): ?>
          <a href="favorites.php" class="btn btn-outline-secondary">
            <i class="fas fa-times me-1"></i>
            Сбросить
          </a>
          <?php endif; ?>
        </form>
      </div>
    </div>
    
    <!-- Таблица -->
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Логин</th>
                <th>Email</th>
                <th>Имя</th>
                <th>Фамилия</th>
                <th>Статус</th>
                <th class="text-end">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['login'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['first_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['last_name'] ?? '') ?></td>
                <td>
                  <span class="badge bg-secondary">
                    <?= htmlspecialchars($r['status'] ?? '') ?>
                  </span>
                </td>
                <td class="text-end">
                  <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-eye me-1"></i>
                    Открыть
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <?php if ($pages > 1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">
          Найдено: <strong><?= number_format($filteredTotal) ?></strong>
          • Стр. <strong><?= $page ?></strong> из <strong><?= $pages ?></strong>
        </div>
        <nav>
          <ul class="pagination m-0">
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
  <script src="assets/js/favorites.js?v=<?= time() ?>"></script>
</body>
</html>


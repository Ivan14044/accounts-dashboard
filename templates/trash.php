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
  <link href="assets/css/design-system.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/components-unified.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/toast.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
  <style>
    .trash-header {
      background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
      color: white;
      padding: 2rem;
      border-radius: 12px;
      margin-bottom: 2rem;
    }
    .trash-icon {
      font-size: 3rem;
      margin-bottom: 1rem;
    }
    .trash-warning {
      background: #fff3cd;
      border: 1px solid #ffc107;
      border-radius: 8px;
      padding: 1rem;
      margin-bottom: 1rem;
    }
    .deleted-badge {
      background: #dc2626;
      color: white;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
      font-weight: 600;
    }
  </style>
</head>
<body>

  <!-- Навигация -->
  <nav class="navbar navbar-expand-lg navbar-light bg-light">
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
  <main class="container-fluid py-4">
    
    <?php if (isset($errorMessage)): ?>
    <!-- Сообщение об ошибке -->
    <div class="alert alert-danger" role="alert">
      <i class="fas fa-exclamation-circle me-2"></i>
      <strong>Ошибка:</strong> <?= htmlspecialchars($errorMessage) ?>
      <br><small>Проверьте логи сервера для получения подробной информации.</small>
    </div>
    <?php endif; ?>
    
    <!-- Заголовок корзины -->
    <div class="trash-header text-center">
      <div class="trash-icon">
        <i class="fas fa-trash-alt"></i>
      </div>
      <h2 class="mb-2">Корзина</h2>
      <p class="mb-0">Удалённые аккаунты (<?= number_format(isset($deletedCount) ? $deletedCount : 0) ?> записей)</p>
    </div>
    
    <!-- Предупреждение -->
    <div class="trash-warning">
      <i class="fas fa-exclamation-triangle me-2"></i>
      <strong>Внимание!</strong> В корзине отображаются аккаунты, которые были удалены (Soft Delete). 
      Вы можете восстановить их или окончательно удалить из базы данных.
    </div>

    <!-- Поиск в корзине (всегда показываем) -->
    <div class="card mb-3">
      <div class="card-body">
        <form method="get" action="trash.php" id="trashSearchForm">
          <div class="row g-3 align-items-end">
            <div class="col-md-6">
              <label class="form-label">Поиск</label>
              <input 
                type="search" 
                name="q" 
                class="form-control" 
                placeholder="логин, email, ID..." 
                value="<?= htmlspecialchars(isset($q) ? $q : '', ENT_QUOTES, 'UTF-8') ?>"
              >
            </div>
            <div class="col-md-3">
              <label class="form-label">Сортировка</label>
              <select name="sort" class="form-select">
                <option value="id" <?= ($sort ?? 'deleted_at') === 'id' ? 'selected' : '' ?>>ID</option>
                <option value="login" <?= ($sort ?? 'deleted_at') === 'login' ? 'selected' : '' ?>>Логин</option>
                <option value="email" <?= ($sort ?? 'deleted_at') === 'email' ? 'selected' : '' ?>>Email</option>
                <option value="deleted_at" <?= ($sort ?? 'deleted_at') === 'deleted_at' ? 'selected' : '' ?>>Дата удаления</option>
              </select>
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-search me-1"></i>
                Поиск
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php if (isset($deletedCount) && $deletedCount > 0): ?>
    
    <!-- Панель инструментов -->
    <div class="toolbar mb-3">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="fas fa-trash me-2"></i>
          Удалённые аккаунты
        </h5>
        <div class="btn-group">
          <button class="btn btn-outline-success" id="restoreSelectedBtn" disabled>
            <i class="fas fa-undo me-1"></i>
            Восстановить
          </button>
          <button class="btn btn-outline-danger" id="deletePermanentlyBtn" disabled>
            <i class="fas fa-trash-alt me-1"></i>
            Удалить навсегда
          </button>
          <button class="btn btn-outline-warning" id="emptyTrashBtn">
            <i class="fas fa-broom me-1"></i>
            Очистить корзину
          </button>
        </div>
      </div>
      <div class="mt-2">
        <span class="text-muted">Выбрано: <strong id="selectedCount">0</strong></span>
      </div>
    </div>

    <!-- Таблица удалённых аккаунтов -->
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="trashTable">
            <thead class="table-light">
              <tr>
                <th style="width: 50px;">
                  <input type="checkbox" id="selectAllTrash" title="Выбрать все">
                </th>
                <th>ID</th>
                <th>Логин</th>
                <th>Email</th>
                <th>Статус</th>
                <th>Дата удаления</th>
                <th class="text-end">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $r): ?>
                  <tr data-id="<?= (int)$r['id'] ?>">
                    <td>
                      <input type="checkbox" class="trash-checkbox" value="<?= (int)$r['id'] ?>">
                    </td>
                    <td>#<?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars(isset($r['login']) ? $r['login'] : '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(isset($r['email']) ? $r['email'] : '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <?php if (!empty($r['status'])): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['deleted_at'])): ?>
                        <span class="text-muted">
                          <?= date('d.m.Y H:i', strtotime($r['deleted_at'])) ?>
                        </span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-sm btn-outline-success restore-btn" data-id="<?= (int)$r['id'] ?>" title="Восстановить">
                          <i class="fas fa-undo"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger delete-permanent-btn" data-id="<?= (int)$r['id'] ?>" title="Удалить навсегда">
                          <i class="fas fa-trash-alt"></i>
                        </button>
                        <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Просмотр">
                          <i class="fas fa-eye"></i>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-5">
                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                    Корзина пуста
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <?php if (isset($pages) && $pages > 1): ?>
        <div class="card-footer">
          <nav aria-label="Пагинация">
            <ul class="pagination justify-content-center mb-0">
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
    <div class="card">
      <div class="card-body text-center py-5">
        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
        <h4 class="text-muted">Корзина пуста</h4>
        <p class="text-muted">Нет удалённых аккаунтов</p>
        <a href="index.php" class="btn btn-primary">
          <i class="fas fa-arrow-left me-1"></i>
          Вернуться к списку аккаунтов
        </a>
      </div>
    </div>
    
    <?php endif; ?>
    
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/toast.js?v=<?= time() ?>"></script>
  <script src="assets/js/trash.js?v=<?= time() ?>"></script>
</body>
</html>


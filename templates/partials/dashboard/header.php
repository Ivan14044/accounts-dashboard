<header class="modern-header">
  <!-- Левая часть: профиль -->
  <div class="modern-header-left">
    <!-- Профиль пользователя -->
    <div class="user-profile" id="userProfileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
      <div class="user-avatar">
        <?php 
        $username = getCurrentUser();
        $initial = mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8');
        echo e($initial);
        ?>
      </div>
      <span class="user-name"><?= e($username) ?></span>
      <i class="fas fa-chevron-down user-dropdown-icon"></i>
    </div>
    
    <!-- Dropdown меню профиля -->
    <ul class="dropdown-menu" aria-labelledby="userProfileDropdown">
      <li><a class="dropdown-item" href="index.php"><i class="fas fa-home me-2"></i>Главная</a></li>
      <li><a class="dropdown-item" href="admin_logs.php"><i class="fas fa-shield-alt me-2"></i>Журнал действий</a></li>
      <li><a class="dropdown-item" href="log.php"><i class="fas fa-file-alt me-2"></i>Системные логи</a></li>
      <li><hr class="dropdown-divider"></li>
      <li>
        <form method="POST" action="logout.php" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="dropdown-item"><i class="fas fa-sign-out-alt me-2"></i>Выйти</button>
        </form>
      </li>
    </ul>
    
    <!-- Выбор таблицы -->
    <?php if (!empty($availableTables) && count($availableTables) > 1): ?>
    <div class="table-selector dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" id="tableSelector" data-bs-toggle="dropdown" aria-expanded="false" title="Выбор таблицы">
        <i class="fas fa-database me-1"></i><?= e($currentTable ?? 'accounts') ?>
      </button>
      <ul class="dropdown-menu" aria-labelledby="tableSelector" style="max-height:300px;overflow-y:auto">
        <?php foreach ($availableTables as $t): ?>
        <li>
          <a class="dropdown-item<?= ($t === ($currentTable ?? 'accounts')) ? ' active' : '' ?>"
             href="?table=<?= urlencode($t) ?>">
            <i class="fas fa-table me-2 text-muted"></i><?= e($t) ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Иконки действий -->
    <div class="header-actions">
      <button class="header-action-btn" id="autoRefreshToggle" title="Автообновление">
        <i class="fas fa-sync-alt"></i>
      </button>
      <button class="header-action-btn" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Настройки">
        <i class="fas fa-cog"></i>
      </button>
      <a href="trash.php<?= !empty($currentTable) && $currentTable !== 'accounts' ? '?table=' . urlencode($currentTable) : '' ?>" class="header-action-btn" title="Корзина">
        <i class="fas fa-trash-alt"></i>
      </a>
    </div>
  </div>
  
  <!-- Правая часть: индикатор БД -->
  <div class="modern-header-right">
    <div class="db-status-indicator">
      <span class="db-status-dot"></span>
      <span class="db-status-text">Активное подключение к БД</span>
    </div>
  </div>
</header>

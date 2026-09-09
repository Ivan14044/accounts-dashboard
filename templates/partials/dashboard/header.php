<header class="modern-header workspace-header">
  <a class="workspace-brand" href="index.php" aria-label="Dashboard — главная">
    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true"><rect width="28" height="28" rx="8" fill="currentColor"/><path d="M8 19V13M14 19V8M20 19V11" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg>
    <span>Dashboard</span>
  </a>
  <?php include __DIR__ . '/workspace-nav.php'; ?>
  <!-- Левая часть: профиль -->
  <div class="modern-header-left">
    <!-- Профиль пользователя -->
    <button type="button" class="user-profile" id="userProfileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
      <div class="user-avatar">
        <?php
        $username = getCurrentUser();
        $initial = mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8');
        echo e($initial);
        ?>
      </div>
      <?php /* title — потому что длинная строка подключения обрезается многоточием */ ?>
      <span class="user-name" title="<?= e($username) ?>"><?= e($username) ?></span>
      <i class="fas fa-chevron-down user-dropdown-icon" aria-hidden="true"></i>
    </button>

    <!-- Dropdown меню профиля -->
    <ul class="dropdown-menu" aria-labelledby="userProfileDropdown">
      <li><a class="dropdown-item" href="index.php"><i class="fas fa-home me-2" aria-hidden="true"></i>Главная</a></li>
      <li><a class="dropdown-item" href="admin_logs.php"><i class="fas fa-shield-alt me-2" aria-hidden="true"></i>Журнал действий</a></li>
      <li><a class="dropdown-item" href="log.php"><i class="fas fa-file-alt me-2" aria-hidden="true"></i>Системные логи</a></li>
      <li><hr class="dropdown-divider"></li>
      <li>
        <form method="POST" action="logout.php" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="dropdown-item"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i>Выйти</button>
        </form>
      </li>
    </ul>

    <!-- Выбор таблицы -->
    <?php if (!empty($availableTables) && count($availableTables) > 1): ?>
    <div class="table-selector dropdown">
      <button class="btn btn-sm btn-ghost dropdown-toggle" id="tableSelector" data-bs-toggle="dropdown" aria-expanded="false" title="Выбор таблицы">
        <i class="fas fa-database me-1" aria-hidden="true"></i><?= e($currentTable ?? 'accounts') ?>
      </button>
      <ul class="dropdown-menu" aria-labelledby="tableSelector" style="max-height:300px;overflow-y:auto">
        <?php foreach ($availableTables as $t): ?>
        <li>
          <a class="dropdown-item<?= ($t === ($currentTable ?? 'accounts')) ? ' active' : '' ?>"
             href="?table=<?= urlencode($t) ?>">
            <i class="fas fa-table me-2 text-muted" aria-hidden="true"></i><?= e($t) ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Иконки действий -->
    <div class="header-actions">
      <button class="header-action-btn" id="themeToggle" type="button" title="Тёмная тема" aria-pressed="false" aria-label="Включить тёмную тему">
        <i class="fas fa-moon" aria-hidden="true"></i>
      </button>
      <button class="header-action-btn" id="autoRefreshToggle" title="Автообновление" aria-label="Включить автообновление" aria-pressed="false">
        <i class="fas fa-sync-alt" aria-hidden="true"></i>
      </button>
      <button class="header-action-btn" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Настройки" aria-label="Настройки">
        <i class="fas fa-cog" aria-hidden="true"></i>
      </button>
      <a href="trash.php<?= !empty($currentTable) && $currentTable !== 'accounts' ? '?table=' . urlencode($currentTable) : '' ?>" class="header-action-btn" title="Корзина">
        <i class="fas fa-trash-alt" aria-hidden="true"></i>
      </a>
    </div>
  </div>

  <!-- Правая часть: индикатор БД -->
  <div class="modern-header-right">
    <div class="db-status-indicator">
      <span class="db-status-dot"></span>
      <span class="db-status-text">Подключено</span>
    </div>
  </div>
</header>

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
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Выйти</a></li>
    </ul>
    
    <!-- Иконки действий -->
    <div class="header-actions">
      <button class="header-action-btn" id="autoRefreshToggle" title="Автообновление">
        <i class="fas fa-sync-alt"></i>
      </button>
      <button class="header-action-btn" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Настройки">
        <i class="fas fa-cog"></i>
      </button>
      <a href="trash.php" class="header-action-btn" title="Корзина">
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

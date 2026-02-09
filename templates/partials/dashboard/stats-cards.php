<!-- Статистические карточки -->
<div class="stats-grid" id="statsRow">
  <!-- Прелоадер для статистических карточек (скрыт по умолчанию, показывается только при обновлении) -->
  <div class="stats-loading-overlay" id="statsLoading" style="display: none;">
    <div class="text-center">
      <span class="loader loader-primary"></span>
    </div>
  </div>
  <!-- Общая статистика -->
  <div class="stat-card fade-in" data-card="total">
    <button type="button" class="stat-card-hide-btn" data-card="total" title="Скрыть карточку" aria-label="Скрыть карточку">
      <i class="fas fa-eye-slash"></i>
    </button>
    <div class="stat-header">
      <h3 class="stat-title">Всего аккаунтов</h3>
    </div>
    <div class="stat-value"><?= number_format((int)$totals['all']) ?></div>
    <?php if ($recentAll !== null): ?>
    <div class="stat-change positive">
      <i class="fas fa-arrow-up"></i>
      <span>+<?= number_format((int)$recentAll) ?> за 24ч</span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Пустые статусы -->
  <div class="stat-card fade-in <?= $emptyStatusCount > 0 ? '' : 'd-none force-hidden' ?>" data-card="empty_status" <?= $emptyStatusCount > 0 ? '' : 'hidden' ?>>
    <button type="button" class="stat-card-hide-btn" data-card="empty_status" title="Скрыть карточку" aria-label="Скрыть карточку">
      <i class="fas fa-eye-slash"></i>
    </button>
    <div class="stat-header">
      <h3 class="stat-title">Пустые статусы</h3>
    </div>
    <div class="stat-value" id="emptyStatusCount"><?= $emptyStatusCount > 0 ? number_format($emptyStatusCount) : '-' ?></div>
    <?php if ($emptyStatusCount > 0): ?>
    <div class="stat-action">
      <a href="empty_status_page.php" class="btn btn-sm btn-warning">
        <i class="fas fa-edit me-1"></i>
        Управление
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Email + 2FA -->
  <div class="stat-card fade-in" data-card="custom:email_twofa">
    <button type="button" class="stat-card-hide-btn" data-card="custom:email_twofa" title="Скрыть карточку" aria-label="Скрыть карточку">
      <i class="fas fa-eye-slash"></i>
    </button>
    <div class="stat-header">
      <h3 class="stat-title">Email + 2FA</h3>
    </div>
    <div class="stat-value"><?= number_format($countEmailTwoFa) ?></div>
  </div>

  <!-- Статистика по статусам -->
  <?php foreach ($byStatus as $stName => $cnt): $safeKey = preg_replace('~[^a-z0-9_]+~i','_', $stName); ?>
  <div class="stat-card fade-in" data-card="status:<?= e($safeKey) ?>" data-status="<?= e($stName) ?>">
    <button type="button" class="stat-card-hide-btn" data-card="status:<?= e($safeKey) ?>" title="Скрыть карточку" aria-label="Скрыть карточку">
      <i class="fas fa-eye-slash"></i>
    </button>
    <div class="stat-header">
      <h3 class="stat-title"><?= e($stName) ?></h3>
    </div>
    <div class="stat-value"><?= number_format($cnt) ?></div>
    <?php if (!empty($recentByStatus) && isset($recentByStatus[$stName])): ?>
    <div class="stat-change positive">
      <i class="fas fa-arrow-up"></i>
      <span>+<?= number_format((int)$recentByStatus[$stName]) ?> за 24ч</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

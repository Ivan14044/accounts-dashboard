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
      <i class="fas fa-eye-slash" aria-hidden="true"></i>
    </button>
    <div class="stat-header">
      <h3 class="stat-title">Всего аккаунтов</h3>
    </div>
    <div class="stat-value"><?= number_format((int)$totals['all']) ?></div>
    <?php if ($recentAll !== null): ?>
    <div class="stat-change positive">
      <i class="fas fa-arrow-up" aria-hidden="true"></i>
      <span>+<?= number_format((int)$recentAll) ?> за 24ч</span>
    </div>
    <?php endif; ?>
    <?php
    /* === 7-day sparkline ===
       $dailyTotals — массив из 7 кумулятивных значений (StatisticsService::getDailyTotals).
       Рендерим только если есть данные и есть видимая динамика.  */
    if (!empty($dailyTotals) && count($dailyTotals) >= 2):
      $sMin = min($dailyTotals);
      $sMax = max($dailyTotals);
      $sRange = max(1, $sMax - $sMin);
      $w = 100; // viewBox width
      $h = 32;  // viewBox height
      $stepX = $w / (count($dailyTotals) - 1);
      $points = [];
      foreach ($dailyTotals as $i => $val) {
          $x = $i * $stepX;
          $y = $h - (($val - $sMin) / $sRange) * ($h - 4) - 2; // 2px breathing space
          $points[] = round($x, 2) . ',' . round($y, 2);
      }
      $linePath = 'M ' . implode(' L ', $points);
      $areaPath = $linePath . ' L ' . round($w, 2) . ',' . $h . ' L 0,' . $h . ' Z';
      $lastPoint = end($points);
      [$lastX, $lastY] = array_map('floatval', explode(',', $lastPoint));
    ?>
    <svg class="sparkline" viewBox="0 0 <?= $w ?> <?= $h ?>" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <linearGradient id="sparkline-gradient" x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.25"/>
          <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
        </linearGradient>
      </defs>
      <path class="sparkline__area" d="<?= e($areaPath) ?>"/>
      <path class="sparkline__path" d="<?= e($linePath) ?>"/>
      <circle class="sparkline__dot" cx="<?= e((string)$lastX) ?>" cy="<?= e((string)$lastY) ?>" r="2.2"/>
    </svg>
    <?php endif; ?>
  </div>

  <!-- Пустые статусы -->
  <div class="stat-card fade-in <?= $emptyStatusCount > 0 ? '' : 'd-none force-hidden' ?>" data-card="empty_status" <?= $emptyStatusCount > 0 ? '' : 'hidden' ?>>
    <button type="button" class="stat-card-hide-btn" data-card="empty_status" title="Скрыть карточку" aria-label="Скрыть карточку">
      <i class="fas fa-eye-slash" aria-hidden="true"></i>
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
      <i class="fas fa-eye-slash" aria-hidden="true"></i>
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
      <i class="fas fa-eye-slash" aria-hidden="true"></i>
    </button>
    <div class="stat-header">
      <h3 class="stat-title"><?= e($stName) ?></h3>
    </div>
    <div class="stat-value"><?= number_format($cnt) ?></div>
    <?php if (!empty($recentByStatus) && isset($recentByStatus[$stName])): ?>
    <div class="stat-change positive">
      <i class="fas fa-arrow-up" aria-hidden="true"></i>
      <span>+<?= number_format((int)$recentByStatus[$stName]) ?> за 24ч</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

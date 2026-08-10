<?php
/**
 * Карточки статистики. Разметка своя (ui.css), FontAwesome и bootstrap-классов нет.
 *
 * Контракт с JS сохранён дословно, его трогать нельзя:
 *   #statsRow, #statsLoading, #emptyStatusCount — по id ищут
 *   dashboard-stats.js и dashboard-refresh.js;
 *   data-card / data-status / .stat-card / .stat-card-hide-btn — по ним
 *   работают скрытие карточек, свайп на мобильном и фильтрация по статусу
 *   (стык описан в DEVELOPER_GUIDE, стережёт tests/test_card_swipe_contract.php).
 * Классы .stat-card и .stat-value оставлены рядом с ui-* именно поэтому:
 *   первое — хук поведения, второе — оформление.
 */
require_once __DIR__ . '/../../ui/icons.php';

$totals          = isset($totals) && is_array($totals) ? $totals : array('all' => 0);
$byStatus        = isset($byStatus) && is_array($byStatus) ? $byStatus : array();
$recentByStatus  = isset($recentByStatus) && is_array($recentByStatus) ? $recentByStatus : array();
$emptyStatusCount = isset($emptyStatusCount) ? (int) $emptyStatusCount : 0;
$countEmailTwoFa = isset($countEmailTwoFa) ? (int) $countEmailTwoFa : 0;
$recentAll       = isset($recentAll) ? $recentAll : null;
$dailyTotals     = isset($dailyTotals) && is_array($dailyTotals) ? $dailyTotals : array();
?>
<div class="ui-stats-grid" id="statsRow">

  <div class="ui-stats-loading" id="statsLoading" style="display:none" aria-hidden="true"></div>

  <!-- Всего аккаунтов -->
  <div class="ui-stat stat-card" data-card="total">
    <button type="button" class="ui-stat__hide stat-card-hide-btn" data-card="total" title="Скрыть карточку" aria-label="Скрыть карточку"><?= ui_icon('eye-off', 14) ?></button>
    <span class="ui-stat__label">Всего аккаунтов</span>
    <span class="ui-stat__value stat-value"><?= number_format((int) $totals['all'], 0, '.', ' ') ?></span>
    <?php if ($recentAll !== null): ?>
      <span class="ui-stat__foot">+<?= number_format((int) $recentAll, 0, '.', ' ') ?> за сутки</span>
    <?php endif; ?>

    <?php
    /* Спарклайн за 7 дней. $dailyTotals — кумулятивные значения из
       StatisticsService::getDailyTotals(). Рисуем только если точек хотя бы две:
       по одной точке линия не строится. */
    if (count($dailyTotals) >= 2):
        $sMin   = min($dailyTotals);
        $sMax   = max($dailyTotals);
        $sRange = max(1, $sMax - $sMin);
        $w = 100;
        $h = 28;
        $stepX = $w / (count($dailyTotals) - 1);
        $points = array();
        foreach ($dailyTotals as $i => $val) {
            $x = $i * $stepX;
            $y = $h - (($val - $sMin) / $sRange) * ($h - 4) - 2;
            $points[] = round($x, 2) . ',' . round($y, 2);
        }
        $linePath = 'M ' . implode(' L ', $points);
        $last = explode(',', end($points));
    ?>
      <svg class="ui-spark" viewBox="0 0 <?= $w ?> <?= $h ?>" preserveAspectRatio="none" aria-hidden="true">
        <path class="ui-spark__line" d="<?= e($linePath) ?>"/>
        <circle class="ui-spark__dot" cx="<?= e($last[0]) ?>" cy="<?= e($last[1]) ?>" r="1.8"/>
      </svg>
    <?php endif; ?>
  </div>

  <!-- Пустые статусы -->
  <div class="ui-stat stat-card<?= $emptyStatusCount > 0 ? '' : ' d-none force-hidden' ?>" data-card="empty_status" <?= $emptyStatusCount > 0 ? '' : 'hidden' ?>>
    <button type="button" class="ui-stat__hide stat-card-hide-btn" data-card="empty_status" title="Скрыть карточку" aria-label="Скрыть карточку"><?= ui_icon('eye-off', 14) ?></button>
    <span class="ui-stat__label">Пустые статусы</span>
    <span class="ui-stat__value stat-value" id="emptyStatusCount"><?= $emptyStatusCount > 0 ? number_format($emptyStatusCount, 0, '.', ' ') : '—' ?></span>
    <?php if ($emptyStatusCount > 0): ?>
      <a class="ui-btn ui-btn--sm" href="empty_status_page.php" style="margin-top:var(--ui-2);align-self:flex-start">Управление</a>
    <?php endif; ?>
  </div>

  <!-- Email + 2FA -->
  <div class="ui-stat stat-card" data-card="custom:email_twofa">
    <button type="button" class="ui-stat__hide stat-card-hide-btn" data-card="custom:email_twofa" title="Скрыть карточку" aria-label="Скрыть карточку"><?= ui_icon('eye-off', 14) ?></button>
    <span class="ui-stat__label">Email + 2FA</span>
    <span class="ui-stat__value stat-value"><?= number_format($countEmailTwoFa, 0, '.', ' ') ?></span>
  </div>

  <!-- По статусам -->
  <?php foreach ($byStatus as $stName => $cnt): ?>
    <?php
      // Пустой статус пропускаем: для него есть отдельная карточка выше.
      // Без этого рисовалась вторая карточка без заголовка — рамка и число.
      if ($stName === '' || $stName === null) {
          continue;
      }
      $safeKey = preg_replace('~[^a-z0-9_]+~i', '_', $stName);
    ?>
    <div class="ui-stat stat-card" data-card="status:<?= e($safeKey) ?>" data-status="<?= e($stName) ?>">
      <button type="button" class="ui-stat__hide stat-card-hide-btn" data-card="status:<?= e($safeKey) ?>" title="Скрыть карточку" aria-label="Скрыть карточку"><?= ui_icon('eye-off', 14) ?></button>
      <span class="ui-stat__label"><?= e($stName) ?></span>
      <span class="ui-stat__value stat-value"><?= number_format($cnt, 0, '.', ' ') ?></span>
      <?php if (isset($recentByStatus[$stName])): ?>
        <span class="ui-stat__foot">+<?= number_format((int) $recentByStatus[$stName], 0, '.', ' ') ?> за сутки</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

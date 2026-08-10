<?php
/**
 * Шапка дашборда. Своя разметка (ui.css), без bootstrap-дропдауна и FontAwesome.
 *
 * Контракт с JS сохранён: #themeToggle (theme-toggle.js), #autoRefreshToggle
 * (auto-refresh.js), кнопка настроек открывает #settingsModal через ui.js.
 * Меню профиля — собственное (data-ui-menu), Bootstrap для него больше не нужен.
 */
require_once __DIR__ . '/../../ui/icons.php';

$username     = function_exists('getCurrentUser') ? getCurrentUser() : 'Пользователь';
$currentTable = isset($currentTable) ? (string) $currentTable : 'accounts';
$availableTables = isset($availableTables) && is_array($availableTables) ? $availableTables : array();
$tableQuery   = ($currentTable !== '' && $currentTable !== 'accounts') ? '?table=' . urlencode($currentTable) : '';
?>
<header class="ui-topbar">

  <div class="ui-menu-wrap">
    <button type="button" class="ui-topbar__user" data-ui-menu="userMenu" aria-expanded="false" aria-haspopup="true">
      <span class="ui-topbar__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
      <span class="ui-topbar__username"><?= e($username) ?></span>
      <?= ui_icon('chevron-down', 14) ?>
    </button>

    <div class="ui-menu" id="userMenu" hidden style="left:0;right:auto">
      <a class="ui-menu__item" href="index.php"><?= ui_icon('table', 15) ?>Дашборд</a>
      <a class="ui-menu__item" href="favorites.php"><?= ui_icon('star', 15) ?>Избранное</a>
      <a class="ui-menu__item" href="admin_logs.php"><?= ui_icon('shield', 15) ?>Журнал действий</a>
      <a class="ui-menu__item" href="log.php"><?= ui_icon('file', 15) ?>Системные логи</a>
      <div class="ui-menu__sep"></div>
      <form method="POST" action="logout.php" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <button type="submit" class="ui-menu__item" data-tone="danger"><?= ui_icon('logout', 15) ?>Выйти</button>
      </form>
    </div>
  </div>

  <?php if (count($availableTables) > 1): ?>
    <div class="ui-menu-wrap">
      <button type="button" class="ui-btn ui-btn--sm" data-ui-menu="tableMenu" aria-expanded="false" aria-haspopup="true" title="Выбор таблицы">
        <?= ui_icon('database', 14) ?><?= e($currentTable) ?><?= ui_icon('chevron-down', 13) ?>
      </button>
      <div class="ui-menu" id="tableMenu" hidden style="left:0;right:auto;max-height:320px;overflow-y:auto">
        <?php foreach ($availableTables as $t): ?>
          <a class="ui-menu__item" href="?table=<?= urlencode($t) ?>"<?= $t === $currentTable ? ' aria-current="true"' : '' ?>>
            <?= ui_icon('table', 15) ?><?= e($t) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="ui-topbar__spacer"></div>

  <div class="ui-topbar__actions">
    <button type="button" class="ui-icon-btn" id="themeToggle" data-ui-theme aria-pressed="false" title="Тёмная тема" aria-label="Переключить тему">
      <span data-ui-theme-icon="moon"><?= ui_icon('moon') ?></span>
      <span data-ui-theme-icon="sun" style="display:none"><?= ui_icon('sun') ?></span>
    </button>

    <button type="button" class="ui-icon-btn" id="autoRefreshToggle" title="Автообновление" aria-label="Автообновление"><?= ui_icon('refresh') ?></button>

    <button type="button" class="ui-icon-btn" data-ui-open="settingsModal" title="Настройки" aria-label="Настройки"><?= ui_icon('settings') ?></button>

    <a class="ui-icon-btn" href="trash.php<?= $tableQuery ?>" title="Корзина" aria-label="Корзина"><?= ui_icon('trash') ?></a>

    <span class="ui-conn" title="Соединение с базой данных активно">
      <span class="ui-dot" data-tone="ok" aria-hidden="true"></span>
      <span class="ui-conn__text">База подключена</span>
    </span>
  </div>
</header>

<?php
/**
 * Страница «Избранные аккаунты». Собрана на собственной системе интерфейса
 * (assets/css/ui.css + assets/js/ui.js), с нуля.
 *
 * Что это значит по факту: страница НЕ подключает bootstrap.min.css,
 * bootstrap.bundle.js, FontAwesome и core-*.css. Ни одного класса `btn`,
 * `card`, `badge`, `col-*` в разметке нет — только собственные `ui-*`.
 * Иконки — инлайновый SVG (templates/ui/icons.php).
 *
 * Из шаблона ожидаются переменные контроллера (favorites.php в корне):
 *   $rows, $filteredTotal, $q, $page, $pages, $prev, $next, $pageNumbers,
 *   $errorMessage (необязательная).
 * Нормализуем их в начале файла: партиал обязан быть самодостаточным, иначе
 * при изменении контроллера получим Warning прямо в проде.
 */

require_once __DIR__ . '/ui/icons.php';

$rows          = isset($rows) && is_array($rows) ? $rows : array();
$filteredTotal = isset($filteredTotal) ? (int) $filteredTotal : 0;
$q             = isset($q) ? (string) $q : '';
$page          = isset($page) ? (int) $page : 1;
$pages         = isset($pages) ? max(1, (int) $pages) : 1;
$prev          = isset($prev) ? (int) $prev : 1;
$next          = isset($next) ? (int) $next : 1;
$pageNumbers   = isset($pageNumbers) && is_array($pageNumbers) ? $pageNumbers : array(1);
$errorMessage  = isset($errorMessage) ? (string) $errorMessage : '';
$assetV        = defined('ASSETS_VERSION') ? ASSETS_VERSION : (string) time();

$username = 'Пользователь';
if (function_exists('getCurrentUser')) {
    try {
        $username = getCurrentUser();
    } catch (Exception $e) {
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Пользователь';
    }
}

/** Ссылка на страницу списка с сохранением поискового запроса. */
function fav_page_url($n, $q)
{
    $url = '?page=' . (int) $n;
    if ($q !== '') {
        $url .= '&q=' . urlencode($q);
    }
    return $url;
}

/** Тон метки статуса: цвет появляется только там, где он что-то значит. */
function fav_status_tone($status)
{
    $s = mb_strtolower(trim((string) $status), 'UTF-8');
    if ($s === '') {
        return 'empty';
    }
    if (strpos($s, 'invalid') !== false || strpos($s, 'ban') !== false || strpos($s, 'error') !== false) {
        return 'danger';
    }
    if (strpos($s, 'valid') !== false || strpos($s, 'new') !== false || strpos($s, 'active') !== false) {
        return 'ok';
    }
    if (strpos($s, 'check') !== false || strpos($s, 'work') !== false || strpos($s, 'wait') !== false) {
        return 'warn';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Избранное — Accounts Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">

  <!-- Тема выставляется ДО первой отрисовки, иначе тёмная мигает белым. -->
  <script>
    (function () {
      try {
        var t = localStorage.getItem('dashboard-theme');
        if (t !== 'dark' && t !== 'light') {
          t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.setAttribute('data-bs-theme', t);
      } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
      }
    })();
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500&family=JetBrains+Mono:wght@400;500&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/css/ui.css?v=<?= e($assetV) ?>" rel="stylesheet">
</head>
<body>

<header class="ui-topbar">
  <a class="ui-topbar__brand" href="index.php">
    <?= ui_icon('logo', 22) ?>
    <span>Accounts Dashboard</span>
  </a>

  <div class="ui-topbar__spacer"></div>

  <div class="ui-topbar__actions">
    <span class="ui-muted ui-row ui-row--tight" style="font-size:12.5px;margin-right:var(--ui-2)">
      <?= ui_icon('user', 15) ?><?= e($username) ?>
    </span>

    <button type="button" class="ui-icon-btn" data-ui-theme aria-pressed="false" aria-label="Переключить тему" title="Тёмная тема">
      <span data-ui-theme-icon="moon"><?= ui_icon('moon') ?></span>
      <span data-ui-theme-icon="sun" style="display:none"><?= ui_icon('sun') ?></span>
    </button>

    <a class="ui-btn ui-btn--sm" href="index.php"><?= ui_icon('arrow-left', 14) ?>Дашборд</a>

    <form method="POST" action="logout.php" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
      <button type="submit" class="ui-icon-btn" title="Выйти" aria-label="Выйти из системы"><?= ui_icon('logout') ?></button>
    </form>
  </div>
</header>

<main class="ui-page">

  <div class="ui-page__head">
    <div class="ui-page__title">
      <span class="ui-eyebrow">Подборка</span>
      <h1 class="ui-h1">Избранные аккаунты</h1>
    </div>
    <div class="ui-row ui-row--tight ui-muted" style="font-size:12.5px">
      <?= ui_icon('star', 14) ?>
      <span>Всего: <strong class="ui-mono" data-fav-total style="color:var(--ui-text)"><?= number_format($filteredTotal, 0, '.', ' ') ?></strong></span>
    </div>
  </div>

  <?php if ($errorMessage !== ''): ?>
    <div class="ui-note" data-tone="danger" role="alert" style="margin-bottom:var(--ui-4)">
      <?= ui_icon('alert') ?><span><?= e($errorMessage) ?></span>
    </div>
  <?php endif; ?>

  <?php if (empty($rows) && $q === ''): ?>

    <div class="ui-card">
      <div class="ui-table__empty">
        <?= ui_icon('star', 28) ?>
        <p class="ui-h2" style="margin-bottom:var(--ui-2)">Пока пусто</p>
        <p style="max-width:46ch;margin:0 auto var(--ui-5)">
          Отметьте аккаунт звёздочкой в таблице дашборда — он появится здесь.
        </p>
        <a class="ui-btn ui-btn--primary" href="index.php"><?= ui_icon('arrow-left', 14) ?>К дашборду</a>
      </div>
    </div>

  <?php else: ?>

    <form method="get" class="ui-row" style="margin-bottom:var(--ui-4);gap:var(--ui-2)">
      <div class="ui-search" style="flex:1 1 320px;min-width:0">
        <?= ui_icon('search') ?>
        <input class="ui-input" type="search" name="q" value="<?= e($q) ?>"
               placeholder="Логин, email, имя…" aria-label="Поиск по избранному">
      </div>
      <button class="ui-btn ui-btn--primary" type="submit">Найти</button>
      <?php if ($q !== ''): ?>
        <a class="ui-btn" href="favorites.php" title="Сбросить поиск"><?= ui_icon('close', 14) ?>Сбросить</a>
      <?php endif; ?>
    </form>

    <div class="ui-table-wrap">
      <div class="ui-table-scroll">
        <table class="ui-table" id="favoritesTable">
          <thead>
            <tr>
              <th style="width:76px">ID</th>
              <th style="width:52px"><span class="ui-visually-hidden">Избранное</span></th>
              <th style="min-width:150px">Логин</th>
              <th style="min-width:210px">Email</th>
              <th style="min-width:120px">Имя</th>
              <th style="min-width:120px">Фамилия</th>
              <th style="min-width:130px">Статус</th>
              <th class="ui-td-actions" style="min-width:110px">Действия</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="8">
                  <div class="ui-table__empty">
                    <?= ui_icon('search', 28) ?>
                    <p>По запросу «<?= e($q) ?>» ничего не нашлось.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $id     = isset($r['id']) ? (int) $r['id'] : 0;
                  $status = isset($r['status']) ? (string) $r['status'] : '';
                  $tone   = fav_status_tone($status);
                ?>
                <tr data-id="<?= $id ?>">
                  <td class="ui-td-mono ui-td-strong">#<?= $id ?></td>
                  <td>
                    <button type="button"
                            class="ui-icon-btn ui-icon-btn--sm favorite-btn is-on"
                            data-account-id="<?= $id ?>"
                            aria-pressed="true"
                            title="Убрать из избранного"
                            aria-label="Убрать аккаунт #<?= $id ?> из избранного">
                      <?= ui_icon_filled('star', 15) ?>
                    </button>
                  </td>
                  <td class="ui-td-strong"><?= e(isset($r['login']) ? $r['login'] : '') ?></td>
                  <td class="ui-td-mono"><?= e(isset($r['email']) ? $r['email'] : '') ?></td>
                  <td><?= e(isset($r['first_name']) ? $r['first_name'] : '') ?></td>
                  <td><?= e(isset($r['last_name']) ? $r['last_name'] : '') ?></td>
                  <td>
                    <span class="ui-badge"<?= $tone !== '' ? ' data-tone="' . e($tone) . '"' : '' ?>>
                      <?= $status !== '' ? e($status) : 'без статуса' ?>
                    </span>
                  </td>
                  <td class="ui-td-actions">
                    <a class="ui-btn ui-btn--sm" href="view.php?id=<?= $id ?>">
                      Открыть<?= ui_icon('arrow-right', 14) ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($pages > 1): ?>
      <div class="ui-row" style="justify-content:space-between;margin-top:var(--ui-4)">
        <span class="ui-muted" style="font-size:12.5px">
          Страница <strong class="ui-mono" style="color:var(--ui-text)"><?= $page ?></strong> из <?= $pages ?>
        </span>
        <nav class="ui-pager" aria-label="Постраничная навигация">
          <a class="ui-pager__link" href="<?= e(fav_page_url($prev, $q)) ?>" aria-label="Предыдущая страница"><?= ui_icon('arrow-left', 14) ?></a>
          <?php foreach ($pageNumbers as $pnum): ?>
            <a class="ui-pager__link" href="<?= e(fav_page_url($pnum, $q)) ?>"<?= (int) $pnum === $page ? ' aria-current="page"' : '' ?>><?= (int) $pnum ?></a>
          <?php endforeach; ?>
          <a class="ui-pager__link" href="<?= e(fav_page_url($next, $q)) ?>" aria-label="Следующая страница"><?= ui_icon('arrow-right', 14) ?></a>
        </nav>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</main>

<script>
  window.DashboardConfig = window.DashboardConfig || {};
  window.DashboardConfig.csrfToken = <?= json_encode((string) getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
</script>
<script src="assets/js/ui.js?v=<?= e($assetV) ?>" defer></script>
<script src="assets/js/pages/favorites.js?v=<?= e($assetV) ?>" defer></script>
</body>
</html>

<?php
/**
 * view.php — карточка одного аккаунта: все его данные для просмотра и
 * копирования.
 *
 * Раскладка (с 19.11.2026): шапка с именем, статусом и действиями, дальше две
 * колонки — слева доступы (вход в Фейсбук, почта, браузер и куки) и служебное,
 * справа сведения (профиль, реклама, фан-страницы, БМ). Какое поле в
 * какой раздел — решает includes/AccountViewLayout.php; там же гарантия, что
 * ни одна колонка не пропадёт (неизвестные уходят в «Прочее»).
 *
 * Пароли показаны открытым текстом: страницу открывает только владелец
 * панели (решение владельца 19.11.2026), а глазик лишь добавлял клик.
 * Пустые поля (у типичного аккаунта их ~120 из 173) спрятаны под кнопкой.
 *
 * Чего здесь осознанно НЕТ: правки полей — аккаунт правят в таблице дашборда.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/AccountViewLayout.php';

requireAuth();
checkSessionTimeout();

$id = (int)get_param('id', '0');
if ($id <= 0) { http_response_code(400); exit('Bad id'); }

$service = new AccountsService($tableName);
$row = $service->getAccountById($id);

if (!$row) { http_response_code(404); exit('Not found'); }

$layout = AccountViewLayout::build($row);
$head = $layout['header'];

// Данные для скрипта страницы. JSON_HEX_TAG обязателен: в куках и заметках
// может встретиться «</script>», и без экранирования он закрыл бы тег и
// превратил данные аккаунта в код на странице. INVALID_UTF8_SUBSTITUTE — чтобы
// одно битое значение не превращало json_encode в false (раньше это ломало
// все кнопки страницы разом).
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
$pagePayload = json_encode([
    'id'     => (int)$row['id'],
    'csrf'   => getCsrfToken(),
    'row'    => $row,
    'access' => AccountViewLayout::accessList($row),
    'all'    => AccountViewLayout::copyList($layout),
], $jsonFlags);
if ($pagePayload === false) {
    $pagePayload = '{}';
}

$socialUrl = trim((string)($row['social_url'] ?? ''));
$hasSocialLink = (bool)preg_match('~^https?://~i', $socialUrl);

/**
 * Кнопка «копировать» для поля. Значение берётся скриптом из данных страницы
 * по ключу; для составных полей (дата рождения) — из атрибута.
 */
function av_copy_button(array $f): string
{
    $label = 'Копировать: ' . $f['title'];
    $src = $f['key'] === 'birthday'
        ? 'data-copy-text="' . e($f['value']) . '"'
        : 'data-copy-key="' . e($f['key']) . '"';
    return '<button type="button" class="av-copy" ' . $src
        . ' data-copy-label="' . e(function_exists('mb_strtolower') ? mb_strtolower($f['title'], 'UTF-8') : $f['title']) . '"'
        . ' title="' . e($label) . '" aria-label="' . e($label) . '">'
        . '<i class="far fa-copy av-idle" aria-hidden="true"></i>'
        . '<i class="fas fa-check av-done" aria-hidden="true"></i></button>';
}

/** Значение поля без кнопки копирования. */
function av_value(array $f): string
{
    if ($f['empty']) {
        return '<span class="av-value av-empty">—</span>';
    }
    $v = $f['value'];
    switch ($f['kind']) {
        case 'flag':
            return $f['flag']
                ? '<span class="av-value av-flag av-flag--yes"><i class="fas fa-check-circle" aria-hidden="true"></i>Да</span>'
                : '<span class="av-value av-flag av-flag--no"><i class="far fa-circle" aria-hidden="true"></i>Нет</span>';
        case 'link':
            return '<span class="av-value"><a href="' . e($v) . '" target="_blank" rel="noopener noreferrer">'
                . e($v) . ' <i class="fas fa-external-link-alt fa-xs" aria-hidden="true"></i></a></span>';
        case 'email':
            return '<span class="av-value av-value--mono av-value--key">' . e($v) . '</span>';
        case 'mono':
            $isKey = in_array($f['key'], ['login', 'password', 'email_password', 'two_fa'], true);
            return '<span class="av-value av-value--mono' . ($isKey ? ' av-value--key' : '') . '">' . e($v) . '</span>';
        case 'long':
            $size = AccountViewLayout::formatSize($f['size']);
            return '<div class="av-long"><pre>' . e($f['display']) . '</pre>'
                . '<button type="button" class="av-long-toggle" data-long-toggle aria-expanded="false">'
                . '<span class="av-when-closed">Развернуть</span><span class="av-when-open">Свернуть</span>'
                . ' <span class="av-long-size">· ' . e($size) . '</span></button></div>';
        default:
            return '<span class="av-value">' . e($f['display']) . '</span>';
    }
}

/** Числовые столбцы таблиц ФП/БМ выравниваются по цифрам. */
function av_is_num_col(string $col): bool
{
    return in_array($col, ['fan_count', 'like_count', 'review_count', 'admin_count', 'year_fan_page', 'year_bm'], true);
}

/** Ссылка на страницу/БМ по ID — открыть в Фейсбуке одним щелчком. */
function av_table_cell(string $col, string $v): string
{
    if ($v === '') {
        return '<span class="av-empty">—</span>';
    }
    if ($col === 'id_fan_page' && ctype_digit($v)) {
        return '<a href="https://www.facebook.com/' . e($v) . '" target="_blank" rel="noopener noreferrer" title="Открыть страницу">' . e($v) . '</a>';
    }
    if ($col === 'id_bm' && ctype_digit($v)) {
        return '<a href="https://business.facebook.com/settings/?business_id=' . e($v) . '" target="_blank" rel="noopener noreferrer" title="Открыть БМ">' . e($v) . '</a>';
    }
    return e($v);
}

/** Раздел-карточка целиком. */
function av_section(array $s): string
{
    $countText = $s['filled'] > 0 ? (string)$s['filled'] : 'пусто';
    $html = '<section class="av-card' . ($s['filled'] === 0 ? ' is-all-empty' : '') . '" data-section="' . e($s['id']) . '" aria-labelledby="av-h-' . e($s['id']) . '">'
        . '<header class="av-card-head"><span class="av-card-icon"><i class="' . e($s['icon']) . '" aria-hidden="true"></i></span>'
        . '<h2 class="av-card-title" id="av-h-' . e($s['id']) . '">' . e($s['title']) . '</h2>'
        . '<span class="av-card-count" title="Заполнено">' . e($countText) . '</span></header>';

    if ($s['fields']) {
        $html .= '<dl class="av-fields">';
        foreach ($s['fields'] as $f) {
            $html .= '<div class="av-field' . ($f['empty'] ? ' is-empty' : '') . '" data-field="' . e($f['key']) . '">'
                . '<dt>' . e($f['title']) . '</dt><dd>';
            if ($f['totp']) {
                $html .= '<div class="av-2fa">' . av_value($f)
                    . '<div class="av-totp" data-totp-key="' . e($f['key']) . '" hidden>'
                    . '<span class="av-totp-label">Код</span>'
                    . '<span class="av-totp-code" aria-live="off">··· ···</span>'
                    . '<span class="av-totp-bar" aria-hidden="true"></span>'
                    . '<span class="av-totp-left" aria-hidden="true"></span>'
                    . '<button type="button" class="av-copy" data-copy-totp data-copy-label="код 2FA" title="Копировать код" aria-label="Копировать код 2FA">'
                    . '<i class="far fa-copy av-idle" aria-hidden="true"></i><i class="fas fa-check av-done" aria-hidden="true"></i></button>'
                    . '</div></div>';
            } else {
                $html .= av_value($f);
            }
            if (!$f['empty'] && $f['kind'] !== 'flag') {
                $html .= av_copy_button($f);
            }
            $html .= '</dd></div>';
        }
        $html .= '</dl>';
    }

    if ($s['table']) {
        // Столбцы, пустые во всех строках, не показываем: у ФП обычно
        // заполнены 2–3 столбца из восьми.
        $cols = [];
        foreach ($s['table']['columns'] as $col => $title) {
            foreach ($s['table']['rows'] as $cells) {
                if ($cells[$col] !== '') {
                    $cols[$col] = $title;
                    break;
                }
            }
        }
        $html .= '<div class="av-table-wrap"><table class="av-table"><thead><tr><th scope="col">№</th>';
        foreach ($cols as $title) {
            $html .= '<th scope="col">' . e($title) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($s['table']['rows'] as $i => $cells) {
            $html .= '<tr><td class="av-num">' . ($i + 1) . '</td>';
            foreach ($cols as $col => $title) {
                $html .= '<td' . (av_is_num_col($col) ? ' class="av-num"' : '') . '>' . av_table_cell($col, $cells[$col]) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }

    return $html . '</section>';
}

/**
 * Колонка разделов. Если в ней всё пустое — вместо голого заголовка короткая
 * подпись (сами пустые разделы появятся по кнопке «Показать пустые»).
 */
function av_column(array $sections, string $emptyText): string
{
    $html = '';
    $filled = 0;
    foreach ($sections as $s) {
        $html .= av_section($s);
        $filled += $s['filled'];
    }
    if ($filled === 0) {
        $html = '<p class="av-col-empty">' . e($emptyText) . '</p>' . $html;
    }
    return $html;
}

$filledTotal = 0;
foreach (['left', 'right'] as $side) {
    foreach ($layout['columns'][$side] as $s) {
        $filledTotal += $s['filled'];
    }
}

?><!doctype html>
<html lang="ru" data-bs-theme="auto">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <script>
    (function(){try{var t=localStorage.getItem('dashboard-theme');
      if(!t){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}
      document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}
      /* Показ пустых полей — до отрисовки, чтобы страница не прыгала */
      try{if(localStorage.getItem('account_view_show_empty_v1')==='1'){document.documentElement.classList.add('av-show-empty');}}catch(e){}})();
  </script>
  <title>#<?= (int)$head['id'] ?> <?= e($head['name']) ?> — Dashboard</title>
  <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <link href="assets/css/core-base.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-components.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-plugins.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-theme.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-mobile.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-design-v2.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/core-dark.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <link href="assets/css/account-view.css?v=<?= ASSETS_VERSION ?>" rel="stylesheet">
  <style>
    body { background: var(--bg-body, var(--page-bg)); min-height: 100vh; min-height: 100dvh; }
  </style>
  <!-- Мобильный слой: подключается последним, после стилей страницы (см. шапку файла) -->
  <link href="assets/css/core-touch.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand bg-white border-bottom shadow-sm" style="height: 64px;">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
      <i class="fas fa-chart-line text-primary"></i>
      Dashboard
    </a>
    <div class="ms-auto d-flex gap-2 gap-md-3 align-items-center">
      <span class="text-muted small fw-medium d-none d-md-inline-block">
        <i class="fas fa-user-circle me-1 text-primary"></i>
        <?= htmlspecialchars(getCurrentUser()) ?>
      </span>
      <div class="vr mx-1 d-none d-md-block"></div>
      <button type="button" id="themeToggle" class="btn btn-sm btn-outline-secondary rounded-pill" title="Тёмная тема" aria-pressed="false" aria-label="Переключить тему">
        <i class="fas fa-moon"></i>
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-av-action="copy-json" title="Скопировать JSON" aria-label="Скопировать данные аккаунта в формате JSON">
        <i class="fas fa-code"></i>
      </button>
      <a class="btn btn-sm btn-outline-secondary rounded-pill d-none d-sm-inline-flex align-items-center gap-1" id="downloadJsonBtn" href="#" download="account_<?= (int)$head['id'] ?>.json" title="Скачать JSON">
        <i class="fas fa-download"></i> JSON
      </a>
      <div class="vr mx-1"></div>
      <form method="POST" action="logout.php" style="margin:0;display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="Выйти из системы" aria-label="Выйти из системы" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
          <i class="fas fa-sign-out-alt"></i>
        </button>
      </form>
    </div>
  </div>
</nav>

<main class="av-page">
  <header class="av-hero">
    <div class="av-hero-main">
      <a class="av-back" href="index.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> К таблице</a>
      <div class="av-title-row">
        <h1 class="av-title"><?= e($head['name']) ?></h1>
        <button type="button" class="av-fav favorite-btn" data-account-id="<?= (int)$head['id'] ?>" title="Избранное" aria-label="Добавить в избранное">
          <i class="far fa-star"></i>
        </button>
      </div>
      <div class="av-meta">
        <button type="button" class="av-id-chip" data-copy-text="<?= (int)$head['id'] ?>" data-copy-label="номер аккаунта" title="Скопировать номер" aria-label="Скопировать номер аккаунта <?= (int)$head['id'] ?>">
          #<?= (int)$head['id'] ?> <i class="far fa-copy" aria-hidden="true"></i>
        </button>
        <?php if ($head['status'] !== ''): ?>
          <span class="av-status" title="Статус"><?= e($head['status']) ?></span>
        <?php endif; ?>
        <?php if ($head['created'] !== ''): ?>
          <span class="av-meta-item" title="Добавлен в базу"><i class="far fa-calendar-plus" aria-hidden="true"></i>добавлен <?= e($head['created']) ?></span>
        <?php endif; ?>
        <?php if ($head['updated'] !== ''): ?>
          <span class="av-meta-item" title="Последнее изменение"><i class="far fa-clock" aria-hidden="true"></i>изменён <?= e($head['updated']) ?></span>
        <?php endif; ?>
        <?php if ($hasSocialLink): ?>
          <a class="av-meta-item" href="<?= e($socialUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook" aria-hidden="true"></i>Открыть профиль</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="av-actions">
      <a class="av-btn" href="history.php?id=<?= (int)$head['id'] ?>"><i class="fas fa-history" aria-hidden="true"></i>История</a>
      <button type="button" class="av-btn" data-av-action="duplicate"><i class="far fa-clone" aria-hidden="true"></i>Дублировать</button>
      <button type="button" class="av-btn" data-av-action="copy-all" title="Все заполненные поля с подписями">
        <i class="fas fa-clipboard-list av-idle" aria-hidden="true"></i><i class="fas fa-check av-done" aria-hidden="true"></i>Копировать всё
      </button>
      <button type="button" class="av-btn av-btn--primary" data-av-action="copy-access" title="Логин, пароль, 2FA, почта, пароль почты, доп. почта">
        <i class="fas fa-key av-idle" aria-hidden="true"></i><i class="fas fa-check av-done" aria-hidden="true"></i>Копировать доступы
      </button>
    </div>
  </header>

  <div class="av-bar">
    <span>Заполнено полей: <strong><?= (int)$filledTotal ?></strong></span>
    <?php if ($layout['emptyCount'] > 0): ?>
      <button type="button" class="av-toggle-empty" data-av-action="toggle-empty" aria-pressed="false">
        <span class="av-when-hidden">Показать пустые (<?= (int)$layout['emptyCount'] ?>)</span>
        <span class="av-when-shown">Скрыть пустые</span>
      </button>
    <?php endif; ?>
  </div>

  <div class="av-columns">
    <div class="av-col">
      <div class="av-col-title">Доступы</div>
      <?= av_column($layout['columns']['left'], 'Доступов у аккаунта нет') ?>
    </div>
    <div class="av-col">
      <div class="av-col-title">Об аккаунте</div>
      <?= av_column($layout['columns']['right'], 'Сведений об аккаунте нет') ?>
    </div>
  </div>
  <div id="avLive" class="av-sr" aria-live="polite"></div>
</main>

<script type="application/json" id="accountViewData"><?= $pagePayload ?></script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/account-view.js?v=<?= ASSETS_VERSION ?>"></script>
<script>DashboardAccountView.init();</script>
<script src="assets/js/favorites.js?v=<?= ASSETS_VERSION ?>"></script>
<script src="assets/js/mobile-touch.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" defer></script>
<script src="assets/js/theme-toggle.js?v=<?= ASSETS_VERSION ?>" defer></script>
</body>
</html>

<?php
// Манифест бандлов CSS/JS. Шаблон самодостаточен: не полагаемся на то, что
// контроллер уже подключил класс.
require_once __DIR__ . '/../includes/AssetBundles.php';
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    (function(){try{var t=localStorage.getItem('dashboard-theme');
      if(!t){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}
      document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Корзина - Dashboard</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <!-- Свой CSS: один бандл, если он собран, иначе исходные core-*.css (см. AssetBundles) -->
<?= AssetBundles::tags('core.css') ?>

  <style>
    /* Заголовок корзины — чистый, danger-акцент (без glassmorphism/градиента) */
    .trash-header {
      background: var(--bg-primary);
      border: 1px solid var(--color-border);
      border-left: 3px solid var(--danger-500);
      color: var(--color-text);
      padding: var(--space-5) var(--space-6);
      border-radius: var(--radius-xl);
      margin-bottom: var(--space-6);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: var(--space-4);
      box-shadow: var(--shadow-sm);
    }
    .trash-header-main {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }
    .trash-icon-wrap {
      width: 52px;
      height: 52px;
      background: var(--danger-50);
      color: var(--danger-600);
      border-radius: var(--radius-lg);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    [data-bs-theme="dark"] .trash-icon-wrap { background: rgba(248,113,113,0.14); color: #fca5a5; }
    .trash-icon-wrap i {
      font-size: 1.5rem;
    }
    .trash-header h1 {
      margin: 0;
      font-size: var(--font-size-2xl);
      font-weight: 700;
      letter-spacing: -0.02em;
      color: var(--color-text);
    }
    .trash-subtitle {
      font-size: var(--font-size-sm);
      color: var(--color-text-secondary);
      margin-top: 2px;
    }
    .trash-count {
      font-size: var(--font-size-sm);
      font-weight: 500;
      color: var(--color-text-secondary);
      background: var(--bg-secondary);
      padding: var(--space-2) var(--space-4);
      border-radius: var(--radius-full);
      border: 1px solid var(--color-border);
    }
    .trash-count strong {
      color: var(--danger-600);
      font-weight: 700;
      font-size: var(--font-size-md);
    }
    [data-bs-theme="dark"] .trash-count strong { color: #fca5a5; }

    .trash-warning {
      background: var(--warning-50);
      border: 1px solid var(--warning-100);
      border-left: 3px solid var(--warning-500);
      border-radius: var(--radius-lg);
      padding: var(--space-4) var(--space-5);
      margin-bottom: var(--space-6);
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      color: var(--warning-700);
    }
    [data-bs-theme="dark"] .trash-warning { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.25); border-left-color: var(--warning-500); color: #fcd34d; }

    /* Нейтральное уведомление (итог прошлой автоочистки). Зеркалит .trash-warning
       по геометрии, но не кричит цветом: это отчёт о уже случившемся, а не угроза. */
    .trash-notice {
      background: var(--bg-secondary);
      border: 1px solid var(--color-border);
      border-left: 3px solid var(--gray-400);
      border-radius: var(--radius-lg);
      padding: var(--space-4) var(--space-5);
      margin-bottom: var(--space-6);
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      color: var(--color-text-secondary);
    }
    [data-bs-theme="dark"] .trash-notice { background: rgba(255,255,255,0.04); border-color: var(--color-border); border-left-color: var(--gray-500); }

    /* Бейджи "возраст / автоудаление" */
    .age-badge { font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
    .age-sub { font-size: 0.7rem; opacity: 0.75; display: block; margin-top: 2px; }
    .age-ok      { color: var(--gray-600, #4b5563); }
    .age-soon    { color: var(--warning-700, #b45309); }
    .age-overdue { color: var(--danger-600, #dc2626); font-weight: 700; }

    /* Кто удалил */
    .who-cell { font-size: 0.8125rem; }
    /* gray-400 — декоративный тон, для текста он не проходит AA (2.6:1). */
    .who-unknown { color: var(--gray-500, #6b7280); font-style: italic; }

    /* Баннер "выбрать все по фильтру" */
    .select-all-banner {
      display: none;
      align-items: center;
      justify-content: center;
      gap: var(--space-3);
      flex-wrap: wrap;
      background: rgba(59, 130, 246, 0.08);
      border: 1px dashed rgba(59, 130, 246, 0.4);
      border-radius: var(--radius-lg);
      padding: var(--space-3) var(--space-4);
      margin-bottom: var(--space-3);
      font-size: 0.875rem;
    }
    .select-all-banner.is-visible { display: flex; }
    .select-all-banner.is-active {
      background: rgba(59, 130, 246, 0.15);
      border-style: solid;
    }

    /* Панель retention */
    .retention-bar {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-wrap: wrap;
      background: var(--bg-primary);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      padding: var(--space-3) var(--space-4);
    }
    .retention-bar .form-control { max-width: 90px; }

    /* История: таймлайн */
    .history-item { border-left: 2px solid var(--gray-200,#e5e7eb); padding: 0 0 var(--space-3) var(--space-4); position: relative; }
    .history-item::before {
      content: ''; position: absolute; left: -5px; top: 4px; width: 8px; height: 8px;
      border-radius: 50%; background: var(--primary-500, #3b82f6);
    }
    .history-meta { font-size: 0.75rem; color: var(--gray-500,#6b7280); }
    .history-field { font-weight: 600; }
    .history-change { font-size: 0.8125rem; word-break: break-word; }

    /* ===== Тёмная тема: страница + новые элементы ===== */
    [data-bs-theme="dark"] body.bg-light { background: #0A0A0F !important; }
    [data-bs-theme="dark"] .age-ok { color: var(--gray-500); }
    [data-bs-theme="dark"] .age-soon { color: #fbbf24; }
    [data-bs-theme="dark"] .age-overdue { color: #f87171; }
    [data-bs-theme="dark"] .who-unknown { color: var(--gray-500); }
    [data-bs-theme="dark"] .history-item { border-left-color: var(--color-border); }
  </style>
</head>
<body class="bg-light">

  <!-- Навигация -->
  <nav class="navbar navbar-expand bg-white border-bottom shadow-sm mb-4" style="height: 64px;">
    <div class="container-fluid px-4">
      <a class="navbar-brand fw-bold" href="index.php">
        <i class="fas fa-chart-line text-primary me-2"></i>
        Dashboard
      </a>
      <div class="d-flex align-items-center gap-3">
        <span class="text-muted small fw-medium">
          <i class="fas fa-user-circle me-1 text-primary"></i>
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
        <button type="button" id="themeToggle" class="btn btn-sm btn-outline-secondary rounded-circle" title="Тёмная тема" aria-pressed="false" aria-label="Переключить тему" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
          <i class="fas fa-moon"></i>
        </button>
        <div class="vr mx-1"></div>
        <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill">
          <i class="fas fa-arrow-left me-1"></i> Назад
        </a>
        <form method="POST" action="logout.php" style="margin:0;display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="Выйти из системы" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
            <i class="fas fa-sign-out-alt"></i>
          </button>
        </form>
      </div>
    </div>
  </nav>

  <!-- Основной контент -->
  <main class="container-fluid px-4 pb-5">

    <?php if (isset($errorMessage)): ?>
    <div class="alert alert-danger shadow-sm rounded-xl" role="alert">
      <i class="fas fa-exclamation-circle me-2"></i>
      <strong>Ошибка:</strong> <?= htmlspecialchars($errorMessage) ?>
    </div>
    <?php endif; ?>

    <?php
    /*
     * Автоочистка корзины: предупреждение ДО и отчёт ПОСЛЕ.
     *
     * Очистка включена по умолчанию (30 дней) и запускается сама при заходе сюда.
     * Удаление физическое: записи не попадают ни в account_history, ни в журнал
     * отмены — восстановить их нечем. До 2026-08-10 единственным следом была
     * строчка в логе, и пользователь узнавал о потере только по отсутствию строк.
     *
     * Два разных сообщения, и порядок важен:
     *  - «будет удалено» показываем ПЕРЕД тем, как это случится: сама очистка
     *    выполняется в shutdown, уже после отрисовки этой страницы, так что у
     *    пользователя есть время передумать и продлить срок хранения;
     *  - «удалено» — результат ПРОШЛОГО прогона, показать его в момент удаления
     *    невозможно по той же причине.
     *
     * Партиал самодостаточен: нормализуем вход, чтобы Warning не оборвал рендер.
     */
    $purgeDueCount    = isset($purgeDueCount) ? (int)$purgeDueCount : 0;
    $lastPurgeDeleted = isset($lastPurgeDeleted) ? (int)$lastPurgeDeleted : 0;
    $retentionDays    = isset($retentionDays) ? (int)$retentionDays : 30;
    $lastPurgeAt      = isset($lastPurgeAt) ? $lastPurgeAt : null;

    /**
     * Русская форма слова по числу: 1 запись, 3 записи, 5 записей.
     * Без этого выходило «3 записей» — мелочь, но текст про безвозвратное
     * удаление должен читаться как написанный человеком.
     *
     * @param int $n Число
     * @param string $one Форма для 1 (запись)
     * @param string $few Форма для 2–4 (записи)
     * @param string $many Форма для 0, 5–20 (записей)
     * @return string Подходящая форма
     */
    if (!function_exists('trashPlural')) {
        function trashPlural($n, $one, $few, $many) {
            $n = abs((int)$n) % 100;
            if ($n >= 11 && $n <= 14) return $many;
            $n %= 10;
            if ($n === 1) return $one;
            if ($n >= 2 && $n <= 4) return $few;
            return $many;
        }
    }
    ?>

    <?php if ($purgeDueCount > 0): ?>
    <div class="trash-warning" role="alert">
      <i class="fas fa-triangle-exclamation fs-5 mt-1"></i>
      <div>
        <strong>Автоочистка удалит <?= number_format($purgeDueCount, 0, '.', ' ') ?>
          <?= trashPlural($purgeDueCount, 'запись', 'записи', 'записей') ?> безвозвратно.</strong>
        Это те, что лежат в корзине дольше <?= $retentionDays ?> дн.
        Восстановить их после удаления будет нельзя — отмена на автоочистку не действует.
        Чтобы этого не произошло, восстановите нужное сейчас или увеличьте срок
        хранения в настройках корзины.
      </div>
    </div>
    <?php endif; ?>

    <?php if ($lastPurgeDeleted > 0): ?>
    <div class="trash-notice" role="status">
      <i class="fas fa-circle-info fs-5 mt-1"></i>
      <div>
        Прошлая автоочистка удалила безвозвратно
        <strong><?= number_format($lastPurgeDeleted, 0, '.', ' ') ?></strong>
        <?= trashPlural($lastPurgeDeleted, 'запись', 'записи', 'записей') ?><?php if ($lastPurgeAt): ?>
        — <?= htmlspecialchars((string)$lastPurgeAt) ?><?php endif; ?>.
      </div>
    </div>
    <?php endif; ?>

    <!-- Заголовок корзины -->
    <div class="trash-header">
      <div class="trash-header-main">
        <div class="trash-icon-wrap">
          <i class="fas fa-trash-alt"></i>
        </div>
        <div>
          <h1>Корзина</h1>
          <div class="trash-subtitle">Хранилище удалённых аккаунтов (Soft Delete)</div>
        </div>
      </div>
      <div class="trash-count">
        Всего записей: <strong><?= number_format(isset($deletedCount) ? $deletedCount : 0) ?></strong>
      </div>
    </div>

    <!-- Предупреждение -->
    <div class="trash-warning">
      <i class="fas fa-exclamation-triangle fs-5 text-warning mt-1"></i>
      <div>
        <strong>Внимание!</strong> В корзине отображаются аккаунты, которые были удалены, но всё ещё хранятся в базе данных.
        Вы можете <strong><span class="text-success">восстановить</span></strong> их обратно или <strong><span class="text-danger">удалить навсегда</span></strong>.
      </div>
    </div>

    <!-- Поиск и Фильтр -->
    <div class="card card-modern mb-4">
      <div class="card-body p-4">
        <form method="get" action="trash.php" id="trashSearchForm">
          <div class="row g-3 align-items-end">
            <div class="col-md-4">
              <label class="form-label text-muted small fw-semibold mb-1">Поиск по корзине</label>
              <div class="position-relative">
                <i class="fas fa-search position-absolute text-muted" style="top: 50%; left: 16px; transform: translateY(-50%);"></i>
                <input
                  type="search"
                  name="q"
                  class="form-control"
                  placeholder="Логин, email, ID..."
                  value="<?= htmlspecialchars(isset($q) ? $q : '', ENT_QUOTES, 'UTF-8') ?>"
                  style="padding-left: 40px; border-radius: var(--radius-lg);"
                >
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label text-muted small fw-semibold mb-1">Статус</label>
              <select name="status" class="form-select" style="border-radius: var(--radius-lg);">
                <option value="">Все статусы</option>
                <?php
                  $curStatus = isset($_GET['status']) && !is_array($_GET['status']) ? (string)$_GET['status'] : '';
                  foreach (($statusList ?? []) as $st):
                ?>
                  <option value="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>" <?= $curStatus === $st ? 'selected' : '' ?>><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label text-muted small fw-semibold mb-1">Дата удаления (диапазон)</label>
              <div class="d-flex gap-2">
                <input type="date" name="deleted_from" class="form-control" value="<?= htmlspecialchars($_GET['deleted_from'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="border-radius: var(--radius-lg);">
                <input type="date" name="deleted_to" class="form-control" value="<?= htmlspecialchars($_GET['deleted_to'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="border-radius: var(--radius-lg);">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label text-muted small fw-semibold mb-1">Сортировка</label>
              <select name="sort" class="form-select" style="border-radius: var(--radius-lg);">
                <option value="id" <?= ($sort ?? 'deleted_at') === 'id' ? 'selected' : '' ?>>По ID аккаунта</option>
                <option value="login" <?= ($sort ?? 'deleted_at') === 'login' ? 'selected' : '' ?>>По логину (А-Я)</option>
                <option value="email" <?= ($sort ?? 'deleted_at') === 'email' ? 'selected' : '' ?>>По Email (А-Я)</option>
                <option value="deleted_at" <?= ($sort ?? 'deleted_at') === 'deleted_at' ? 'selected' : '' ?>>По дате удаления (сначала новые)</option>
              </select>
            </div>
            <div class="col-md-4 d-flex align-items-center gap-4 pt-2">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="only_empty" value="1" id="onlyEmptyCheck" <?= !empty($_GET['only_empty']) ? 'checked' : '' ?>>
                <label class="form-check-label small fw-semibold" for="onlyEmptyCheck">Только пустые (без логина и email)</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="empty_status" value="1" id="emptyStatusCheck" <?= !empty($_GET['empty_status']) ? 'checked' : '' ?>>
                <label class="form-check-label small fw-semibold" for="emptyStatusCheck">Пустой статус</label>
              </div>
            </div>
            <div class="col-md-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary w-100" style="border-radius: var(--radius-lg);">
                <i class="fas fa-filter me-2"></i> Применить
              </button>
              <a href="trash.php" class="btn btn-outline-secondary" style="border-radius: var(--radius-lg);" title="Сбросить фильтры">
                <i class="fas fa-times"></i>
              </a>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Настройки автоочистки (Retention) -->
    <div class="retention-bar mb-4">
      <span class="fw-semibold small text-muted d-flex align-items-center gap-2">
        <i class="fas fa-clock-rotate-left text-primary"></i> Автоочистка корзины
      </span>
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" id="retentionEnabled" <?= !empty($trashSettings['enabled']) ? 'checked' : '' ?>>
        <label class="form-check-label small" for="retentionEnabled">Включена</label>
      </div>
      <span class="small text-muted">Удалять навсегда старше</span>
      <input type="number" min="1" max="3650" class="form-control form-control-sm" id="retentionDays" value="<?= (int)$retentionDays ?>">
      <span class="small text-muted">дней</span>
      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="saveRetentionBtn">
        <i class="fas fa-save me-1"></i> Сохранить
      </button>
      <div class="vr"></div>
      <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3" id="purgeOldBtn">
        <i class="fas fa-broom me-1"></i> Очистить старше N дней сейчас
      </button>
      <?php if (!empty($trashSettings['last_purge_at'])): ?>
        <span class="small text-muted ms-auto">Последняя автоочистка: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($trashSettings['last_purge_at'])), ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
    </div>

    <?php if (isset($deletedCount) && $deletedCount > 0): ?>

    <!-- Панель инструментов (Массовые действия) -->
    <div class="toolbar-modern mb-4">
      <div class="d-flex align-items-center gap-3">
        <div class="toolbar-modern-title d-flex align-items-center gap-2">
          <i class="fas fa-tasks text-muted"></i> Опции корзины
        </div>
        <div class="vr"></div>
        <span class="text-muted small">Выбрано аккаунтов: <strong id="selectedCount" class="text-primary fs-6">0</strong></span>
      </div>
      <div class="toolbar-modern-actions">
        <button class="btn btn-sm btn-outline-success rounded-pill px-3" id="restoreSelectedBtn" disabled>
          <i class="fas fa-undo me-1"></i> Восстановить выбранное
        </button>
        <button class="btn btn-sm btn-outline-danger rounded-pill px-3" id="deletePermanentlyBtn" disabled>
          <i class="fas fa-minus-circle me-1"></i> Удалить навсегда
        </button>
        <div class="vr mx-1"></div>
        <button class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm" id="emptyTrashBtn">
          <i class="fas fa-dumpster-fire me-1"></i> Очистить корзину полностью
        </button>
      </div>
    </div>

    <!-- Баннер "выбрать все по фильтру" -->
    <div class="select-all-banner" id="selectAllBanner">
      <span id="selectAllBannerText"></span>
      <button type="button" class="btn btn-sm btn-link p-0" id="selectAllFilterBtn"></button>
    </div>

    <!-- Таблица (Premium Glassmorphism) -->
    <div class="dashboard-table">
      <div class="dashboard-table__inner">
        <div class="dashboard-table__scroll">
          <table class="ac-table" id="trashTable">
            <thead>
              <tr>
                <th class="ac-cell--checkbox text-center" style="width: 50px;">
                  <div class="form-check justify-content-center m-0">
                    <input class="form-check-input" type="checkbox" id="selectAllTrash" title="Выбрать все на странице">
                  </div>
                </th>
                <th>ID</th>
                <th>Логин</th>
                <th>Email</th>
                <th>Статус</th>
                <th>Дата удаления</th>
                <th>Возраст</th>
                <th>Удалил</th>
                <th class="ac-cell--actions text-center">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    // Расчёт возраста и срока автоудаления
                    $ageHtml = '<span class="text-muted">—</span>';
                    if (!empty($r['deleted_at'])) {
                        $delTs = strtotime($r['deleted_at']);
                        if ($delTs !== false) {
                            $ageDays = (int)floor((time() - $delTs) / 86400);
                            $ageLabel = $ageDays <= 0 ? 'сегодня' : ('в корзине ' . $ageDays . ' дн.');
                            $cls = 'age-ok';
                            $sub = '';
                            if (!empty($trashSettings['enabled'])) {
                                $remaining = (int)$retentionDays - $ageDays;
                                if ($remaining <= 0) {
                                    $cls = 'age-overdue';
                                    $sub = 'просрочено — будет удалено';
                                } elseif ($remaining <= 7) {
                                    $cls = 'age-soon';
                                    $sub = 'автоудаление через ' . $remaining . ' дн.';
                                } else {
                                    $sub = 'автоудаление через ' . $remaining . ' дн.';
                                }
                            }
                            $ageHtml = '<span class="age-badge ' . $cls . '">' . htmlspecialchars($ageLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                            if ($sub !== '') {
                                $ageHtml .= '<span class="age-sub ' . $cls . '">' . htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                        }
                    }
                    // Кто удалил
                    $rid = (int)$r['id'];
                    $who = $deletedByMap[$rid]['changed_by'] ?? '';
                  ?>
                  <tr data-id="<?= $rid ?>">
                    <td class="ac-cell--checkbox text-center">
                      <div class="form-check justify-content-center m-0">
                        <input class="form-check-input trash-checkbox" type="checkbox" value="<?= $rid ?>">
                      </div>
                    </td>
                    <td class="fw-bold text-muted">#<?= $rid ?></td>
                    <td class="fw-medium text-dark"><?= htmlspecialchars(isset($r['login']) ? $r['login'] : '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="text-muted"><i class="fas fa-envelope me-2 opacity-50"></i><?= htmlspecialchars(isset($r['email']) ? $r['email'] : '', ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td>
                      <?php if (!empty($r['status'])): ?>
                        <span class="badge bg-secondary px-3 py-2 rounded-pill shadow-sm"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php else: ?>
                        <span class="badge badge-empty-status px-3 py-2 rounded-pill shadow-sm">Пустой статус</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['deleted_at'])): ?>
                        <span class="text-danger fw-medium" style="font-size: 0.8125rem;">
                          <i class="fas fa-clock me-1 opacity-50"></i>
                          <?= date('d.m.Y H:i', strtotime($r['deleted_at'])) ?>
                        </span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td><?= $ageHtml ?></td>
                    <td class="who-cell">
                      <?php if ($who !== ''): ?>
                        <span><i class="fas fa-user me-1 opacity-50"></i><?= htmlspecialchars($who, ENT_QUOTES, 'UTF-8') ?></span>
                      <?php else: ?>
                        <span class="who-unknown">неизвестно</span>
                      <?php endif; ?>
                    </td>
                    <td class="ac-cell--actions text-center">
                      <div class="btn-group btn-group-sm rounded-pill shadow-sm">
                        <button type="button" class="btn btn-outline-secondary history-btn border-end-0" data-id="<?= $rid ?>" title="История изменений">
                          <i class="fas fa-clock-rotate-left"></i>
                        </button>
                        <button type="button" class="btn btn-outline-success restore-btn border-end-0 border-start-0" data-id="<?= $rid ?>" title="Восстановить">
                          <i class="fas fa-undo"></i> Восстановить
                        </button>
                        <button type="button" class="btn btn-outline-danger delete-permanent-btn border-start-0" data-id="<?= $rid ?>" title="Удалить навсегда">
                          <i class="fas fa-times"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="9">
                    <div class="empty-state border-0 shadow-none my-2">
                       <h3 class="empty-state-title">Нет совпадений</h3>
                       <p class="empty-state-desc">По вашему запросу ничего не найдено в корзине.</p>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if (isset($pages) && $pages > 1): ?>
        <div class="dashboard-table__footer">
          <div class="dashboard-table__counter">
            Страница <span class="dashboard-table__counter-value"><?= $page ?? 1 ?></span> из <?= $pages ?>
          </div>
          <nav>
            <ul class="pagination pagination-modern m-0">
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
    <div class="empty-state">
      <i class="fas fa-dumpster empty-state-icon" style="background: linear-gradient(135deg, var(--gray-300), var(--gray-500)); -webkit-background-clip: text;"></i>
      <h3 class="empty-state-title" style="font-size: 1.5rem;">Корзина пуста</h3>
      <p class="empty-state-desc" style="font-size: 1rem;">Здесь будут храниться удаленные аккаунты. В данный момент корзина абсолютно чиста.</p>
      <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-2">
        <i class="fas fa-arrow-left me-2"></i> Вернуться к дашборду
      </a>
    </div>

    <?php endif; ?>

  </main>

  <!-- Модалка: История изменений -->
  <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
      <div class="modal-content rounded-xl">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>История изменений <span id="historyAccountId" class="text-muted"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
        </div>
        <div class="modal-body" id="historyBody">
          <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Загрузка…</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Модалка: подтверждение необратимого действия по фильтру (typed-confirm) -->
  <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content rounded-xl">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-triangle-exclamation me-2"></i>Подтверждение удаления</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Закрыть"></button>
        </div>
        <div class="modal-body">
          <p id="confirmDeleteText" class="mb-3"></p>
          <p class="text-muted small mb-2">Для подтверждения введите число <strong id="confirmDeleteNumber"></strong>:</p>
          <input type="text" inputmode="numeric" class="form-control" id="confirmDeleteInput" placeholder="Введите число для подтверждения">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
          <button type="button" class="btn btn-danger" id="confirmDeleteOk" disabled>
            <i class="fas fa-minus-circle me-1"></i> Удалить навсегда
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="assets/js/toast.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
  <script src="assets/js/theme-toggle.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
  <script>
    window.DashboardConfig = window.DashboardConfig || {};
    window.DashboardConfig.csrfToken = <?= json_encode((string)getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;

    // Конфиг страницы корзины для trash.js (режим "выбрать все по фильтру", retention).
    window.TrashConfig = {
      filterParams: <?= json_encode($trashFilterParams ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>,
      filteredTotal: <?= (int)($filteredTotal ?? 0) ?>,
      pageRows: <?= isset($rows) ? count($rows) : 0 ?>,
      retention: {
        enabled: <?= !empty($trashSettings['enabled']) ? 'true' : 'false' ?>,
        days: <?= (int)$retentionDays ?>
      }
    };

    // На странице корзины не подключается dashboard-init.js, поэтому
    // даём минимальное определение getTableAwareUrl — иначе trash.js падает
    // с "window.getTableAwareUrl is not a function" при empty/restore.
    if (typeof window.getTableAwareUrl !== 'function') {
      window.getTableAwareUrl = function (url) {
        var table = (window.DashboardConfig && window.DashboardConfig.currentTable) ||
                    (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.currentTable) || '';
        if (!table || table === 'accounts') return url;
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        return url + sep + 'table=' + encodeURIComponent(table);
      };
    }
  </script>
  <script src="assets/js/trash.js?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>"></script>
</body>
</html>

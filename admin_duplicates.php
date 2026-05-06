<?php
/**
 * admin_duplicates.php — поиск и удаление существующих дублей в БД.
 *
 * Под старой логикой дедуп шёл только по login → в БД могли накопиться
 * дубли по FB ID (тот же FB-аккаунт под разными логинами). Эта страница:
 *   1. Сканирует все активные аккаунты.
 *   2. Извлекает FB ID-fingerprint (id_soc_account / social_url / c_user
 *      из cookies — см. AccountFingerprint).
 *   3. Группирует аккаунты по совпадающим FB ID через union-find.
 *   4. Показывает группы пользователю; для каждой группы можно выбрать
 *      какой аккаунт оставить (по дефолту — самый старый по created_at).
 *   5. По нажатию "Удалить помеченные" — soft-delete всех неотмеченных
 *      (через AccountsService::deleteAccounts → deleted_at = NOW).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/AccountFingerprint.php';
require_once __DIR__ . '/includes/Validator.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Logger.php';

requireAuth();
checkSessionTimeout();

function e_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$service = new AccountsService($tableName);
$flash = null;

// ───────────── POST: удаление выбранных дублей ─────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        Validator::validateCsrfToken((string)($_POST['csrf'] ?? ''));
    } catch (Throwable $e) {
        http_response_code(403);
        die('CSRF validation failed');
    }

    if (($_POST['action'] ?? '') === 'delete') {
        // $_POST['keep'][groupKey] = id оставляемого аккаунта.
        // $_POST['group_ids'][groupKey] = CSV всех id в группе.
        $keepMap   = is_array($_POST['keep'] ?? null)      ? $_POST['keep']      : [];
        $groupIdsMap = is_array($_POST['group_ids'] ?? null) ? $_POST['group_ids'] : [];

        $toDelete = [];
        foreach ($groupIdsMap as $groupKey => $csvIds) {
            $idsInGroup = array_filter(array_map('intval', explode(',', (string)$csvIds)));
            $keepId     = (int)($keepMap[$groupKey] ?? 0);
            // Безопасность: keepId должен быть в составе группы (чтобы юзер
            // не мог через подделку формы удалить произвольную запись).
            if (!in_array($keepId, $idsInGroup, true)) {
                continue;
            }
            foreach ($idsInGroup as $id) {
                if ($id !== $keepId) {
                    $toDelete[] = $id;
                }
            }
        }
        $toDelete = array_values(array_unique(array_filter($toDelete, static fn($x) => $x > 0)));

        if (empty($toDelete)) {
            $flash = ['type' => 'info', 'msg' => 'Не выбрано ни одного аккаунта для удаления.'];
        } else {
            try {
                $deleted = $service->deleteAccounts($toDelete);
                Logger::info('ADMIN DUPLICATES: deleted', ['count' => $deleted, 'ids' => $toDelete]);
                $flash = ['type' => 'success', 'msg' => "Удалено $deleted аккаунтов в корзину (восстановимы оттуда)."];
            } catch (Throwable $e) {
                Logger::error('ADMIN DUPLICATES: delete failed', ['error' => $e->getMessage()]);
                $flash = ['type' => 'danger', 'msg' => 'Ошибка удаления: ' . $e->getMessage()];
            }
        }
    }
}

// ───────────── GET: поиск групп дублей ─────────────
$mysqli = Database::getInstance()->getConnection();
$cookiesTrunc = (int)Config::VALIDATE_COOKIES_TRUNCATE;

// Берём ТОЛЬКО активные (не удалённые). Cookies обрезаем до 4KB —
// c_user всегда лежит в первых ~4KB FB-cookies, full LONGTEXT
// scan на 10K строк = 50–100MB лишней памяти.
$cols = ['id', 'login', 'id_soc_account', 'social_url', 'created_at', 'status'];
$cookiesAlias = "SUBSTRING(cookies, 1, $cookiesTrunc) AS cookies";

$sql = "SELECT id, login, id_soc_account, social_url, created_at, status, $cookiesAlias "
     . "FROM accounts WHERE deleted_at IS NULL ORDER BY id ASC";
$result = $mysqli->query($sql);

$accounts = [];           // id => row
$accountFbIds = [];       // id => [fbId, ...]
$fbidToAccounts = [];     // fbId => [id, ...]

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];
        $accounts[$id] = $row;
        $fbIds = AccountFingerprint::extractFbIds($row);
        // Дополнительно: если сам login выглядит как FB ID, тоже учитываем.
        $login = trim((string)($row['login'] ?? ''));
        if ($login !== '' && preg_match(AccountFingerprint::FB_ID_PATTERN, $login)) {
            $fbIds[] = $login;
        }
        $fbIds = array_values(array_unique($fbIds));
        $accountFbIds[$id] = $fbIds;
        foreach ($fbIds as $fbId) {
            $fbidToAccounts[$fbId][] = $id;
        }
    }
    $result->free();
}

// Union-find: объединяем аккаунты, у которых есть хотя бы один общий FB ID.
$parent = [];
foreach (array_keys($accounts) as $id) { $parent[$id] = $id; }

$find = function (int $x) use (&$parent): int {
    while ($parent[$x] !== $x) {
        $parent[$x] = $parent[$parent[$x]] ?? $parent[$x]; // path compression
        $x = $parent[$x];
    }
    return $x;
};
$union = function (int $a, int $b) use (&$parent, $find): void {
    $ra = $find($a); $rb = $find($b);
    if ($ra !== $rb) { $parent[$ra] = $rb; }
};

foreach ($fbidToAccounts as $fbId => $ids) {
    if (count($ids) < 2) continue;
    $first = $ids[0];
    foreach ($ids as $i => $other) {
        if ($i === 0) continue;
        $union($first, $other);
    }
}

// Группируем аккаунты по корню union-find. Включаем в результат
// только группы из 2+ аккаунтов.
$rootToIds = [];
foreach (array_keys($accounts) as $id) {
    $root = $find($id);
    $rootToIds[$root][] = $id;
}
$groups = [];
foreach ($rootToIds as $root => $ids) {
    if (count($ids) < 2) continue;
    // Собираем общие FB IDs группы (для отображения "по чему совпало").
    $fbIdsInGroup = [];
    foreach ($ids as $id) {
        foreach ($accountFbIds[$id] ?? [] as $fbId) {
            $fbIdsInGroup[$fbId] = true;
        }
    }
    // Сортируем аккаунты в группе по created_at ASC (старые сверху —
    // дефолтный keep — самый ранний).
    usort($ids, function ($a, $b) use ($accounts) {
        $ca = strtotime((string)($accounts[$a]['created_at'] ?? '1970-01-01'));
        $cb = strtotime((string)($accounts[$b]['created_at'] ?? '1970-01-01'));
        return $ca <=> $cb ?: ($a <=> $b);
    });
    $groups[] = [
        'key'     => 'g_' . $root,
        'ids'     => $ids,
        'fb_ids'  => array_keys($fbIdsInGroup),
    ];
}
// Сортируем группы по размеру (большие сверху).
usort($groups, fn($a, $b) => count($b['ids']) - count($a['ids']));

$totalDupGroups = count($groups);
$totalDupAccounts = array_sum(array_map(fn($g) => count($g['ids']), $groups));
$totalToDelete   = array_sum(array_map(fn($g) => count($g['ids']) - 1, $groups));

$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Поиск дублей — Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body { background: #f6f8fa; }
    .header { background:#fff; border-bottom:1px solid #e5e7eb; padding:1rem 1.5rem; margin-bottom:1.5rem; }
    .group-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:1rem; overflow:hidden; }
    .group-head { padding:.75rem 1rem; background:#f3f4f6; border-bottom:1px solid #e5e7eb; font-weight:600; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
    .group-head .fbids { font-family:monospace; font-size:.85rem; color:#6b7280; word-break:break-all; }
    .acc-row { padding:.5rem 1rem; border-bottom:1px solid #f3f4f6; display:grid; grid-template-columns: 40px 90px 1.5fr 2fr 1.5fr 1fr; gap:1rem; align-items:center; font-size:.9rem; }
    .acc-row:last-child { border-bottom:none; }
    .acc-row.keep { background:#ecfdf5; }
    .acc-row.del  { background:#fff7ed; }
    .acc-id { font-weight:600; color:#1e40af; }
    .acc-login { font-weight:500; word-break:break-all; }
    .acc-meta { color:#6b7280; font-size:.825rem; word-break:break-all; }
    .badge-keep { background:#10b981; color:#fff; font-size:.7rem; padding:.15rem .4rem; border-radius:4px; }
    .badge-del  { background:#f59e0b; color:#fff; font-size:.7rem; padding:.15rem .4rem; border-radius:4px; }
    .empty-state { text-align:center; padding:4rem; background:#fff; border-radius:8px; border:1px solid #e5e7eb; }
    .actions-bar { position:sticky; top:0; background:#fff; border-bottom:1px solid #e5e7eb; padding:.75rem 1.5rem; margin-bottom:1.5rem; z-index:10; box-shadow:0 1px 3px rgba(0,0,0,.05); }
  </style>
</head>
<body>

<div class="header">
  <div class="d-flex align-items-center justify-content-between">
    <div>
      <h1 class="h4 mb-1"><i class="fas fa-clone me-2 text-warning"></i>Поиск и удаление дублей</h1>
      <small class="text-muted">Аккаунты с одинаковым FB ID (id_soc_account / c_user в cookies / FB ID в social_url)</small>
    </div>
    <div>
      <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> К дашборду</a>
    </div>
  </div>
</div>

<div class="container-fluid" style="max-width:1400px;">

<?php if ($flash): ?>
  <div class="alert alert-<?= e_html($flash['type']) ?>"><?= e_html($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($totalDupGroups === 0): ?>
  <div class="empty-state">
    <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
    <h3>Дублей не найдено</h3>
    <p class="text-muted">Все аккаунты в БД уникальны по FB ID.</p>
  </div>
<?php else: ?>

  <form method="POST" id="dupForm">
    <input type="hidden" name="csrf" value="<?= e_html($csrf) ?>">
    <input type="hidden" name="action" value="delete">

    <div class="actions-bar">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
          <strong><?= $totalDupGroups ?></strong> групп · <strong><?= $totalDupAccounts ?></strong> аккаунтов с дублями · к удалению по умолчанию: <strong class="text-danger"><?= $totalToDelete ?></strong>
        </div>
        <div class="d-flex gap-2">
          <button type="button" id="keepNewest" class="btn btn-sm btn-outline-secondary" title="В каждой группе оставить самый новый">Оставить новые</button>
          <button type="button" id="keepOldest" class="btn btn-sm btn-outline-secondary" title="В каждой группе оставить самый старый (дефолт)">Оставить старые</button>
          <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить <?= $totalToDelete ?> аккаунтов в корзину?\n\nИх можно будет восстановить из корзины.');">
            <i class="fas fa-trash me-1"></i> Удалить помеченные
          </button>
        </div>
      </div>
    </div>

    <?php foreach ($groups as $group): ?>
      <?php $groupKey = $group['key']; $idsCsv = implode(',', $group['ids']); ?>
      <div class="group-card" data-group="<?= e_html($groupKey) ?>">
        <div class="group-head">
          <div>
            <i class="fas fa-users me-1 text-warning"></i>
            <?= count($group['ids']) ?> аккаунтов · общий FB ID:
            <span class="fbids"><?= e_html(implode(', ', array_slice($group['fb_ids'], 0, 3))) ?><?= count($group['fb_ids']) > 3 ? ' …' : '' ?></span>
          </div>
        </div>
        <input type="hidden" name="group_ids[<?= e_html($groupKey) ?>]" value="<?= e_html($idsCsv) ?>">

        <?php foreach ($group['ids'] as $i => $accId): $acc = $accounts[$accId]; ?>
          <?php $isDefaultKeep = ($i === 0); // первый = oldest по сортировке выше ?>
          <label class="acc-row <?= $isDefaultKeep ? 'keep' : 'del' ?>">
            <input type="radio" name="keep[<?= e_html($groupKey) ?>]" value="<?= (int)$accId ?>" <?= $isDefaultKeep ? 'checked' : '' ?> class="keep-radio">
            <div class="acc-id">#<?= (int)$acc['id'] ?></div>
            <div>
              <div class="acc-login"><?= e_html($acc['login'] ?? '') ?></div>
              <div class="acc-meta">создан: <?= e_html($acc['created_at'] ?? '—') ?></div>
            </div>
            <div class="acc-meta">
              <?php if (!empty($acc['id_soc_account'])): ?>
                <div><strong>id_soc:</strong> <?= e_html($acc['id_soc_account']) ?></div>
              <?php endif; ?>
              <?php if (!empty($acc['social_url'])): ?>
                <div><strong>url:</strong> <?= e_html($acc['social_url']) ?></div>
              <?php endif; ?>
            </div>
            <div class="acc-meta">
              <?php
                $cUser = !empty($acc['cookies']) ? AccountFingerprint::extractCUserFromCookies($acc['cookies']) : null;
                if ($cUser !== null):
              ?>
                <strong>c_user:</strong> <?= e_html($cUser) ?>
              <?php else: ?>
                <span class="text-muted">— нет c_user в cookies</span>
              <?php endif; ?>
            </div>
            <div>
              <span class="default-badge badge-keep" style="<?= $isDefaultKeep ? '' : 'display:none' ?>">оставить</span>
              <span class="default-badge badge-del"  style="<?= $isDefaultKeep ? 'display:none' : '' ?>">удалить</span>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  </form>

<?php endif; ?>

</div>

<script>
// Перерасчёт визуальных подсветок (keep/del) по выбору radio в группе.
function updateGroup(groupCard) {
  const checkedId = groupCard.querySelector('input[type="radio"]:checked')?.value;
  groupCard.querySelectorAll('.acc-row').forEach(row => {
    const radio = row.querySelector('input[type="radio"]');
    const isKeep = radio && radio.value === checkedId;
    row.classList.toggle('keep', isKeep);
    row.classList.toggle('del',  !isKeep);
    const keepBadge = row.querySelector('.badge-keep');
    const delBadge  = row.querySelector('.badge-del');
    if (keepBadge) keepBadge.style.display = isKeep ? '' : 'none';
    if (delBadge)  delBadge.style.display  = isKeep ? 'none' : '';
  });
}

document.querySelectorAll('.keep-radio').forEach(r => {
  r.addEventListener('change', () => updateGroup(r.closest('.group-card')));
});

document.getElementById('keepOldest')?.addEventListener('click', () => {
  document.querySelectorAll('.group-card').forEach(card => {
    const first = card.querySelector('.keep-radio'); // первый — oldest по серверной сортировке
    if (first) { first.checked = true; updateGroup(card); }
  });
});
document.getElementById('keepNewest')?.addEventListener('click', () => {
  document.querySelectorAll('.group-card').forEach(card => {
    const radios = card.querySelectorAll('.keep-radio');
    const last = radios[radios.length - 1];
    if (last) { last.checked = true; updateGroup(card); }
  });
});
</script>

</body>
</html>

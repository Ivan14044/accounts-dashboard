<?php
/**
 * bundles.php — связки «кинг + рекламные кабинеты»: список и выгрузка.
 *
 * ЧТО ТАКОЕ СВЯЗКА. Один главный аккаунт (кинг) и несколько дешёвых авторегов,
 * которые отдали ему права на свои рекламные кабинеты. Собирает их софт
 * фермы; в базу он кладёт две колонки:
 *
 *   `bundle`      — номер связки. Стоит У ВСЕХ её участников, и равен `id`
 *                   главного аккаунта: номер уникален в базе навсегда и сам
 *                   говорит, чья это десятка. Вторая связка того же кинга
 *                   получает хвост: «262-2».
 *   `bundle_ads`  — у кинга перечень кабинетов, которые ему отдали.
 *
 * ЗАЧЕМ ЭТА СТРАНИЦА. Собранную связку надо ОТДАТЬ покупателю одним файлом:
 * вход кинга, входы всех доноров и номера кабинетов. До сих пор такой файл
 * умела отдавать только панель на самой ферме — то есть за ним приходилось
 * лезть на сервер. Здесь то же самое делается из общей панели.
 *
 * ПОЧЕМУ БЕЗ ОБРАЩЕНИЙ К ФЕРМЕ. Всё, что нужно, уже лежит в этой же базе:
 * номер связки, входы аккаунтов и номера кабинетов. Ходить за этим по сети на
 * чужую машину значило бы поставить выгрузку в зависимость от того, включена
 * ли ферма и доступна ли она снаружи.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Database.php';

requireAuth();
checkSessionTimeout();

if (!function_exists('e_html')) {
    function e_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$mysqli = Database::getInstance()->getConnection();

/**
 * Строки одной связки: главный аккаунт первым, доноры под ним.
 *
 * Кинг узнаётся по тому, что номер связки — это его `id` (см. шапку). У формы
 * с хвостом («262-2») сравнение идёт по числовой части, поэтому CAST.
 *
 * @param mysqli $mysqli
 * @param string $bundle Номер связки
 * @return array Список строк аккаунтов
 */
function bundle_rows(mysqli $mysqli, string $bundle): array {
    $sql = "SELECT id, login, password, email, email_password, two_fa, "
         . "first_name, last_name, status, ads_id, bundle, bundle_ads "
         . "FROM accounts WHERE bundle = ? AND deleted_at IS NULL "
         . "ORDER BY (CAST(bundle AS UNSIGNED) <> id), id";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('s', $bundle);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    $stmt->close();
    return $rows;
}

/**
 * Одна строка входа — ровно в том виде, в каком её ждёт покупатель.
 */
function bundle_login_line(array $r): string {
    $parts = [(string)($r['login'] ?? ''), (string)($r['password'] ?? '')];
    foreach (['email', 'email_password', 'two_fa'] as $k) {
        if (!empty($r[$k])) { $parts[] = (string)$r[$k]; }
    }
    return implode(':', array_filter($parts, function ($p) { return $p !== ''; }));
}

// ───────────── выгрузка одной связки файлом ─────────────
$download = isset($_GET['download']) ? trim((string)$_GET['download']) : '';
if ($download !== '') {
    // Номер связки — цифры и, возможно, хвост «-2». Всё прочее отсекаем: имя
    // попадает в заголовок файла, и подставлять туда что угодно нельзя.
    if (!preg_match('/^\d{1,20}(-\d{1,3})?$/', $download)) {
        http_response_code(400);
        die('Неверный номер связки');
    }
    $rows = bundle_rows($mysqli, $download);
    if (!$rows) {
        http_response_code(404);
        die('Связки с таким номером нет');
    }
    $king = array_shift($rows);
    $lines = [
        'Связка №' . $download,
        'Кабинетов: ' . count(array_filter($rows, function ($r) {
            return !empty($r['ads_id']);
        })),
        '',
        'ГЛАВНЫЙ (кинг):',
        bundle_login_line($king),
        '',
        'ДОНОРЫ И ИХ КАБИНЕТЫ:',
    ];
    $cabs = [];
    foreach ($rows as $r) {
        $cab = trim((string)($r['ads_id'] ?? ''));
        if ($cab !== '') { $cabs[] = $cab; }
        $lines[] = ($cab !== '' ? 'act_' . $cab . '  ' : '')
                 . bundle_login_line($r);
    }
    $lines[] = '';
    $lines[] = 'Кабинеты одной строкой:';
    $lines[] = implode(', ', $cabs);

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="svyazka-' . $download . '.txt"');
    header('Cache-Control: no-store');
    echo implode("\n", $lines) . "\n";
    exit;
}

// ───────────── список связок ─────────────
//
// Считаем прямо в базе и по указателю `idx_bundle` (его заводит софт фермы):
// перебирать 185 тысяч строк ради списка из десятка связок незачем.
$bundles = [];
$sql = "SELECT bundle, COUNT(*) AS total, "
     . "SUM(CASE WHEN ads_id IS NOT NULL AND ads_id <> '' THEN 1 ELSE 0 END) AS with_cab, "
     . "MAX(updated_at) AS touched "
     . "FROM accounts WHERE bundle IS NOT NULL AND bundle <> '' "
     . "AND deleted_at IS NULL GROUP BY bundle ORDER BY touched DESC";
if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) { $bundles[] = $row; }
    $res->free();
}

// Главные аккаунты связок — одним запросом, чтобы показать имя и статус.
$kings = [];
if ($bundles) {
    $ids = [];
    foreach ($bundles as $b) {
        $ids[] = (int)$b['bundle'];   // «262-2» -> 262, это и есть кинг
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids) {
        $in = implode(',', $ids);
        $sqlK = "SELECT id, first_name, last_name, login, status, bundle, bundle_ads "
              . "FROM accounts WHERE id IN ($in)";
        if ($res = $mysqli->query($sqlK)) {
            while ($row = $res->fetch_assoc()) { $kings[(int)$row['id']] = $row; }
            $res->free();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    (function(){try{var t=localStorage.getItem('dashboard-theme');
      if(!t){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}
      document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <title>Связки — Dashboard</title>
  <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <style>
    body { background:#f6f8fa; }
    .header { background:#fff; border-bottom:1px solid #e5e7eb; padding:1rem 1.5rem; margin-bottom:1.5rem; }
    .bundle-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:1rem; }
    .bundle-head { padding:.75rem 1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
    .bundle-num { font-weight:700; color:#1e40af; }
    .bundle-meta { color:#6b7280; font-size:.875rem; }
    .cab-count { font-weight:600; }
    .cab-count.full { color:#059669; }
    .empty-state { text-align:center; padding:4rem; background:#fff; border-radius:8px; border:1px solid #e5e7eb; }
    [data-bs-theme="dark"] body { background:#0A0A0F; color:#C6CBD2; }
    [data-bs-theme="dark"] .header,
    [data-bs-theme="dark"] .bundle-card,
    [data-bs-theme="dark"] .empty-state { background:#15161A; border-color:#2C2E36; }
    [data-bs-theme="dark"] .bundle-num { color:#60a5fa; }
    [data-bs-theme="dark"] .bundle-meta { color:#8C929C; }
  </style>
</head>
<body>
  <div class="header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="fas fa-link me-2"></i>Связки «кинг + рекламные кабинеты»</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-arrow-left me-1"></i>К аккаунтам
    </a>
  </div>

  <div class="container-fluid" style="max-width:1100px">
    <?php if (!$bundles): ?>
      <div class="empty-state">
        <i class="fas fa-link fa-2x mb-3 text-muted"></i>
        <p class="mb-1">Связок пока нет.</p>
        <p class="text-muted mb-0">Они появятся здесь, как только ферма соберёт первую.</p>
      </div>
    <?php else: ?>
      <?php foreach ($bundles as $b):
        $num   = (string)$b['bundle'];
        $king  = $kings[(int)$num] ?? null;
        $cabs  = (int)$b['with_cab'];
        $name  = $king ? trim((string)$king['first_name'] . ' ' . (string)$king['last_name']) : '';
        if ($name === '' && $king) { $name = (string)$king['login']; }
      ?>
        <div class="bundle-card">
          <div class="bundle-head">
            <div>
              <span class="bundle-num">Связка №<?= e_html($num) ?></span>
              <?php if ($name !== ''): ?>
                <span class="ms-2"><?= e_html($name) ?></span>
              <?php endif; ?>
              <div class="bundle-meta">
                <span class="cab-count<?= $cabs >= 9 ? ' full' : '' ?>">
                  кабинетов: <?= $cabs ?>
                </span>
                · аккаунтов в связке: <?= (int)$b['total'] ?>
                <?php if ($king && $king['status'] !== ''): ?>
                  · статус главного: <?= e_html((string)$king['status']) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-primary"
                 href="index.php?q=<?= urlencode($num) ?>">
                <i class="fas fa-search me-1"></i>Показать аккаунты
              </a>
              <a class="btn btn-sm btn-primary"
                 href="bundles.php?download=<?= urlencode($num) ?>">
                <i class="fas fa-download me-1"></i>Скачать
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>

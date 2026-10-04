<?php
/** Журнал выдачи данных экспорта. Значения аккаунтов здесь не хранятся. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Database.php';

requireAuth();
checkSessionTimeout();

function exportLogEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function exportLogJsonList($value): array {
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function exportLogLabel(string $kind, string $value): string {
    $labels = [
        'stage' => ['file' => 'файл', 'idlist' => 'список ID', 'rows' => 'фрагмент TXT', 'audit_log' => 'журнал действий'],
        'scope' => ['selected' => 'выбранные', 'all' => 'все по фильтру', 'custom' => 'лимит', 'filtered' => 'по фильтру'],
        'state' => ['started' => 'начата', 'completed' => 'отдано сервером', 'failed' => 'ошибка', 'interrupted' => 'прервана'],
    ];
    return $labels[$kind][$value] ?? $value;
}

$db = Database::getInstance()->getConnection();
$dateFrom = (string)($_GET['date_from'] ?? '');
$dateTo = (string)($_GET['date_to'] ?? '');
$username = trim((string)($_GET['user'] ?? ''));
$ip = trim((string)($_GET['ip'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$conditions = [];
$values = [];
$types = '';

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $conditions[] = 'created_at >= ?';
    $values[] = $dateFrom . ' 00:00:00';
    $types .= 's';
} else { $dateFrom = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $conditions[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $values[] = $dateTo . ' 00:00:00';
    $types .= 's';
} else { $dateTo = ''; }
if ($username !== '') {
    $conditions[] = 'username = ?';
    $values[] = substr($username, 0, 255);
    $types .= 's';
}
if ($ip !== '') {
    $conditions[] = 'ip_address = ?';
    $values[] = substr($ip, 0, 45);
    $types .= 's';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$exists = $db->query("SHOW TABLES LIKE 'export_audit'");
$hasTable = $exists && $exists->num_rows > 0;
$total = 0;
$rows = [];

if ($hasTable) {
    $count = $db->prepare('SELECT COUNT(*) AS n FROM export_audit' . $where);
    if ($types !== '') { $count->bind_param($types, ...$values); }
    $count->execute();
    $total = (int)$count->get_result()->fetch_assoc()['n'];
    $count->close();

    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $list = $db->prepare('SELECT id, username, ip_address, table_name, format, stage, scope, columns_json, filter_status_json, row_count, status_counts_json, state, created_at, finished_at FROM export_audit' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
    $listValues = array_merge($values, [$perPage, $offset]);
    $list->bind_param($types . 'ii', ...$listValues);
    $list->execute();
    $rows = $list->get_result()->fetch_all(MYSQLI_ASSOC);
    $list->close();
}

function exportLogPageUrl(int $page): string {
    $query = $_GET;
    $query['page'] = $page;
    return 'export_logs.php?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="ru" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Журнал выгрузок — Dashboard</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="assets/css/core-theme.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
</head>
<body>
<main class="container-fluid py-4" style="max-width: 1700px">
    <header class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Журнал выгрузок</h1>
            <p class="text-muted mb-0">Запросы на выдачу данных. Время указано по часовому поясу сервера.</p>
        </div>
        <a class="btn btn-outline-secondary" href="admin_logs.php">← Журнал действий</a>
    </header>

    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-sm-3 col-lg-2"><label class="form-label" for="date_from">С даты</label><input id="date_from" name="date_from" type="date" class="form-control" value="<?= exportLogEscape($dateFrom) ?>"></div>
        <div class="col-sm-3 col-lg-2"><label class="form-label" for="date_to">По дату</label><input id="date_to" name="date_to" type="date" class="form-control" value="<?= exportLogEscape($dateTo) ?>"></div>
        <div class="col-sm-3 col-lg-3"><label class="form-label" for="user">Логин</label><input id="user" name="user" class="form-control" value="<?= exportLogEscape($username) ?>"></div>
        <div class="col-sm-3 col-lg-2"><label class="form-label" for="ip">IP</label><input id="ip" name="ip" class="form-control" value="<?= exportLogEscape($ip) ?>"></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit">Показать</button></div>
        <div class="col-auto"><a class="btn btn-outline-secondary" href="export_logs.php">Сбросить</a></div>
    </form>

    <?php if (!$hasTable): ?>
        <div class="alert alert-info">Журнал пока пуст. Он появится после первой выгрузки с новой версией приложения.</div>
    <?php else: ?>
        <p class="text-muted">Найдено записей: <?= number_format($total, 0, ',', ' ') ?>. Для TXT каждый фрагмент показан отдельно.</p>
        <div class="table-responsive border rounded">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead><tr><th>Время</th><th>Логин / IP</th><th>Источник</th><th>Формат</th><th>Объём</th><th>Статусы</th><th>Поля / фильтр</th><th>Результат</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $counts = exportLogJsonList($row['status_counts_json']); $columns = exportLogJsonList($row['columns_json']); $statuses = exportLogJsonList($row['filter_status_json']); ?>
                    <tr>
                        <td class="text-nowrap"><?= exportLogEscape($row['created_at']) ?></td>
                        <td><div><?= exportLogEscape($row['username']) ?></div><small class="text-muted"><?= exportLogEscape($row['ip_address']) ?></small></td>
                        <td><?= exportLogEscape($row['table_name']) ?></td>
                        <td><?= exportLogEscape(strtoupper($row['format'])) ?><small class="d-block text-muted"><?= exportLogEscape(exportLogLabel('stage', $row['stage'])) ?> · <?= exportLogEscape(exportLogLabel('scope', $row['scope'])) ?></small></td>
                        <td class="text-nowrap"><?= number_format((int)$row['row_count'], 0, ',', ' ') ?></td>
                        <td><?php if ($counts): foreach ($counts as $status => $count): ?><div><?= exportLogEscape($status === '' ? '(пусто)' : $status) ?>: <?= (int)$count ?></div><?php endforeach; else: ?>—<?php endif; ?></td>
                        <td><small><?= exportLogEscape(implode(', ', $columns)) ?></small><?php if ($statuses): ?><small class="d-block text-muted">Фильтр: <?= exportLogEscape(implode(', ', $statuses)) ?></small><?php endif; ?></td>
                        <td><?= exportLogEscape(exportLogLabel('state', $row['state'])) ?><small class="d-block text-muted"><?= exportLogEscape($row['finished_at'] ?? '') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Записи не найдены</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > $perPage): ?>
            <nav class="d-flex gap-2 mt-3" aria-label="Страницы журнала">
                <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="<?= exportLogEscape(exportLogPageUrl($page - 1)) ?>">← Назад</a><?php endif; ?>
                <span class="align-self-center">Страница <?= $page ?> из <?= (int)ceil($total / $perPage) ?></span>
                <?php if ($page * $perPage < $total): ?><a class="btn btn-outline-secondary" href="<?= exportLogEscape(exportLogPageUrl($page + 1)) ?>">Вперёд →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>

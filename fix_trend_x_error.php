<?php
/**
 * Переносит аккаунты в статус trend_x_error.
 *
 * Условие: аккаунт был в статусе trend_x_create_fp и за последние 3 дня
 * перешёл в один из статусов: trash, wrong_password, check_whatsapp.
 *
 * Режимы:
 *   GET  /fix_trend_x_error.php          — предпросмотр (dry-run)
 *   POST /fix_trend_x_error.php          — применить изменения
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/AuditLogger.php';
require_once __DIR__ . '/includes/Logger.php';

requireAuth();
checkSessionTimeout();

// ── Константы ────────────────────────────────────────────────────────────────

const FROM_STATUS  = 'trend_x_create_fp';
const NEW_STATUS   = 'trend_x_error';
const DAYS_BACK    = 3;
const TARGET_STATUSES = ['trash', 'wrong_password', 'check_whatsapp'];

// ── Helpers ───────────────────────────────────────────────────────────────────

function getAffectedAccounts(mysqli $db): array
{
    $placeholders = implode(',', array_fill(0, count(TARGET_STATUSES), '?'));
    $types        = 's' . str_repeat('s', count(TARGET_STATUSES)) . str_repeat('s', count(TARGET_STATUSES));

    // Ищем аккаунты, у которых в account_history есть запись:
    //   статус изменился С trend_x_create_fp НА один из целевых — за последние N дней.
    // Текущий статус аккаунта тоже должен быть одним из целевых
    // (исключаем уже перенесённые в trend_x_error).
    $sql = "
        SELECT DISTINCT
            a.id,
            a.login,
            a.status AS current_status,
            h.new_value AS transition_to,
            h.changed_at AS transitioned_at
        FROM accounts a
        INNER JOIN account_history h ON h.account_id = a.id
        WHERE h.field_name    = 'status'
          AND h.old_value     = ?
          AND h.new_value     IN ($placeholders)
          AND h.changed_at    >= NOW() - INTERVAL " . DAYS_BACK . " DAY
          AND a.status        IN ($placeholders)
          AND a.deleted_at    IS NULL
        ORDER BY h.changed_at DESC
    ";

    $params = array_merge([FROM_STATUS], TARGET_STATUSES, TARGET_STATUSES);
    $stmt   = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function applyUpdate(mysqli $db, array $accounts): int
{
    if (empty($accounts)) {
        return 0;
    }

    $ids = array_column($accounts, 'id');

    // Сохраняем текущие статусы для аудита
    $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $types          = str_repeat('i', count($ids));

    $selectSql = "SELECT id, status FROM accounts WHERE id IN ($idPlaceholders)";
    $stmt = $db->prepare($selectSql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $currentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $oldStatuses = [];
    foreach ($currentRows as $row) {
        $oldStatuses[$row['id']] = $row['status'];
    }

    // Обновляем статус
    $updateSql = "UPDATE accounts SET status = ?, updated_at = NOW() WHERE id IN ($idPlaceholders) AND deleted_at IS NULL";
    $updateParams = array_merge([NEW_STATUS], $ids);
    $updateTypes  = 's' . str_repeat('i', count($ids));

    $stmt = $db->prepare($updateSql);
    $stmt->bind_param($updateTypes, ...$updateParams);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    // Пишем в account_history для каждого обновлённого аккаунта
    $histSql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address)
                VALUES (?, 'status', ?, ?, 'fix_trend_x_error', ?)";
    $histStmt = $db->prepare($histSql);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    foreach ($ids as $id) {
        $old = $oldStatuses[$id] ?? '';
        $histStmt->bind_param('isss', $id, $old, NEW_STATUS, $ip);
        $histStmt->execute();
    }
    $histStmt->close();

    Logger::info('fix_trend_x_error: applied', [
        'affected' => $affected,
        'ids'      => $ids,
        'user'     => $_SESSION['username'] ?? 'unknown',
    ]);

    return $affected;
}

// ── Основная логика ───────────────────────────────────────────────────────────

$dbObj    = Database::getInstance();
$mysqli   = $dbObj->getConnection();
$isDryRun = ($_SERVER['REQUEST_METHOD'] !== 'POST');
$error    = null;
$affected = 0;
$accounts = [];

try {
    $accounts = getAffectedAccounts($mysqli);

    if (!$isDryRun) {
        if (empty($_POST['confirmed']) || $_POST['confirmed'] !== '1') {
            $error = 'Не передан параметр confirmed=1';
            $isDryRun = true; // откатываемся в режим просмотра
        } else {
            $affected = applyUpdate($mysqli, $accounts);
            // После обновления перечитываем — их уже не будет в списке
            $accounts = getAffectedAccounts($mysqli);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    Logger::error('fix_trend_x_error: exception', ['msg' => $e->getMessage()]);
}

// ── Вывод ─────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>fix_trend_x_error</title>
<style>
  body { font-family: monospace; padding: 20px; background: #1a1a2e; color: #e0e0e0; }
  h1 { color: #f0c040; margin-bottom: 4px; }
  .meta { color: #888; font-size: 13px; margin-bottom: 20px; }
  .info  { background: #0d3349; border-left: 4px solid #3a9fd5; padding: 12px 16px; margin: 12px 0; }
  .warn  { background: #3a2000; border-left: 4px solid #f0a000; padding: 12px 16px; margin: 12px 0; }
  .ok    { background: #0d3320; border-left: 4px solid #30c060; padding: 12px 16px; margin: 12px 0; }
  .err   { background: #3a0d0d; border-left: 4px solid #d53a3a; padding: 12px 16px; margin: 12px 0; }
  table  { border-collapse: collapse; width: 100%; margin-top: 16px; font-size: 13px; }
  th, td { border: 1px solid #333; padding: 6px 10px; text-align: left; }
  th     { background: #222; color: #aaa; }
  tr:hover td { background: #1e2a3a; }
  form   { margin-top: 20px; }
  button { background: #c0392b; color: #fff; border: none; padding: 10px 24px;
           font-size: 15px; cursor: pointer; border-radius: 4px; }
  button:hover { background: #e74c3c; }
  .tag-from { color: #aaa; }
  .tag-new  { color: #30c060; font-weight: bold; }
</style>
</head>
<body>

<h1>fix_trend_x_error</h1>
<div class="meta">
  Источник: <span class="tag-from"><?= FROM_STATUS ?></span> &rarr;
  цель: <span class="tag-new"><?= NEW_STATUS ?></span><br>
  Целевые статусы: <code><?= implode(', ', TARGET_STATUSES) ?></code><br>
  Окно: последние <?= DAYS_BACK ?> дня
</div>

<?php if ($error): ?>
  <div class="err"><strong>Ошибка:</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!$isDryRun && !$error): ?>
  <div class="ok">
    <strong>Готово.</strong> Обновлено аккаунтов: <strong><?= $affected ?></strong><br>
    Оставшихся в очереди: <?= count($accounts) ?>
  </div>
<?php elseif ($isDryRun): ?>
  <div class="info">
    <strong>Режим предпросмотра (dry-run).</strong>
    Найдено аккаунтов для переноса: <strong><?= count($accounts) ?></strong>.<br>
    Нажмите кнопку ниже, чтобы применить изменения.
  </div>
<?php endif; ?>

<?php if (!empty($accounts)): ?>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>ID</th>
      <th>Login</th>
      <th>Текущий статус</th>
      <th>Переход из</th>
      <th>Дата перехода</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($accounts as $i => $row): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= (int)$row['id'] ?></td>
      <td><?= htmlspecialchars($row['login'] ?? '—') ?></td>
      <td><?= htmlspecialchars($row['current_status']) ?></td>
      <td><?= htmlspecialchars(FROM_STATUS) ?></td>
      <td><?= htmlspecialchars($row['transitioned_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php elseif (!$error): ?>
  <div class="warn">Аккаунтов, удовлетворяющих условию, не найдено.</div>
<?php endif; ?>

<?php if ($isDryRun && !empty($accounts)): ?>
<form method="POST">
  <input type="hidden" name="confirmed" value="1">
  <div class="warn" style="margin-top:16px">
    Будет изменён статус <strong><?= count($accounts) ?></strong> аккаунтов
    &rarr; <span class="tag-new"><?= NEW_STATUS ?></span>.<br>
    Действие записывается в account_history и необратимо без ручного отката.
  </div>
  <button type="submit">Применить (<?= count($accounts) ?> аккаунтов)</button>
</form>
<?php endif; ?>

</body>
</html>

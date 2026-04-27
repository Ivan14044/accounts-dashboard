<?php
/**
 * Диагностика fix_trend_x_error.
 * Показывает почему аккаунты не находятся и что реально есть в БД.
 * Удали этот файл после диагностики.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Database.php';

requireAuth();
checkSessionTimeout();

$db  = Database::getInstance();
$myi = $db->getConnection();

function q(mysqli $db, string $sql, array $p = []): array {
    if (!$p) {
        $r = $db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
    $types = str_repeat('s', count($p));
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$p);
    $st->execute();
    $r = $st->get_result();
    $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>debug_trend_x</title>
<style>
  body{font-family:monospace;background:#111;color:#ddd;padding:20px}
  h2{color:#f0c040;border-bottom:1px solid #333;padding-bottom:4px}
  table{border-collapse:collapse;width:100%;margin:10px 0 24px;font-size:13px}
  th,td{border:1px solid #333;padding:5px 9px;text-align:left}
  th{background:#222;color:#aaa}
  .none{color:#888;font-style:italic}
  .hi{color:#4fc;font-weight:bold}
</style>
</head>
<body>

<?php

// ── 1. Аккаунты с текущим статусом trend_x_create_fp ─────────────────────────
echo '<h2>1. Аккаунты с текущим статусом <span class="hi">trend_x_create_fp</span></h2>';
$rows = q($myi, "SELECT id, login, status, updated_at FROM accounts WHERE status = 'trend_x_create_fp' AND deleted_at IS NULL LIMIT 20");
if ($rows) {
    echo '<table><tr><th>id</th><th>login</th><th>status</th><th>updated_at</th></tr>';
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>" . htmlspecialchars($r['login']??'') . "</td><td>{$r['status']}</td><td>{$r['updated_at']}</td></tr>";
    }
    echo '</table>';
} else {
    echo '<p class="none">Не найдено</p>';
}

// ── 2. Аккаунты с целевыми статусами ─────────────────────────────────────────
echo '<h2>2. Аккаунты с текущим статусом <span class="hi">trash / wrong_password / check_whatsapp</span></h2>';
$rows = q($myi, "SELECT id, login, status, updated_at FROM accounts WHERE status IN ('trash','wrong_password','check_whatsapp') AND deleted_at IS NULL LIMIT 20");
if ($rows) {
    echo '<table><tr><th>id</th><th>login</th><th>status</th><th>updated_at</th></tr>';
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>" . htmlspecialchars($r['login']??'') . "</td><td>{$r['status']}</td><td>{$r['updated_at']}</td></tr>";
    }
    echo '</table>';
} else {
    echo '<p class="none">Не найдено</p>';
}

// ── 3. Что есть в account_history за последние 3 дня ─────────────────────────
echo '<h2>3. account_history — последние записи за 3 дня (field_name=status)</h2>';
$histExists = $myi->query("SHOW TABLES LIKE 'account_history'")->num_rows > 0;
if (!$histExists) {
    echo '<p class="none">Таблица account_history не существует!</p>';
} else {
    $rows = q($myi, "SELECT account_id, old_value, new_value, changed_by, changed_at FROM account_history WHERE field_name='status' AND changed_at >= NOW() - INTERVAL 3 DAY ORDER BY changed_at DESC LIMIT 30");
    if ($rows) {
        echo '<table><tr><th>account_id</th><th>old_value</th><th>new_value</th><th>changed_by</th><th>changed_at</th></tr>';
        foreach ($rows as $r) {
            echo "<tr><td>{$r['account_id']}</td><td>{$r['old_value']}</td><td>{$r['new_value']}</td><td>{$r['changed_by']}</td><td>{$r['changed_at']}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p class="none">Нет записей за последние 3 дня с field_name=status</p>';
    }

    // 3b. Сколько записей вообще в history
    $cnt = q($myi, "SELECT COUNT(*) as c FROM account_history WHERE field_name='status'");
    echo '<p>Всего записей field_name=status в account_history: <b>' . ($cnt[0]['c'] ?? 0) . '</b></p>';

    // 3c. Записи с old_value = trend_x_create_fp (без ограничения по времени)
    echo '<h2>4. account_history — все переходы ИЗ <span class="hi">trend_x_create_fp</span> (без лимита по дате)</h2>';
    $rows = q($myi, "SELECT account_id, old_value, new_value, changed_at FROM account_history WHERE field_name='status' AND old_value='trend_x_create_fp' ORDER BY changed_at DESC LIMIT 20");
    if ($rows) {
        echo '<table><tr><th>account_id</th><th>old_value</th><th>new_value</th><th>changed_at</th></tr>';
        foreach ($rows as $r) {
            echo "<tr><td>{$r['account_id']}</td><td>{$r['old_value']}</td><td>{$r['new_value']}</td><td>{$r['changed_at']}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p class="none">Нет записей с old_value=trend_x_create_fp</p>';
    }
}

// ── 4. Реальные статусы в БД (топ-30 по количеству) ──────────────────────────
echo '<h2>5. Все статусы в таблице accounts (топ-30)</h2>';
$rows = q($myi, "SELECT status, COUNT(*) as cnt FROM accounts WHERE deleted_at IS NULL GROUP BY status ORDER BY cnt DESC LIMIT 30");
if ($rows) {
    echo '<table><tr><th>status</th><th>count</th></tr>';
    foreach ($rows as $r) {
        $hi = in_array($r['status'], ['trend_x_create_fp','trend_x_error','trash','wrong_password','check_whatsapp']) ? ' class="hi"' : '';
        echo "<tr><td{$hi}>" . htmlspecialchars($r['status']??'NULL') . "</td><td>{$r['cnt']}</td></tr>";
    }
    echo '</table>';
}

?>
</body>
</html>

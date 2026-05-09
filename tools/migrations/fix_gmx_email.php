<?php
/**
 * Утилита: очистка email и email_password для аккаунтов с почтой @gmx.com
 * Шаг 1 — просмотр:   /fix_gmx_email.php
 * Шаг 2 — выполнение: /fix_gmx_email.php?confirm=1
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: text/html; charset=utf-8');

requireAuth();

$db     = Database::getInstance();
$mysqli = $db->getConnection();
$domain = 'gmx.com';
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

$pattern = '%@' . $domain;
$stmt = $mysqli->prepare(
    "SELECT id, login, email, email_password FROM accounts
     WHERE email LIKE ? ORDER BY id"
);
$stmt->bind_param('s', $pattern);
$stmt->execute();
$result = $stmt->get_result();
$rows   = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count = count($rows);

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">
<title>Fix GMX Email</title>
<style>
  body { font-family: monospace; padding: 24px; background: #1a1a1a; color: #e0e0e0; }
  h2   { color: #f5c518; }
  table{ border-collapse: collapse; width: 100%; margin-top: 16px; }
  th,td{ border: 1px solid #444; padding: 6px 10px; text-align: left; }
  th   { background: #333; }
  tr:hover { background: #2a2a2a; }
  .btn { display:inline-block; margin-top:20px; padding:10px 24px;
         background:#c0392b; color:#fff; text-decoration:none;
         border-radius:4px; font-size:15px; }
  .ok  { color: #2ecc71; font-weight: bold; }
  .warn{ color: #e67e22; }
</style></head><body>';

echo "<h2>Очистка email + email_password для <code>@{$domain}</code></h2>";

if ($count === 0) {
    echo "<p class=\"ok\">Аккаунтов с почтой @{$domain} не найдено. Всё чисто.</p>";
    echo '</body></html>';
    exit;
}

if (!$confirm) {
    echo "<p class=\"warn\">Найдено записей: <strong>{$count}</strong> — поля email и email_password будут очищены (→ NULL).</p>";
    echo '<table><tr><th>id</th><th>login</th><th>email</th><th>email_password</th></tr>';
    foreach ($rows as $r) {
        $id    = htmlspecialchars($r['id']);
        $login = htmlspecialchars($r['login']);
        $email = htmlspecialchars($r['email']);
        $pass  = $r['email_password'] !== null ? '●●●●●●' : '<em>пусто</em>';
        echo "<tr><td>{$id}</td><td>{$login}</td><td>{$email}</td><td>{$pass}</td></tr>";
    }
    echo '</table>';
    echo '<a class="btn" href="?confirm=1">Подтвердить: очистить email + email_password у ' . $count . ' записей</a>';
} else {
    $upd = $mysqli->prepare(
        "UPDATE accounts
         SET email = NULL, email_password = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE email LIKE ?"
    );
    $upd->bind_param('s', $pattern);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    $db->clearCache();

    echo "<p class=\"ok\">Готово! Очищено записей: <strong>{$affected}</strong>.</p>";
    echo "<p>Поля <code>email</code> и <code>email_password</code> обнулены у всех аккаунтов с почтой <code>@{$domain}</code>.</p>";
    echo '<p><a href="index.php">← Вернуться в дашборд</a></p>';
}

echo '</body></html>';

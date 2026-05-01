<?php
/**
 * Утилита: очистка 31-значных значений two_fa у аккаунтов со статусом perechec_true
 * Запуск: открыть в браузере будучи залогиненным в дашборде
 * Шаг 1 — просмотр: /fix_2fa_31digit.php
 * Шаг 2 — выполнение: /fix_2fa_31digit.php?confirm=1
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: text/html; charset=utf-8');

// Только для авторизованных пользователей
requireAuth();

$db = Database::getInstance();
$mysqli = $db->getConnection();

$STATUS   = 'perechec_true';
$FA_LEN   = 31;
$confirm  = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// Находим записи-кандидаты
$stmt = $mysqli->prepare(
    "SELECT id, login, two_fa FROM accounts
     WHERE status = ? AND CHAR_LENGTH(two_fa) = ?
     ORDER BY id"
);
$stmt->bind_param('si', $STATUS, $FA_LEN);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count = count($rows);

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">
<title>Fix 2FA 31-digit</title>
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

echo "<h2>Очистка 31-значных 2FA (статус: <code>{$STATUS}</code>)</h2>";

if ($count === 0) {
    echo '<p class="ok">Записей с 31-значным two_fa и статусом perechec_true не найдено. Всё чисто.</p>';
    echo '</body></html>';
    exit;
}

if (!$confirm) {
    // РЕЖИМ ПРОСМОТРА
    echo "<p class=\"warn\">Найдено записей: <strong>{$count}</strong> — two_fa будет очищен (→ NULL).</p>";
    echo '<table><tr><th>id</th><th>login</th><th>two_fa (31 символ)</th></tr>';
    foreach ($rows as $r) {
        $id    = htmlspecialchars($r['id']);
        $login = htmlspecialchars($r['login']);
        $fa    = htmlspecialchars($r['two_fa']);
        echo "<tr><td>{$id}</td><td>{$login}</td><td>{$fa}</td></tr>";
    }
    echo '</table>';
    echo '<a class="btn" href="?confirm=1">Подтвердить: очистить two_fa у ' . $count . ' записей</a>';
} else {
    // РЕЖИМ ВЫПОЛНЕНИЯ
    $upd = $mysqli->prepare(
        "UPDATE accounts SET two_fa = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE status = ? AND CHAR_LENGTH(two_fa) = ?"
    );
    $upd->bind_param('si', $STATUS, $FA_LEN);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    $db->clearCache();

    echo "<p class=\"ok\">Готово! Очищено записей: <strong>{$affected}</strong>.</p>";
    echo '<p>Поле <code>two_fa</code> установлено в NULL для всех аккаунтов с 31-значным значением и статусом <code>perechec_true</code>.</p>';
    echo '<p><a href="index.php">← Вернуться в дашборд</a></p>';
}

echo '</body></html>';

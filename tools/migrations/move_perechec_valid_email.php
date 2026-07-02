<?php
/**
 * Утилита: перенос аккаунтов со статусами perechec_true / perechec
 * в статус perechec_valid_email, если email содержит один из доменов:
 * fuhrenmail.com, vargosmail.com, wildbmail.com, duhastmail.com,
 * tacoblastmail.com, legenmail.com
 *
 * Запуск: открыть в браузере будучи залогиненным в дашборде
 * Шаг 1 — просмотр:    /tools/migrations/move_perechec_valid_email.php
 * Шаг 2 — выполнение:  /tools/migrations/move_perechec_valid_email.php?confirm=1
 * Другая таблица:      добавить &table=имя_таблицы (по умолчанию accounts)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: text/html; charset=utf-8');

// Только для авторизованных пользователей
requireAuth();

$db = Database::getInstance();
$mysqli = $db->getConnection();

$FROM_STATUSES = ['perechec_true', 'perechec'];
$TO_STATUS     = 'perechec_valid_email';
$DOMAINS = [
    'fuhrenmail.com',
    'vargosmail.com',
    'wildbmail.com',
    'duhastmail.com',
    'tacoblastmail.com',
    'legenmail.com',
];

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// Таблица: по умолчанию accounts, можно переопределить через ?table=,
// но только если такая таблица реально существует в текущей БД
$table = 'accounts';
if (isset($_GET['table']) && $_GET['table'] !== '') {
    $requested = $_GET['table'];
    $chk = $mysqli->prepare(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ?"
    );
    $chk->bind_param('s', $requested);
    $chk->execute();
    $found = $chk->get_result()->fetch_row();
    $chk->close();
    if (!$found) {
        http_response_code(400);
        die('Таблица не найдена: ' . htmlspecialchars($requested));
    }
    $table = $found[0]; // имя из INFORMATION_SCHEMA — безопасно для подстановки
}

// Проверяем наличие нужных колонок (email, status, deleted_at)
$cols = [];
$colStmt = $mysqli->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
);
$colStmt->bind_param('s', $table);
$colStmt->execute();
$colRes = $colStmt->get_result();
while ($row = $colRes->fetch_row()) {
    $cols[] = $row[0];
}
$colStmt->close();

foreach (['email', 'status'] as $required) {
    if (!in_array($required, $cols, true)) {
        http_response_code(400);
        die('В таблице ' . htmlspecialchars($table) . ' нет колонки ' . $required);
    }
}
$hasDeletedAt = in_array('deleted_at', $cols, true);
$hasUpdatedAt = in_array('updated_at', $cols, true);

// Условие: статус из списка + email содержит @домен + не в корзине
$statusPlaceholders = implode(',', array_fill(0, count($FROM_STATUSES), '?'));
$domainConditions = implode(' OR ', array_fill(0, count($DOMAINS), 'email LIKE ?'));
$where = "status IN ($statusPlaceholders) AND ($domainConditions)"
       . ($hasDeletedAt ? ' AND deleted_at IS NULL' : '');

$params = $FROM_STATUSES;
foreach ($DOMAINS as $d) {
    $params[] = '%@' . $d . '%';
}
$types = str_repeat('s', count($params));

// Находим записи-кандидаты
$stmt = $mysqli->prepare(
    "SELECT id, login, email, status FROM `$table` WHERE $where ORDER BY id"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count = count($rows);

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">
<title>Перенос в perechec_valid_email</title>
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
  code { color: #6fc3df; }
</style></head><body>';

echo '<h2>Перенос perechec_true / perechec → <code>' . htmlspecialchars($TO_STATUS) . '</code></h2>';
echo '<p>Таблица: <code>' . htmlspecialchars($table) . '</code>. Домены: <code>'
   . htmlspecialchars(implode(', ', $DOMAINS)) . '</code>'
   . ($hasDeletedAt ? ' (аккаунты в корзине не трогаем)' : '') . '</p>';

if ($count === 0) {
    echo '<p class="ok">Подходящих записей не найдено.</p>';
    echo '</body></html>';
    exit;
}

if (!$confirm) {
    // РЕЖИМ ПРОСМОТРА
    echo "<p class=\"warn\">Найдено записей: <strong>{$count}</strong> — статус будет изменён на <code>{$TO_STATUS}</code>.</p>";

    // Сводка по текущим статусам
    $byStatus = [];
    foreach ($rows as $r) {
        $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
    }
    echo '<p>';
    foreach ($byStatus as $s => $n) {
        echo htmlspecialchars($s) . ': <strong>' . $n . '</strong>&nbsp;&nbsp;';
    }
    echo '</p>';

    echo '<table><tr><th>id</th><th>login</th><th>email</th><th>текущий статус</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . htmlspecialchars($r['id']) . '</td>'
           . '<td>' . htmlspecialchars($r['login']) . '</td>'
           . '<td>' . htmlspecialchars($r['email']) . '</td>'
           . '<td>' . htmlspecialchars($r['status']) . '</td></tr>';
    }
    echo '</table>';
    $tableParam = $table !== 'accounts' ? '&table=' . urlencode($table) : '';
    echo '<a class="btn" href="?confirm=1' . $tableParam . '">Подтвердить: перенести '
       . $count . ' записей в ' . htmlspecialchars($TO_STATUS) . '</a>';
} else {
    // РЕЖИМ ВЫПОЛНЕНИЯ
    $set = 'status = ?' . ($hasUpdatedAt ? ', updated_at = CURRENT_TIMESTAMP' : '');
    $upd = $mysqli->prepare("UPDATE `$table` SET $set WHERE $where");
    $updParams = array_merge([$TO_STATUS], $params);
    $upd->bind_param('s' . $types, ...$updParams);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    $db->clearCache();

    echo "<p class=\"ok\">Готово! Перенесено записей: <strong>{$affected}</strong>.</p>";
    echo '<p>Статус установлен в <code>' . htmlspecialchars($TO_STATUS)
       . '</code> для всех подходящих аккаунтов.</p>';
    echo '<p><a href="../../index.php">← Вернуться в дашборд</a></p>';
}

echo '</body></html>';

<?php
/**
 * Утилита: перенос «залежавшихся» аккаунтов в статус perechec_new_ivan.
 *
 * Берём аккаунты, которые СЕЙЧАС в статусе sale_2 или trash_document,
 * смотрим по account_history, КОГДА они получили этот текущий статус,
 * и переносим в perechec_new_ivan те, что попали туда РАНЬШЕ, чем полгода
 * назад (changed_at < NOW() − 6 месяцев).
 *
 * Важные решения (согласованы с владельцем):
 *  - «позднее чем пол года» = СТАРШЕ полугода (залежались), а не «недавние».
 *  - Аккаунты БЕЗ записи о смене статуса в account_history ПРОПУСКАЕМ
 *    (дату определить нельзя — не трогаем). Их количество показано отдельно.
 *  - Работаем ТОЛЬКО по таблице `accounts`: account_history жёстко завязана
 *    на неё (FK + чтение старых значений из хардкод-`accounts` в AuditLogger),
 *    у других таблиц аккаунтов история недостоверна.
 *
 * Дата «когда добавили в текущий статус» = самая свежая запись
 * account_history с field_name='status' и new_value = текущий статус аккаунта
 * (MAX(changed_at)). Это корректно и при цепочках sale_2 → … → sale_2.
 *
 * Запуск: открыть в браузере будучи залогиненным в дашборде
 * Шаг 1 — просмотр:   /tools/migrations/move_stale_to_perechec_new_ivan.php
 * Шаг 2 — выполнение: /tools/migrations/move_stale_to_perechec_new_ivan.php?confirm=1
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: text/html; charset=utf-8');

// Только для авторизованных пользователей
requireAuth();

$db = Database::getInstance();
$mysqli = $db->getConnection();

$TABLE         = 'accounts';
$FROM_STATUSES = ['sale_2', 'trash_document'];
$TO_STATUS     = 'perechec_new_ivan';
$MONTHS        = 6;

// Явная collation для сравнения колонка↔колонка (history.new_value ↔ accounts.status).
// В проекте account_history — utf8mb4_unicode_ci, а accounts.status на MySQL 8
// обычно utf8mb4_0900_ai_ci ⇒ прямое '=' даёт "Illegal mix of collations".
// Обе колонки в charset utf8mb4, поэтому приведение обеих сторон к одной
// collation безопасно и снимает конфликт.
$COLL = 'utf8mb4_unicode_ci';

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// Какие из колонок реально есть в таблице (login/email — только для показа,
// updated_at / deleted_at — для корректного UPDATE и исключения корзины)
$cols = [];
$colStmt = $mysqli->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
);
$colStmt->bind_param('s', $TABLE);
$colStmt->execute();
$colRes = $colStmt->get_result();
while ($row = $colRes->fetch_row()) {
    $cols[] = $row[0];
}
$colStmt->close();

if (!in_array('status', $cols, true)) {
    http_response_code(500);
    die('В таблице ' . htmlspecialchars($TABLE) . ' нет колонки status');
}
$hasLogin     = in_array('login', $cols, true);
$hasEmail     = in_array('email', $cols, true);
$hasDeletedAt = in_array('deleted_at', $cols, true);
$hasUpdatedAt = in_array('updated_at', $cols, true);

$softDeleteClause = $hasDeletedAt ? "\n      AND a.deleted_at IS NULL" : '';

// Подзапрос h — «когда аккаунт в последний раз получил ЭТОТ статус».
// INNER JOIN на new_value = a.status автоматически отбрасывает аккаунты без
// подходящей записи в истории (их пропускаем по решению владельца).
$JOIN = "
    JOIN (
        SELECT account_id, new_value, MAX(changed_at) AS entered_at
        FROM account_history
        WHERE field_name = 'status' AND new_value IN (?, ?)
        GROUP BY account_id, new_value
    ) h ON h.account_id = a.id
       AND h.new_value COLLATE $COLL = a.status COLLATE $COLL";

$WHERE = "
    WHERE a.status IN (?, ?)" . $softDeleteClause . "
      AND h.entered_at < DATE_SUB(NOW(), INTERVAL ? MONTH)";

// ---------------------------------------------------------------------------
// SELECT кандидатов
// Порядок плейсхолдеров: подзапрос IN(?,?), внешний IN(?,?), INTERVAL ?
// ---------------------------------------------------------------------------
$selCols = ['a.id'];
if ($hasLogin) $selCols[] = 'a.login';
if ($hasEmail) $selCols[] = 'a.email';
$selCols[] = 'a.status';
$selCols[] = 'h.entered_at';

$selectSql = 'SELECT ' . implode(', ', $selCols) . " FROM `$TABLE` a"
           . $JOIN . $WHERE . "\n    ORDER BY h.entered_at, a.id";

$selParams = [$FROM_STATUSES[0], $FROM_STATUSES[1], $FROM_STATUSES[0], $FROM_STATUSES[1], $MONTHS];
$selTypes  = 'ssssi';

$stmt = $mysqli->prepare($selectSql);
if (!$stmt) {
    http_response_code(500);
    die('Ошибка подготовки запроса: ' . htmlspecialchars($mysqli->error));
}
$stmt->bind_param($selTypes, ...$selParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$count = count($rows);

// Диагностика: сколько аккаунтов в этих статусах пропущено из-за отсутствия
// записи о смене статуса (дату определить нельзя — не трогаем).
$skippedNoHistory = 0;
$diagSql = "SELECT COUNT(*) AS cnt FROM `$TABLE` a
    WHERE a.status IN (?, ?)" . $softDeleteClause . "
      AND NOT EXISTS (
          SELECT 1 FROM account_history hh
          WHERE hh.account_id = a.id AND hh.field_name = 'status'
            AND hh.new_value COLLATE $COLL = a.status COLLATE $COLL
      )";
$diag = $mysqli->prepare($diagSql);
if ($diag) {
    $diag->bind_param('ss', $FROM_STATUSES[0], $FROM_STATUSES[1]);
    $diag->execute();
    $skippedNoHistory = (int)($diag->get_result()->fetch_assoc()['cnt'] ?? 0);
    $diag->close();
}

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">
<title>Перенос залежавшихся → perechec_new_ivan</title>
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
  .muted{ color: #888; }
  code { color: #6fc3df; }
</style></head><body>';

echo '<h2>Перенос sale_2 / trash_document → <code>' . htmlspecialchars($TO_STATUS) . '</code></h2>';
echo '<p>Условие: аккаунт получил текущий статус <strong>раньше, чем ' . $MONTHS
   . ' мес. назад</strong> (по account_history)'
   . ($hasDeletedAt ? ', аккаунты в корзине не трогаем' : '') . '.</p>';

if ($skippedNoHistory > 0) {
    echo '<p class="muted">Пропущено без записи в истории (дату определить нельзя, не трогаем): <strong>'
       . $skippedNoHistory . '</strong>.</p>';
}

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

    echo '<table><tr><th>id</th>';
    if ($hasLogin) echo '<th>login</th>';
    if ($hasEmail) echo '<th>email</th>';
    echo '<th>текущий статус</th><th>получен (entered_at)</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . htmlspecialchars($r['id']) . '</td>';
        if ($hasLogin) echo '<td>' . htmlspecialchars($r['login'] ?? '') . '</td>';
        if ($hasEmail) echo '<td>' . htmlspecialchars($r['email'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['status']) . '</td>'
           . '<td>' . htmlspecialchars($r['entered_at']) . '</td></tr>';
    }
    echo '</table>';
    echo '<a class="btn" href="?confirm=1">Подтвердить: перенести '
       . $count . ' записей в ' . htmlspecialchars($TO_STATUS) . '</a>';
} else {
    // РЕЖИМ ВЫПОЛНЕНИЯ — тот же JOIN+WHERE, атомарным UPDATE ... JOIN.
    // Синтаксис: UPDATE t a JOIN (...) h ON ... SET ... WHERE ...
    // Порядок плейсхолдеров: подзапрос IN(?,?), SET ?, внешний IN(?,?), INTERVAL ?
    $set = 'a.status = ?' . ($hasUpdatedAt ? ', a.updated_at = CURRENT_TIMESTAMP' : '');
    $updateSql = "UPDATE `$TABLE` a" . $JOIN . "\n    SET $set" . $WHERE;

    $updParams = [
        $FROM_STATUSES[0], $FROM_STATUSES[1], // подзапрос IN
        $TO_STATUS,                            // SET
        $FROM_STATUSES[0], $FROM_STATUSES[1], // внешний IN
        $MONTHS,                               // INTERVAL
    ];
    $updTypes = 'sssssi';

    $upd = $mysqli->prepare($updateSql);
    if (!$upd) {
        http_response_code(500);
        die('Ошибка подготовки UPDATE: ' . htmlspecialchars($mysqli->error));
    }
    $upd->bind_param($updTypes, ...$updParams);
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

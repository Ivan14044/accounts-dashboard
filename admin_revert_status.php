<?php
/**
 * admin_revert_status.php — точечный откат массовой смены статуса.
 *
 * Зачем отдельная страница, а не штатная кнопка «Отменить последнее»:
 * та отменяет только САМОЕ последнее действие пользователя, а после нужной нам
 * операции в журнале успели появиться другие (смена статуса у другой пачки и
 * удаления в корзину). Дотянуться до конкретной операции из UI нечем.
 *
 * Почему страница лежит в корне, а не в tools/migrations/: каталог tools/ на
 * проде закрыт .htaccess и отдаёт 403 (проверено живым запросом 2026-08-26),
 * то есть открыть утилиту в браузере оттуда физически нельзя.
 *
 * Что делает: находит в account_history строки конкретной операции
 * (field_name='status', old_value=TO_STATUS, new_value=FROM_STATUS в заданном
 * окне времени) и возвращает статус обратно — но ТОЛЬКО тем аккаунтам, что
 * до сих пор стоят в FROM_STATUS. Всё, что после операции успели перевести
 * куда-то ещё, не трогается: чужая свежая правка важнее нашего отката.
 * Логика отбора вынесена в {@see StatusRevertPlanner} и покрыта тестами.
 *
 * Безопасность: просмотр — GET (только чтение), выполнение — POST + CSRF.
 * Это осознанное отличие от старых утилит в tools/migrations/, где запись
 * делалась по ссылке `?confirm=1`: такую ссылку можно подсунуть извне.
 *
 * Сам откат пишется в аудит как обычное действие, поэтому виден в журнале и
 * при необходимости отменяется штатной кнопкой «Отменить последнее».
 *
 * Одноразовая утилита под конкретный инцидент: параметры заданы константами
 * ниже. Для другого случая — правь константы, а не URL (см. «Безопасность»).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/AuditLogger.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Validator.php';
require_once __DIR__ . '/includes/StatusRevertPlanner.php';

requireAuth();
checkSessionTimeout();

header('Content-Type: text/html; charset=utf-8');

// ---------------------------------------------------------------------------
// Параметры инцидента (26.08.2026, 13:59:22 — Used_Chek_valid → perechek_new).
// Окно времени намеренно узкое: в тот же день в perechek_new лили и из других
// статусов (yaroslav_rychnoy_farm_2 в 14:02), их трогать нельзя.
// ---------------------------------------------------------------------------
const TABLE_NAME  = 'accounts';           // account_history завязана на accounts
const TO_STATUS   = 'Used_Chek_valid';    // прежний статус — куда возвращаем
const FROM_STATUS = 'perechek_new';       // проставленный статус — откуда возвращаем
const WINDOW_FROM = '2026-08-26 13:59:00';
const WINDOW_TO   = '2026-08-26 14:00:00';
const CHUNK       = 500;

$includeTrashed = isset($_GET['with_trashed']) && $_GET['with_trashed'] === '1';
$isExecute      = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$db     = Database::getInstance();
$mysqli = $db->getConnection();

// --- какие колонки реально есть (deleted_at/updated_at опциональны) ---
$cols    = [];
$colStmt = $mysqli->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
);
$tableForBind = TABLE_NAME;
$colStmt->bind_param('s', $tableForBind);
$colStmt->execute();
$colRes = $colStmt->get_result();
while ($r = $colRes->fetch_row()) { $cols[] = $r[0]; }
$colStmt->close();

$hasDeletedAt = in_array('deleted_at', $cols, true);
$hasUpdatedAt = in_array('updated_at', $cols, true);
$hasLogin     = in_array('login', $cols, true);

/**
 * Кандидаты на откат: строки истории нужной операции + текущее состояние аккаунта.
 *
 * @return array строки {account_id, current_status, deleted_at, login}
 */
function fetchCandidates(mysqli $mysqli, bool $hasDeletedAt, bool $hasLogin): array {
    $select = 'h.account_id, a.status AS current_status';
    $select .= $hasDeletedAt ? ', a.deleted_at' : ', NULL AS deleted_at';
    $select .= $hasLogin ? ', a.login' : ", '' AS login";

    $sql = "SELECT DISTINCT $select
            FROM account_history h
            JOIN `" . TABLE_NAME . "` a ON a.id = h.account_id
            WHERE h.field_name = 'status'
              AND h.old_value = ?
              AND h.new_value = ?
              AND h.changed_at >= ?
              AND h.changed_at <  ?
            ORDER BY h.account_id";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить выборку: ' . $mysqli->error);
    }
    $old = TO_STATUS; $new = FROM_STATUS; $from = WINDOW_FROM; $to = WINDOW_TO;
    $stmt->bind_param('ssss', $old, $new, $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$rows = fetchCandidates($mysqli, $hasDeletedAt, $hasLogin);
$plan = StatusRevertPlanner::plan($rows, FROM_STATUS, $includeTrashed);

// ---------------------------------------------------------------------------
// ВЫПОЛНЕНИЕ (POST + CSRF)
// ---------------------------------------------------------------------------
$report = null;
$error  = null;

if ($isExecute) {
    try {
        Validator::validateCsrfToken((string)($_POST['csrf'] ?? ''));

        // Откат тысяч строк может идти дольше обычного лимита
        set_time_limit(0);

        $candidates = $plan['revert'];
        $reverted   = 0;
        $confirmed  = [];

        $mysqli->begin_transaction();
        try {
            // Блокируем строки и перепроверяем статус уже в БД — между
            // просмотром и нажатием кнопки его могли успеть поменять.
            foreach (array_chunk($candidates, CHUNK) as $chunk) {
                $ph   = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $mysqli->prepare(
                    "SELECT id FROM `" . TABLE_NAME . "` WHERE id IN ($ph) AND status = ? FOR UPDATE"
                );
                if (!$stmt) {
                    throw new RuntimeException('Не удалось заблокировать строки: ' . $mysqli->error);
                }
                $params = $chunk;
                $params[] = FROM_STATUS;
                $types    = str_repeat('i', count($chunk)) . 's';
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $confirmed[] = (int)$row['id']; }
                $stmt->close();
            }

            $auditLogger  = AuditLogger::getInstance();
            $auditEnabled = $auditLogger->isEnabled();

            if (!empty($confirmed)) {
                // Аудит ДО обновления — иначе старое значение уже не прочитать
                if ($auditEnabled) {
                    $auditLogger->beginAction(
                        'update_status',
                        TABLE_NAME,
                        'Откат: возврат «' . FROM_STATUS . '» → «' . TO_STATUS . '» ('
                            . count($confirmed) . ' акк.)'
                    );
                    $auditLogger->logBulkChange(
                        $confirmed, 'status', null, TO_STATUS, null, TABLE_NAME
                    );
                }

                $set = 'status = ?' . ($hasUpdatedAt ? ', updated_at = CURRENT_TIMESTAMP' : '');
                foreach (array_chunk($confirmed, CHUNK) as $chunk) {
                    $ph   = implode(',', array_fill(0, count($chunk), '?'));
                    // Условие status = ? оставлено и здесь: UPDATE обязан быть
                    // безопасным сам по себе, не полагаясь на выборку выше.
                    $stmt = $mysqli->prepare(
                        "UPDATE `" . TABLE_NAME . "` SET $set WHERE id IN ($ph) AND status = ?"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Не удалось подготовить UPDATE: ' . $mysqli->error);
                    }
                    $newStatus = TO_STATUS;
                    $params = array_merge([$newStatus], $chunk, [FROM_STATUS]);
                    $types  = 's' . str_repeat('i', count($chunk)) . 's';
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $reverted += $stmt->affected_rows;
                    $stmt->close();
                }

                if ($auditEnabled) {
                    $auditLogger->finishAction($reverted);
                }
            }

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }

        $db->clearCache();

        $report = [
            'reverted'         => $reverted,
            'candidates'       => count($candidates),
            'lost_to_race'     => count($candidates) - count($confirmed),
        ];

        Logger::info('REVERT STATUS: откат выполнен', [
            'from'   => FROM_STATUS,
            'to'     => TO_STATUS,
            'report' => $report,
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        Logger::error('REVERT STATUS: ошибка отката', ['message' => $e->getMessage()]);
    }
}

$totalHistory = count($rows);
$toRevert     = count($plan['revert']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Откат смены статуса</title>
<style>
  body { font-family: -apple-system, Segoe UI, monospace; padding: 24px; background: #1a1a1a; color: #e0e0e0; line-height: 1.5; }
  h2 { color: #f5c518; margin-top: 0; }
  code { color: #6fc3df; }
  .card { background: #222; border: 1px solid #444; border-radius: 6px; padding: 16px; margin: 16px 0; max-width: 900px; }
  .num { font-size: 1.6rem; font-weight: bold; }
  .ok { color: #2ecc71; } .warn { color: #e67e22; } .bad { color: #e74c3c; } .muted { color: #888; }
  table { border-collapse: collapse; margin-top: 12px; width: 100%; max-width: 900px; }
  th, td { border: 1px solid #444; padding: 6px 10px; text-align: left; }
  th { background: #333; }
  .btn { display: inline-block; margin-top: 8px; padding: 10px 24px; background: #c0392b;
         color: #fff; border: 0; border-radius: 4px; font-size: 15px; cursor: pointer; }
  .btn.secondary { background: #34495e; text-decoration: none; }
  a { color: #6fc3df; }
</style>
</head>
<body>

<h2>Откат: <code><?= e(FROM_STATUS) ?></code> → <code><?= e(TO_STATUS) ?></code></h2>
<p class="muted">
  Операция от <?= e(WINDOW_FROM) ?> — <?= e(WINDOW_TO) ?>, таблица <code><?= e(TABLE_NAME) ?></code>.
</p>

<?php if ($error !== null): ?>
  <div class="card"><p class="bad">Ошибка: <?= e($error) ?></p>
  <p class="muted">Изменения откачены, данные не тронуты.</p></div>
<?php endif; ?>

<?php if ($report !== null): ?>
  <div class="card">
    <p class="ok num">Возвращено записей: <?= (int)$report['reverted'] ?></p>
    <?php if ($report['lost_to_race'] > 0): ?>
      <p class="warn">Пропущено (статус изменился между просмотром и подтверждением):
        <?= (int)$report['lost_to_race'] ?></p>
    <?php endif; ?>
    <p><a href="index.php">← Вернуться в дашборд</a></p>
  </div>
<?php endif; ?>

<div class="card">
  <p>Найдено в истории записей этой операции: <span class="num"><?= (int)$totalHistory ?></span></p>
  <table>
    <tr><th>Категория</th><th>Записей</th><th>Что с ними будет</th></tr>
    <tr>
      <td class="ok">До сих пор в <code><?= e(FROM_STATUS) ?></code></td>
      <td class="ok num"><?= (int)$toRevert ?></td>
      <td>вернём в <code><?= e(TO_STATUS) ?></code></td>
    </tr>
    <tr>
      <td class="warn">Статус изменён после операции</td>
      <td class="warn"><?= (int)$plan['skipped_changed'] ?></td>
      <td>не трогаем — это более свежая правка</td>
    </tr>
    <tr>
      <td class="muted">В корзине</td>
      <td class="muted"><?= (int)$plan['skipped_trashed'] ?></td>
      <td>
        <?php if ($includeTrashed): ?>
          входят в откат
        <?php else: ?>
          не трогаем (<a href="?with_trashed=1">включить в откат</a>)
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($plan['duplicates'] > 0): ?>
    <tr>
      <td class="muted">Дубли в истории</td>
      <td class="muted"><?= (int)$plan['duplicates'] ?></td>
      <td>схлопнуты, обновляются один раз</td>
    </tr>
    <?php endif; ?>
  </table>

  <?php if ($includeTrashed): ?>
    <p class="muted" style="margin-top:12px"><a href="?">← вернуться к режиму «без корзины»</a></p>
  <?php endif; ?>
</div>

<?php if ($report === null && $toRevert > 0): ?>
  <div class="card">
    <p class="warn">Будет изменено <strong><?= (int)$toRevert ?></strong> записей в боевой базе.</p>
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= e(getCsrfToken()) ?>">
      <?php if ($includeTrashed): ?>
        <input type="hidden" name="with_trashed" value="1">
      <?php endif; ?>
      <button type="submit" class="btn">
        Подтвердить: вернуть <?= (int)$toRevert ?> записей в «<?= e(TO_STATUS) ?>»
      </button>
    </form>
    <p class="muted" style="margin-top:12px">
      Откат попадёт в журнал действий и его можно будет отменить кнопкой
      «Отменить последнее» в дашборде.
    </p>
  </div>
<?php elseif ($report === null): ?>
  <div class="card"><p class="ok">Нечего откатывать: подходящих записей не найдено.</p></div>
<?php endif; ?>

<p><a class="btn secondary" href="admin_logs.php?field=status&search=<?= e(rawurlencode(TO_STATUS)) ?>">Журнал действий</a></p>

</body>
</html>

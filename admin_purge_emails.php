<?php
/**
 * admin_purge_emails.php — РАЗОВАЯ страница чистки мёртвых почт.
 *
 * Задача: у аккаунтов, чья почта принадлежит списку доменов PURGE_DOMAINS
 * (мёртвые / одноразовые домены), очистить ПОЛЯ `email` и `email_password`
 * (выставить NULL). Сами аккаунты остаются в БД нетронутыми — меняются только
 * эти два поля.
 *
 * Почему отдельная страница, а не кнопка «поле для всех»: поиск в панели —
 * нечёткий (LIKE по login/email/social_url/cookies/token), и по строке
 * «@scalomail.com» он зацепил бы адрес, лежащий в куках, снеся ЧУЖУЮ почту.
 * Здесь отбор строгий — по ДОМЕНУ адреса (часть после последней `@`), см.
 * EmailPurgePlanner и tests/test_email_purge_planner.php.
 *
 * Безопасность:
 *   - только залогиненному (requireAuth);
 *   - просмотр — GET (только читает), выполнение — POST + CSRF + слово-замок PURGE;
 *   - перед изменением создаётся таблица-бэкап со старыми значениями
 *     (восстановление одной командой), плюс владелец забирает файл-выгрузку;
 *   - каждая отобранная строка ПЕРЕПРОВЕРЯЕТСЯ в PHP (EmailPurgePlanner::matches):
 *     если SQL вернул что-то, что не совпадает по домену, — операция прерывается.
 *
 * После использования страница снимается с прода (см. CLAUDE.md про разовые
 * утилиты). Планировщик EmailPurgePlanner и его тест остаются в репозитории.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Validator.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/EmailPurgePlanner.php';

requireAuth();
checkSessionTimeout();

function pe_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Список доменов инцидента (мёртвые / одноразовые), 37 штук ──
$PURGE_DOMAINS = EmailPurgePlanner::normalizeDomains([
    'appearmail.com', 'supracomail.com', 'intercostomail.com', 'coagumail.com',
    'seriousfmail.com', 'marsipomail.com', 'insubsmail.com', 'rhinolmail.com',
    'psychosmail.com', 'overfammail.com', 'bombsmail.com', 'valeriamail.com',
    'antedimail.com', 'perambumail.com', 'traticallymail.com', 'cyanomethemail.com',
    'extraordimail.com', 'ctivitymail.com', 'horseshoefmail.com', 'appearfmail.com',
    'epimymail.com', 'cholecystolimail.com', 'superintemail.com', 'mediosmail.com',
    'overdetemail.com', 'scalomail.com', 'chlorotrifluomail.com', 'ecclesiamail.com',
    'nationamail.com', 'microsmail.com', 'disproportimail.com', 'hyperintelmail.com',
    'apommail.com', 'impolitmail.com', 'stenocemail.com', 'ectoparmail.com',
    'anisomemail.com',
]);

$db    = Database::getInstance();
$table = $tableName; // из config.php → TableResolver::getCurrentTable(), уже провалидировано

// ── Выгрузка таблицы-бэкапа в файл (копия владельцу «на руки») ──
// Только чтение: отдаёт CSV со старыми значениями. Имя таблицы жёстко валидируется
// (наш префикс + [a-z0-9_]), поэтому подстановка в SQL безопасна.
if (isset($_GET['download'])) {
    $bt = (string)$_GET['download'];
    if (!preg_match('/^email_purge_backup_[a-zA-Z0-9_]+$/', $bt) || strlen($bt) > 64
        || !$db->tableExists($bt)) {
        http_response_code(404);
        die('Нет такой таблицы-бэкапа.');
    }
    $data = $db->prepare("SELECT account_id, old_email, old_email_password, purged_at FROM `$bt` ORDER BY account_id");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $bt . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
    fputcsv($out, ['account_id', 'old_email', 'old_email_password', 'purged_at']);
    foreach ($data as $row) {
        fputcsv($out, [$row['account_id'], $row['old_email'], $row['old_email_password'], $row['purged_at']]);
    }
    fclose($out);
    exit;
}

$placeholders = implode(',', array_fill(0, count($PURGE_DOMAINS), '?'));

/**
 * Отобрать строки, чья почта принадлежит списку доменов (строго по домену).
 * Возвращает [rows, mismatches]: rows — прошедшие и SQL, и PHP-перепроверку,
 * mismatches — то, что SQL счёл подходящим, а строгая PHP-логика отвергла
 * (в норме пусто; непусто = повод остановиться).
 *
 * @return array{0: array<int,array{id:int,email:?string,email_password:?string,deleted_at:?string}>, 1: array}
 */
function pe_select(Database $db, string $table, array $domains, string $placeholders): array {
    $sql = "SELECT id, email, email_password, deleted_at
            FROM `$table`
            WHERE SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1) IN ($placeholders)";
    $rows = $db->prepare($sql, $domains);
    $clean = [];
    $mismatch = [];
    foreach ($rows as $r) {
        if (EmailPurgePlanner::matches($r['email'] ?? null, $domains)) {
            $clean[] = $r;
        } else {
            $mismatch[] = $r;
        }
    }
    return [$clean, $mismatch];
}

$flash = null;
$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');

if ($isPost) {
    try {
        Validator::validateCsrfToken((string)($_POST['csrf'] ?? ''));
    } catch (Throwable $e) {
        http_response_code(403);
        die('CSRF validation failed');
    }
    if (($_POST['lock'] ?? '') !== 'PURGE') {
        http_response_code(400);
        die('Слово-замок неверно — операция не выполнена.');
    }

    list($rows, $mismatch) = pe_select($db, $table, $PURGE_DOMAINS, $placeholders);

    if (!empty($mismatch)) {
        http_response_code(500);
        die('ОТМЕНА: SQL вернул ' . count($mismatch) . ' строк, не прошедших строгую проверку домена. Ничего не менял.');
    }

    if (empty($rows)) {
        $flash = ['type' => 'info', 'msg' => 'Под очистку ничего не попало — нечего делать.'];
    } else {
        $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);

        // 1. Бэкап старых значений в отдельную таблицу (имя генерим сами — не из ввода).
        $backupTable = 'email_purge_backup_' . preg_replace('/[^a-z0-9]/i', '', $table) . '_' . date('Ymd_His');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $backupTable) || strlen($backupTable) > 64) {
            http_response_code(500);
            die('Не удалось сформировать безопасное имя таблицы-бэкапа.');
        }
        $conn = $db->getConnection();
        $createOk = $conn->query(
            "CREATE TABLE `$backupTable` (
                account_id INT NOT NULL,
                source_table VARCHAR(64) NOT NULL,
                old_email VARCHAR(255) NULL,
                old_email_password VARCHAR(255) NULL,
                purged_at DATETIME NOT NULL,
                PRIMARY KEY (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if ($createOk === false) {
            http_response_code(500);
            die('Не удалось создать таблицу-бэкап: ' . pe_e($conn->error));
        }

        $now = date('Y-m-d H:i:s');
        $backedUp = 0;
        foreach (EmailPurgePlanner::chunk($ids, 500) as $chunkIds) {
            // Достаём актуальные значения этой пачки и складываем в бэкап.
            $ph = implode(',', array_fill(0, count($chunkIds), '?'));
            $cur = $db->prepare(
                "SELECT id, email, email_password FROM `$table` WHERE id IN ($ph)",
                $chunkIds
            );
            foreach ($cur as $row) {
                $db->prepare(
                    "INSERT INTO `$backupTable` (account_id, source_table, old_email, old_email_password, purged_at)
                     VALUES (?, ?, ?, ?, ?)",
                    [(int)$row['id'], $table, $row['email'], $row['email_password'], $now]
                );
                $backedUp++;
            }
        }

        // 2. Сама очистка — пачками по первичному ключу (индекс, а не скан таблицы).
        //    affected_rows после Database::prepare() читать нельзя: метод закрывает
        //    statement и возвращает -1, поэтому реальный итог берём из проверки ниже.
        foreach (EmailPurgePlanner::chunk($ids, 500) as $chunkIds) {
            $ph = implode(',', array_fill(0, count($chunkIds), '?'));
            $db->prepare(
                "UPDATE `$table` SET email = NULL, email_password = NULL WHERE id IN ($ph)",
                $chunkIds
            );
        }

        // 3. Проверка: сколько ещё осталось с этими доменами (должно стать 0),
        //    и сколько по факту очищено = было отобрано минус осталось.
        list($remain, ) = pe_select($db, $table, $PURGE_DOMAINS, $placeholders);
        $remaining = count($remain);
        $updated = count($rows) - $remaining;

        Logger::info('admin_purge_emails: выполнено', [
            'table' => $table, 'selected' => count($rows), 'backed_up' => $backedUp,
            'updated' => $updated, 'remaining' => $remaining, 'backup_table' => $backupTable,
        ]);

        $dlUrl = '?table=' . rawurlencode($table) . '&download=' . rawurlencode($backupTable);
        $flash = [
            'type' => $remaining === 0 ? 'success' : 'warning',
            'msg'  => "Очищено строк: $updated. В бэкап-таблице <code>" . pe_e($backupTable) . "</code> сохранено: $backedUp. "
                    . "Осталось с этими доменами: $remaining (должно быть 0)."
                    . ($remaining === 0 ? '' : ' — проверь!')
                    . ' <a href="' . pe_e($dlUrl) . '">Скачать бэкап (CSV)</a>',
        ];
    }
}

// ── Предпросмотр (и после POST — свежая картина) ──
list($rows, $mismatch) = pe_select($db, $table, $PURGE_DOMAINS, $placeholders);
$total = count($rows);
$active = 0; $trashed = 0; $withPwd = 0;
$byDomain = [];
foreach ($rows as $r) {
    if (!empty($r['deleted_at'])) $trashed++; else $active++;
    if (($r['email_password'] ?? '') !== '') $withPwd++;
    $d = EmailPurgePlanner::emailDomain($r['email'] ?? null) ?: '(?)';
    $byDomain[$d] = ($byDomain[$d] ?? 0) + 1;
}
arsort($byDomain);
$csrf = getCsrfToken();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Чистка мёртвых почт</title>
<style>
  body { font: 15px/1.5 -apple-system, Segoe UI, Roboto, sans-serif; max-width: 820px; margin: 24px auto; padding: 0 16px; color: #1c1e21; }
  h1 { font-size: 20px; }
  .box { border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin: 14px 0; }
  .flash { padding: 12px 14px; border-radius: 8px; margin: 12px 0; }
  .flash.success { background: #e6f4ea; border: 1px solid #9fd8b3; }
  .flash.warning { background: #fff4e5; border: 1px solid #f0c48a; }
  .flash.info { background: #eef2f7; border: 1px solid #c9d6e5; }
  table { border-collapse: collapse; width: 100%; font-size: 14px; }
  td, th { border-bottom: 1px solid #eee; padding: 4px 8px; text-align: left; }
  .big { font-size: 28px; font-weight: 700; }
  code { background: #f2f3f5; padding: 1px 5px; border-radius: 4px; }
  .danger-btn { background: #d0342c; color: #fff; border: 0; padding: 10px 18px; border-radius: 8px; font-size: 15px; cursor: pointer; }
  input[type=text] { padding: 8px; font-size: 15px; border: 1px solid #bbb; border-radius: 6px; }
  .muted { color: #65676b; }
</style>
</head>
<body>
  <h1>Чистка мёртвых почт из базы</h1>
  <p class="muted">Таблица: <code><?= pe_e($table) ?></code>. Доменов в списке: <?= count($PURGE_DOMAINS) ?>.
     Меняются только поля <code>email</code> и <code>email_password</code> → пусто. Аккаунты остаются.</p>

  <?php
    // Список уже созданных бэкап-таблиц (со ссылками на скачивание).
    $backups = [];
    foreach ($db->prepare("SHOW TABLES LIKE 'email_purge_backup_%'") as $r) {
        $backups[] = reset($r);
    }
  ?>
  <?php if (!empty($backups)): ?>
    <div class="box">
      <strong>Бэкапы (старые значения, для восстановления):</strong>
      <ul>
        <?php foreach ($backups as $bt): ?>
          <li><code><?= pe_e($bt) ?></code>
            — <a href="?table=<?= pe_e(rawurlencode($table)) ?>&download=<?= pe_e(rawurlencode($bt)) ?>">скачать CSV</a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="flash <?= pe_e($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if (!empty($mismatch)): ?>
    <div class="flash warning">Внимание: SQL отобрал <?= count($mismatch) ?> строк, которые строгая проверка домена отвергла. Выполнение заблокировано до разбора.</div>
  <?php endif; ?>

  <div class="box">
    <div class="big"><?= $total ?></div>
    <div>аккаунтов с почтой на этих доменах<?= $isPost ? ' (осталось сейчас)' : ' (сейчас в базе)' ?>.</div>
    <p class="muted">Из них активных: <?= $active ?>, в корзине: <?= $trashed ?>, с сохранённым паролем почты: <?= $withPwd ?>.</p>
  </div>

  <?php if ($total > 0): ?>
  <div class="box">
    <strong>Разбивка по доменам:</strong>
    <table>
      <tr><th>домен</th><th>аккаунтов</th></tr>
      <?php foreach ($byDomain as $d => $c): ?>
        <tr><td><?= pe_e($d) ?></td><td><?= (int)$c ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="box">
    <p><strong>Выполнить очистку.</strong> Перед изменением автоматически создаётся таблица-бэкап
       со старыми значениями (её можно вернуть одной командой). Чтобы подтвердить — впиши слово
       <code>PURGE</code> и нажми кнопку.</p>
    <form method="post" onsubmit="return confirm('Очистить почту и пароль почты у <?= $total ?> аккаунтов? Аккаунты останутся.');">
      <input type="hidden" name="csrf" value="<?= pe_e($csrf) ?>">
      <input type="text" name="lock" placeholder="впиши PURGE" autocomplete="off">
      <button class="danger-btn" type="submit">Очистить почту у <?= $total ?> аккаунтов</button>
    </form>
  </div>
  <?php else: ?>
    <div class="box">Под очистку ничего не попадает.</div>
  <?php endif; ?>
</body>
</html>

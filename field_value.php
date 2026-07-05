<?php
/**
 * API: полное значение одного поля записи.
 *
 * GET ?id=123&field=cookies[&table=xxx]
 *   → { success: true, value: "..." }
 *
 * Нужен «тонкой» разметке таблицы: длинные значения (cookies, token и т.п.)
 * больше не кладутся в HTML целиком, а подгружаются по требованию
 * (копирование / просмотр / редактирование) — см. assets/js/modules/cell-actions.js.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

requireAuth();
checkSessionTimeout();
checkRateLimit('api');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$id    = (int)get_param('id', '0');
$field = get_param('field', '');

if ($id <= 0 || $field === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
    json_error('Некорректные параметры', 400);
}

// Колонка должна существовать в текущей таблице ($tableName резолвится в config.php)
$metadata = ColumnMetadata::getInstance($mysqli, $tableName);
if (!$metadata->columnExists($field)) {
    json_error('Неизвестное поле', 400);
}

$stmt = $mysqli->prepare("SELECT `$field` FROM `$tableName` WHERE id = ?");
if (!$stmt) {
    json_error('Ошибка запроса', 500);
}
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_row() : null;
$stmt->close();

if ($row === null) {
    json_error('Запись не найдена', 404);
}

json_success(['value' => $row[0] === null ? '' : (string)$row[0]]);

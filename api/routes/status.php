<?php
/**
 * Маршруты ресурса `status`.
 *
 * @var ApiRouter $router
 * @var string    $tableName
 */
$router->post('/status/register', function() use ($tableName) {
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new Exception('Invalid input');
    }

    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('REGISTER STATUS API: CSRF validation failed');
        throw new Exception('CSRF validation failed');
    }

    $status = isset($input['status']) ? trim((string)$input['status']) : '';

    if ($status === '') {
        throw new Exception('Status is required');
    }

    // Валидация статуса (буквы включая кириллицу, цифры, подчеркивания, дефисы, пробелы)
    if (!preg_match('/^[\p{L}0-9_\-\s]+$/u', $status)) {
        throw new Exception('Invalid status format. Only letters (including Cyrillic), numbers, underscores, hyphens and spaces are allowed');
    }

    $service = new AccountsService($tableName);
    $mysqli = Database::getInstance()->getConnection();

    // Проверяем, есть ли уже записи с таким статусом, используя INSERT ... ON DUPLICATE KEY UPDATE
    // Используем специальный префикс для идентификации служебных записей
    $serviceLogin = '__status_marker_' . md5($status);

    // Используем INSERT ... ON DUPLICATE KEY UPDATE для атомарной операции
    $sql = "INSERT INTO accounts (login, status, created_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status)";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }

    $stmt->bind_param('ss', $serviceLogin, $status);
    if (!$stmt->execute()) {
        throw new Exception('Failed to register status: ' . $stmt->error);
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    // Очищаем кэш метаданных и кэши запросов, чтобы новый статус появился в списке
    ColumnMetadata::clearCache();
    Database::getInstance()->clearCache();

    // Определяем, был ли статус создан или уже существовал
    $exists = $affectedRows === 0 || $affectedRows === 2; // 2 = UPDATE, 1 = INSERT
    json_success(['message' => 'Status registered successfully', 'exists' => $exists]);
});

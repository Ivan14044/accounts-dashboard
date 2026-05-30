<?php
/**
 * Маршруты ресурса `filters` (сохранённые фильтры дашборда / пресеты).
 *
 * @var ApiRouter $router
 * @var string    $tableName
 */

// Убедиться что таблица saved_filters существует
function ensureSavedFiltersTable(Database $db): void {
    if ($db->tableExists('saved_filters')) {
        // Добавляем колонку table_name если её ещё нет
        $mysqli = $db->getConnection();
        $check = $mysqli->query("SHOW COLUMNS FROM `saved_filters` LIKE 'table_name'");
        if ($check && $check->num_rows === 0) {
            $mysqli->query("ALTER TABLE `saved_filters` ADD COLUMN `table_name` VARCHAR(255) NOT NULL DEFAULT 'accounts' AFTER `user_id`");
            $mysqli->query("ALTER TABLE `saved_filters` ADD INDEX `idx_user_table` (`user_id`, `table_name`)");
        }
        return;
    }
    $sql = "
    CREATE TABLE IF NOT EXISTS `saved_filters` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` VARCHAR(255) NOT NULL,
        `table_name` VARCHAR(255) NOT NULL DEFAULT 'accounts',
        `name` VARCHAR(255) NOT NULL,
        `filters` JSON NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_user_updated` (`user_id`, `updated_at`),
        INDEX `idx_user_table` (`user_id`, `table_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    if (!$db->executeDDL($sql, ['saved_filters'])) {
        throw new Exception('Ошибка создания таблицы saved_filters');
    }
}

$router->get('/filters', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $db = Database::getInstance();
    ensureSavedFiltersTable($db);
    $mysqli = $db->getConnection();

    $filterTable = $_GET['table'] ?? 'accounts';
    $stmt = $mysqli->prepare("SELECT id, name, filters, created_at, updated_at FROM saved_filters WHERE user_id = ? AND table_name = ? ORDER BY updated_at DESC LIMIT 100");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement');
    }
    $stmt->bind_param('ss', $userId, $filterTable);
    $stmt->execute();
    $result = $stmt->get_result();

    $filters = [];
    while ($row = $result->fetch_assoc()) {
        $filters[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'filters' => json_decode($row['filters'], true),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();

    json_success(['filters' => $filters]);
});

$router->post('/filters', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $input = read_json_input(1048576);

    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('SAVED FILTERS API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }

    $name = trim($input['name'] ?? '');
    $filters = $input['filters'] ?? [];

    if (empty($name)) {
        json_error('Название фильтра обязательно');
        return;
    }

    if (mb_strlen($name, 'UTF-8') > 255) {
        json_error('Название фильтра слишком длинное (макс. 255 символов)');
        return;
    }

    if (empty($filters) || !is_array($filters)) {
        json_error('Фильтры должны быть массивом');
        return;
    }

    $db = Database::getInstance();
    ensureSavedFiltersTable($db);
    $mysqli = $db->getConnection();

    // Проверяем лимит количества сохранённых фильтров на пользователя
    $countStmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM saved_filters WHERE user_id = ?");
    if ($countStmt) {
        $countStmt->bind_param('s', $userId);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        if (($countResult['cnt'] ?? 0) >= 50) {
            json_error('Достигнут лимит сохранённых фильтров (максимум 50). Удалите ненужные фильтры.');
            return;
        }
    }

    $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
    $filterTable = $input['table'] ?? 'accounts';
    $stmt = $mysqli->prepare("INSERT INTO saved_filters (user_id, table_name, name, filters) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement');
    }
    $stmt->bind_param('ssss', $userId, $filterTable, $name, $filtersJson);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to save filter: ' . $error);
    }
    $filterId = $mysqli->insert_id;
    $stmt->close();

    Logger::info('Filter saved', ['user' => $userId, 'filter_id' => $filterId, 'name' => $name]);
    json_success(['id' => $filterId, 'message' => 'Фильтр сохранён']);
});

$router->put('/filters', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $input = read_json_input(1048576);

    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('SAVED FILTERS API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $name = trim($input['name'] ?? '');
    $filters = $input['filters'] ?? [];

    if ($id <= 0) {
        json_error('Invalid filter ID');
        return;
    }

    if (empty($name)) {
        json_error('Название фильтра обязательно');
        return;
    }

    if (empty($filters) || !is_array($filters)) {
        json_error('Фильтры должны быть массивом');
        return;
    }

    $db = Database::getInstance();
    ensureSavedFiltersTable($db);
    $mysqli = $db->getConnection();
    $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
    $stmt = $mysqli->prepare("UPDATE saved_filters SET name = ?, filters = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement');
    }
    $stmt->bind_param('ssis', $name, $filtersJson, $id, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_error('Фильтр не найден или не принадлежит вам', 404);
        return;
    }

    Logger::info('Filter updated', ['user' => $userId, 'filter_id' => $id, 'name' => $name]);
    json_success(['message' => 'Фильтр обновлён']);
});

$router->delete('/filters', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $input = read_json_input(1048576);

    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('SAVED FILTERS API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }

    $filterId = isset($input['id']) ? (int)$input['id'] : 0;

    if ($filterId <= 0) {
        json_error('Invalid filter id');
        return;
    }

    $db = Database::getInstance();
    ensureSavedFiltersTable($db);
    $mysqli = $db->getConnection();
    $stmt = $mysqli->prepare("DELETE FROM saved_filters WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement');
    }
    $stmt->bind_param('is', $filterId, $userId);
    $stmt->execute();
    $stmt->close();

    Logger::info('Filter deleted', ['user' => $userId, 'filter_id' => $filterId]);
    json_success(['message' => 'Фильтр удалён']);
});

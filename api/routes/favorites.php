<?php
/**
 * Маршруты ресурса `favorites` (избранные аккаунты пользователя).
 *
 * @var ApiRouter $router
 * @var string    $tableName
 */

// Убедиться что таблица account_favorites существует
function ensureAccountFavoritesTable(Database $db): void {
    if ($db->tableExists('account_favorites')) {
        return;
    }
    $sql = "
    CREATE TABLE IF NOT EXISTS `account_favorites` (
        `user_id` VARCHAR(255) NOT NULL,
        `account_id` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`, `account_id`),
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_account_id` (`account_id`),
        INDEX `idx_user_created` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    if (!$db->executeDDL($sql, ['account_favorites'])) {
        throw new Exception('Ошибка создания таблицы избранного');
    }
}

$router->get('/favorites', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    ensureAccountFavoritesTable($db);

    $stmt = $mysqli->prepare("SELECT account_id FROM account_favorites WHERE user_id = ? ORDER BY created_at DESC");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    $stmt->bind_param('s', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }
    $result = $stmt->get_result();

    $favorites = [];
    while ($row = $result->fetch_assoc()) {
        $favorites[] = (int)$row['account_id'];
    }
    $stmt->close();

    json_success(['favorites' => $favorites]);
});

$router->post('/favorites', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $input = read_json_input(1048576);

    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('FAVORITES API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }

    $accountId = isset($input['account_id']) ? (int)$input['account_id'] : 0;

    if ($accountId <= 0) {
        json_error('Invalid account ID');
        return;
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    ensureAccountFavoritesTable($db);

    $stmt = $mysqli->prepare("INSERT IGNORE INTO account_favorites (user_id, account_id) VALUES (?, ?)");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    $stmt->bind_param('si', $userId, $accountId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }
    $stmt->close();

    Logger::info('Favorite added', ['user' => $userId, 'account_id' => $accountId]);
    json_success(['message' => 'Добавлено в избранное']);
});

$router->delete('/favorites', function() use ($tableName) {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $input = read_json_input(1048576);

    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('FAVORITES API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }

    $accountId = isset($input['account_id']) ? (int)$input['account_id'] : 0;

    if ($accountId <= 0) {
        json_error('Invalid account ID');
        return;
    }

    $mysqli = Database::getInstance()->getConnection();
    $stmt = $mysqli->prepare("DELETE FROM account_favorites WHERE user_id = ? AND account_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    $stmt->bind_param('si', $userId, $accountId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to execute statement: ' . $error);
    }
    $stmt->close();

    Logger::info('Favorite removed', ['user' => $userId, 'account_id' => $accountId]);
    json_success(['message' => 'Удалено из избранного']);
});

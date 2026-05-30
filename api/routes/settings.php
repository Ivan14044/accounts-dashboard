<?php
/**
 * Маршруты ресурса `settings` — пользовательские настройки (custom_cards,
 * hidden_cards, отображение колонок и т.п.). Хранятся одной строкой JSON на
 * пару (username, setting_type) в таблице `user_settings`.
 *
 * @var ApiRouter $router
 * @var string    $tableName
 */

// Убедиться что таблица user_settings существует
function ensureUserSettingsTable(Database $db): void {
    if ($db->tableExists('user_settings')) {
        return;
    }
    $sql = "
    CREATE TABLE IF NOT EXISTS `user_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(255) NOT NULL,
        `setting_type` VARCHAR(100) NOT NULL,
        `setting_value` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_setting` (`username`, `setting_type`),
        INDEX `idx_username` (`username`),
        INDEX `idx_setting_type` (`setting_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    if (!$db->executeDDL($sql, ['user_settings'])) {
        throw new Exception('Failed to create user_settings table');
    }
}

// Сохранить настройку пользователя (используется и POST, и PUT)
function saveUserSetting(string $username, array $input): void {
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('USER SETTINGS API: CSRF validation failed');
        throw new Exception('CSRF validation failed');
    }

    if (!isset($input['value'])) {
        throw new Exception('Value is required');
    }

    $settingType = $input['type'] ?? 'custom_cards';
    $settingValue = $input['value'];

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    ensureUserSettingsTable($db);

    $valueJson = json_encode($settingValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $mysqli->prepare("
        INSERT INTO user_settings (username, setting_type, setting_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    $stmt->bind_param('sss', $username, $settingType, $valueJson);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save settings: ' . $mysqli->error);
    }
    $stmt->close();

    json_success(['message' => 'Settings saved successfully']);
}

$router->get('/settings', function() use ($tableName) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }

    $settingType = $_GET['type'] ?? 'custom_cards';
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    ensureUserSettingsTable($db);

    $stmt = $mysqli->prepare("SELECT setting_value FROM user_settings WHERE username = ? AND setting_type = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    $stmt->bind_param('ss', $username, $settingType);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $value = json_decode($row['setting_value'], true);
        json_success(['value' => $value]);
    } else {
        json_success(['value' => []]);
    }

    $stmt->close();
});

$router->post('/settings', function() use ($tableName) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }
    saveUserSetting($username, read_json_input(1048576));
});

$router->put('/settings', function() use ($tableName) {
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }
    saveUserSetting($username, read_json_input(1048576));
});

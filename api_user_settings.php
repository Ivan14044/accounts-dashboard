<?php
/**
 * API для работы с настройками пользователя
 * Сохраняет настройки в БД для синхронизации между устройствами
 */

// Загружаем config.php с обработкой ошибок подключения к БД
try {
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    // Если config.php выбрасывает исключение (например, ошибка подключения к БД),
    // обрабатываем его здесь
    require_once __DIR__ . '/includes/Utils.php';
    require_once __DIR__ . '/includes/Logger.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'User Settings API (config)', 500);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    // Получаем идентификатор пользователя из сессии
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }
    
    // Получаем метод запроса
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Получаем тип настройки из параметра
    $settingType = $_GET['type'] ?? $_POST['type'] ?? 'custom_cards';
    
    // Подключаемся к БД
    global $mysqli;
    if (!$mysqli) {
        require_once __DIR__ . '/includes/Logger.php';
        Logger::error('Database connection failed in api_user_settings.php (mysqli is null)');
        throw new RuntimeException('Database connection failed (mysqli is null)');
    }
    
    if ($mysqli->connect_errno) {
        require_once __DIR__ . '/includes/Logger.php';
        Logger::error('Database connection error in api_user_settings.php', [
            'errno' => $mysqli->connect_errno,
            'error' => $mysqli->connect_error
        ]);
        throw new RuntimeException('Database connection failed: ' . $mysqli->connect_error);
    }
    
    // Проверяем существование таблицы и создаём, если её нет (безопасная проверка)
    require_once __DIR__ . '/includes/Database.php';
    $db = Database::getInstance();
    if (!$db->tableExists('user_settings')) {
        // Создаем таблицу, если её нет
        $createTableSQL = "
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
        
        $allowedTables = ['user_settings'];
        if (!$db->executeDDL($createTableSQL, $allowedTables)) {
            throw new Exception('Failed to create user_settings table: ' . $mysqli->error);
        }
    }
    
    if ($method === 'GET') {
        // Загрузка настроек
        $stmt = $mysqli->prepare("SELECT `setting_value` FROM `user_settings` WHERE `username` = ? AND `setting_type` = ?");
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
            // Возвращаем значение по умолчанию
            $defaultValue = $settingType === 'custom_cards' ? [] : [];
            json_success(['value' => $defaultValue]);
        }
        
        $stmt->close();
        
    } elseif ($method === 'POST' || $method === 'PUT') {
        // Сохранение настроек
        $input = read_json_input(1048576); // 1MB максимум
        
        // Проверка CSRF токена
        require_once __DIR__ . '/includes/Validator.php';
        $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
        if (!Validator::validateCsrfToken($csrf)) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::warning('USER SETTINGS API: CSRF validation failed');
            throw new Exception('CSRF validation failed');
        }
        
        if (!isset($input['value'])) {
            throw new Exception('Value is required');
        }
        
        $value = json_encode($input['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $stmt = $mysqli->prepare("
            INSERT INTO `user_settings` (`username`, `setting_type`, `setting_value`) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                `setting_value` = VALUES(`setting_value`),
                `updated_at` = CURRENT_TIMESTAMP
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $mysqli->error);
        }
        
        $stmt->bind_param('sss', $username, $settingType, $value);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to save settings: ' . $mysqli->error);
        }
        
        $stmt->close();
        
        json_success(['message' => 'Settings saved successfully']);
        
    } elseif ($method === 'DELETE') {
        // Удаление настроек
        // Проверка CSRF токена
        $input = read_json_input(1048576); // 1MB максимум
        require_once __DIR__ . '/includes/Validator.php';
        $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
        if (!Validator::validateCsrfToken($csrf)) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::warning('USER SETTINGS API: CSRF validation failed');
            throw new Exception('CSRF validation failed');
        }
        
        $stmt = $mysqli->prepare("DELETE FROM `user_settings` WHERE `username` = ? AND `setting_type` = ?");
        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $mysqli->error);
        }
        
        $stmt->bind_param('ss', $username, $settingType);
        $stmt->execute();
        $stmt->close();
        
        json_success(['message' => 'Settings deleted successfully']);
        
    } else {
        throw new Exception('Method not allowed');
    }
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    // Определяем правильный HTTP код
    $httpCode = 500; // По умолчанию 500 для серверных ошибок
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400; // Bad Request для валидационных ошибок
    } elseif (strpos($e->getMessage(), 'not authenticated') !== false || 
              strpos($e->getMessage(), 'Unauthorized') !== false) {
        $httpCode = 401; // Unauthorized для ошибок авторизации
    } elseif (strpos($e->getMessage(), 'Database connection') !== false ||
              strpos($e->getMessage(), 'Failed to prepare') !== false ||
              strpos($e->getMessage(), 'Failed to execute') !== false) {
        $httpCode = 500; // Internal Server Error для ошибок БД
    }
    ErrorHandler::handleError($e, 'User Settings API', $httpCode);
}




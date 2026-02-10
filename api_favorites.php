<?php
/**
 * API для работы с избранными аккаунтами
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
    ErrorHandler::handleError($e, 'Favorites API (config)', 500);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Безопасное чтение JSON с ограничением размера (только для POST/PUT/DELETE)
    $input = [];
    if ($method !== 'GET') {
        try {
            $input = read_json_input(1048576); // 1MB максимум
            if ($input === null) {
                $input = [];
            }
        } catch (Exception $e) {
            // Если не удалось прочитать JSON, используем пустой массив
            $input = [];
        }
    }
    
    $mysqli = Database::getInstance()->getConnection();

    // Проверяем существование таблицы и создаём, если её нет (безопасная проверка)
    $db = Database::getInstance();
    if (!$db->tableExists('account_favorites')) {
        // Создаём таблицу автоматически
        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS `account_favorites` (
            `user_id` VARCHAR(255) NOT NULL,
            `account_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `account_id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_account_id` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $allowedTables = ['account_favorites'];
        if (!$db->executeDDL($createTableSQL, $allowedTables)) {
            Logger::error('Failed to create favorites table', ['error' => $db->getConnection()->error]);
            json_error('Ошибка создания таблицы избранного. Обратитесь к администратору.');
        }
        
        Logger::info('Favorites table created automatically');
    }
    
    switch ($method) {
        case 'GET':
            // Получение списка избранных
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
            break;
            
        case 'POST':
            // Добавление в избранное
            // Проверка CSRF токена
            require_once __DIR__ . '/includes/Validator.php';
            $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
            if (!Validator::validateCsrfToken($csrf)) {
                Logger::warning('FAVORITES API: CSRF validation failed');
                json_error('CSRF validation failed', 403);
            }
            
            $accountId = isset($input['account_id']) ? (int)$input['account_id'] : 0;
            
            if ($accountId <= 0) {
                json_error('Invalid account ID');
            }
            
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
            break;
            
        case 'DELETE':
            // Удаление из избранного
            // Проверка CSRF токена
            require_once __DIR__ . '/includes/Validator.php';
            $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
            if (!Validator::validateCsrfToken($csrf)) {
                Logger::warning('FAVORITES API: CSRF validation failed');
                json_error('CSRF validation failed', 403);
            }
            
            $accountId = isset($input['account_id']) ? (int)$input['account_id'] : 0;
            
            if ($accountId <= 0) {
                json_error('Invalid account ID');
            }
            
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
            break;
            
        default:
            json_error('Method not allowed', 405);
    }
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    // Определяем правильный HTTP код
    $httpCode = 500; // По умолчанию 500 для серверных ошибок
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400; // Bad Request для валидационных ошибок
    } elseif (strpos($e->getMessage(), 'not authenticated') !== false || 
              strpos($e->getMessage(), 'Unauthorized') !== false ||
              strpos($e->getMessage(), 'необходима авторизация') !== false) {
        $httpCode = 401; // Unauthorized для ошибок авторизации
    } elseif (strpos($e->getMessage(), 'Database connection') !== false ||
              strpos($e->getMessage(), 'Failed to prepare') !== false ||
              strpos($e->getMessage(), 'Failed to execute') !== false) {
        $httpCode = 500; // Internal Server Error для ошибок БД
    }
    ErrorHandler::handleError($e, 'Favorites API', $httpCode);
}


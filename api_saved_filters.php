<?php
/**
 * API для работы с сохранёнными фильтрами (Presets)
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
    ErrorHandler::handleError($e, 'Saved Filters API (config)', 500);
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
    
    global $mysqli;
    
    // Проверяем подключение к БД
    if (!$mysqli) {
        require_once __DIR__ . '/includes/Logger.php';
        Logger::error('Database connection failed in api_saved_filters.php (mysqli is null)');
        throw new RuntimeException('Database connection failed (mysqli is null)');
    }
    
    if ($mysqli->connect_errno) {
        require_once __DIR__ . '/includes/Logger.php';
        Logger::error('Database connection error in api_saved_filters.php', [
            'errno' => $mysqli->connect_errno,
            'error' => $mysqli->connect_error
        ]);
        throw new RuntimeException('Database connection failed: ' . $mysqli->connect_error);
    }
    
    switch ($method) {
        case 'GET':
            // Получение списка сохранённых фильтров
            $stmt = $mysqli->prepare("SELECT id, name, filters, created_at, updated_at FROM saved_filters WHERE user_id = ? ORDER BY updated_at DESC");
            if (!$stmt) {
                throw new Exception('Failed to prepare statement');
            }
            $stmt->bind_param('s', $userId);
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
            break;
            
        case 'POST':
            // Проверка CSRF токена
            require_once __DIR__ . '/includes/Validator.php';
            $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
            if (!Validator::validateCsrfToken($csrf)) {
                Logger::warning('SAVED FILTERS API: CSRF validation failed');
                json_error('CSRF validation failed', 403);
            }
            
            // Сохранение нового фильтра
            $name = isset($input['name']) ? trim((string)$input['name']) : '';
            $filters = isset($input['filters']) ? $input['filters'] : [];
            
            if (empty($name)) {
                json_error('Название фильтра обязательно');
            }
            
            if (empty($filters) || !is_array($filters)) {
                json_error('Фильтры должны быть массивом');
            }
            
            $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
            
            $stmt = $mysqli->prepare("INSERT INTO saved_filters (user_id, name, filters) VALUES (?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Failed to prepare statement');
            }
            $stmt->bind_param('sss', $userId, $name, $filtersJson);
            $stmt->execute();
            $filterId = $mysqli->insert_id;
            $stmt->close();
            
            Logger::info('Filter saved', ['user' => $userId, 'filter_id' => $filterId, 'name' => $name]);
            json_success(['id' => $filterId, 'message' => 'Фильтр сохранён']);
            break;
            
        case 'PUT':
            // Проверка CSRF токена
            require_once __DIR__ . '/includes/Validator.php';
            $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
            if (!Validator::validateCsrfToken($csrf)) {
                Logger::warning('SAVED FILTERS API: CSRF validation failed');
                json_error('CSRF validation failed', 403);
            }
            
            // Обновление существующего фильтра
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $name = isset($input['name']) ? trim((string)$input['name']) : '';
            $filters = isset($input['filters']) ? $input['filters'] : [];
            
            if ($id <= 0) {
                json_error('Invalid filter ID');
            }
            
            if (empty($name)) {
                json_error('Название фильтра обязательно');
            }
            
            if (empty($filters) || !is_array($filters)) {
                json_error('Фильтры должны быть массивом');
            }
            
            $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
            
            $stmt = $mysqli->prepare("UPDATE saved_filters SET name = ?, filters = ? WHERE id = ? AND user_id = ?");
            if (!$stmt) {
                throw new Exception('Failed to prepare statement');
            }
            $stmt->bind_param('ssis', $name, $filtersJson, $id, $userId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected === 0) {
                json_error('Фильтр не найден или нет доступа');
            }
            
            Logger::info('Filter updated', ['user' => $userId, 'filter_id' => $id]);
            json_success(['message' => 'Фильтр обновлён']);
            break;
            
        case 'DELETE':
            // Проверка CSRF токена
            require_once __DIR__ . '/includes/Validator.php';
            $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
            if (!Validator::validateCsrfToken($csrf)) {
                Logger::warning('SAVED FILTERS API: CSRF validation failed');
                json_error('CSRF validation failed', 403);
            }
            
            // Удаление фильтра
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            
            if ($id <= 0) {
                json_error('Invalid filter ID');
            }
            
            $stmt = $mysqli->prepare("DELETE FROM saved_filters WHERE id = ? AND user_id = ?");
            if (!$stmt) {
                throw new Exception('Failed to prepare statement');
            }
            $stmt->bind_param('is', $id, $userId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected === 0) {
                json_error('Фильтр не найден или нет доступа');
            }
            
            Logger::info('Filter deleted', ['user' => $userId, 'filter_id' => $id]);
            json_success(['message' => 'Фильтр удалён']);
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
    ErrorHandler::handleError($e, 'Saved Filters API', $httpCode);
}



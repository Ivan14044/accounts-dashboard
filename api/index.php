<?php
/**
 * Единая точка входа для всех API endpoints
 * 
 * Все API запросы должны идти через этот файл:
 * /api/accounts/count
 * /api/accounts
 * /api/accounts/bulk
 * /api/favorites
 * /api/settings
 * и т.д.
 */

// Загружаем config.php с обработкой ошибок подключения к БД
try {
    require_once __DIR__ . '/../config.php';
} catch (Throwable $e) {
    require_once __DIR__ . '/../includes/Utils.php';
    require_once __DIR__ . '/../includes/Logger.php';
    require_once __DIR__ . '/../includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'API Router (config)', 500);
    exit;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/ApiRouter.php';
require_once __DIR__ . '/../includes/AccountsService.php';
require_once __DIR__ . '/../includes/Utils.php';
require_once __DIR__ . '/../includes/Logger.php';
require_once __DIR__ . '/../includes/Validator.php';
require_once __DIR__ . '/../includes/Database.php';

// Middleware для проверки авторизации и rate limiting
$authMiddleware = function() {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api');
    return true;
};

// Создаем роутер
$router = new ApiRouter();
$router->addMiddleware($authMiddleware);

// Регистрируем маршруты
// Accounts endpoints
$router->get('/accounts/count', function() {
    $service = new AccountsService();
    
    // Если передан параметр q, возвращаем также количество результатов
    if (!empty($_GET['q'])) {
        $filter = $service->createFilterFromRequest($_GET);
        $count = $service->getAccountsCount($filter);
        
        // Также возвращаем ограниченный список для quick search
        $limit = isset($_GET['limit']) ? Validator::validateId($_GET['limit'], true) : 10;
        if ($limit > 0 && $limit <= 50) {
            $rows = $service->getAccounts($filter, 'id', 'DESC', $limit, 0);
            json_success([
                'count' => $count,
                'rows' => $rows
            ]);
        } else {
            json_success(['count' => $count]);
        }
    } else {
        // Обычный подсчет
        $filter = $service->createFilterFromRequest($_GET);
        $count = $service->getAccountsCount($filter);
        json_success(['count' => $count]);
    }
});

$router->post('/accounts', function() {
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    // Валидация CSRF токена
    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CREATE ACCOUNT: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Валидация обязательных полей
    $login = trim((string)($input['login'] ?? ''));
    $status = trim((string)($input['status'] ?? ''));
    
    if (empty($login)) {
        throw new InvalidArgumentException('Login is required');
    }
    
    if (empty($status)) {
        throw new InvalidArgumentException('Status is required');
    }
    
    // Получаем сервис и метаданные для валидации полей
    $service = new AccountsService();
    $meta = $service->getColumnMetadata();
    
    // Подготавливаем данные для создания аккаунта
    // Исключаем служебные поля (csrf) и проверяем существование остальных
    $accountData = [];
    foreach ($input as $field => $value) {
        // Пропускаем служебные поля
        if (in_array($field, ['csrf'], true)) {
            continue;
        }
        
        // Пропускаем системные поля, которые не должны передаваться
        if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        
        // Проверяем существование поля в метаданных
        if (!in_array($field, $meta['all'], true)) {
            Logger::warning('CREATE ACCOUNT: Unknown field ignored', ['field' => $field]);
            continue;
        }
        
        // Валидируем поле
        try {
            $validatedField = Validator::validateField($field, $meta['all']);
            $accountData[$validatedField] = $value;
        } catch (InvalidArgumentException $e) {
            Logger::warning('CREATE ACCOUNT: Invalid field skipped', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            continue;
        }
    }
    
    // Убеждаемся, что обязательные поля присутствуют после фильтрации
    if (empty($accountData['login']) || trim((string)$accountData['login']) === '') {
        throw new InvalidArgumentException('Login is required');
    }
    
    if (empty($accountData['status']) || trim((string)$accountData['status']) === '') {
        throw new InvalidArgumentException('Status is required');
    }
    
    $id = $service->createAccount($accountData);
    
    json_success([
        'id' => $id,
        'message' => 'Account created successfully'
    ]);
});

$router->post('/accounts/bulk', function() {
    $input = read_json_input(20 * 1024 * 1024); // 20MB максимум для bulk операций
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    // Валидация CSRF токена
    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CREATE ACCOUNTS BULK: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Получаем массив аккаунтов
    $accountsData = $input['accounts'] ?? [];
    if (!is_array($accountsData) || empty($accountsData)) {
        throw new InvalidArgumentException('Accounts array is required and must not be empty');
    }
    
    // Ограничение на количество аккаунтов за один запрос
    if (count($accountsData) > 10000) {
        throw new InvalidArgumentException('Maximum 10000 accounts per request allowed');
    }
    
    // Действие при дубликатах
    $duplicateAction = $input['duplicate_action'] ?? 'skip';
    if (!in_array($duplicateAction, ['skip', 'error'], true)) {
        $duplicateAction = 'skip';
    }
    
    // Получаем сервис и метаданные для валидации полей
    $service = new AccountsService();
    $meta = $service->getColumnMetadata();
    
    // Валидируем и нормализуем данные для каждого аккаунта
    $validatedAccounts = [];
    foreach ($accountsData as $idx => $accountData) {
        if (!is_array($accountData)) {
            continue; // Пропускаем невалидные записи
        }
        
        $validatedAccount = [];
        
        // Проверяем обязательные поля
        $login = trim((string)($accountData['login'] ?? ''));
        $status = trim((string)($accountData['status'] ?? ''));
        
        if (empty($login) || empty($status)) {
            continue; // Пропускаем записи без обязательных полей
        }
        
        $validatedAccount['login'] = $login;
        $validatedAccount['status'] = $status;
        
        // Валидируем остальные поля
        foreach ($accountData as $field => $value) {
            // Пропускаем служебные поля
            if (in_array($field, ['csrf', 'id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            
            // Проверяем существование поля в метаданных
            if (!in_array($field, $meta['all'], true)) {
                continue;
            }
            
            // Валидируем поле
            try {
                $validatedField = Validator::validateField($field, $meta['all']);
                $validatedAccount[$validatedField] = $value;
            } catch (InvalidArgumentException $e) {
                continue; // Пропускаем невалидные поля
            }
        }
        
        $validatedAccounts[] = $validatedAccount;
    }
    
    if (empty($validatedAccounts)) {
        throw new InvalidArgumentException('No valid accounts to create');
    }
    
    $result = $service->createAccountsBulk($validatedAccounts, $duplicateAction);
    
    json_success($result);
});

$router->post('/accounts/custom-card', function() {
    // Проверка CSRF токена для POST запросов
    $input = read_json_input(1048576); // 1MB максимум
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CUSTOM CARD API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }
    
    $service = new AccountsService();
    
    // Получаем фильтры из POST запроса (JSON)
    $filters = $input;
    
    if (!$filters || !is_array($filters)) {
        // Если не JSON, пробуем GET параметры (для обратной совместимости)
        $filters = $_GET;
    }
    
    // Создаем фильтр из переданных параметров
    $filter = $service->createFilterFromArray($filters);
    
    // Подсчитываем количество записей
    $count = $service->getAccountsCount($filter);
    
    json_success(['count' => $count]);
});

$router->post('/status/register', function() {
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
    
    // Валидация статуса (только буквы, цифры, подчеркивания, дефисы, пробелы)
    if (!preg_match('/^[a-zA-Z0-9_\-\s]+$/', $status)) {
        throw new Exception('Invalid status format. Only letters, numbers, underscores, hyphens and spaces are allowed');
    }
    
    $service = new AccountsService();
    
    // Проверяем, есть ли уже записи с таким статусом
    $meta = $service->getColumnMetadata();
    require_once __DIR__ . '/../includes/FilterBuilder.php';
    require_once __DIR__ . '/../includes/ColumnMetadata.php';
    require_once __DIR__ . '/../includes/Database.php';
    
    $filter = new FilterBuilder($meta['columns'], $meta['numeric'], \AccountsService::getNumericLikeColumns());
    $filter->addEqualFilter('status', $status);
    $count = $service->getAccountsCount($filter);
    
    if ($count > 0) {
        // Статус уже существует в БД — просто очищаем кэши, чтобы он появился в фильтрах
        ColumnMetadata::clearCache();
        Database::getInstance()->clearCache();
        json_success(['message' => 'Status already exists', 'exists' => true]);
        return;
    }
    
    // Создаем запись с новым статусом, чтобы он появился в списке доступных статусов
    // Используем специальный префикс для идентификации таких записей
    $mysqli = Database::getInstance()->getConnection();

    // Проверяем, есть ли уже служебная запись с таким статусом
    $serviceLogin = '__status_marker_' . md5($status);
    $checkStmt = $mysqli->prepare("SELECT id FROM accounts WHERE login = ? AND status = ? LIMIT 1");
    $checkStmt->bind_param('ss', $serviceLogin, $status);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        // Создаем служебную запись с новым статусом
        $insertStmt = $mysqli->prepare("INSERT INTO accounts (login, status, created_at) VALUES (?, ?, NOW())");
        $insertStmt->bind_param('ss', $serviceLogin, $status);
        $insertStmt->execute();
        $insertStmt->close();
    }
    
    $checkStmt->close();
    
    // Очищаем кэш метаданных и кэши запросов, чтобы новый статус появился в списке
    ColumnMetadata::clearCache();
    Database::getInstance()->clearCache();
    
    json_success(['message' => 'Status registered successfully', 'exists' => false]);
});

// Favorites endpoints
$router->get('/favorites', function() {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    // Проверяем существование таблицы и создаём, если её нет
    if (!$db->tableExists('account_favorites')) {
        $createTableSQL = "
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
        
        $allowedTables = ['account_favorites'];
        if (!$db->executeDDL($createTableSQL, $allowedTables)) {
            json_error('Ошибка создания таблицы избранного');
            return;
        }
    }
    
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

$router->post('/favorites', function() {
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

    // Проверяем существование таблицы
    if (!$db->tableExists('account_favorites')) {
        $createTableSQL = "
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
        
        $allowedTables = ['account_favorites'];
        if (!$db->executeDDL($createTableSQL, $allowedTables)) {
            json_error('Ошибка создания таблицы избранного');
            return;
        }
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
});

$router->delete('/favorites', function() {
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

// Settings endpoints
$router->get('/settings', function() {
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }

    $settingType = $_GET['type'] ?? 'custom_cards';

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    // Проверяем существование таблицы
    if (!$db->tableExists('user_settings')) {
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
            throw new Exception('Failed to create user_settings table');
        }
    }
    
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
        $defaultValue = $settingType === 'custom_cards' ? [] : [];
        json_success(['value' => $defaultValue]);
    }
    
    $stmt->close();
});

$router->post('/settings', function() {
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }
    
    $input = read_json_input(1048576);
    
    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('USER SETTINGS API: CSRF validation failed');
        throw new Exception('CSRF validation failed');
    }
    
    $settingType = $input['type'] ?? 'custom_cards';
    $settingValue = $input['value'] ?? null;
    
    if (!isset($input['value'])) {
        throw new Exception('Value is required');
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    // Проверяем существование таблицы
    if (!$db->tableExists('user_settings')) {
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
            throw new Exception('Failed to create user_settings table');
        }
    }
    
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
});

$router->put('/settings', function() {
    // PUT обрабатывается так же, как POST
    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        throw new Exception('User not authenticated');
    }
    
    $input = read_json_input(1048576);
    
    // Проверка CSRF токена
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('USER SETTINGS API: CSRF validation failed');
        throw new Exception('CSRF validation failed');
    }
    
    $settingType = $input['type'] ?? 'custom_cards';
    $settingValue = $input['value'] ?? null;
    
    if (!isset($input['value'])) {
        throw new Exception('Value is required');
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    // Проверяем существование таблицы
    if (!$db->tableExists('user_settings')) {
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
            throw new Exception('Failed to create user_settings table');
        }
    }
    
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
});

// Saved filters endpoints
$router->get('/filters', function() {
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        json_error('Необходима авторизация');
        return;
    }

    $mysqli = Database::getInstance()->getConnection();
    
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
});

$router->post('/filters', function() {
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
    
    if (empty($filters) || !is_array($filters)) {
        json_error('Фильтры должны быть массивом');
        return;
    }

    $mysqli = Database::getInstance()->getConnection();
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
});

$router->put('/filters', function() {
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

    $mysqli = Database::getInstance()->getConnection();
    $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
    $stmt = $mysqli->prepare("UPDATE saved_filters SET name = ?, filters = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement');
    }
    $stmt->bind_param('ssis', $name, $filtersJson, $id, $userId);
    $stmt->execute();
    $stmt->close();
    
    Logger::info('Filter updated', ['user' => $userId, 'filter_id' => $id, 'name' => $name]);
    json_success(['message' => 'Фильтр обновлён']);
});

$router->delete('/filters', function() {
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

    $mysqli = Database::getInstance()->getConnection();
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

// Обработка запроса
$router->dispatch();

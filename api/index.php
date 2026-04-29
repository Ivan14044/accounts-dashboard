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

// ============================================================
// Вспомогательные функции (вынесены сюда, чтобы не дублировать)
// ============================================================

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
$router->get('/accounts/count', function() use ($tableName) {
    $service = new AccountsService($tableName);
    
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

$router->post('/accounts', function() use ($tableName) {
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
    $service = new AccountsService($tableName);
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

$router->post('/accounts/bulk', function() use ($tableName) {
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
    $service = new AccountsService($tableName);
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

$router->post('/accounts/custom-card', function() use ($tableName) {
    // Проверка CSRF токена для POST запросов
    $input = read_json_input(1048576); // 1MB максимум
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CUSTOM CARD API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }
    
    $service = new AccountsService($tableName);
    
    // Получаем фильтры из POST запроса (JSON)
    $filters = $input;
    
    if (!$filters || !is_array($filters)) {
        // Если не JSON, пробуем GET параметры (для обратной совместимости)
        $filters = $_GET;
    }
    
    // Создаем фильтр из переданных параметров
    $filter = $service->createFilterFromRequest($filters);
    
    // Подсчитываем количество записей
    $count = $service->getAccountsCount($filter);
    
    json_success(['count' => $count]);
});

// ────────────────────────────────────────────────────────────
// Проверка аккаунтов на валидность (acctool.top)
// ────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/AccountValidationService.php';

/**
 * POST /accounts/validate/preview
 * Pre-flight COUNT: возвращает только число записей в выбранном scope,
 * без выборки cookies/regex-парсинга. Нужен для мгновенной обратной связи
 * пользователю ("Будет проверено: 1234 аккаунта") до старта тяжёлого prepare.
 */
$router->post('/accounts/validate/preview', function() use ($tableName) {
    $input = read_json_input(1048576);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid input');

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $scope = (string)($input['scope'] ?? 'selected');
    if (!in_array($scope, ['selected', 'page', 'filter'], true)) {
        throw new InvalidArgumentException('Invalid scope');
    }

    $ids   = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
    $query = (string)($input['query'] ?? '');

    $service = new AccountsService($tableName);
    $total   = $service->getValidationCount($scope, $ids, $query);

    json_success(['total' => $total, 'scope' => $scope]);
});

/**
 * POST /accounts/validate/prepare
 * Подготовка списка: извлекает FB ID из записей, фильтрует пустые.
 */
$router->post('/accounts/validate/prepare', function() use ($tableName) {
    $input = read_json_input(1048576);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid input');

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $scope = (string)($input['scope'] ?? 'selected');
    if (!in_array($scope, ['selected', 'page', 'filter'], true)) {
        throw new InvalidArgumentException('Invalid scope');
    }

    $ids    = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
    $query  = (string)($input['query'] ?? '');
    $limit  = min(max((int)($input['limit'] ?? Config::VALIDATE_PREPARE_LIMIT), 1), Config::VALIDATE_PREPARE_LIMIT);
    $offset = max(0, (int)($input['offset'] ?? 0));

    $tStart = microtime(true);
    $service  = new AccountsService($tableName);
    $data     = $service->getAccountsForValidation($scope, $ids, $query, $limit, $offset);
    $tSql     = microtime(true);
    $prepared = AccountValidationService::prepareItems($data['rows']);
    $tEnd     = microtime(true);

    $rowCount = count($data['rows']);
    $nextOffset = $offset + $rowCount;
    $sqlMs    = (int)(($tSql - $tStart) * 1000);
    $extractMs = (int)(($tEnd - $tSql) * 1000);
    $totalMs  = $sqlMs + $extractMs;

    Logger::debug('validate/prepare timing', [
        'scope'      => $scope,
        'rows'       => $rowCount,
        'items'      => count($prepared['items']),
        'skipped'    => count($prepared['skipped']),
        'sql_ms'     => $sqlMs,
        'extract_ms' => $extractMs,
        'total_ms'   => $totalMs,
    ]);
    if ($totalMs > 3000) {
        Logger::warning('validate/prepare slow', [
            'scope' => $scope, 'rows' => $rowCount,
            'sql_ms' => $sqlMs, 'extract_ms' => $extractMs,
        ]);
    }

    json_success([
        'items'       => $prepared['items'],
        'skipped'     => $prepared['skipped'],
        'total'       => $data['total'],
        'has_more'    => $scope === 'filter' && $nextOffset < $data['total'],
        'next_offset' => $nextOffset,
    ]);
});

/**
 * POST /accounts/validate/check
 * Проверка батча через acctool.top (curl_multi, параллельно внутри запроса).
 * Сессия закрывается до начала — чтобы не блокировать другие запросы.
 *
 * Если передан job_id — после каждого sub-batch acctool пишем инкрементальный
 * прогресс в JobProgress. Фронт читает его через polling /progress, что даёт
 * движение % ВНУТРИ одного /check (без этого UI стоит на 0% по 5–15 сек).
 */
require_once __DIR__ . '/../includes/JobProgress.php';

$router->post('/accounts/validate/check', function() use ($tableName) {
    $input = read_json_input(1048576);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid input');

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
    if (count($items) > Config::VALIDATE_CHECK_MAX_ITEMS) {
        throw new InvalidArgumentException('Too many items, max ' . Config::VALIDATE_CHECK_MAX_ITEMS);
    }

    $jobId = (string)($input['job_id'] ?? '');
    if ($jobId !== '' && !JobProgress::isValidId($jobId)) {
        $jobId = ''; // невалидный — игнорируем, но не падаем
    }

    // Cleanup старых job-файлов: на shared нет cron, делаем оппортунистически.
    // Дешёвая операция (glob + filemtime), 1 раз на /check некритично.
    if ($jobId !== '') {
        JobProgress::cleanup();
    }

    // Отпускаем сессию — длинная операция не должна блокировать UI
    session_write_close();
    set_time_limit(120);

    $tStart = microtime(true);
    $result = AccountValidationService::checkItems($items, $jobId !== '' ? $jobId : null);
    $totalMs = (int)((microtime(true) - $tStart) * 1000);

    Logger::debug('validate/check timing', [
        'items'    => count($items),
        'valid'    => count($result['valid']   ?? []),
        'invalid'  => count($result['invalid'] ?? []),
        'skipped'  => count($result['skipped'] ?? []),
        'total_ms' => $totalMs,
        'job_id'   => $jobId,
    ]);
    if ($totalMs > 15000) {
        Logger::warning('validate/check slow', [
            'items' => count($items), 'total_ms' => $totalMs,
        ]);
    }

    json_success($result);
});

/**
 * GET /accounts/validate/progress?job_id=X
 * Возвращает текущий прогресс задачи валидации. Фронт делает polling
 * каждые 1.5 сек чтобы UI двигался во время /check.
 */
$router->get('/accounts/validate/progress', function() {
    $jobId = (string)($_GET['job_id'] ?? '');
    if (!JobProgress::isValidId($jobId)) {
        throw new InvalidArgumentException('Invalid job_id');
    }

    // Polling может прилететь до того как сервер начал писать — это не ошибка
    $data = JobProgress::read($jobId);
    if ($data === null) {
        json_success(['exists' => false, 'checked' => 0]);
        return;
    }

    json_success(array_merge(['exists' => true], $data));
});

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

// Favorites endpoints
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

// Вспомогательная функция: убедиться что таблица user_settings существует
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

// Вспомогательная функция: сохранить настройку пользователя (POST и PUT)
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

// Settings endpoints
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

// Вспомогательная функция: убедиться что таблица saved_filters существует
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

// Saved filters endpoints
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

// Обработка запроса
$router->dispatch();

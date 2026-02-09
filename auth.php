<?php
/**
 * Система авторизации
 */

// Загружаем SessionManager для управления сессиями
// Сессия уже должна быть инициализирована в config.php или bootstrap.php
// Если auth.php загружается отдельно, инициализируем сессию здесь
if (!class_exists('SessionManager')) {
    require_once __DIR__ . '/includes/SessionManager.php';
}
if (!SessionManager::isActive()) {
    SessionManager::start();
}

/**
 * Класс для управления пользователями
 */
class UserManager {
    private static $usersFile = __DIR__ . '/users.json';
    
    /**
     * Загрузка пользователей из файла
     */
    private static function loadUsers(): array {
        if (!file_exists(self::$usersFile)) {
            // Создаем дефолтного пользователя при первом запуске
            $defaultUsers = [
                'admin' => [
                    'password' => password_hash('admin123', PASSWORD_DEFAULT),
                    'role' => 'admin'
                ]
            ];
            
            $saved = self::saveUsers($defaultUsers);
            if (!$saved) {
                error_log('WARNING: Failed to create users.json - using in-memory users');
            }
            
            return $defaultUsers;
        }
        
        $content = @file_get_contents(self::$usersFile);
        if ($content === false) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::error('Failed to read users.json');
            // Возвращаем дефолтного пользователя в памяти
            return [
                'admin' => [
                    'password' => password_hash('admin123', PASSWORD_DEFAULT),
                    'role' => 'admin'
                ]
            ];
        }
        
        $users = json_decode($content, true);
        if (!is_array($users) || empty($users)) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::warning('users.json is empty or invalid - using default admin');
            return [
                'admin' => [
                    'password' => password_hash('admin123', PASSWORD_DEFAULT),
                    'role' => 'admin'
                ]
            ];
        }
        
        return $users;
    }
    
    /**
     * Сохранение пользователей в файл
     */
    private static function saveUsers(array $users): bool {
        $content = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $result = @file_put_contents(self::$usersFile, $content, LOCK_EX);
        
        if ($result === false) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::error('Failed to write to users.json', [
                'file' => self::$usersFile,
                'message' => 'Check permissions'
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Проверка учетных данных пользователя
     */
    public static function verifyCredentials(string $username, string $password): bool {
        $users = self::loadUsers();
        
        if (!isset($users[$username])) {
            return false;
        }
        
        return password_verify($password, $users[$username]['password']);
    }
    
    /**
     * Создание нового пользователя
     */
    public static function createUser(string $username, string $password, string $role = 'user'): bool {
        $users = self::loadUsers();
        
        if (isset($users[$username])) {
            return false; // Пользователь уже существует
        }
        
        $users[$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role
        ];
        
        return self::saveUsers($users);
    }
    
    /**
     * Обновление пароля пользователя
     */
    public static function updatePassword(string $username, string $newPassword): bool {
        $users = self::loadUsers();
        
        if (!isset($users[$username])) {
            return false;
        }
        
        $users[$username]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        return self::saveUsers($users);
    }
}

/**
 * Проверка авторизации
 */
function isAuthenticated(): bool {
    return isset($_SESSION['user_authenticated']) && $_SESSION['user_authenticated'] === true;
}

/**
 * Парсинг строки подключения к БД
 * Формат: server=host;port=3306;user id=username;password=pass;database=dbname;characterset=utf8mb4
 */
function parseConnectionString(string $connectionString): ?array {
    $config = [];
    $parts = explode(';', $connectionString);
    
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        $pos = strpos($part, '=');
        if ($pos === false) continue;
        
        $key = trim(substr($part, 0, $pos));
        $value = trim(substr($part, $pos + 1));
        
        // Нормализуем ключи
        $key = strtolower($key);
        switch ($key) {
            case 'server':
                $config['host'] = $value;
                break;
            case 'port':
                $config['port'] = (int)$value ?: 3306;
                break;
            case 'user id':
            case 'userid':
            case 'user':
                $config['user'] = $value;
                break;
            case 'password':
            case 'pwd':
                $config['password'] = $value;
                break;
            case 'database':
            case 'dbname':
            case 'initial catalog':
                $config['database'] = $value;
                break;
            case 'characterset':
            case 'charset':
                $config['charset'] = $value;
                break;
        }
    }
    
    // Проверяем обязательные параметры
    if (empty($config['host']) || empty($config['user']) || empty($config['database'])) {
        return null;
    }
    
    // Устанавливаем значения по умолчанию
    $config['port'] = $config['port'] ?? 3306;
    $config['password'] = $config['password'] ?? '';
    $config['charset'] = $config['charset'] ?? 'utf8mb4';
    
    return $config;
}

/**
 * Тестирование подключения к БД
 */
function testDatabaseConnection(array $dbConfig): array {
    $host = $dbConfig['host'] ?? 'localhost';
    $port = $dbConfig['port'] ?? 3306;
    $user = $dbConfig['user'] ?? '';
    $password = $dbConfig['password'] ?? '';
    $database = $dbConfig['database'] ?? '';
    
    if (empty($user) || empty($database)) {
        return ['success' => false, 'error' => 'Не указаны обязательные параметры подключения'];
    }
    
    // Отключаем отчеты об ошибках для тестового подключения
    $oldReport = mysqli_report(MYSQLI_REPORT_OFF);
    
    $mysqli = @new mysqli($host, $user, $password, $database, $port);
    
    if ($mysqli->connect_errno) {
        $error = $mysqli->connect_error;
        $mysqli->close();
        mysqli_report($oldReport);
        return ['success' => false, 'error' => $error];
    }
    
    // Устанавливаем кодировку
    $mysqli->set_charset($dbConfig['charset'] ?? 'utf8mb4');
    
    // Автоматическая проверка и настройка структуры БД
    require_once __DIR__ . '/includes/DatabaseSchemaManager.php';
    $schemaManager = new DatabaseSchemaManager($mysqli);
    $migrationResults = $schemaManager->validateAndMigrate();
    
    // Логируем результаты миграции
    require_once __DIR__ . '/includes/Logger.php';
    if (!empty($migrationResults['tables_created']) || 
        !empty($migrationResults['columns_added']) || 
        !empty($migrationResults['indexes_created'])) {
        Logger::info('DB Schema migration completed during connection test', [
            'tables' => $migrationResults['tables_created'],
            'columns' => $migrationResults['columns_added'],
            'indexes' => $migrationResults['indexes_created']
        ]);
    }
    
    if (!empty($migrationResults['errors'])) {
        Logger::error('DB Schema migration errors during connection test', $migrationResults['errors']);
        // Не блокируем подключение из-за ошибок миграции, но логируем их
    }
    
    // Проверяем, что основная таблица accounts теперь существует
    $dbName = $database;
    $tableName = 'accounts';
    $checkTable = $mysqli->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    if ($checkTable) {
        $checkTable->bind_param('ss', $dbName, $tableName);
        $checkTable->execute();
        $result = $checkTable->get_result();
        $row = $result->fetch_assoc();
        $tableExists = ($row['cnt'] ?? 0) > 0;
        $checkTable->close();
    } else {
        // Fallback на старый способ
        $tableExists = $mysqli->query("SHOW TABLES LIKE 'accounts'");
        $tableExists = $tableExists && $tableExists->num_rows > 0;
    }
    
    if (!$tableExists) {
        $mysqli->close();
        mysqli_report($oldReport);
        return ['success' => false, 'error' => 'Не удалось создать таблицу accounts. Проверьте права доступа к БД.'];
    }
    
    $mysqli->close();
    mysqli_report($oldReport);
    
    return ['success' => true];
}

/**
 * Авторизация пользователя по строке подключения к БД
 * Авторизация происходит автоматически при успешном подключении к БД
 */
function authenticate(array $dbConfig, bool $rememberMe = true): bool {
    // Проверяем, что dbConfig передан и содержит необходимые параметры
    if (empty($dbConfig) || empty($dbConfig['host']) || empty($dbConfig['user']) || empty($dbConfig['database'])) {
        require_once __DIR__ . '/includes/Logger.php';
        Logger::warning('AUTH: Invalid dbConfig provided', ['keys' => array_keys($dbConfig ?? [])]);
        return false;
    }
    
    // Предотвращаем фиксацию сессии
    SessionManager::regenerateId();
    
    // Авторизуем пользователя (авторизация происходит по факту успешного подключения к БД)
    $_SESSION['user_authenticated'] = true;
    $_SESSION['username'] = $dbConfig['user'] . '@' . $dbConfig['host']; // Используем user@host как идентификатор
    $_SESSION['login_time'] = time();
    $_SESSION['remember_me'] = $rememberMe;
    
    // Сохраняем параметры подключения к БД в сессии
    $_SESSION['db_config'] = $dbConfig;
    // Логируем для отладки (только ключи, без паролей)
    require_once __DIR__ . '/includes/Logger.php';
    $logConfig = $dbConfig;
    if (isset($logConfig['password'])) {
        $logConfig['password'] = '***';
    }
    Logger::debug('AUTH: DB config saved to session', $logConfig);
    
    // Очищаем кэш метаданных колонок при смене БД
    // Это нужно, чтобы при подключении к новой БД использовались актуальные метаданные
    require_once __DIR__ . '/includes/ColumnMetadata.php';
    ColumnMetadata::clearCache();
    
    // Дополнительная проверка и миграция БД при авторизации
    // (на случай, если миграция не была выполнена в testDatabaseConnection)
    try {
        // Создаем временное подключение для миграции
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $user = $dbConfig['user'] ?? '';
        $password = $dbConfig['password'] ?? '';
        $database = $dbConfig['database'] ?? '';
        $charset = $dbConfig['charset'] ?? 'utf8mb4';
        
        $tempMysqli = @new mysqli($host, $user, $password, $database, $port);
        if (!$tempMysqli->connect_errno) {
            $tempMysqli->set_charset($charset);
            
            require_once __DIR__ . '/includes/DatabaseSchemaManager.php';
            $schemaManager = new DatabaseSchemaManager($tempMysqli);
            $migrationResults = $schemaManager->validateAndMigrate();
            
            // Логируем результаты
            if (!empty($migrationResults['tables_created']) || 
                !empty($migrationResults['columns_added']) || 
                !empty($migrationResults['indexes_created'])) {
                Logger::info('DB Schema migration completed during authentication', [
                    'tables' => $migrationResults['tables_created'],
                    'columns' => $migrationResults['columns_added'],
                    'indexes' => $migrationResults['indexes_created']
                ]);
            }
            
            if (!empty($migrationResults['errors'])) {
                Logger::warning('DB Schema migration warnings during authentication', $migrationResults['errors']);
            }
            
            $tempMysqli->close();
        }
    } catch (Exception $e) {
        // Не блокируем авторизацию из-за ошибок миграции
        Logger::warning('DB Schema migration failed during authentication', [
            'error' => $e->getMessage()
        ]);
    }
    
    // Если включен "запомнить меня", устанавливаем долгосрочный cookie
    if ($rememberMe) {
        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']);
        $secure = !$isLocalhost && (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        );
        $cookieLifetime = time() + (30 * 24 * 60 * 60); // 30 дней
        
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), [
                'expires' => $cookieLifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',  // Изменено с Strict на Lax для поддержки редиректов
            ]);
        } else {
            setcookie(session_name(), session_id(), $cookieLifetime, '/; samesite=Lax', '', $secure, true);
        }
    }
    
    return true;
}

/**
 * Выход пользователя
 */
function logout(): void {
    // Сохраняем сообщение перед уничтожением сессии
    $message = 'Вы успешно вышли из системы';
    
    // Уничтожаем сессию через SessionManager
    SessionManager::destroy();
    
    // НЕ перезапускаем сессию - это вызывает циклические редиректы
    // Сообщение будет передано через GET параметр
}

/**
 * Проверка авторизации на защищенных страницах
 */
function requireAuth(): void {
    if (!isAuthenticated()) {
        // Для AJAX запросов возвращаем JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Unauthorized', 'redirect' => 'login.php']);
            exit;
        }
        
        // Для обычных запросов делаем редирект с абсолютным путём
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $loginUrl = $protocol . '://' . $host . rtrim($scriptDir, '/') . '/login.php';
        
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * Получение имени текущего пользователя
 */
function getCurrentUser(): string {
    return $_SESSION['username'] ?? 'Гость';
}

/**
 * Проверка таймаута сессии
 */
function checkSessionTimeout(): void {
    // Проверяем, что пользователь авторизован
    if (!isAuthenticated()) {
        return;
    }
    
    // Если включен "запомнить меня", используем длительный таймаут (30 дней)
    // Иначе используем короткий таймаут (8 часов)
    $rememberMe = $_SESSION['remember_me'] ?? true;
    $timeout = $rememberMe ? (30 * 24 * 60 * 60) : (8 * 60 * 60);
    
    // Проверяем время последней активности (обновляем только раз в 5 минут)
    $lastActivity = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time();
    $activityUpdateInterval = 5 * 60; // 5 минут
    
    // Проверяем таймаут по времени входа
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $timeout) {
        // Очищаем сессию без перезапуска
        $_SESSION = [];
        session_destroy();
        
        // Редирект с сообщением через GET
        header('Location: login.php?message=timeout');
        exit;
    }
    
    // Обновляем время последней активности только раз в 5 минут
    // Это предотвращает бесконечное продление сессии при каждом запросе
    if (!isset($_SESSION['last_activity']) || (time() - $lastActivity) >= $activityUpdateInterval) {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Генерация CSRF токена
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Проверка CSRF токена
 */
function verifyCsrfToken(string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

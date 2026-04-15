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
            // Создаем дефолтного пользователя при первом запуске с СЛУЧАЙНЫМ паролем
            $randomPassword = bin2hex(random_bytes(12)); // 24-символьный случайный пароль
            $defaultUsers = [
                'admin' => [
                    'password' => password_hash($randomPassword, PASSWORD_DEFAULT),
                    'role' => 'admin'
                ]
            ];

            $saved = self::saveUsers($defaultUsers);
            if (!$saved) {
                error_log('WARNING: Failed to create users.json - using in-memory users');
            }

            // Логируем сгенерированный пароль (один раз, при первом запуске)
            error_log('FIRST RUN: Admin user created with password: ' . $randomPassword . ' — CHANGE IT IMMEDIATELY!');

            return $defaultUsers;
        }

        $content = @file_get_contents(self::$usersFile);
        if ($content === false) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::error('Failed to read users.json');
            // Возвращаем пустой массив — без фолбека на дефолтный пароль
            return [];
        }

        $users = json_decode($content, true);
        if (!is_array($users) || empty($users)) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::warning('users.json is empty or invalid');
            return [];
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

    // Ротируем CSRF-токен при новой авторизации
    unset($_SESSION['csrf_token']);

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
    
    // Миграция схемы БД уже выполнена в testDatabaseConnection(),
    // который всегда вызывается перед authenticate(). Повторный вызов не нужен.

    // Устанавливаем cookie: remember_me → 30 дней, иначе session cookie
    if ($rememberMe) {
        SessionManager::refreshRememberMeCookie();
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

    // Если включен "запомнить меня" — таймаут 30 дней по неактивности
    // Иначе — 8 часов по неактивности
    $rememberMe = $_SESSION['remember_me'] ?? false;
    $timeout = $rememberMe ? SessionManager::REMEMBER_ME_LIFETIME : SessionManager::DEFAULT_LIFETIME;

    // Определяем время последней активности
    $lastActivity = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time();

    // Проверяем таймаут по времени ПОСЛЕДНЕЙ АКТИВНОСТИ (не по login_time)
    if ((time() - $lastActivity) > $timeout) {
        // Уничтожаем сессию через SessionManager (удаляет и cookie)
        SessionManager::destroy();

        // Для AJAX запросов возвращаем JSON (иначе fetch получит HTML и JSON.parse упадёт)
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Session expired', 'redirect' => 'login.php?message=timeout']);
            exit;
        }

        // Редирект с сообщением через GET
        header('Location: login.php?message=timeout');
        exit;
    }

    // Обновляем время последней активности только раз в 5 минут
    // чтобы не писать в сессию на каждый запрос
    $activityUpdateInterval = 5 * 60;
    if (!isset($_SESSION['last_activity']) || (time() - $lastActivity) >= $activityUpdateInterval) {
        $_SESSION['last_activity'] = time();

        // Продлеваем cookie для remember_me (скользящее окно 30 дней)
        SessionManager::refreshRememberMeCookie();
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

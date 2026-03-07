<?php
/**
 * Конфигурация приложения и подключение к БД
 */

// Настройки отображения ошибок
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

// Отключаем автоматические отчеты об ошибках MySQL
mysqli_report(MYSQLI_REPORT_OFF);

// Загружаем SessionManager для управления сессиями
require_once __DIR__ . '/includes/SessionManager.php';
SessionManager::start();

// Проверяем наличие параметров БД в сессии (приоритет над любыми глобальными настройками)
if (isset($_SESSION['db_config']) && is_array($_SESSION['db_config'])) {
    $dbConfig = $_SESSION['db_config'];
    $DB_HOST = $dbConfig['host'] ?? 'localhost';
    $DB_NAME = $dbConfig['database'] ?? '';
    $DB_USER = $dbConfig['user'] ?? '';
    $DB_PASS = $dbConfig['password'] ?? '';
    $DB_PORT = $dbConfig['port'] ?? 3306;
    $DB_CHARSET = $dbConfig['charset'] ?? 'utf8mb4';
    
    // Логируем для отладки (без пароля)
    $logConfig = $dbConfig;
    if (isset($logConfig['password'])) {
        $logConfig['password'] = '***';
    }
    error_log('CONFIG: Using DB config from session: ' . json_encode($logConfig));
} else {
    // Жёсткий отказ от .env / переменных окружения для настроек БД аккаунтов.
    // Единственный допустимый источник настроек подключения к БД — данные,
    // которые пользователь ввёл на странице логина и которые сохранены в сессии.
    error_log('CONFIG: No session db_config found; DB connection must be configured via login form.');
    
    // Инициализируем значения по умолчанию (чтобы последующая проверка сработала корректно)
    $DB_HOST = 'localhost';
    $DB_NAME = '';
    $DB_USER = '';
    $DB_PASS = '';
    $DB_PORT = 3306;
    $DB_CHARSET = 'utf8mb4';
}

// Проверка обязательных параметров
if (empty($DB_NAME) || empty($DB_USER)) {
    $errorMsg = 'Database configuration is missing. ';
    if (isset($_SESSION['db_config'])) {
        $errorMsg .= 'Session db_config exists but missing required fields. ';
        $errorMsg .= 'Config: ' . json_encode($_SESSION['db_config']);
    } else {
        $errorMsg .= 'No session db_config found. Database connection must be provided via login form.';
    }
    error_log($errorMsg);

    // Если пользователь не авторизован (сессия истекла или не было входа) —
    // редирект на страницу логина вместо HTTP 500. Так пользователь увидит форму входа,
    // а не сообщение «Сайт не может обработать этот запрос».
    if (!function_exists('isAuthenticated')) {
        require_once __DIR__ . '/auth.php';
    }
    if (!isAuthenticated()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Unauthorized', 'redirect' => 'login.php']);
            exit;
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $loginUrl = $protocol . '://' . $host . rtrim($scriptDir, '/') . '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }

    // Авторизован, но конфигурации БД нет (неконсистентная сессия) — выбрасываем исключение
    throw new Exception('Ошибка конфигурации. Проверьте настройки подключения к БД. ' . $errorMsg);
}

// Подключение к базе данных
$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);

// Проверяем, что подключение успешно (mysqli может вернуть false при критических ошибках)
if ($mysqli === false || $mysqli->connect_errno) {
    $connectError = ($mysqli !== false && isset($mysqli->connect_error)) 
        ? $mysqli->connect_error 
        : 'Unknown database connection error';
    
    $errorMsg = 'DB connect failed: ' . $connectError . ' | Host: ' . $DB_HOST . ' | Port: ' . $DB_PORT . ' | User: ' . $DB_USER . ' | Database: ' . $DB_NAME;
    // Логируем через error_log, так как Logger может быть недоступен на этой стадии
    error_log($errorMsg);
    
    // Сохраняем ошибку в сессию для отображения на странице логина
    $_SESSION['last_db_error'] = $connectError;
    
    // Устанавливаем $mysqli в null для явной индикации ошибки
    $mysqli = null;
    
    // Выбрасываем исключение вместо exit(), чтобы можно было перехватить в try-catch
    throw new RuntimeException('Сервис временно недоступен. Ошибка подключения к базе данных: ' . $connectError);
}

// Устанавливаем кодировку
if (!$mysqli->set_charset($DB_CHARSET)) {
    error_log('Failed to set charset to ' . $DB_CHARSET . ': ' . $mysqli->error);
    // Продолжаем работу, так как это не критично
}

// Автоматическое применение оптимизаций при первом запуске
if (file_exists(__DIR__ . '/auto_setup.php')) {
    require_once __DIR__ . '/auto_setup.php';
}

// Безопасный вывод (если функция не определена)
if (!function_exists('e')) {
    function e($v) { 
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
    }
}

// Проверяем, что таблица accounts существует (безопасная проверка через INFORMATION_SCHEMA)
$dbName = $DB_NAME;
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
    // Fallback на старый способ, если INFORMATION_SCHEMA недоступен
    $tableExists = $mysqli->query("SHOW TABLES LIKE 'accounts'");
    $tableExists = $tableExists && $tableExists->num_rows > 0;
}

if (!$tableExists) {
    // Создаем таблицу, если её нет
    $createTable = "
    CREATE TABLE IF NOT EXISTS `accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `login` VARCHAR(255) NOT NULL,
        `password` VARCHAR(255),
        `email` VARCHAR(255),
        `email_password` VARCHAR(255),
        `first_name` VARCHAR(255),
        `last_name` VARCHAR(255),
        `social_url` TEXT,
        `birth_day` INT,
        `birth_month` INT,
        `birth_year` INT,
        `token` TEXT,
        `ads_id` VARCHAR(255),
        `cookies` TEXT,
        `first_cookie` TEXT,
        `user_agent` TEXT,
        `two_fa` VARCHAR(255),
        `extra_info_1` TEXT,
        `extra_info_2` TEXT,
        `extra_info_3` TEXT,
        `extra_info_4` TEXT,
        `status` VARCHAR(100),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_status` (`status`),
        INDEX `idx_created_at` (`created_at`),
        INDEX `idx_updated_at` (`updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    if (!$mysqli->query($createTable)) {
        error_log('Failed to create accounts table: ' . $mysqli->error);
    }
}

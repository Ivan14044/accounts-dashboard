<?php
/**
 * Конфигурация приложения и подключение к БД
 */

// Настройки отображения ошибок
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/php_errors.log');

// Отключаем автоматические отчеты об ошибках MySQL
mysqli_report(MYSQLI_REPORT_OFF);

// Загружаем SessionManager для управления сессиями
require_once __DIR__ . '/includes/SessionManager.php';
SessionManager::start();

// Устанавливаем базовые security-заголовки для всех страниц, инклудящих config.php.
// CSP/X-Frame-Options/X-Content-Type-Options/Referrer-Policy/Permissions-Policy/HSTS.
// Для JSON-endpoints это не мешает (заголовки поверх Content-Type).
require_once __DIR__ . '/includes/ResponseHeaders.php';
ResponseHeaders::setSecurityHeaders();

// Проверяем наличие параметров БД в сессии (приоритет над любыми глобальными настройками)
if (isset($_SESSION['db_config']) && is_array($_SESSION['db_config'])) {
    $dbConfig = $_SESSION['db_config'];
    $DB_HOST = $dbConfig['host'] ?? 'localhost';
    $DB_NAME = $dbConfig['database'] ?? '';
    $DB_USER = $dbConfig['user'] ?? '';
    $DB_PASS = $dbConfig['password'] ?? '';
    $DB_PORT = $dbConfig['port'] ?? 3306;
    $DB_CHARSET = $dbConfig['charset'] ?? 'utf8mb4';
    
    // Не логируем хост/юзера/базу в общий php_errors.log — это утечка инфраструктуры.
    // Если нужна отладка, включите DEBUG в config и логируйте через Logger::debug().
} else {
    // Жёсткий отказ от .env / переменных окружения для настроек БД аккаунтов.
    // Единственный допустимый источник настроек подключения к БД — данные,
    // которые пользователь ввёл на странице логина и которые сохранены в сессии.
    // Не логируем это событие в общий php_errors.log — оно нормальное для первого захода.

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
        $safeConfig = $_SESSION['db_config'];
        if (isset($safeConfig['password'])) $safeConfig['password'] = '***';
        $errorMsg .= 'Config: ' . json_encode($safeConfig);
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

    // Логируем детальную информацию для администраторов/разработчиков
    $detailedErrorMsg = 'DB connect failed: ' . $connectError . ' | Host: ' . $DB_HOST . ' | Port: ' . $DB_PORT . ' | User: ' . $DB_USER . ' | Database: ' . $DB_NAME;
    error_log($detailedErrorMsg);

    // Сохраняем ошибку в сессию для отображения на странице логина
    $_SESSION['last_db_error'] = $connectError;

    // Устанавливаем $mysqli в null для явной индикации ошибки
    $mysqli = null;

    // Выбрасываем исключение с общей ошибкой (без деталей подключения)
    throw new RuntimeException('Сервис временно недоступен. Пожалуйста, попробуйте позже.');
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

// Определяем текущую таблицу через TableResolver
require_once __DIR__ . '/includes/TableResolver.php';
$tableResolver = TableResolver::getInstance($mysqli, $DB_NAME);
$tableName = $tableResolver->getCurrentTable();
$availableTables = $tableResolver->getAvailableTables();

// Если таблица accounts не существует — создаём её автоматически
if (!in_array('accounts', $availableTables, true)) {
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

// ==========================================
// КОНСОЛИДАЦИЯ BOOTSTRAP.PHP -> CONFIG.PHP
// ==========================================

// Определяем пути к директориям
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}
if (!defined('INCLUDES_DIR')) {
    define('INCLUDES_DIR', PROJECT_ROOT . '/includes');
}
if (!defined('TEMPLATES_DIR')) {
    define('TEMPLATES_DIR', PROJECT_ROOT . '/templates');
}
if (!defined('ASSETS_DIR')) {
    define('ASSETS_DIR', PROJECT_ROOT . '/assets');
}

// Версия ассетов для кеша: фиксированная версия позволяет браузеру кешировать JS/CSS
// Меняйте вручную при деплое новой версии (например: '2026-03-19')
// ВАЖНО: time() отключает кеш браузера — каждый запрос грузит все файлы заново!
if (!defined('ASSETS_VERSION')) {
    $v = getenv('ASSETS_VERSION');
    if ($v !== false && $v !== '') {
        define('ASSETS_VERSION', $v);
    } else {
        define('ASSETS_VERSION', '2026-04-25-v15');
    }
}

// Настройки PHP для поддержки загрузки файлов до 20MB
@ini_set('upload_max_filesize', '20M');
@ini_set('post_max_size', '25M');
@ini_set('memory_limit', '256M');

// Загружаем основные утилиты
require_once INCLUDES_DIR . '/Utils.php';
require_once INCLUDES_DIR . '/Logger.php';
require_once INCLUDES_DIR . '/Config.php';
require_once INCLUDES_DIR . '/ErrorHandler.php';

// Загружаем классы для работы с БД
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/ColumnMetadata.php';
require_once INCLUDES_DIR . '/FilterBuilder.php';

// Загружаем сервисы
require_once INCLUDES_DIR . '/AccountsService.php';
require_once INCLUDES_DIR . '/AuditLogger.php';
require_once INCLUDES_DIR . '/MassTransferService.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/RateLimitMiddleware.php';
require_once INCLUDES_DIR . '/RequestHandler.php';

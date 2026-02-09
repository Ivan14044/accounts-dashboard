<?php
/**
 * Bootstrap файл для централизованной загрузки зависимостей
 * Устраняет множественные require_once по всему проекту
 */

// Защита от двойной загрузки
if (defined('BOOTSTRAP_LOADED')) {
    return;
}
define('BOOTSTRAP_LOADED', true);

// Определяем базовую директорию проекта
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

// Определяем пути к директориям
if (!defined('INCLUDES_DIR')) {
    define('INCLUDES_DIR', PROJECT_ROOT . '/includes');
}
if (!defined('TEMPLATES_DIR')) {
    define('TEMPLATES_DIR', PROJECT_ROOT . '/templates');
}
if (!defined('ASSETS_DIR')) {
    define('ASSETS_DIR', PROJECT_ROOT . '/assets');
}

// Настройки отображения ошибок (для production установить display_errors = 0)
if (!ini_get('error_reporting')) {
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
ini_set('display_errors', '0');

// Отключаем автоматические отчеты об ошибках MySQL
mysqli_report(MYSQLI_REPORT_OFF);

// Загружаем SessionManager и инициализируем сессию
require_once INCLUDES_DIR . '/SessionManager.php';
SessionManager::start();

// Загружаем основные утилиты
require_once INCLUDES_DIR . '/Utils.php';
require_once INCLUDES_DIR . '/Logger.php';
require_once INCLUDES_DIR . '/Config.php';
require_once INCLUDES_DIR . '/ErrorHandler.php';

// Настройки PHP для поддержки загрузки файлов до 20MB
// Примечание: эти настройки могут не работать, если они уже установлены в php.ini
// В таком случае нужно изменить их напрямую в php.ini
@ini_set('upload_max_filesize', '20M');
@ini_set('post_max_size', '25M'); // Немного больше, чем upload_max_filesize
@ini_set('memory_limit', '256M'); // Увеличиваем лимит памяти для обработки больших файлов

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

// Загружаем авторизацию (после SessionManager)
require_once PROJECT_ROOT . '/auth.php';

// Регистрируем глобальный обработчик ошибок (опционально, можно включить в production)
// Для включения раскомментируйте следующую строку:
// ErrorHandler::register();


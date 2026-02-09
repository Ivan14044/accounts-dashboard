<?php
/**
 * Главный контроллер дашборда
 */

// Включаем отображение ошибок для отладки (можно отключить в продакшене)
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Отключено для предотвращения вывода перед HTML
ini_set('log_errors', '1');

// Загружаем зависимости
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RequestHandler.php';
require_once __DIR__ . '/includes/Config.php';

try {
    // Проверяем авторизацию
    requireAuth();
    checkSessionTimeout();
} catch (Throwable $e) {
    Logger::error('FATAL ERROR in index.php (before service init)', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
    echo '<h1>Ошибка инициализации</h1>';
    echo '<p>Произошла ошибка при загрузке системы.</p>';
    if (ini_get('display_errors')) {
        echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        echo '<p>Проверьте логи сервера для получения подробной информации.</p>';
    }
    echo '<p><a href="login.php">Вернуться к авторизации</a></p>';
    echo '</body></html>';
    exit;
}

// Создаем сервис
try {
    $service = new AccountsService();
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Service Creation');
}

// Загружаем DashboardController
require_once __DIR__ . '/includes/DashboardController.php';

// Создаем контроллер
$controller = new DashboardController($service);

// Обработка массового обновления статуса при клике на кастомную карточку
if ($controller->handleApplyStatus()) {
    exit; // Редирект выполнен
}

// Получаем все данные для шаблона
try {
    $dashboardData = $controller->prepareDashboardData();
    
    // Извлекаем переменные из массива данных для шаблона
    extract($dashboardData);
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Dashboard Data Preparation');
}

// Включаем шаблон
require __DIR__ . '/templates/dashboard.php';


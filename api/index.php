<?php
/**
 * Единая точка входа для всех API endpoints.
 *
 * Apache (.htaccess в той же папке) переписывает любой /api/<path> на этот файл.
 * Здесь поднимается контекст (bootstrap + auth + rate-limit), создаётся
 * {@see ApiRouter} и подключаются файлы маршрутов из `routes/`.
 *
 * URL-схема: см. соответствующие файлы в `routes/`:
 *   - /accounts/*            → routes/accounts.php
 *   - /accounts/check-fb     → routes/check-fb.php
 *   - /status/register       → routes/status.php
 *   - /favorites             → routes/favorites.php
 *   - /settings              → routes/settings.php
 *   - /filters               → routes/filters.php
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

// Создаём роутер и регистрируем глобальный auth middleware
$router = new ApiRouter();
$router->addMiddleware($authMiddleware);

// Регистрация маршрутов по ресурсам (каждый файл получает $router и $tableName из этого scope)
require __DIR__ . '/routes/accounts.php';
require __DIR__ . '/routes/status.php';
require __DIR__ . '/routes/favorites.php';
require __DIR__ . '/routes/settings.php';
require __DIR__ . '/routes/filters.php';
require __DIR__ . '/routes/check-fb.php';

// Обработка запроса
$router->dispatch();

<?php
/**
 * API для подсчета статистики по кастомным карточкам
 * Принимает POST запрос с JSON фильтрами и возвращает количество записей
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';

header('Content-Type: application/json');

try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    // Проверка CSRF токена для POST запросов
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/includes/Validator.php';
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $csrf = isset($data['csrf']) ? (string)$data['csrf'] : '';
        
        if (!Validator::validateCsrfToken($csrf)) {
            require_once __DIR__ . '/includes/Logger.php';
            Logger::warning('CUSTOM CARD API: CSRF validation failed');
            json_error('CSRF validation failed', 403);
        }
    }
    
    $service = new AccountsService();
    
    // Получаем фильтры из POST запроса (JSON)
    $input = read_json_input(1048576); // 1MB максимум
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
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Custom Card API', 400);
}




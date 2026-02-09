<?php
/**
 * API для подсчета записей по кастомным фильтрам
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/ResponseHeaders.php';

try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    $service = new AccountsService();
    
    // Если передан параметр q, возвращаем также количество результатов
    if (!empty($_GET['q'])) {
        $filter = $service->createFilterFromRequest($_GET);
        $count = $service->getAccountsCount($filter);
        
        // Также возвращаем ограниченный список для quick search
        require_once __DIR__ . '/includes/Validator.php';
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
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'API', 400);
}

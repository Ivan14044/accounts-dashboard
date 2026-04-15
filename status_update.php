<?php
/**
 * API для обновления статуса аккаунтов.
 *
 * Поддерживает два режима работы:
 * 1) Обновление выбранных записей:
 *    POST JSON:
 *    {
 *      "ids": [1, 2, 3],          // массив ID аккаунтов (максимум 1000)
 *      "status": "NEW_STATUS",    // новый статус
 *      "csrf": "..."              // CSRF-токен
 *    }
 *
 * 2) Обновление всех записей по активным фильтрам:
 *    POST JSON:
 *    {
 *      "ids": [],                 // пустой массив
 *      "select": "all",           // специальный режим "все по фильтру"
 *      "query": "q=&status[]=...",// строка query-параметров текущей страницы
 *      "status": "NEW_STATUS",    // новый статус
 *      "csrf": "..."              // CSRF-токен
 *    }
 *
 * Ответ в обоих случаях:
 * {
 *   "success": true,
 *   "affected": 123,              // количество обновлённых записей
 *   "scope": "all-filtered"       // только для режима select=all
 * }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';

    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }

    $selectAll = isset($input['select']) && $input['select'] === 'all';
    $queryString = isset($input['query']) ? (string)$input['query'] : '';
    $status = trim((string)($input['status'] ?? ''));
    $csrf = (string)($input['csrf'] ?? '');
    
    if (empty($status)) {
        throw new InvalidArgumentException('Status is required');
    }
    
    // Валидация CSRF токена
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('STATUS UPDATE: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Валидация статуса
    $status = Validator::validateStatus($status);
    
    // Валидация ID (если не selectAll)
    $ids = [];
    if (!$selectAll) {
        $ids = Validator::validateIds($input['ids'] ?? []);
    }
    
    $service = new AccountsService($tableName);
    
    if ($selectAll) {
        // Обновление всех по фильтру
        parse_str($queryString, $params);
        
        Logger::info('STATUS UPDATE: Processing bulk update', [
            'status' => $status,
            'query_string' => $queryString,
            'params' => $params
        ]);
        
        $filter = $service->createFilterFromRequest($params);
        
        // Защита от случайного обновления всех записей
        if ($filter->getConditionsCount() === 0) {
            throw new InvalidArgumentException('Нельзя применить ко всем без фильтра. Уточните фильтры или выберите строки.');
        }
        
        Logger::info('STATUS UPDATE: Filter created', [
            'conditions_count' => $filter->getConditionsCount()
        ]);
        
        $affected = $service->updateStatusByFilter($filter, $status);
        
        Logger::info('STATUS UPDATE: Update completed', [
            'affected' => $affected,
            'status' => $status
        ]);
        
        json_success([
            'affected' => $affected,
            'scope' => 'all-filtered'
        ]);
    } else {
        // Обновление выбранных записей
        Logger::info('STATUS UPDATE: Processing selected IDs', [
            'status' => $status,
            'ids_count' => count($ids)
        ]);
        
        $affected = $service->updateStatus($ids, $status);
        
        Logger::info('STATUS UPDATE: Update completed', [
            'affected' => $affected,
            'status' => $status
        ]);
        
        json_success([
            'affected' => $affected
        ]);
    }
    
} catch (Throwable $e) {
    Logger::error('STATUS UPDATE: Exception caught', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => get_class($e),
        'trace' => $e->getTraceAsString()
    ]);
    ErrorHandler::handleError($e, 'Status Update API', 400);
}

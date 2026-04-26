<?php
/**
 * API для удаления аккаунтов
 * Поддерживает удаление выбранных записей или всех по фильтру
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

requireAuth();
checkSessionTimeout();
checkRateLimit('api'); // Rate limiting для API

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    $ids = $input['ids'] ?? [];
    $csrf = $input['csrf'] ?? '';
    $selectAll = isset($_GET['select']) && $_GET['select'] === 'all';
    
    // Debug логирование (автоматически отключается в production)
    Logger::debug('DELETE REQUEST', [
        'ids_count' => is_array($ids) ? count($ids) : 0,
        'select_all' => $selectAll
    ]);
    
    // Валидация CSRF токена
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('DELETE: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    $service = new AccountsService($tableName);
    
    if ($selectAll) {
        // Удаление всех по фильтру
        $filter = $service->createFilterFromRequest($_GET);
        
        // Защита от случайного удаления всех записей
        if ($filter->getConditionsCount() === 0) {
            throw new InvalidArgumentException('Нельзя удалить все записи без фильтра. Уточните фильтры.');
        }
        
        $deleted = $service->deleteAccountsByFilter($filter);
        
        json_success([
            'message' => 'Удаление завершено',
            'deleted_count' => $deleted
        ]);
    } else {
        // Удаление выбранных записей
        $validIds = Validator::validateIds($ids);
        
        Logger::debug('DELETE: Attempting to delete accounts', ['count' => count($validIds)]);
        $deleted = $service->deleteAccounts($validIds);
        Logger::info('DELETE: Successfully deleted accounts', ['count' => $deleted]);
        
        json_success([
            'message' => "Удалено $deleted аккаунтов",
            'deleted_count' => $deleted,
            'deleted_ids' => $validIds
        ]);
    }
    
} catch (Throwable $e) {
    ErrorHandler::handleError($e, 'Delete API', 400);
}

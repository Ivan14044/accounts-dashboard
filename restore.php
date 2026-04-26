<?php
/**
 * API для восстановления аккаунтов из корзины
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/ErrorHandler.php';

try {
    requireAuth();
    checkSessionTimeout();
    
    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Method not allowed');
    }
    
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    $ids = $input['ids'] ?? [];
    $csrf = $input['csrf'] ?? '';
    
    // CSRF валидация
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('RESTORE: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Валидация ID
    $ids = Validator::validateIds($ids);
    
    // Проверка на пустой массив ID
    if (empty($ids)) {
        throw new InvalidArgumentException('IDs are required');
    }
    
    $service = new AccountsService($tableName);
    
    // Проверяем, поддерживается ли Soft Delete
    $meta = $service->getColumnMetadata();
    $supportsSoftDelete = in_array('deleted_at', $meta['all'], true);
    
    if (!$supportsSoftDelete) {
        json_error('Soft Delete не поддерживается');
    }
    
    // Восстанавливаем аккаунты
    $restored = $service->restoreAccounts($ids);
    
    Logger::info('Accounts restored from trash', [
        'user' => $_SESSION['username'] ?? 'unknown',
        'count' => $restored,
        'ids' => $ids
    ]);
    
    json_success([
        'message' => "Восстановлено $restored аккаунт(ов)",
        'restored_count' => $restored
    ]);
    
} catch (Throwable $e) {
    ErrorHandler::handleError($e, 'Restore API', 400);
}


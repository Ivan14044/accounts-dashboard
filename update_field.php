<?php
/**
 * API для обновления одного поля записи.
 *
 * Ожидает POST JSON:
 * {
 *   "id": 123,                 // ID аккаунта
 *   "field": "status",         // имя допустимого поля (валидируется по метаданным)
 *   "value": "NEW_VALUE",      // новое значение
 *   "csrf": "..."              // CSRF-токен
 * }
 *
 * Ответ:
 * {
 *   "success": true,
 *   "affected": 1
 * }
 */

// Загружаем config.php с обработкой ошибок подключения к БД
try {
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    // Если config.php выбрасывает исключение (например, ошибка подключения к БД),
    // обрабатываем его здесь
    require_once __DIR__ . '/includes/Utils.php';
    require_once __DIR__ . '/includes/Logger.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Update Field API (config)', 500);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';

try {
    requireAuth();
    checkSessionTimeout();
    
    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    // Валидация входных данных
    $id = Validator::validateId($input['id'] ?? 0);
    $field = trim((string)($input['field'] ?? ''));
    $value = $input['value'] ?? '';
    // Ограничиваем длину значения (64KB — достаточно для TEXT полей)
    if (is_string($value) && strlen($value) > 65536) {
        throw new InvalidArgumentException('Value is too long (max 64KB)');
    }
    $csrf = (string)($input['csrf'] ?? '');
    
    if (empty($field)) {
        throw new InvalidArgumentException('Field is required');
    }
    
    // Валидация CSRF токена
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('UPDATE FIELD: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Валидация поля через метаданные
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();
    $field = Validator::validateField($field, $meta['all']);
    
    Logger::debug('UPDATE FIELD', ['id' => $id, 'field' => $field]);
    
    $affected = $service->updateField($id, $field, $value);
    
    json_success(['affected' => $affected]);
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    // Определяем правильный HTTP код
    $httpCode = 500; // По умолчанию 500 для серверных ошибок
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400; // Bad Request для валидационных ошибок
    } elseif (strpos($e->getMessage(), 'not authenticated') !== false || 
              strpos($e->getMessage(), 'Unauthorized') !== false) {
        $httpCode = 401; // Unauthorized для ошибок авторизации
    } elseif (strpos($e->getMessage(), 'Database connection') !== false ||
              strpos($e->getMessage(), 'Failed to prepare') !== false ||
              strpos($e->getMessage(), 'Failed to execute') !== false) {
        $httpCode = 500; // Internal Server Error для ошибок БД
    }
    ErrorHandler::handleError($e, 'Update Field API', $httpCode);
}

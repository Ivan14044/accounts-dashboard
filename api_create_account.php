<?php
/**
 * API для создания нового аккаунта
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
    ErrorHandler::handleError($e, 'Create Account API (config)', 500);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';

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
    
    // Валидация CSRF токена
    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CREATE ACCOUNT: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Валидация обязательных полей
    $login = trim((string)($input['login'] ?? ''));
    $status = trim((string)($input['status'] ?? ''));
    
    if (empty($login)) {
        throw new InvalidArgumentException('Login is required');
    }
    
    if (empty($status)) {
        throw new InvalidArgumentException('Status is required');
    }
    
    // Получаем сервис и метаданные для валидации полей
    $service = new AccountsService();
    $meta = $service->getColumnMetadata();
    
    // Подготавливаем данные для создания аккаунта
    // Исключаем служебные поля (csrf) и проверяем существование остальных
    $accountData = [];
    foreach ($input as $field => $value) {
        // Пропускаем служебные поля
        if (in_array($field, ['csrf'], true)) {
            continue;
        }
        
        // Пропускаем системные поля, которые не должны передаваться
        if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        
        // Проверяем существование поля в метаданных
        if (!in_array($field, $meta['all'], true)) {
            Logger::warning('CREATE ACCOUNT: Unknown field ignored', ['field' => $field]);
            continue;
        }
        
        // Валидируем поле
        try {
            $validatedField = Validator::validateField($field, $meta['all']);
            $accountData[$validatedField] = $value;
        } catch (InvalidArgumentException $e) {
            Logger::warning('CREATE ACCOUNT: Invalid field skipped', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            continue;
        }
    }
    
    // Убеждаемся, что обязательные поля присутствуют после фильтрации
    if (empty($accountData['login']) || trim((string)$accountData['login']) === '') {
        throw new InvalidArgumentException('Login is required');
    }
    
    if (empty($accountData['status']) || trim((string)$accountData['status']) === '') {
        throw new InvalidArgumentException('Status is required');
    }
    
    Logger::debug('CREATE ACCOUNT', [
        'login' => $login,
        'fields_count' => count($accountData)
    ]);
    
    // Создаем аккаунт
    $result = $service->createAccount($accountData);
    
    json_success([
        'id' => $result['id'],
        'account' => $result['account'],
        'message' => 'Account created successfully'
    ]);
    
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
    } elseif (strpos($e->getMessage(), 'already exists') !== false) {
        $httpCode = 409; // Conflict для дубликатов
    }
    ErrorHandler::handleError($e, 'Create Account API', $httpCode);
}

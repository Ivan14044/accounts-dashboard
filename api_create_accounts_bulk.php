<?php
/**
 * API для массового создания аккаунтов
 * Поддерживает вставку множественных аккаунтов за один запрос
 */

// Загружаем config.php с обработкой ошибок подключения к БД
try {
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/Utils.php';
    require_once __DIR__ . '/includes/Logger.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Create Accounts Bulk API (config)', 500);
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
    
    $input = read_json_input(20 * 1024 * 1024); // 20MB максимум для bulk операций
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    // Валидация CSRF токена
    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CREATE ACCOUNTS BULK: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Получаем массив аккаунтов
    $accountsData = $input['accounts'] ?? [];
    if (!is_array($accountsData) || empty($accountsData)) {
        throw new InvalidArgumentException('Accounts array is required and must not be empty');
    }
    
    // Ограничение на количество аккаунтов за один запрос
    if (count($accountsData) > 10000) {
        throw new InvalidArgumentException('Maximum 10000 accounts per request allowed');
    }
    
    // Действие при дубликатах
    $duplicateAction = $input['duplicate_action'] ?? 'skip';
    if (!in_array($duplicateAction, ['skip', 'error'], true)) {
        $duplicateAction = 'skip';
    }
    
    // Получаем сервис и метаданные для валидации полей
    $service = new AccountsService();
    $meta = $service->getColumnMetadata();
    
    // Валидируем и нормализуем данные для каждого аккаунта
    $validatedAccounts = [];
    foreach ($accountsData as $idx => $accountData) {
        if (!is_array($accountData)) {
            continue; // Пропускаем невалидные записи
        }
        
        $validatedAccount = [];
        
        // Проверяем обязательные поля
        $login = trim((string)($accountData['login'] ?? ''));
        $status = trim((string)($accountData['status'] ?? ''));
        
        if (empty($login) || empty($status)) {
            continue; // Пропускаем записи без обязательных полей
        }
        
        $validatedAccount['login'] = $login;
        $validatedAccount['status'] = $status;
        
        // Валидируем остальные поля
        foreach ($accountData as $field => $value) {
            // Пропускаем служебные поля
            if (in_array($field, ['csrf', 'id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            
            // Проверяем существование поля в метаданных
            if (!in_array($field, $meta['all'], true)) {
                continue;
            }
            
            // Валидируем поле
            try {
                $validatedField = Validator::validateField($field, $meta['all']);
                $validatedAccount[$validatedField] = $value;
            } catch (InvalidArgumentException $e) {
                // Пропускаем невалидные поля
                continue;
            }
        }
        
        if (!empty($validatedAccount)) {
            $validatedAccounts[] = $validatedAccount;
        }
    }
    
    if (empty($validatedAccounts)) {
        throw new InvalidArgumentException('No valid accounts to create. Please check that all accounts have login and status fields.');
    }
    
    Logger::debug('CREATE ACCOUNTS BULK', [
        'total' => count($accountsData),
        'validated' => count($validatedAccounts),
        'duplicate_action' => $duplicateAction
    ]);
    
    // Создаем аккаунты
    $result = $service->createAccountsBulk($validatedAccounts, $duplicateAction);
    
    json_success([
        'created' => $result['created'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
        'total' => count($validatedAccounts),
        'message' => sprintf(
            'Создано: %d, Пропущено: %d, Ошибок: %d',
            $result['created'],
            $result['skipped'],
            count($result['errors'])
        )
    ]);
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    // Определяем правильный HTTP код
    $httpCode = 500;
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400;
    } elseif (strpos($e->getMessage(), 'not authenticated') !== false || 
              strpos($e->getMessage(), 'Unauthorized') !== false) {
        $httpCode = 401;
    } elseif (strpos($e->getMessage(), 'Database connection') !== false ||
              strpos($e->getMessage(), 'Failed to prepare') !== false ||
              strpos($e->getMessage(), 'Failed to execute') !== false) {
        $httpCode = 500;
    }
    ErrorHandler::handleError($e, 'Create Accounts Bulk API', $httpCode);
}

<?php
/**
 * API для регистрации нового статуса
 * Создает временную запись с новым статусом, чтобы он появился в списке доступных статусов
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/FilterBuilder.php';
require_once __DIR__ . '/includes/ColumnMetadata.php';

try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    // Безопасное чтение JSON с ограничением размера
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new Exception('Invalid input');
    }
    
    // Проверка CSRF токена
    require_once __DIR__ . '/includes/Validator.php';
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('REGISTER STATUS API: CSRF validation failed');
        throw new Exception('CSRF validation failed');
    }
    
    $status = isset($input['status']) ? trim((string)$input['status']) : '';
    
    if ($status === '') {
        throw new Exception('Status is required');
    }
    
    // Валидация статуса (буквы включая кириллицу, цифры, подчеркивания, дефисы, пробелы)
    // Используем \p{L} для поддержки Unicode букв (латиница, кириллица и др.)
    if (!preg_match('/^[\p{L}0-9_\-\s]+$/u', $status)) {
        throw new Exception('Invalid status format. Only letters (including Cyrillic), numbers, underscores, hyphens and spaces are allowed');
    }
    
    $service = new AccountsService();
    
    // Проверяем, есть ли уже записи с таким статусом
    $meta = $service->getColumnMetadata();
    $filter = new FilterBuilder($meta['columns'], $meta['numeric'], AccountsService::getNumericLikeColumns());
    $filter->addEqualFilter('status', $status);
    $count = $service->getAccountsCount($filter);
    
    if ($count > 0) {
        // Статус уже существует в БД — просто очищаем кэши, чтобы он появился в фильтрах
        ColumnMetadata::clearCache();
        Database::getInstance()->clearCache();
        json_success(['message' => 'Status already exists', 'exists' => true]);
    }
    
    // Создаем запись с новым статусом, чтобы он появился в списке доступных статусов
    // Используем специальный префикс для идентификации таких записей
    $mysqli = Database::getInstance()->getConnection();

    // Проверяем, есть ли уже служебная запись с таким статусом
    $checkStmt = $mysqli->prepare("SELECT id FROM accounts WHERE login = ? AND status = ? LIMIT 1");
    $serviceLogin = '__status_marker_' . md5($status);
    $checkStmt->bind_param('ss', $serviceLogin, $status);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        // Создаем служебную запись с новым статусом
        $insertStmt = $mysqli->prepare("INSERT INTO accounts (login, status, created_at) VALUES (?, ?, NOW())");
        $insertStmt->bind_param('ss', $serviceLogin, $status);
        $insertStmt->execute();
        $insertStmt->close();
    }

    $checkStmt->close();
    
    // Очищаем кэш метаданных и кэши запросов, чтобы новый статус появился в списке
    ColumnMetadata::clearCache();
    Database::getInstance()->clearCache();
    
    json_success(['message' => 'Status registered successfully', 'exists' => false]);
    
} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Register Status API', 400);
}


<?php
/**
 * Дублирование аккаунта
 * Создает копию аккаунта со всеми полями кроме ID
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/Database.php';

try {
    requireAuth();
    checkSessionTimeout();
    
    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    
    $id = Validator::validateId(get_param('id', '0'));
    
    $service = new AccountsService();
    $account = $service->getAccountById($id);
    
    if (!$account) {
        json_error('Account not found');
    }
    
    // Получаем метаданные колонок для определения типов
    $meta = $service->getColumnMetadata();
    $columnMetadata = $meta['columns'];
    
    // Подготавливаем данные для новой записи
    // Исключаем ID и системные поля, а также deleted_at (копия не должна быть удалённой)
    $excludeFields = ['id', 'created_at', 'updated_at', 'deleted_at'];
    $newAccount = [];
    
    foreach ($account as $key => $value) {
        if (!in_array($key, $excludeFields, true)) {
            $newAccount[$key] = $value;
        }
    }
    
    // Если есть поле login, добавляем суффикс "(копия)"
    if (!empty($newAccount['login'])) {
        $newAccount['login'] = $newAccount['login'] . ' (копия)';
    }
    
    // Вставляем новую запись
    $mysqli = Database::getInstance()->getConnection();
    $columns = array_keys($newAccount);
    $placeholders = array_map(function($col) { return '`' . $col . '` = ?'; }, $columns);
    $values = array_values($newAccount);
    
    $sql = "INSERT INTO `accounts` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    
    // Определяем типы параметров на основе метаданных колонок
    $types = '';
    foreach ($columns as $col) {
        if (!isset($columnMetadata[$col])) {
            $types .= 's'; // По умолчанию строка
            continue;
        }
        
        $colType = strtolower($columnMetadata[$col]['type'] ?? '');
        if (preg_match('/(int|tinyint|smallint|mediumint|bigint)/', $colType)) {
            $types .= 'i';
        } elseif (preg_match('/(decimal|float|double|numeric)/', $colType)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }
    
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Failed to duplicate account: ' . $stmt->error);
    }
    
    $newId = $mysqli->insert_id;
    $stmt->close();
    
    Logger::info('Account duplicated', [
        'original_id' => $id,
        'new_id' => $newId,
        'user' => $_SESSION['username'] ?? 'unknown'
    ]);
    
    // Возвращаем ID нового аккаунта для редиректа
    json_success([
        'message' => 'Аккаунт успешно скопирован',
        'new_id' => $newId,
        'redirect' => "view.php?id=$newId"
    ]);
    
} catch (Throwable $e) {
    ErrorHandler::handleError($e, 'Duplicate API', 400);
}



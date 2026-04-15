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

    // Только POST-запросы с CSRF-токеном
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
        exit;
    }

    // Читаем JSON body (с ограничением размера)
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) $input = [];
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $id = Validator::validateId($input['id'] ?? '0');
    
    $service = new AccountsService($tableName);
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
    
    // Если есть поле login, добавляем суффикс "(копия)", не превышая лимит поля
    if (!empty($newAccount['login'])) {
        $suffix = ' (копия)';
        $maxLen = 255;
        $login = $newAccount['login'];
        // Убираем предыдущие суффиксы "(копия)" чтобы не накапливались
        $login = preg_replace('/\s*\(копия\)(\s*\(копия\))*$/u', '', $login);
        if (mb_strlen($login . $suffix, 'UTF-8') > $maxLen) {
            $login = mb_substr($login, 0, $maxLen - mb_strlen($suffix, 'UTF-8'), 'UTF-8');
        }
        $newAccount['login'] = $login . $suffix;
    }
    
    // Вставляем новую запись
    $mysqli = Database::getInstance()->getConnection();
    $columns = array_keys($newAccount);
    $values = array_values($newAccount);
    
    $sql = "INSERT INTO `accounts` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $mysqli->error);
    }
    
    // Определяем типы параметров на основе метаданных колонок
    // NULL-значения всегда привязываем как 's' (строка) — mysqli корректно передаёт NULL для любого типа
    $types = '';
    foreach ($columns as $idx => $col) {
        if ($values[$idx] === null) {
            $types .= 's';
            continue;
        }
        if (!isset($columnMetadata[$col])) {
            $types .= 's';
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
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to duplicate account: ' . $error);
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



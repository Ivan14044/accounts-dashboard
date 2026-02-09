<?php
/**
 * API для очистки корзины (окончательное удаление всех удалённых аккаунтов)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/ErrorHandler.php';

// Устанавливаем заголовки JSON для всех ответов API
header('Content-Type: application/json; charset=utf-8');

try {
    Logger::debug('EMPTY TRASH: Начало обработки запроса', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
    ]);
    
    requireAuth();
    checkSessionTimeout();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Logger::warning('EMPTY TRASH: Неверный метод запроса', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
        json_error('Method not allowed', 405);
    }
    
    // Безопасное чтение JSON с ограничением размера
    Logger::debug('EMPTY TRASH: Чтение JSON входных данных...');
    $input = read_json_input(1048576); // 1MB максимум
    
    Logger::debug('EMPTY TRASH: JSON прочитан', [
        'input_type' => gettype($input),
        'is_array' => is_array($input),
        'has_csrf' => isset($input['csrf']) && is_array($input)
    ]);
    
    if (!is_array($input)) {
        $rawInput = @file_get_contents('php://input');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? 'not_set';
        Logger::error('EMPTY TRASH: Неверный формат входных данных', [
            'input_type' => gettype($input),
            'input' => $input,
            'raw_input' => $rawInput,
            'raw_input_length' => strlen($rawInput ?? ''),
            'content_type' => $contentType
        ]);
        json_error("Invalid input. Expected JSON. Received: " . gettype($input) . ". Content-Type: $contentType. Raw: " . substr($rawInput ?? '', 0, 100), 400);
        exit;
    }
    
    $csrf = $input['csrf'] ?? '';
    
    Logger::debug('EMPTY TRASH: Проверка CSRF токена', [
        'csrf_present' => !empty($csrf),
        'csrf_length' => strlen($csrf)
    ]);
    
    // CSRF валидация
    if (!verifyCsrfToken($csrf)) {
        Logger::warning('EMPTY TRASH: CSRF validation failed', [
            'csrf' => substr($csrf, 0, 20) . '...',
            'session_csrf' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 20) . '...' : 'not_set'
        ]);
        json_error('CSRF validation failed', 403);
    }
    
    Logger::debug('EMPTY TRASH: CSRF токен валиден');
    
    Logger::debug('EMPTY TRASH: Инициализация Database и ColumnMetadata...');
    require_once __DIR__ . '/includes/Database.php';
    require_once __DIR__ . '/includes/ColumnMetadata.php';
    global $mysqli;
    
    if (!$mysqli) {
        throw new Exception('Database connection not available');
    }
    
    Logger::debug('EMPTY TRASH: Проверка поддержки Soft Delete...');
    // Проверяем, поддерживается ли Soft Delete (безопасная проверка)
    try {
        $db = Database::getInstance();
        $metadata = ColumnMetadata::getInstance($mysqli);
        
        if (!$metadata->columnExists('deleted_at')) {
            Logger::warning('EMPTY TRASH: Soft Delete не поддерживается');
            json_error('Soft Delete не поддерживается. Таблица accounts не имеет колонки deleted_at.');
            exit;
        }
        
        Logger::debug('EMPTY TRASH: Soft Delete поддерживается');
    } catch (Exception $e) {
        Logger::error('EMPTY TRASH: Ошибка при инициализации Database/Metadata', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw new Exception('Failed to initialize database components: ' . $e->getMessage());
    }
    
    Logger::debug('EMPTY TRASH: Подсчет удаленных аккаунтов...');
    // Считаем количество удалённых аккаунтов перед удалением
    // Для TIMESTAMP колонки правильное условие - только IS NOT NULL (пустая строка там быть не может)
    try {
        $getCountSql = "SELECT COUNT(*) as cnt FROM accounts WHERE deleted_at IS NOT NULL";
        $countStmt = $mysqli->prepare($getCountSql);
        if (!$countStmt) {
            Logger::error('EMPTY TRASH: Ошибка подготовки запроса подсчета', [
                'error' => $mysqli->error,
                'errno' => $mysqli->errno
            ]);
            throw new Exception('Failed to prepare count statement: ' . $mysqli->error);
        }
        
        if (!$countStmt->execute()) {
            $error = $countStmt->error;
            $countStmt->close();
            Logger::error('EMPTY TRASH: Ошибка выполнения запроса подсчета', [
                'error' => $error
            ]);
            throw new Exception('Failed to execute count statement: ' . $error);
        }
        
        $countResult = $countStmt->get_result();
        $countRow = $countResult ? $countResult->fetch_assoc() : null;
        $deletedCount = $countRow ? (int)($countRow['cnt'] ?? 0) : 0;
        $countStmt->close();
        
        Logger::debug('EMPTY TRASH: Найдено удаленных аккаунтов', ['count' => $deletedCount]);
        
        if ($deletedCount === 0) {
            Logger::info('EMPTY TRASH: Корзина уже пуста');
            json_success([
                'message' => 'Корзина уже пуста',
                'deleted_count' => 0
            ]);
            exit;
        }
    } catch (Exception $e) {
        Logger::error('EMPTY TRASH: Ошибка при подсчете удаленных аккаунтов', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw new Exception('Failed to count deleted accounts: ' . $e->getMessage());
    }
    
    Logger::debug('EMPTY TRASH: Получение ID удаленных аккаунтов...');
    // Получаем ID всех удалённых аккаунтов для очистки связанных данных
    // Для TIMESTAMP колонки правильное условие - только IS NOT NULL
    $getIdsSql = "SELECT id FROM accounts WHERE deleted_at IS NOT NULL";
    $getIdsStmt = $mysqli->prepare($getIdsSql);
    if (!$getIdsStmt) {
        throw new Exception('Failed to prepare select statement: ' . $mysqli->error);
    }
    
    if (!$getIdsStmt->execute()) {
        $error = $getIdsStmt->error;
        $getIdsStmt->close();
        throw new Exception('Failed to execute select statement: ' . $error);
    }
    
    $result = $getIdsStmt->get_result();
    $deletedIds = [];
    while ($row = $result->fetch_assoc()) {
        $deletedIds[] = (int)$row['id'];
    }
    $getIdsStmt->close();
    
    Logger::debug('EMPTY TRASH: Найдено ID для удаления', ['count' => count($deletedIds)]);
    
    // Очищаем связанные данные перед удалением
    if (!empty($deletedIds)) {
        Logger::debug('EMPTY TRASH: Очистка связанных данных...');
        $placeholders = str_repeat('?,', count($deletedIds) - 1) . '?';
        $types = str_repeat('i', count($deletedIds));
        
        // Удаляем из избранного
        $cleanupSql = "DELETE FROM account_favorites WHERE account_id IN ($placeholders)";
        $cleanupStmt = $mysqli->prepare($cleanupSql);
        if ($cleanupStmt) {
            $cleanupStmt->bind_param($types, ...$deletedIds);
            if ($cleanupStmt->execute()) {
                $favoritesDeleted = $cleanupStmt->affected_rows;
                Logger::debug('EMPTY TRASH: Удалено из избранного', ['count' => $favoritesDeleted]);
            } else {
                Logger::warning('EMPTY TRASH: Ошибка удаления из избранного', ['error' => $cleanupStmt->error]);
            }
            $cleanupStmt->close();
        }
        
        // Удаляем историю изменений (опционально - можно оставить для аудита)
        // Раскомментируйте, если нужно удалять историю:
        // $cleanupSql = "DELETE FROM account_history WHERE account_id IN ($placeholders)";
        // $cleanupStmt = $mysqli->prepare($cleanupSql);
        // if ($cleanupStmt) {
        //     $cleanupStmt->bind_param($types, ...$deletedIds);
        //     $cleanupStmt->execute();
        //     $cleanupStmt->close();
        // }
    }
    
    Logger::debug('EMPTY TRASH: Окончательное удаление аккаунтов из таблицы accounts...');
    // Окончательно удаляем все удалённые аккаунты
    // Для TIMESTAMP колонки правильное условие - только IS NOT NULL
    $sql = "DELETE FROM accounts WHERE deleted_at IS NOT NULL";
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare delete statement: ' . $mysqli->error);
    }
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $errorCode = $stmt->errno;
        $stmt->close();
        Logger::error('EMPTY TRASH: Ошибка выполнения DELETE', [
            'error' => $error,
            'error_code' => $errorCode
        ]);
        throw new Exception('Failed to empty trash: ' . $error . ' (Error code: ' . $errorCode . ')');
    }
    
    $actualDeleted = $stmt->affected_rows;
    $stmt->close();
    
    Logger::info('EMPTY TRASH: Успешно удалено аккаунтов', [
        'deleted_count' => $actualDeleted
    ]);
    
    Logger::warning('Trash emptied', [
        'user' => $_SESSION['username'] ?? 'unknown',
        'count' => $actualDeleted
    ]);
    
    json_success([
        'message' => "Корзина очищена. Удалено $actualDeleted аккаунт(ов)",
        'deleted_count' => $actualDeleted
    ]);
    
} catch (Throwable $e) {
    Logger::error('EMPTY TRASH: Критическая ошибка', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    $httpCode = 500;
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400;
    } elseif (strpos($e->getMessage(), 'CSRF') !== false) {
        $httpCode = 403;
    }
    
    json_error($e->getMessage(), $httpCode);
}


<?php
/**
 * API для окончательного удаления аккаунтов из БД (Hard Delete)
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
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
    
    // Безопасное чтение JSON с ограничением размера
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new Exception('Invalid input');
    }
    
    $ids = $input['ids'] ?? [];
    $csrf = $input['csrf'] ?? '';
    
    // CSRF валидация
    if (!verifyCsrfToken($csrf)) {
        Logger::warning('DELETE PERMANENT: CSRF validation failed');
        json_error('CSRF validation failed', 403);
    }
    
    if (empty($ids) || !is_array($ids)) {
        json_error('IDs are required');
    }
    
    // Валидация ID
    $ids = array_filter(array_map('intval', $ids));
    if (empty($ids)) {
        json_error('Valid IDs are required');
    }
    
    global $mysqli;
    
    // Проверяем, что аккаунты действительно удалены
    // Для TIMESTAMP колонки достаточно проверки IS NOT NULL (пустая строка там быть не может)
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $checkSql = "SELECT id FROM accounts WHERE id IN ($placeholders) AND deleted_at IS NOT NULL";
    $checkStmt = $mysqli->prepare($checkSql);
    
    if (!$checkStmt) {
        throw new Exception('Failed to prepare check statement');
    }
    
    $types = str_repeat('i', count($ids));
    $checkStmt->bind_param($types, ...$ids);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    $validIds = [];
    while ($row = $result->fetch_assoc()) {
        $validIds[] = (int)$row['id'];
    }
    $checkStmt->close();
    
    if (empty($validIds)) {
        json_error('Не найдено удалённых аккаунтов для окончательного удаления');
    }
    
    // Очищаем связанные данные перед удалением
    $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
    $types = str_repeat('i', count($validIds));
    
    // Удаляем из избранного
    $cleanupSql = "DELETE FROM account_favorites WHERE account_id IN ($placeholders)";
    $cleanupStmt = $mysqli->prepare($cleanupSql);
    if ($cleanupStmt) {
        $cleanupStmt->bind_param($types, ...$validIds);
        $cleanupStmt->execute();
        $cleanupStmt->close();
    }
    
    // Удаляем историю изменений (опционально - можно оставить для аудита)
    // Раскомментируйте, если нужно удалять историю:
    // $cleanupSql = "DELETE FROM account_history WHERE account_id IN ($placeholders)";
    // $cleanupStmt = $mysqli->prepare($cleanupSql);
    // if ($cleanupStmt) {
    //     $cleanupStmt->bind_param($types, ...$validIds);
    //     $cleanupStmt->execute();
    //     $cleanupStmt->close();
    // }
    
    // Окончательно удаляем аккаунты
    // Для TIMESTAMP колонки достаточно проверки IS NOT NULL
    $sql = "DELETE FROM accounts WHERE id IN ($placeholders) AND deleted_at IS NOT NULL";
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare delete statement');
    }
    
    $stmt->bind_param($types, ...$validIds);
    
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Failed to delete accounts permanently');
    }
    
    $deletedCount = $stmt->affected_rows;
    $stmt->close();
    
    Logger::warning('Accounts permanently deleted', [
        'user' => $_SESSION['username'] ?? 'unknown',
        'count' => $deletedCount,
        'ids' => $validIds
    ]);
    
    json_success([
        'message' => "Окончательно удалено $deletedCount аккаунт(ов)",
        'deleted_count' => $deletedCount
    ]);
    
} catch (Throwable $e) {
    Logger::error('Delete permanent error', ['message' => $e->getMessage()]);
    json_error($e->getMessage());
}


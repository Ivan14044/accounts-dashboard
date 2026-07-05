<?php
/**
 * API отмены последнего действия (undo)
 *
 * GET  — последнее отменяемое действие текущего пользователя
 *        → { success, action: {id, action_type, table_name, description, affected_count, created_at} | null }
 * POST — откат действия: JSON {"action_id": 123, "csrf": "..."}
 *        → { success, reverted, skipped_conflict, skipped_sensitive, unsupported, action }
 *
 * Отменяются только собственные действия; повторный откат блокируется атомарно.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/UndoService.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

requireAuth();
checkSessionTimeout();
checkRateLimit('api'); // Rate limiting для API

$username = $_SESSION['username'] ?? 'system';

try {
    $service = new UndoService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_success(['action' => $service->getLastUndoableAction($username)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';

    $input = read_json_input(1048576);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }

    if (!Validator::validateCsrfToken($input['csrf'] ?? '')) {
        Logger::warning('UNDO: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $actionId = (int)($input['action_id'] ?? 0);
    if ($actionId <= 0) {
        throw new InvalidArgumentException('Не указано действие для отмены');
    }

    // Откат массовой операции может обрабатывать десятки тысяч строк
    set_time_limit(0);

    $report = $service->undoAction($actionId, $username);
    json_success($report);

} catch (Throwable $e) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Undo API', 400);
}

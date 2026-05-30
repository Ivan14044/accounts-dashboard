<?php
/**
 * API: очистка корзины от записей старше N дней (retention purge).
 *
 * POST JSON: { csrf, days?:int }
 *   - days опционален; если не передан — берётся из TrashSettings.
 * Удаляет чанками с кэпом за один вызов; клиент дочищает остаток повторными
 * запросами, пока remaining > 0.
 *
 * Ответ: { success, deleted_count, remaining, days }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/ErrorHandler.php';
require_once __DIR__ . '/includes/TrashSettings.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();
    checkSessionTimeout();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    $input = read_json_input(1048576);
    if (!is_array($input)) {
        throw new Exception('Invalid input');
    }

    $csrf = $input['csrf'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        Logger::warning('PURGE OLD: CSRF validation failed');
        json_error('CSRF validation failed', 403);
    }

    // Порог в днях: из запроса либо из настроек
    $days = isset($input['days']) && $input['days'] !== ''
        ? TrashSettings::clampDays($input['days'])
        : TrashSettings::get()['days'];

    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();
    if (!in_array('deleted_at', $meta['all'], true)) {
        json_error('Soft Delete не поддерживается');
    }

    $maxRows = 50000; // кэп за один HTTP-вызов
    $deletedCount = $service->purgeTrashOlderThan($days, $maxRows);
    $remaining = $service->countTrashOlderThan($days);

    json_success([
        'message' => "Удалено $deletedCount аккаунт(ов) старше $days дн.",
        'deleted_count' => $deletedCount,
        'remaining' => $remaining,
        'days' => $days
    ]);

} catch (Throwable $e) {
    Logger::error('Purge old error', ['message' => $e->getMessage()]);
    json_error($e->getMessage());
}

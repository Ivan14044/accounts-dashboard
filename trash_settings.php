<?php
/**
 * API: сохранение настроек retention/автоочистки корзины.
 *
 * POST JSON: { csrf, enabled:bool, days:int }
 * Ответ: { success, enabled, days }
 *
 * Хранилище — TrashSettings (таблица user_settings, сентинел-пользователь __system__).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
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
        Logger::warning('TRASH SETTINGS: CSRF validation failed');
        json_error('CSRF validation failed', 403);
    }

    $enabled = !empty($input['enabled']);
    $days = TrashSettings::clampDays($input['days'] ?? TrashSettings::DEFAULT_DAYS);

    $saved = TrashSettings::save($enabled, $days);

    Logger::info('Trash retention settings saved', [
        'user' => $_SESSION['username'] ?? 'unknown',
        'enabled' => $saved['enabled'],
        'days' => $saved['days'],
    ]);

    json_success([
        'message' => 'Настройки сохранены',
        'enabled' => $saved['enabled'],
        'days' => $saved['days'],
    ]);

} catch (Throwable $e) {
    Logger::error('Trash settings error', ['message' => $e->getMessage()]);
    json_error($e->getMessage());
}

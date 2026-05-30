<?php
/**
 * API: история изменений одного аккаунта из корзины (аудит "кто/когда/почему").
 *
 * GET ?id=<int> → { success, history:[{changed_at, changed_by, field_name, old_value, new_value, ip_address}], deleted_by, deleted_at }
 * Источник — таблица account_history через AuditLogger::getAccountHistory().
 * Никакой миграции схемы: используем уже логируемые события (вкл. deleted_at и delete_note).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/AuditLogger.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/ErrorHandler.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireAuth();
    checkSessionTimeout();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        json_error('Valid account id is required');
    }

    $auditLogger = AuditLogger::getInstance();
    $rows = $auditLogger->getAccountHistory($id, 200);

    // Выделяем последнее событие удаления (для inline "кто/когда удалил")
    $deletedBy = null;
    $deletedAt = null;
    foreach ($rows as $r) {
        if (($r['field_name'] ?? '') === 'deleted_at' && !empty($r['new_value'])) {
            // getAccountHistory отсортирован DESC по changed_at — первое совпадение самое свежее
            $deletedBy = $r['changed_by'] ?? null;
            $deletedAt = $r['new_value'] ?? null;
            break;
        }
    }

    json_success([
        'history'    => $rows,
        'deleted_by' => $deletedBy,
        'deleted_at' => $deletedAt,
    ]);

} catch (Throwable $e) {
    Logger::error('Trash history error', ['message' => $e->getMessage()]);
    json_error($e->getMessage());
}

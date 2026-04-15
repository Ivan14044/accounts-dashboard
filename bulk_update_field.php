<?php
/**
 * API для массового обновления произвольного поля.
 *
 * Поддерживает два режима:
 * 1) Обновление выбранных записей:
 *    POST JSON:
 *    {
 *      "field": "status",              // имя допустимого поля (валидируется по метаданным)
 *      "value": "NEW_VALUE",           // новое значение
 *      "ids": [1, 2, 3],               // массив ID аккаунтов (максимум 1000)
 *      "scope": "selected",            // режим \"выбранные\"
 *      "csrf": "..."                   // CSRF-токен
 *    }
 *
 * 2) Обновление по фильтру / для всех:
 *    POST JSON:
 *    {
 *      "field": "status",
 *      "value": "NEW_VALUE",
 *      "ids": [],                      // пустой массив
 *      "select": "all",                // специальный режим \"все по фильтру\"
 *      "query": "q=&status[]=...",     // строка query-параметров текущей страницы
 *      "scope": "filtered" | "all",    // \"filtered\" — только по фильтру, \"all\" — по всем без фильтра (требует явного подтверждения)
 *      "csrf": "..."
 *    }
 *
 * Ответ:
 * {
 *   "success": true,
 *   "affected": 123,                   // количество обновлённых записей
 *   "mode": "filter" | "all" | "selected",
 *   "scope": "filtered" | "all" | "selected"
 * }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';

try {
    requireAuth();
    checkSessionTimeout();
    
    require_once __DIR__ . '/includes/Validator.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    
    // Безопасное чтение JSON с ограничением размера
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }
    
    $selectAll = isset($input['select']) && $input['select'] === 'all';
    $scope = isset($input['scope']) ? trim((string)$input['scope']) : 'selected';
    $queryString = isset($input['query']) ? (string)$input['query'] : '';
    $field = trim((string)($input['field'] ?? ''));
    $value = $input['value'] ?? '';
    $csrf = (string)($input['csrf'] ?? '');

    if (is_string($value) && strlen($value) > 65536) {
        throw new InvalidArgumentException('Value is too long (max 64KB)');
    }

    if (empty($field)) {
        throw new InvalidArgumentException('Field is required');
    }
    
    // Валидация CSRF токена
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('BULK UPDATE: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }
    
    // Валидация ID (если не selectAll)
    $ids = [];
    if (!$selectAll) {
        $ids = Validator::validateIds($input['ids'] ?? []);
    }
    
    $service = new AccountsService($tableName);
    
    // Валидация поля через метаданные
    $meta = $service->getColumnMetadata();
    $field = Validator::validateField($field, $meta['all']);
    
    if ($selectAll) {
        // Массовое обновление всех по фильтру
        parse_str($queryString, $params);
        $filter = $service->createFilterFromRequest($params);

        $conditionsCount = $filter->getConditionsCount();

        if ($conditionsCount === 0) {
            if ($scope === 'all') {
                Logger::info('BULK UPDATE: Global field update', [
                    'field' => $field,
                    'user' => $_SESSION['username'] ?? 'unknown'
                ]);
                $affected = $service->updateFieldForAll($field, $value);
                json_success([
                    'affected' => $affected,
                    'mode' => 'all',
                    'scope' => 'all'
                ]);
            }
            throw new InvalidArgumentException('Нельзя применить ко всем без фильтра. Уточните фильтры или подтвердите глобальное изменение.');
        }

        Logger::debug('BULK UPDATE: Updating by filter', ['field' => $field]);
        
        // Безопасное массовое обновление по фильтру
        $affected = $service->updateFieldByFilter($filter, $field, $value);
        
        Logger::info('BULK UPDATE: Completed by filter', ['affected' => $affected, 'field' => $field]);
        
        json_success([
            'affected' => $affected,
            'mode' => 'filter',
            'scope' => 'filtered'
        ]);
    } else {
        Logger::debug('BULK UPDATE: Updating selected IDs', ['count' => count($ids), 'field' => $field]);
        
        // Массовое обновление выбранных записей
        $affected = $service->bulkUpdateField($ids, $field, $value);
        
        Logger::info('BULK UPDATE: Completed', ['affected' => $affected, 'field' => $field]);
        
        json_success([
            'affected' => $affected,
            'scope' => 'selected'
        ]);
    }
    
} catch (Throwable $e) {
    ErrorHandler::handleError($e, 'Bulk Update Field API', 400);
}


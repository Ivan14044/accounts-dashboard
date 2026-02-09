<?php
/**
 * API для обновления данных дашборда
 * Возвращает актуальные данные таблицы и статистику по текущим фильтрам
 */

// Загружаем config.php с обработкой ошибок подключения к БД
try {
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    // Если config.php выбрасывает исключение (например, ошибка подключения к БД),
    // обрабатываем его здесь
    require_once __DIR__ . '/includes/Utils.php';
    require_once __DIR__ . '/includes/Logger.php';
    require_once __DIR__ . '/includes/ErrorHandler.php';
    ErrorHandler::handleError($e, 'Refresh API (config)', 500);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RequestHandler.php';
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/ResponseHeaders.php';

try {
    requireAuth();
    checkSessionTimeout();
    
    $service = new AccountsService();
    
    // Создаем фильтр из GET-параметров
    $filter = $service->createFilterFromRequest($_GET);
    
    // Пагинация и сортировка
    $paginationParams = RequestHandler::getPaginationParams();
    $page = $paginationParams['page'];
    $perPage = $paginationParams['perPage'];

    $meta = $service->getColumnMetadata();
    $sortParams = RequestHandler::getSortParams($meta['all']);
    $sort = $sortParams['sort'];
    $dir = $sortParams['dir'];
    
    // Получаем статистику
    $stats = $service->getStatistics($filter);
    
    // Корректируем страницу
    $filteredTotal = $stats['filteredTotal'];
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    
    if ($filteredTotal > 0) {
        $page = min(max(1, $page), $pages);
    } else {
        $page = 1;
    }
    
    $offset = ($page - 1) * $perPage;
    
    // Получаем данные таблицы
    $rows = $service->getAccounts($filter, $sort, $dir, $perPage, $offset);
    
    // Метаданные колонок
    $meta = $service->getColumnMetadata();
    
    $response = [
        'rows' => $rows,
        'totals' => ['all' => $stats['total']],
        'byStatus' => $stats['byStatus'],
        'byStatusFiltered' => $stats['byStatusFiltered'],
        'filteredTotal' => $filteredTotal,
        'page' => $page,
        'perPage' => $perPage,
        'pages' => $pages,
        'columns' => $meta['all']
    ];
    
    // Отладка (только если включен режим debug в URL)
    if (isset($_GET['debug'])) {
        $response['debug'] = [
            'receivedParams' => $_GET,
            'statusParam' => $_GET['status'] ?? null,
            'filterConditions' => $filter->getConditionsCount()
        ];
    }
    
    // Устанавливаем заголовки для JSON ответа
    ResponseHeaders::setJsonHeaders();
    
    json_success($response);
    
} catch (Throwable $e) {
    Logger::error('Refresh error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(200);
    ResponseHeaders::setJsonHeaders();
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to refresh data',
        'debug' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    exit;
}

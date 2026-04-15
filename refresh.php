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

/**
 * Строит HTML-блок пагинации — идентично footer.php.
 * Возвращается в JSON как paginationHtml, чтобы JS мог заменить
 * существующий #paginationNav без перезагрузки страницы.
 */
function buildPaginationHtml(int $page, int $pages, int $prev, int $next, array $pageNumbers, int $startPage, int $endPage, array $queryParams): string
{
    if ($pages <= 1) {
        // Возвращаем скрытый nav — чтобы JS не потерял контейнер для будущих вставок
        return '<nav aria-label="Навигация по страницам" class="dashboard-table__pagination" id="paginationNav" style="display:none"></nav>';
    }

    $qs = $queryParams;
    unset($qs['page']);
    $qs = $qs ?: [];

    $h = function(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $href = function(int $p) use ($qs): string {
        return '?' . http_build_query(array_merge($qs, ['page' => $p]));
    };

    $html  = '<nav aria-label="Навигация по страницам" class="dashboard-table__pagination" id="paginationNav">';
    $html .= '<ul class="pagination m-0">';

    // Первая
    $dis = $page <= 1 ? ' disabled' : '';
    $html .= '<li class="page-item' . $dis . '">'
           . '<a class="page-link" href="' . $h($href(1)) . '" data-page="1" aria-label="Первая">'
           . '<i class="fas fa-angle-double-left"></i></a></li>';

    // Предыдущая
    $html .= '<li class="page-item' . $dis . '">'
           . '<a class="page-link" href="' . $h($href($prev)) . '" data-page="' . $prev . '" aria-label="Предыдущая">'
           . '<i class="fas fa-angle-left"></i></a></li>';

    // «1 …» если окно не начинается с 1
    if ($startPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $h($href(1)) . '" data-page="1">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    // Окно номеров
    foreach ($pageNumbers as $pnum) {
        $pnum = (int)$pnum;
        if ($pnum === $page) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $pnum . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $h($href($pnum)) . '" data-page="' . $pnum . '">' . $pnum . '</a></li>';
        }
    }

    // «… N» если окно не заканчивается последней
    if ($endPage < $pages) {
        if ($endPage < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $h($href($pages)) . '" data-page="' . $pages . '">' . $pages . '</a></li>';
    }

    // Следующая
    $disNext = $page >= $pages ? ' disabled' : '';
    $html .= '<li class="page-item' . $disNext . '">'
           . '<a class="page-link" href="' . $h($href($next)) . '" data-page="' . $next . '" aria-label="Следующая">'
           . '<i class="fas fa-angle-right"></i></a></li>';

    // Последняя
    $html .= '<li class="page-item' . $disNext . '">'
           . '<a class="page-link" href="' . $h($href($pages)) . '" data-page="' . $pages . '" aria-label="Последняя">'
           . '<i class="fas fa-angle-double-right"></i></a></li>';

    $html .= '</ul></nav>';
    return $html;
}

try {
    requireAuth();
    checkSessionTimeout();
    
    $service = new AccountsService($tableName);
    $filter = $service->createFilterFromRequest($_GET);
    $paginationParams = RequestHandler::getPaginationParams();
    $page = $paginationParams['page'];
    $perPage = $paginationParams['perPage'];
    $meta = $service->getColumnMetadata();
    $sortParams = RequestHandler::getSortParams($meta['all']);
    $sort = $sortParams['sort'];
    $dir = $sortParams['dir'];

    $light = isset($_GET['light']) && ($_GET['light'] === '1' || $_GET['light'] === 'true');

    if ($light) {
        $filteredTotal = $service->getAccountsCount($filter);
    } else {
        $stats = $service->getStatistics($filter);
        $filteredTotal = $stats['filteredTotal'];
    }

    // Двухфазный поиск: если точный поиск (фаза 1) не дал результатов — откат на LIKE (фаза 2)
    if ($filteredTotal === 0 && $filter->canFallbackToLikeSearch()) {
        $filter->fallbackToLikeSearch();
        if ($light) {
            $filteredTotal = $service->getAccountsCount($filter);
        } else {
            $stats = $service->getStatistics($filter);
            $filteredTotal = $stats['filteredTotal'];
        }
    }

    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    if ($filteredTotal > 0) {
        $page = min(max(1, $page), $pages);
    } else {
        $page = 1;
    }
    $offset = ($page - 1) * $perPage;
    $rows = $service->getAccounts($filter, $sort, $dir, $perPage, $offset);

    // Вычисляем окно кнопок (те же правила, что в DashboardController)
    $prev = max(1, $page - 1);
    $next = $page < $pages ? $page + 1 : $pages;
    $window = 2;
    $startPage = max(1, $page - $window);
    $endPage   = min($pages, $page + $window);
    if ($endPage - $startPage < 4) {
        $need = 4 - ($endPage - $startPage);
        $startPage = max(1, $startPage - $need);
        $endPage   = min($pages, $endPage + max(0, 4 - ($endPage - $startPage)));
    }
    $pageNumbers   = range($startPage, $endPage);
    $paginationHtml = buildPaginationHtml($page, $pages, $prev, $next, $pageNumbers, $startPage, $endPage, $_GET);

    if ($light) {
        $response = [
            'rows'           => $rows,
            'totals'         => ['all' => $filteredTotal],
            'byStatus'       => [],
            'byStatusFiltered' => [],
            'filteredTotal'  => $filteredTotal,
            'page'           => $page,
            'perPage'        => $perPage,
            'pages'          => $pages,
            'columns'        => $meta['all'],
            'paginationHtml' => $paginationHtml,
        ];
    } else {
        $response = [
            'rows'           => $rows,
            'totals'         => ['all' => $stats['total']],
            'byStatus'       => $stats['byStatus'],
            'byStatusFiltered' => $stats['byStatusFiltered'],
            'filteredTotal'  => $filteredTotal,
            'page'           => $page,
            'perPage'        => $perPage,
            'pages'          => $pages,
            'columns'        => $meta['all'],
            'paginationHtml' => $paginationHtml,
        ];
    }
    
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
    http_response_code(500);
    ResponseHeaders::setJsonHeaders();
    echo json_encode([
        'success' => false,
        'error' => 'Failed to refresh data'
    ]);
    exit;
}

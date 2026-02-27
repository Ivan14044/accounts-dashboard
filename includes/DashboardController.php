<?php
/**
 * Контроллер для главной страницы дашборда
 * Упрощает index.php, вынося логику обработки запросов
 * 
 * Отвечает за подготовку всех данных для шаблона дашборда
 * и обработку действий пользователя (например, массовое обновление статуса)
 * 
 * @package includes
 */
require_once __DIR__ . '/AccountsService.php';
require_once __DIR__ . '/RequestHandler.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';

class DashboardController {
    private $service;
    
    public function __construct(AccountsService $service) {
        $this->service = $service;
    }
    
    /**
     * Обработка массового обновления статуса при клике на кастомную карточку
     * 
     * @return bool true если был обработан запрос и нужно сделать редирект
     */
    public function handleApplyStatus(): bool {
        if (!isset($_GET['apply_status']) || empty($_GET['apply_status'])) {
            return false;
        }
        
        try {
            $targetStatus = trim($_GET['apply_status']);
            
            // Создаем фильтр из текущих параметров (без apply_status)
            $filterParams = $_GET;
            unset($filterParams['apply_status']);
            unset($filterParams['page']); // Сбрасываем страницу
            
            // Нормализуем параметр status для правильной обработки
            if (isset($filterParams['status']) && is_array($filterParams['status'])) {
                // Уже массив, оставляем как есть
            } elseif (isset($filterParams['status[]']) && is_array($filterParams['status[]'])) {
                // Если передан status[] как массив, преобразуем в status
                $filterParams['status'] = $filterParams['status[]'];
                unset($filterParams['status[]']);
            }
            
            Logger::debug('Apply status', [
                'targetStatus' => $targetStatus,
                'filterParams' => $filterParams
            ]);
            
            $filter = $this->service->createFilterFromRequest($filterParams);
            
            // Обновляем статус для всех записей по фильтру
            $affectedRows = $this->service->updateStatusByFilter($filter, $targetStatus);
            
            // Показываем уведомление
            $_SESSION['success_message'] = "Статус обновлен для " . number_format($affectedRows) . " записей";
            
            // Убираем параметр apply_status из URL и перенаправляем
            unset($_GET['apply_status']);
            $redirectUrl = '?' . http_build_query($_GET);
            header("Location: $redirectUrl");
            return true; // Запрос обработан, нужен редирект
        } catch (Exception $e) {
            Logger::error('Error applying status', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $_SESSION['error_message'] = "Ошибка обновления статуса: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * Получение кастомных статусов из user_settings
     * 
     * @return array Массив уникальных статусов
     */
    public function getCustomStatuses(): array {
        $customStatuses = [];
        
        try {
            if (isset($_SESSION['username'])) {
                $mysqli = \Database::getInstance()->getConnection();
                if ($mysqli instanceof mysqli) {
                    $stmt = $mysqli->prepare("SELECT setting_value FROM user_settings WHERE username = ? AND setting_type = 'custom_cards' LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('s', $_SESSION['username']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $customCards = json_decode($row['setting_value'], true);
                            if (is_array($customCards)) {
                                foreach ($customCards as $card) {
                                    if (!empty($card['targetStatus']) && is_string($card['targetStatus'])) {
                                        $customStatuses[] = trim($card['targetStatus']);
                                    }
                                    if (!empty($card['filters']['status']) && is_array($card['filters']['status'])) {
                                        foreach ($card['filters']['status'] as $statusFromFilter) {
                                            if (is_string($statusFromFilter)) {
                                                $customStatuses[] = trim($statusFromFilter);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $stmt->close();
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::warning('Custom status merge error', ['message' => $e->getMessage()]);
        }
        
        return $customStatuses;
    }
    
    /**
     * Подготовка всех данных для шаблона дашборда
     * 
     * @return array Массив с данными для шаблона
     */
    public function prepareDashboardData(): array {
        // Получаем фильтр из GET-параметров
        $filter = $this->service->createFilterFromRequest($_GET);
        
        // Получаем метаданные колонок для валидации
        $meta = $this->service->getColumnMetadata();
        
        // Пагинация
        $paginationParams = RequestHandler::getPaginationParams();
        $page = $paginationParams['page'];
        $perPage = $paginationParams['perPage'];
        
        // Сортировка
        if (isset($meta['all']) && is_array($meta['all'])) {
            $sortParams = RequestHandler::getSortParams($meta['all']);
            $sort = $sortParams['sort'];
            $dir = $sortParams['dir'];
        } else {
            // Fallback если метаданные не получены
            $sort = get_param('sort', 'id');
            $dir = strtolower(get_param('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        }
        
        // Получаем статистику
        $stats = $this->service->getStatistics($filter);
        $filteredTotal = $stats['filteredTotal'];

        // Двухфазный поиск: если точный поиск (фаза 1) не дал результатов — откат на LIKE (фаза 2)
        if ($filteredTotal === 0 && $filter->canFallbackToLikeSearch()) {
            $filter->fallbackToLikeSearch();
            $stats = $this->service->getStatistics($filter);
            $filteredTotal = $stats['filteredTotal'];
        }
        $pages = max(1, (int)ceil($filteredTotal / $perPage));
        
        if ($filteredTotal > 0) {
            $page = min(max(1, $page), $pages);
        } else {
            $page = 1;
        }
        
        $offset = ($page - 1) * $perPage;
        
        // Получаем данные таблицы
        $rows = $this->service->getAccounts($filter, $sort, $dir, $perPage, $offset);
        
        // Получаем все уникальные значения фильтров одним оптимизированным запросом
        $uniqueFilterValues = $this->service->getUniqueFilterValues();
        $statuses = array_keys($uniqueFilterValues['status'] ?? []);
        $statusesMarketplace = $uniqueFilterValues['status_marketplace'] ?? [];
        $currenciesList = $uniqueFilterValues['currency'] ?? [];
        $geosList = $uniqueFilterValues['geo'] ?? [];
        $statusRkList = $uniqueFilterValues['status_rk'] ?? [];
        
        // Получаем количество пустых значений одним запросом вместо четырёх
        $emptyCounts = $this->service->getEmptyFilterCounts();
        $emptyMarketplaceStatusCount = $emptyCounts['status_marketplace'];
        $emptyCurrencyCount = $emptyCounts['currency'];
        $emptyGeoCount = $emptyCounts['geo'];
        $emptyStatusRkCount = $emptyCounts['status_rk'];
        
        // Дополняем список статусов целевыми статусами из кастомных карточек
        $customStatuses = $this->getCustomStatuses();
        if (!empty($customStatuses)) {
            $customStatuses = array_filter($customStatuses, function($s) {
                return $s !== '';
            });
            $statuses = array_values(array_unique(array_merge(
                $statuses,
                $customStatuses
            )));
            natcasesort($statuses);
            $statuses = array_values($statuses);
        }
        
        // Параметры фильтров для передачи в шаблон
        $filterParams = RequestHandler::getFilterParams();
        
        // Обработка множественного выбора статусов для UI
        $selectedStatuses = [];
        if (isset($_GET['status'])) {
            if (is_array($_GET['status'])) {
                $selectedStatuses = array_map('trim', $_GET['status']);
            } elseif (is_string($_GET['status']) && $_GET['status'] !== '') {
                $selectedStatuses = explode(',', $_GET['status']);
            }
        }
        $selectedStatuses = array_filter($selectedStatuses);
        $statusArray = $selectedStatuses; // Для совместимости с шаблоном
        $emptyStatusParam = get_param('empty_status');
        
        // Подсчет активных фильтров
        $activeFiltersCount = RequestHandler::countActiveFilters($filterParams);
        
        // URL для экспорта
        $exportUrl = 'export.php?' . http_build_query(array_filter($filterParams));
        
        // CSRF токен
        $csrfToken = getCsrfToken();
        
        // Параметры для пагинации
        $prev = max(1, $page - 1);
        $next = $page < $pages ? $page + 1 : $pages;
        
        // Окно страниц для пагинации
        $window = 2;
        $startPage = max(1, $page - $window);
        $endPage = min($pages, $page + $window);
        
        if ($endPage - $startPage < 4) {
            $need = 4 - ($endPage - $startPage);
            $startPage = max(1, $startPage - $need);
            $endPage = min($pages, $endPage + max(0, 4 - ($endPage - $startPage)));
        }
        
        $pageNumbers = range($startPage, $endPage);
        
        // Дополнительные переменные для шаблона
        $q = $filterParams['q'] ?? '';
        $emptyStatusCount = $stats['byStatus'][''] ?? 0;
        $totals = ['all' => $stats['total']];
        $recentAll = null;
        $byStatus = $stats['byStatus'];
        $countEmailTwoFa = $stats['emailTwoFa'] ?? 0;
        
        // Константы для обрезки длинных полей
        $CLIP_LEN = 80;
        $TOKEN_CLIP = 20;
        
        // Параметры фильтров из GET для шаблона
        $statusMarketplace = get_param('status_marketplace');
        $currencyFilter = get_param('currency');
        $geoFilter = get_param('geo');
        $statusRkFilter = get_param('status_rk');
        $limitRkFrom = get_param('limit_rk_from');
        $limitRkTo = get_param('limit_rk_to');
        $hasEmailParam = get_param('has_email');
        $hasTwoFaParam = get_param('has_two_fa');
        $hasTokenParam = get_param('has_token');
        $hasAvatarParam = get_param('has_avatar');
        $hasCoverParam = get_param('has_cover');
        $hasPasswordParam = get_param('has_password');
        $hasFanPageParam = get_param('has_fan_page');
        $hasBmParam = get_param('has_bm');
        $fullFilledParam = get_param('full_filled');
        $pharmaFrom = get_param('pharma_from');
        $pharmaTo = get_param('pharma_to');
        $friendsFrom = get_param('friends_from');
        $friendsTo = get_param('friends_to');
        $yearCreatedFrom = get_param('year_created_from');
        $yearCreatedTo = get_param('year_created_to');
        $favoritesOnlyParam = get_param('favorites_only', '');
        
        return [
            'rows' => $rows,
            'meta' => $meta,
            'ALL_COLUMNS' => $meta['columns'],
            'NUMERIC_COLS' => $meta['numeric'],
            'LONG_FIELDS' => ['cookies', 'token', 'user_agent', 'social_url'],
            'statuses' => $statuses,
            'statusesMarketplace' => $statusesMarketplace,
            'currenciesList' => $currenciesList,
            'geosList' => $geosList,
            'statusRkList' => $statusRkList,
            'emptyMarketplaceStatusCount' => $emptyMarketplaceStatusCount,
            'emptyCurrencyCount' => $emptyCurrencyCount,
            'emptyGeoCount' => $emptyGeoCount,
            'emptyStatusRkCount' => $emptyStatusRkCount,
            'filterParams' => $filterParams,
            'selectedStatuses' => $selectedStatuses,
            'statusArray' => $statusArray,
            'emptyStatusParam' => $emptyStatusParam,
            'activeFiltersCount' => $activeFiltersCount,
            'q' => $q,
            'exportUrl' => $exportUrl,
            'csrfToken' => $csrfToken,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'prev' => $prev,
            'next' => $next,
            'pageNumbers' => $pageNumbers,
            'emptyStatusCount' => $emptyStatusCount,
            'totals' => $totals,
            'recentAll' => $recentAll,
            'byStatus' => $byStatus,
            'countEmailTwoFa' => $countEmailTwoFa,
            'CLIP_LEN' => $CLIP_LEN,
            'TOKEN_CLIP' => $TOKEN_CLIP,
            'statusMarketplace' => $statusMarketplace,
            'currencyFilter' => $currencyFilter,
            'geoFilter' => $geoFilter,
            'statusRkFilter' => $statusRkFilter,
            'limitRkFrom' => $limitRkFrom,
            'limitRkTo' => $limitRkTo,
            'hasEmailParam' => $hasEmailParam,
            'hasTwoFaParam' => $hasTwoFaParam,
            'hasTokenParam' => $hasTokenParam,
            'hasAvatarParam' => $hasAvatarParam,
            'hasCoverParam' => $hasCoverParam,
            'hasPasswordParam' => $hasPasswordParam,
            'hasFanPageParam' => $hasFanPageParam,
            'hasBmParam' => $hasBmParam,
            'fullFilledParam' => $fullFilledParam,
            'pharmaFrom' => $pharmaFrom,
            'pharmaTo' => $pharmaTo,
            'friendsFrom' => $friendsFrom,
            'friendsTo' => $friendsTo,
            'yearCreatedFrom' => $yearCreatedFrom,
            'yearCreatedTo' => $yearCreatedTo,
            'favoritesOnlyParam' => $favoritesOnlyParam,
            // Добавляем недостающие переменные
            'stats' => $stats,
            'filter' => $filter,
            'sort' => $sort,
            'dir' => $dir,
            'offset' => $offset,
            'filteredTotal' => $filteredTotal,
        ];
    }
}


<?php
/**
 * Страница избранных аккаунтов
 * Отображает все аккаунты, добавленные пользователем в избранное
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/RequestHandler.php';
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/Logger.php';

try {
    // Проверяем авторизацию
    requireAuth();
    checkSessionTimeout();
    
    $userId = $_SESSION['username'] ?? null;
    if (!$userId) {
        header('Location: login.php');
        exit;
    }
    
    // Создаем сервис
    $service = new AccountsService($tableName);
    
    // Создаем фильтр с обязательным условием "только избранные"
    // shouldFilter=true — обязательный второй аргумент, без него фильтр не применяется
    $filter = $service->createFilterFromRequest($_GET);
    $filter->addFavoritesFilter($userId, true);
    
    // Получаем метаданные колонок
    $meta = $service->getColumnMetadata();
    
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
        $sort = get_param('sort', 'id');
        $dir = strtolower(get_param('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
    }
    
    // Получаем статистику
    $stats = $service->getStatistics($filter);
    $filteredTotal = $stats['filteredTotal'];
    
    // Корректируем страницу
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;
    
    // Получаем данные
    $rows = $service->getAccounts($filter, $sort, $dir, $perPage, $offset);
    
    // Параметры для шаблона
    $q = get_param('q', '');
    $ALL_COLUMNS = $meta['columns'];
    
    // Подготовка данных для пагинации
    $prev = max(1, $page - 1);
    $next = min($pages, $page + 1);
    $startPage = max(1, $page - 2);
    $endPage = min($pages, $page + 2);
    $pageNumbers = range($startPage, $endPage);
    
    // Подсчет активных фильтров
    $activeFiltersCount = 0;
    if ($q !== '') $activeFiltersCount++;
    // Добавляем другие фильтры по необходимости
    
} catch (Throwable $e) {
    Logger::error('Favorites page error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    $errorMessage = 'Ошибка загрузки страницы избранного: ' . $e->getMessage();
    $rows = [];
    $filteredTotal = 0;
    $pages = 1;
    $page = 1;
    $prev = 1;
    $next = 1;
    $startPage = 1;
    $endPage = 1;
    $pageNumbers = [1];
    $q = '';
    $ALL_COLUMNS = [];
    $activeFiltersCount = 0;
    $sort = 'id';
    $dir = 'ASC';
    $perPage = 25;
}

// Включаем шаблон
require __DIR__ . '/templates/favorites.php';


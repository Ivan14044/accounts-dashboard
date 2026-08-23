<?php
/**
 * Класс для централизованной обработки HTTP-запросов
 * Устраняет дублирование логики получения параметров
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Utils.php';

class RequestHandler {
    /**
     * Список допустимых параметров фильтров
     */
    private static $allowedFilters = [
        'q', 'status', 'status_marketplace', 'empty_status',
        'currency', 'geo', 'status_rk', 'limit_rk_from', 'limit_rk_to',
        'has_email', 'has_two_fa', 'has_token', 'has_avatar',
        'has_cover', 'has_password', 'has_fan_page', 'has_passkey', 'full_filled',
        'phone_removed',
        'pharma_from', 'pharma_to', 'friends_from', 'friends_to',
        'year_created_from', 'year_created_to',
        'bm_status'
    ];

    /**
     * Фильтры, у которых значение честно многозначное.
     *
     * Сейчас это только статус: фронт канонически шлёт его как
     * `status[]=A&status[]=B` (assets/js/filters-modern.js). Такие ключи
     * читаются через get_param_array() и остаются МАССИВОМ, остальные —
     * через get_param() и остаются строкой.
     *
     * Почему status не выкинут из $allowedFilters, хотя сама выборка читает
     * `$_GET['status']` в обход RequestHandler (DashboardController): от этого
     * списка зависит countActiveFilters(), которая обязана посчитать каждый
     * выбранный статус отдельным фильтром. Раньше она получала строку "Array"
     * и по случайности насчитывала ровно 1 — теперь считает реальное число.
     */
    private static $multiValueFilters = ['status'];
    
    /**
     * Получить все параметры фильтров
     * 
     * @return array Ассоциативный массив параметров фильтров. Значение —
     *               строка для одиночных фильтров и массив строк для тех,
     *               что перечислены в self::$multiValueFilters.
     */
    public static function getFilterParams(): array {
        $params = [];
        foreach (self::$allowedFilters as $key) {
            if (in_array($key, self::$multiValueFilters, true)) {
                $params[$key] = get_param_array($key);
            } else {
                $params[$key] = get_param($key);
            }
        }
        return $params;
    }
    
    /**
     * Получить параметры пагинации
     * 
     * @return array Массив с ключами 'page' и 'perPage'
     */
    public static function getPaginationParams(): array {
        $page = max(1, (int)get_param('page', '1'));
        $perPage = (int)get_param('per_page', (string)Config::DEFAULT_PAGE_SIZE);
        $allowedPerPage = [25, 50, 100, 200];
        
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = Config::DEFAULT_PAGE_SIZE;
        }
        
        return [
            'page' => $page,
            'perPage' => $perPage,
            'allowedPerPage' => $allowedPerPage
        ];
    }
    
    /**
     * Получить параметры сортировки
     * 
     * @param array $allowedColumns Список разрешенных колонок для сортировки
     * @return array Массив с ключами 'sort' и 'dir'
     */
    public static function getSortParams(array $allowedColumns = []): array {
        $sort = get_param('sort', 'id');
        $dir = strtolower(get_param('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        
        // Валидация колонки сортировки
        if (!empty($allowedColumns) && !in_array($sort, $allowedColumns, true)) {
            $sort = 'id';
        }
        
        return [
            'sort' => $sort,
            'dir' => $dir
        ];
    }
    
    /**
     * Подсчитать количество активных фильтров
     * 
     * @param array $filterParams Параметры фильтров (из getFilterParams())
     * @return int Количество активных фильтров
     */
    public static function countActiveFilters(array $filterParams): int {
        $count = 0;
        
        // Поисковый запрос
        if (!empty($filterParams['q'])) {
            $count++;
        }
        
        // Статусы (каждый статус считается отдельно)
        if (!empty($filterParams['status'])) {
            if (is_array($filterParams['status'])) {
                $count += count(array_filter($filterParams['status']));
            } elseif (is_string($filterParams['status']) && $filterParams['status'] !== '') {
                $statuses = explode(',', $filterParams['status']);
                $count += count(array_filter(array_map('trim', $statuses)));
            }
        }
        
        // Пустой статус
        if (!empty($filterParams['empty_status'])) {
            $count++;
        }
        
        // Булевы фильтры "не пустое"
        $boolFilters = ['has_email', 'has_two_fa', 'has_token', 'has_avatar',
                       'has_cover', 'has_password', 'has_fan_page', 'has_passkey', 'full_filled'];
        foreach ($boolFilters as $key) {
            if (!empty($filterParams[$key])) {
                $count++;
            }
        }
        
        // Одиночные фильтры.
        // phone_removed здесь же: значения 'yes'/'no' оба непустые, а пустая
        // строка означает «фильтр не выбран» — ровно как у соседей.
        $singleFilters = ['status_marketplace', 'currency', 'geo', 'status_rk', 'phone_removed'];
        foreach ($singleFilters as $key) {
            if (!empty($filterParams[$key])) {
                $count++;
            }
        }
        
        // Диапазонные фильтры (каждый диапазон = 1 фильтр, а не 2)
        $rangeFilters = [
            ['pharma_from', 'pharma_to'],
            ['friends_from', 'friends_to'],
            ['year_created_from', 'year_created_to'],
            ['limit_rk_from', 'limit_rk_to'],
            ['bm_from', 'bm_to'],
        ];
        foreach ($rangeFilters as $range) {
            if (!empty($filterParams[$range[0]]) || !empty($filterParams[$range[1]])) {
                $count++;
            }
        }

        // Статус БМ (has_valid / has_ban / only_valid)
        if (!empty($filterParams['bm_status']) && $filterParams['bm_status'] !== 'any') {
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Получить список разрешенных параметров фильтров
     * 
     * @return array Массив разрешенных ключей параметров
     */
    public static function getAllowedFilters(): array {
        return self::$allowedFilters;
    }
}


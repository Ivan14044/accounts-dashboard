<?php
/**
 * Сервис для работы со статистикой аккаунтов
 * Содержит методы для получения статистики и уникальных значений фильтров
 * 
 * Оптимизирует запросы, объединяя несколько запросов в один.
 * Использует кэширование для повышения производительности.
 * 
 * @package includes
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/FilterBuilder.php';
require_once __DIR__ . '/ColumnMetadata.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';

class StatisticsService {
    private $db;
    private $table = 'accounts';
    private $metadata;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $mysqli = $this->db->getConnection();
        $this->metadata = ColumnMetadata::getInstance($mysqli);
    }
    
    /**
     * Получение статистики (общая и по статусам)
     * 
     * @param FilterBuilder|null $filter Фильтр
     * @return array
     */
    public function getStatistics(FilterBuilder $filter = null): array {
        // Проверяем кэш (если включено кэширование статистики)
        $cacheKey = 'stats_' . md5(serialize($filter ? $filter->getParams() : []));
        $cached = $this->db->getCached($cacheKey);
        
        if ($cached !== null && Config::FEATURE_STATS_CACHING) {
            Logger::debug('STATISTICS: Returned from cache');
            return $cached;
        }
        
        // Определяем поле timestamp для "недавних"
        $tsField = 'created_at';
        if ($this->metadata->columnExists('updated_at')) {
            $tsField = $this->metadata->columnExists('created_at') 
                ? 'COALESCE(updated_at, created_at)' 
                : 'updated_at';
        }
        
        // ОПТИМИЗАЦИЯ: Один запрос вместо 8!
        // Используем агрегацию и условный подсчёт
        $where = '';
        $params = [];
        
        if ($filter && $filter->getConditionsCount() > 0) {
            $where = $filter->getWhereClause(false); // false = не включать удаленные (deleted_at IS NULL)
            $params = $filter->getParams();
        } else {
            // Если фильтр не передан, все равно исключаем удаленные записи
            if ($this->metadata->columnExists('deleted_at')) {
                $where = 'WHERE deleted_at IS NULL';
            }
        }
        
        $sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) as empty_status,
            SUM(CASE WHEN email IS NOT NULL AND email <> '' 
                     AND two_fa IS NOT NULL AND two_fa <> '' THEN 1 ELSE 0 END) as email_two_fa,
            SUM(CASE WHEN $tsField >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as recent_all
        FROM {$this->table}
        $where
        ";
        
        $mainStats = $this->db->prepare($sql, $params);
        $stats = $mainStats[0] ?? [];
        
        $total = (int)($stats['total'] ?? 0);
        $emptyStatus = (int)($stats['empty_status'] ?? 0);
        $emailTwoFa = (int)($stats['email_two_fa'] ?? 0);
        $recentAll = (int)($stats['recent_all'] ?? 0);
        
        // Статистика по статусам (отдельный GROUP BY для детализации).
        // ВАЖНО: используем GROUP BY status (без COALESCE), чтобы MySQL мог применить
        // idx_stats_covering(deleted_at, status, updated_at, created_at) как covering index
        // с loose index scan. COALESCE в GROUP BY запрещает использование индекса для
        // группировки и вызывает filesort на 139k строках (было 7.8 сек в slow log).
        // Объединение NULL и '' выполняется в PHP (строчка ниже).
        $statusSql = "
        SELECT 
            status,
            COUNT(*) as count,
            SUM(CASE WHEN $tsField >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as recent_count
        FROM {$this->table}
        $where
        GROUP BY status
        ORDER BY status
        ";
        
        $statusStats = $this->db->prepare($statusSql, $params, 'status_stats');
        
        $byStatus = [];
        $recentByStatus = [];
        
        foreach ($statusStats as $row) {
            // NULL и '' объединяем в одну группу — оба означают «без статуса».
            // Именно здесь происходит то, что раньше делал COALESCE в SQL.
            $status = ($row['status'] === null || $row['status'] === '') ? '' : $row['status'];
            $byStatus[$status]       = ($byStatus[$status]       ?? 0) + (int)$row['count'];
            $recentByStatus[$status] = ($recentByStatus[$status] ?? 0) + (int)($row['recent_count'] ?? 0);
        }
        
        // Гарантируем предсказуемый порядок статусов:
        // сортируем их по алфавиту (натуральная сортировка, без учёта регистра),
        // чтобы новые статусы попадали на своё место, а не в конец списка.
        if (!empty($byStatus)) {
            $sortedByStatus = $byStatus;
            uksort($sortedByStatus, 'strnatcasecmp');
            $byStatus = $sortedByStatus;
            
            // Синхронизируем порядок массива "недавних" значений со списком статусов
            $sortedRecent = [];
            foreach (array_keys($byStatus) as $key) {
                $sortedRecent[$key] = $recentByStatus[$key] ?? 0;
            }
            $recentByStatus = $sortedRecent;
        }
        
        // Если фильтр не применён, статистика одинаковая
        $filteredTotal = $total;
        $byStatusFiltered = $byStatus;
        
        // Если нужна статистика БЕЗ фильтра (для сравнения)
        if ($filter && $filter->getConditionsCount() > 0) {
            // ОПТИМИЗАЦИЯ: Получаем общую статистику без фильтра одним запросом
            // Исключаем удаленные записи
            $unfilteredWhere = '';
            if ($this->metadata->columnExists('deleted_at')) {
                $unfilteredWhere = 'WHERE deleted_at IS NULL';
            }
            
            // Объединяем два запроса в один с подзапросом для оптимизации
            $unfilteredSql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) as empty_status,
                COALESCE(status, '') as status,
                COUNT(*) as status_count
            FROM {$this->table}
            $unfilteredWhere
            GROUP BY status WITH ROLLUP
            ";
            
            $unfilteredStats = $this->db->prepare($unfilteredSql, [], 'unfiltered_stats_combined');
            
            $totalUnfiltered = 0;
            $byStatusUnfiltered = [];
            
            // Обрабатываем результаты: WITH ROLLUP создает итоговую строку с NULL в status
            foreach ($unfilteredStats as $row) {
                $status = $row['status'] ?? '';
                $count = (int)($row['status_count'] ?? 0);
                
                if ($status === '') {
                    // Это итоговая строка от WITH ROLLUP
                    $totalUnfiltered = $count;
                } else {
                    $byStatusUnfiltered[$status] = $count;
                }
            }
            
            // Если WITH ROLLUP не сработал, делаем отдельные запросы
            if ($totalUnfiltered === 0) {
                $unfilteredSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) as empty_status
                FROM {$this->table}
                $unfilteredWhere
                ";
                
                $unfilteredStats = $this->db->prepare($unfilteredSql, []);
                $unfilteredData = $unfilteredStats[0] ?? [];
                $totalUnfiltered = (int)($unfilteredData['total'] ?? 0);
                
                // Статистика по статусам БЕЗ фильтра
                $unfilteredStatusSql = "
                SELECT COALESCE(status, '') as status, COUNT(*) as count
                FROM {$this->table}
                $unfilteredWhere
                GROUP BY status
                ORDER BY status
                ";
                
                $unfilteredStatusStats = $this->db->prepare($unfilteredStatusSql, [], 'unfiltered_status_stats');
                
                foreach ($unfilteredStatusStats as $row) {
                    $status = $row['status'] ?? '';
                    $byStatusUnfiltered[$status] = (int)$row['count'];
                }
                
                // Упорядочиваем статусы без фильтра по алфавиту,
                // чтобы отображение в разных частях дашборда было единообразным.
                if (!empty($byStatusUnfiltered)) {
                    $sortedUnfiltered = $byStatusUnfiltered;
                    uksort($sortedUnfiltered, 'strnatcasecmp');
                    $byStatusUnfiltered = $sortedUnfiltered;
                }
            }
            
            // Для отфильтрованной статистики используем уже полученные данные
            $filteredTotal = $total;
            $byStatusFiltered = $byStatus;
            
            $result = [
                'total' => $totalUnfiltered,
                'filteredTotal' => $filteredTotal,
                'byStatus' => $byStatusUnfiltered,
                'byStatusFiltered' => $byStatusFiltered,
                'emailTwoFa' => $emailTwoFa,
                'emptyStatus' => $emptyStatus,
                'recentAll' => $recentAll,
                'recentByStatus' => $recentByStatus
            ];
        } else {
            $result = [
                'total' => $total,
                'filteredTotal' => $filteredTotal,
                'byStatus' => $byStatus,
                'byStatusFiltered' => $byStatusFiltered,
                'emailTwoFa' => $emailTwoFa,
                'emptyStatus' => $emptyStatus,
                'recentAll' => $recentAll,
                'recentByStatus' => $recentByStatus
            ];
        }
        
        // Кэшируем результат на 5 минут
        if (Config::FEATURE_STATS_CACHING) {
            $this->db->cache($cacheKey, $result, Config::STATS_CACHE_TTL);
        }
        
        Logger::debug('STATISTICS: Calculated', ['total' => $total, 'filtered' => $filteredTotal]);
        
        return $result;
    }
    
    /**
     * Получение всех уникальных значений фильтров одним запросом
     * Оптимизация: объединяет 5 отдельных запросов в один через UNION
     * 
     * @return array Ассоциативный массив с ключами: status, status_marketplace, currency, geo, status_rk
     */
    public function getUniqueFilterValues(): array {
        $cacheKey = 'unique_filter_values';
        $cached = $this->db->getCached($cacheKey);
        if ($cached !== null) {
            Logger::debug('UNIQUE FILTER VALUES: Returned from cache');
            return $cached;
        }
        
        // Один запрос вместо 5 отдельных запросов
        // Исключаем удаленные записи из всех подсчетов
        $deletedCondition = '';
        if ($this->metadata->columnExists('deleted_at')) {
            $deletedCondition = 'AND deleted_at IS NULL';
        }
        
        $sql = "
        SELECT 
            'status' as type, status as value, COUNT(*) as count
            FROM {$this->table} 
            WHERE status IS NOT NULL AND status != '' $deletedCondition
            GROUP BY status
        UNION ALL
            SELECT 'status_marketplace', status_marketplace, COUNT(*)
            FROM {$this->table}
            WHERE status_marketplace IS NOT NULL AND status_marketplace != '' $deletedCondition
            GROUP BY status_marketplace
        UNION ALL
            SELECT 'currency', currency, COUNT(*)
            FROM {$this->table}
            WHERE currency IS NOT NULL AND currency != '' $deletedCondition
            GROUP BY currency
        UNION ALL
            SELECT 'geo', geo, COUNT(*)
            FROM {$this->table}
            WHERE geo IS NOT NULL AND geo != '' $deletedCondition
            GROUP BY geo
        UNION ALL
            SELECT 'status_rk', status_rk, COUNT(*)
            FROM {$this->table}
            WHERE status_rk IS NOT NULL AND status_rk != '' $deletedCondition
            GROUP BY status_rk
        ORDER BY type, value
        ";
        
        $results = $this->db->prepare($sql, [], $cacheKey);
        
        // Группируем результаты по типу
        $grouped = [
            'status' => [],
            'status_marketplace' => [],
            'currency' => [],
            'geo' => [],
            'status_rk' => []
        ];
        
        foreach ($results as $row) {
            $type = $row['type'];
            $value = $row['value'];
            $count = (int)$row['count'];
            
            if (isset($grouped[$type])) {
                $grouped[$type][$value] = $count;
            }
        }
        
        // Кэшируем результат на 5 минут
        $this->db->cache($cacheKey, $grouped, Config::STATS_CACHE_TTL);
        
        Logger::debug('UNIQUE FILTER VALUES: Calculated', [
            'status_count' => count($grouped['status']),
            'status_marketplace_count' => count($grouped['status_marketplace']),
            'currency_count' => count($grouped['currency']),
            'geo_count' => count($grouped['geo']),
            'status_rk_count' => count($grouped['status_rk'])
        ]);
        
        return $grouped;
    }
    
    /**
     * Получение списка уникальных статусов
     * 
     * @return array
     */
    public function getUniqueStatuses(): array {
        $values = $this->getUniqueFilterValues();
        return array_keys($values['status'] ?? []);
    }
    
    /**
     * Получение списка уникальных статусов marketplace с подсчетом
     * 
     * @return array
     */
    public function getUniqueMarketplaceStatuses(): array {
        if (!$this->metadata->columnExists('status_marketplace')) {
            return [];
        }
        
        $values = $this->getUniqueFilterValues();
        return $values['status_marketplace'] ?? [];
    }
    
    /**
     * Получение всех счётчиков пустых значений фильтров одним запросом (вместо 4 отдельных).
     * Ключи: status_marketplace, currency, geo, status_rk.
     *
     * @return array<string, int>
     */
    public function getEmptyFilterCounts(): array {
        $deletedCondition = '';
        if ($this->metadata->columnExists('deleted_at')) {
            $deletedCondition = 'WHERE deleted_at IS NULL';
        }
        $parts = [];
        if ($this->metadata->columnExists('status_marketplace')) {
            $parts[] = "SUM(CASE WHEN status_marketplace IS NULL OR status_marketplace = '' THEN 1 ELSE 0 END) as empty_status_marketplace";
        }
        if ($this->metadata->columnExists('currency')) {
            $parts[] = "SUM(CASE WHEN currency IS NULL OR currency = '' THEN 1 ELSE 0 END) as empty_currency";
        }
        if ($this->metadata->columnExists('geo')) {
            $parts[] = "SUM(CASE WHEN geo IS NULL OR geo = '' THEN 1 ELSE 0 END) as empty_geo";
        }
        if ($this->metadata->columnExists('status_rk')) {
            $parts[] = "SUM(CASE WHEN status_rk IS NULL OR status_rk = '' THEN 1 ELSE 0 END) as empty_status_rk";
        }
        $default = [
            'status_marketplace' => 0,
            'currency' => 0,
            'geo' => 0,
            'status_rk' => 0
        ];
        if ($parts === []) {
            return $default;
        }
        $sql = "SELECT " . implode(", ", $parts) . " FROM {$this->table} $deletedCondition";
        $rows = $this->db->prepare($sql, [], 'empty_filter_counts');
        $row = $rows[0] ?? [];
        return [
            'status_marketplace' => (int)($row['empty_status_marketplace'] ?? $default['status_marketplace']),
            'currency' => (int)($row['empty_currency'] ?? $default['currency']),
            'geo' => (int)($row['empty_geo'] ?? $default['geo']),
            'status_rk' => (int)($row['empty_status_rk'] ?? $default['status_rk'])
        ];
    }

    /**
     * Получение количества записей с пустым статусом marketplace
     * 
     * @return int
     */
    public function getEmptyMarketplaceStatusCount(): int {
        if (!$this->metadata->columnExists('status_marketplace')) {
            return 0;
        }
        $deletedFilter = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        return (int)$this->db->getCount(
            $this->table,
            '(status_marketplace IS NULL OR status_marketplace = "")' . $deletedFilter
        );
    }
    
    /**
     * Получение списка уникальных валют (Currency) с подсчетом
     * 
     * @return array
     */
    public function getUniqueCurrencies(): array {
        if (!$this->metadata->columnExists('currency')) {
            return [];
        }
        
        $values = $this->getUniqueFilterValues();
        return $values['currency'] ?? [];
    }
    
    /**
     * Получение количества записей с пустой валютой
     * 
     * @return int
     */
    public function getEmptyCurrencyCount(): int {
        if (!$this->metadata->columnExists('currency')) {
            return 0;
        }
        $deletedFilter = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        return (int)$this->db->getCount(
            $this->table,
            '(currency IS NULL OR currency = "")' . $deletedFilter
        );
    }
    
    /**
     * Получение списка уникальных значений geo с подсчетом
     * 
     * @return array
     */
    public function getUniqueGeos(): array {
        if (!$this->metadata->columnExists('geo')) {
            return [];
        }
        
        $values = $this->getUniqueFilterValues();
        return $values['geo'] ?? [];
    }
    
    /**
     * Получение количества записей с пустым geo
     * 
     * @return int
     */
    public function getEmptyGeoCount(): int {
        if (!$this->metadata->columnExists('geo')) {
            return 0;
        }
        $deletedFilter = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        return (int)$this->db->getCount(
            $this->table,
            '(geo IS NULL OR geo = "")' . $deletedFilter
        );
    }
    
    /**
     * Получение списка уникальных значений status_rk с подсчетом
     * 
     * @return array
     */
    public function getUniqueStatusRk(): array {
        if (!$this->metadata->columnExists('status_rk')) {
            return [];
        }
        
        $values = $this->getUniqueFilterValues();
        return $values['status_rk'] ?? [];
    }
    
    /**
     * Получение количества записей с пустым status_rk
     * 
     * @return int
     */
    public function getEmptyStatusRkCount(): int {
        if (!$this->metadata->columnExists('status_rk')) {
            return 0;
        }
        $deletedFilter = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        return (int)$this->db->getCount(
            $this->table,
            '(status_rk IS NULL OR status_rk = "")' . $deletedFilter
        );
    }
}


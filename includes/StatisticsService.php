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
require_once __DIR__ . '/StatsCache.php';

class StatisticsService {
    private $db;
    private $table;
    private $metadata;

    public function __construct(string $table = 'accounts') {
        $this->table = $table;
        $this->db = Database::getInstance();
        $mysqli = $this->db->getConnection();
        $this->metadata = ColumnMetadata::getInstance($mysqli, $this->table);
    }
    
    /**
     * Получение статистики (общая и по статусам)
     * 
     * @param FilterBuilder|null $filter Фильтр
     * @return array
     */
    public function getStatistics(FilterBuilder $filter = null): array {
        $params = $filter ? $filter->getParams() : [];
        $key = $this->cacheKey('stats', [$params, $filter ? $filter->getWhereClause(false) : '']);
        $self = $this;

        return StatsCache::remember($key, Config::STATS_FILE_CACHE_TTL, function () use ($self, $filter) {
            return $self->computeStatistics($filter);
        });
    }

    /**
     * Разбирает строки `GROUP BY status` в общее число и счётчики по статусам.
     *
     * NULL и пустая строка — это одно и то же «без статуса», поэтому они
     * складываются в одну группу с ключом ''. Так же поступает и разбор
     * отфильтрованной статистики ниже — иначе одна и та же запись считалась бы
     * по-разному в двух местах.
     *
     * Общее число берётся суммой групп, а не отдельной строкой ROLLUP: строка
     * ROLLUP приходит с NULL в status и неотличима от настоящей группы пустых
     * статусов — ровно на этом и ломался счётчик «Без статуса».
     * Стережёт tests/test_empty_status_counter.php.
     *
     * @param array $rows Строки вида ['status' => ?string, 'status_count' => int|string]
     * @return array{total:int,byStatus:array<string,int>}
     */
    public static function splitStatusGroups(array $rows): array {
        $total = 0;
        $byStatus = [];

        foreach ($rows as $row) {
            $count = (int)($row['status_count'] ?? 0);
            $total += $count;

            $raw = array_key_exists('status', $row) ? $row['status'] : null;
            $key = ($raw === null) ? '' : (string)$raw;
            $byStatus[$key] = ($byStatus[$key] ?? 0) + $count;
        }

        return ['total' => $total, 'byStatus' => $byStatus];
    }

    /**
     * Собственно расчёт статистики — полный скан таблицы.
     * Публичный только потому, что вызывается из замыкания кэша (PHP 7.3 не даёт
     * замыканию доступа к private-методам через $self).
     *
     * @param FilterBuilder|null $filter
     * @return array
     */
    public function computeStatistics(FilterBuilder $filter = null): array {
        // Проверяем кэш (если включено кэширование статистики)
        $cacheKey = 'stats_' . md5(serialize($filter ? $filter->getParams() : []));
        $cached = $this->db->getCached($cacheKey);
        
        if ($cached !== null && Config::FEATURE_STATS_CACHING) {
            Logger::debug('STATISTICS: Returned from cache');
            return $cached;
        }
        
        // Определяем поле timestamp для "недавних"
        $hasCreatedAt = $this->metadata->columnExists('created_at');
        $hasUpdatedAt = $this->metadata->columnExists('updated_at');
        $hasStatus = $this->metadata->columnExists('status');
        $hasEmail = $this->metadata->columnExists('email');
        $hasTwoFa = $this->metadata->columnExists('two_fa');

        $tsField = null;
        if ($hasUpdatedAt && $hasCreatedAt) {
            $tsField = 'COALESCE(updated_at, created_at)';
        } elseif ($hasUpdatedAt) {
            $tsField = 'updated_at';
        } elseif ($hasCreatedAt) {
            $tsField = 'created_at';
        }

        $where = '';
        $params = [];

        if ($filter && $filter->getConditionsCount() > 0) {
            $where = $filter->getWhereClause(false);
            $params = $filter->getParams();
        } else {
            if ($this->metadata->columnExists('deleted_at')) {
                $where = 'WHERE deleted_at IS NULL';
            }
        }

        // Строим SELECT-часть динамически под доступные колонки
        $selectParts = ['COUNT(*) as total'];
        if ($hasStatus) {
            $selectParts[] = "SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) as empty_status";
        }
        if ($hasEmail && $hasTwoFa) {
            $selectParts[] = "SUM(CASE WHEN email IS NOT NULL AND email <> '' AND two_fa IS NOT NULL AND two_fa <> '' THEN 1 ELSE 0 END) as email_two_fa";
        }
        if ($tsField) {
            $selectParts[] = "SUM(CASE WHEN $tsField >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as recent_all";
        }

        $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$this->table} $where";
        
        $mainStats = $this->db->prepare($sql, $params);
        $stats = $mainStats[0] ?? [];
        
        $total = (int)($stats['total'] ?? 0);
        $emptyStatus = (int)($stats['empty_status'] ?? 0);
        $emailTwoFa = (int)($stats['email_two_fa'] ?? 0);
        $recentAll = (int)($stats['recent_all'] ?? 0);
        
        // Статистика по статусам
        $statusStats = [];
        if ($hasStatus) {
            $recentPart = $tsField ? ", SUM(CASE WHEN $tsField >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as recent_count" : '';
            $statusSql = "
            SELECT status, COUNT(*) as count{$recentPart}
            FROM {$this->table} $where
            GROUP BY status ORDER BY status
            ";
            $statusStats = $this->db->prepare($statusSql, $params, 'status_stats');
        }
        
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
            
            // Нефильтрованная статистика: обычный GROUP BY, общее число складываем
            // из групп в PHP.
            //
            // Раньше здесь был WITH ROLLUP и COALESCE(status,''), а разбор шёл по
            // правилу «status пустой — значит это итоговая строка ROLLUP». Беда в
            // том, что COALESCE делает пустыми ОБЕ строки: итоговую (у неё
            // status = NULL) и настоящую группу пустых статусов. Проверено
            // запросом на стенде — приходили обе, со счётчиками 182 021 и 36.
            // Настоящая группа при этом не попадала в список вовсе, и карточка
            // «Без статуса» показывала «-», стоило применить любой фильтр.
            //
            // GROUPING() здесь не помощник: он есть только в MySQL 8 (версия
            // прода неизвестна) и всё равно не отличил бы итоговую строку от
            // настоящей группы со status IS NULL — обе приходят с NULL.
            // Без ROLLUP неоднозначности нет вообще, а запрос остаётся один.
            $unfilteredSql = "
            SELECT status, COUNT(*) as status_count
            FROM {$this->table}
            $unfilteredWhere
            GROUP BY status
            ";

            $unfilteredStats = $this->db->prepare($unfilteredSql, [], 'unfiltered_stats_combined');

            $split = self::splitStatusGroups($unfilteredStats);
            $totalUnfiltered    = $split['total'];
            $byStatusUnfiltered = $split['byStatus'];

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
        $self = $this;

        return StatsCache::remember(
            $this->cacheKey('unique_filter_values', []),
            Config::STATS_FILE_CACHE_TTL,
            function () use ($self) { return $self->computeUniqueFilterValues(); }
        );
    }

    /**
     * Расчёт уникальных значений фильтров — GROUP BY по всей таблице.
     *
     * @return array
     */
    public function computeUniqueFilterValues(): array {
        $cacheKey = 'unique_filter_values_' . $this->table;
        $cached = $this->db->getCached($cacheKey);
        if ($cached !== null) {
            Logger::debug('UNIQUE FILTER VALUES: Returned from cache');
            return $cached;
        }

        $deletedCondition = '';
        if ($this->metadata->columnExists('deleted_at')) {
            $deletedCondition = 'AND deleted_at IS NULL';
        }

        // Строим UNION ALL динамически — только для существующих колонок
        $filterColumns = ['status', 'status_marketplace', 'currency', 'geo', 'status_rk'];
        $unions = [];
        foreach ($filterColumns as $col) {
            if ($this->metadata->columnExists($col)) {
                $unions[] = "SELECT '{$col}' as type, `{$col}` as value, COUNT(*) as count FROM {$this->table} WHERE `{$col}` IS NOT NULL AND `{$col}` != '' {$deletedCondition} GROUP BY `{$col}`";
            }
        }

        $grouped = [];
        foreach ($filterColumns as $col) {
            $grouped[$col] = [];
        }

        if (!empty($unions)) {
            $sql = implode(" UNION ALL ", $unions) . " ORDER BY type, value LIMIT 10000";
            $results = $this->db->prepare($sql, [], $cacheKey);

            foreach ($results as $row) {
                $type = $row['type'];
                $value = $row['value'];
                if (isset($grouped[$type])) {
                    $grouped[$type][$value] = (int)$row['count'];
                }
            }
        }

        $this->db->cache($cacheKey, $grouped, Config::STATS_CACHE_TTL);
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
     * Счётчики пустых значений фильтров: status_marketplace, currency, geo, status_rk.
     *
     * Ходит через тот же файловый кэш, что и остальные агрегаты. Раньше не ходил —
     * и это была почти вся стоимость прогретой страницы на проде: расчёт выполнялся
     * на каждый заход, даже когда все прочие агрегаты приходили из кэша.
     * Замер на стенде с прод-формой данных (182 021 строка): 0,23 с на запрос.
     *
     * Сам расчёт с 2026-09-03 идёт четырьмя отдельными запросами по индексам
     * вместо одного общего скана — почему именно так, написано в
     * computeEmptyFilterCounts().
     *
     * Инварианты стерегут tests/test_stats_aggregates_cached.php (кэш) и
     * tests/test_empty_filter_counts_split.php (запрос на колонку).
     *
     * @return array<string, int>
     */
    public function getEmptyFilterCounts(): array {
        $key  = $this->cacheKey('empty_filter_counts', []);
        $self = $this;

        return StatsCache::remember($key, Config::STATS_FILE_CACHE_TTL, function () use ($self) {
            return $self->computeEmptyFilterCounts();
        });
    }

    /**
     * Собственно расчёт счётчиков пустых значений — по запросу на колонку.
     *
     * Публичный только потому, что вызывается из замыкания кэша: PHP 7.3 не даёт
     * замыканию доступа к private-методам через $self (та же причина, что у
     * computeStatistics выше).
     *
     * @return array<string, int>
     */
    public function computeEmptyFilterCounts(): array {
        $deletedCondition = '';
        if ($this->metadata->columnExists('deleted_at')) {
            $deletedCondition = 'deleted_at IS NULL';
        }

        $counts = [
            'status_marketplace' => 0,
            'currency' => 0,
            'geo' => 0,
            'status_rk' => 0
        ];

        // По одному запросу на колонку — намеренно, а не «чтобы было проще».
        //
        // Раньше это был ОДИН запрос с четырьмя SUM(CASE ...) по четырём разным
        // колонкам. Такое не покрывается ни одним индексом, поэтому он читал
        // таблицу целиком: в боевом медленном журнале 198 запусков, 182 087 строк
        // за запуск, в среднем 5,9 с и до 8,2 с — второй по стоимости запрос всей
        // панели. Отдельный COUNT(*) по одной колонке ложится на уже имеющийся
        // индекс (deleted_at, колонка) из Database::MANAGED_INDEXES и читается
        // прямо из индекса, без похода за самими строками.
        //
        // Замер на стенде (185 000 строк прод-формы, mysql:8.0, прогретый кэш):
        // 373–460 мс одним запросом против 32–35 мс четырьмя — в 12 раз быстрее.
        // Схлопывать обратно в один запрос нельзя, это стережёт
        // tests/test_empty_filter_counts_split.php. (проверено 2026-09-03)
        foreach (array_keys($counts) as $column) {
            if (!$this->metadata->columnExists($column)) {
                continue;
            }
            $conditions = [];
            if ($deletedCondition !== '') {
                $conditions[] = $deletedCondition;
            }
            $conditions[] = "(`$column` IS NULL OR `$column` = '')";
            $sql = "SELECT COUNT(*) as empty_count FROM {$this->table} WHERE " . implode(' AND ', $conditions);
            $rows = $this->db->prepare($sql, [], 'empty_filter_count_' . $column);
            $counts[$column] = (int)($rows[0]['empty_count'] ?? 0);
        }

        return $counts;
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
     * Уникальные значения status среди записей в корзине (для фильтра корзины).
     * Скоупится к deleted_at IS NOT NULL — показываем только релевантные статусы.
     *
     * @return string[] Список непустых статусов
     */
    public function getDistinctStatuses(): array {
        if (!$this->metadata->columnExists('status')) {
            return [];
        }
        $deletedScope = $this->metadata->columnExists('deleted_at')
            ? 'WHERE deleted_at IS NOT NULL AND status IS NOT NULL AND status <> \'\''
            : 'WHERE status IS NOT NULL AND status <> \'\'';
        $sql = "SELECT DISTINCT status FROM `{$this->table}` $deletedScope ORDER BY status ASC LIMIT 500";
        try {
            $rows = $this->db->prepare($sql, []);
        } catch (Throwable $e) {
            Logger::debug('getDistinctStatuses failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (isset($r['status']) && $r['status'] !== '') {
                $out[] = (string)$r['status'];
            }
        }
        return $out;
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

    /**
     * Получение кумулятивного количества аккаунтов за последние N дней
     * (для отрисовки sparkline в карточке "Всего аккаунтов").
     *
     * Возвращает массив из N int значений (running total на конец каждого дня).
     * Если в таблице нет колонки created_at — возвращает пустой массив.
     *
     * @param int $days
     * @return array
     */
    public function getDailyTotals(int $days = 7): array {
        $self = $this;

        return StatsCache::remember(
            $this->cacheKey('daily_totals', [$days]),
            Config::STATS_FILE_CACHE_TTL,
            function () use ($self, $days) { return $self->computeDailyTotals($days); }
        );
    }

    /**
     * Расчёт графика за N дней.
     *
     * Вопреки тому, что можно подумать по GROUP BY DATE(created_at), запрос
     * дешёвый: фильтр `created_at >= DATE_SUB(CURDATE(), ...)` — это range-скан
     * по idx_created_at, и группировка идёт по строкам одной недели, а не по
     * всей таблице. Замер на 180 000 строк (данные за 400 дней): 4,9 мс,
     * план `type: range, key: idx_created_at, rows: 3150`. (проверено 2026-08-09)
     *
     * @param int $days
     * @return array
     */
    public function computeDailyTotals(int $days = 7): array {
        if ($days < 2) $days = 2;
        if ($days > 90) $days = 90;

        if (!$this->metadata->columnExists('created_at')) {
            return [];
        }

        $cacheKey = 'sparkline_' . $this->table . '_' . $days;
        $cached = $this->db->getCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $deletedFilter = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';

        // События за последние $days дней (включая сегодня)
        $sql = "SELECT DATE(created_at) AS d, COUNT(*) AS c
                FROM `{$this->table}`
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  {$deletedFilter}
                GROUP BY DATE(created_at)
                ORDER BY d ASC";

        try {
            $rows = $this->db->prepare($sql, [$days - 1]);
        } catch (Throwable $e) {
            Logger::debug('SPARKLINE: query failed - ' . $e->getMessage());
            return [];
        }

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['d']] = (int)$r['c'];
        }

        // Стартовое значение = всего записей до начала окна
        $startSql = "created_at < DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)" . $deletedFilter;
        $start = (int)$this->db->getCount($this->table, $startSql);

        $result = [];
        $running = $start;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $running += $byDate[$d] ?? 0;
            $result[] = $running;
        }

        $this->db->cache($cacheKey, $result, Config::STATS_CACHE_TTL);
        return $result;
    }

    /**
     * Округлить отпечаток данных вниз до границы окна.
     *
     * Зачем. Точный MAX(updated_at) делал кэш агрегатов почти бесполезным на
     * живой панели: правка любой строки кем угодно меняла ключ, и следующий
     * открывший страницу платил полный пересчёт (0,58 с против 0,045 с из кэша
     * на 184 800 строках). Правки идут постоянно, поэтому в пересчёт попадали
     * почти все заходы. С округлением все правки внутри одного окна делят общий
     * ключ, и пересчёт случается не чаще раза в окно.
     *
     * Метод статический и чистый, чтобы его можно было проверить тестом без БД
     * (tests/test_stats_fingerprint_bucket.php).
     *
     * @param string $fingerprint Отпечаток: дата-время MySQL, 'empty' или 'na'
     * @param int    $bucket      Ширина окна в секундах; <= 1 — округления нет
     * @return string Округлённый отпечаток; неразбираемое значение возвращается
     *                как есть — лучше лишний пересчёт, чем неверный ключ
     */
    public static function bucketFingerprint($fingerprint, $bucket) {
        $bucket = (int)$bucket;
        if ($bucket <= 1) {
            return (string)$fingerprint;
        }

        $ts = strtotime((string)$fingerprint);
        if ($ts === false) {
            // 'na', 'empty' или мусор — округлять нечего.
            return (string)$fingerprint;
        }

        return 'b' . (string)((int)floor($ts / $bucket) * $bucket);
    }

    /**
     * Писал ли ЭТОТ пользователь в таблицу только что.
     *
     * Тот, кто сам сменил статусы или удалил строки, должен увидеть новые числа
     * сразу, а не через окно округления — иначе кажется, что операция не
     * сработала. Метка ставится в Database::prepare() на любом изменяющем
     * запросе и живёт одно окно.
     *
     * @return bool
     */
    private static function viewerJustWrote() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $until = isset($_SESSION[Config::STATS_SELF_WRITE_FLAG])
            ? (int)$_SESSION[Config::STATS_SELF_WRITE_FLAG]
            : 0;

        return $until >= time();
    }

    /**
     * Ключ кэша агрегата: база + таблица + аргументы + отпечаток данных.
     *
     * Отпечаток — MAX(updated_at) по таблице. Колонка обновляется автоматически
     * при любой вставке и правке строки (ON UPDATE CURRENT_TIMESTAMP). Сам запрос
     * дешёвый — читается вершина индекса idx_updated_at.
     *
     * Отпечаток округляется до окна STATS_FINGERPRINT_BUCKET — почему, написано
     * в докблоке bucketFingerprint(). Исключение: пользователь, который сам
     * только что писал в таблицу, получает точный отпечаток и видит свои правки
     * мгновенно.
     *
     * Чего отпечаток не ловит: жёсткое удаление строк (MAX не меняется). На этот
     * случай остаётся TTL.
     *
     * @param string $name Имя агрегата
     * @param array  $args Аргументы, влияющие на результат
     * @return string
     */
    private function cacheKey(string $name, array $args): string {
        static $fingerprint = null;

        if ($fingerprint === null) {
            $fingerprint = 'na';
            if ($this->metadata->columnExists('updated_at')) {
                try {
                    $rows = $this->db->prepare("SELECT MAX(updated_at) AS mx FROM `{$this->table}`");
                    $fingerprint = (string)($rows[0]['mx'] ?? 'empty');
                } catch (Throwable $e) {
                    // Не смогли получить отпечаток — работаем на одном TTL.
                    Logger::debug('STATS CACHE: отпечаток данных недоступен', ['error' => $e->getMessage()]);
                }
            }
        }

        $dbName = Database::nameOf($this->db->getConnection());

        $keyFingerprint = self::viewerJustWrote()
            ? $fingerprint
            : self::bucketFingerprint($fingerprint, Config::STATS_FINGERPRINT_BUCKET);

        return implode('|', [$dbName, $this->table, $name, $keyFingerprint, md5(serialize($args))]);
    }
}


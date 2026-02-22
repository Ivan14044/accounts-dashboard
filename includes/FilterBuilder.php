<?php
/**
 * Класс для построения SQL фильтров
 * Устраняет дублирование кода фильтрации в разных частях приложения
 * 
 * Позволяет динамически строить WHERE-условия для SQL-запросов
 * с использованием prepared statements для безопасности
 * 
 * @package includes
 */
class FilterBuilder {
    private $conditions = [];
    private $params = [];
    private $columnsList = [];
    private $numericColumns = [];
    /** @var string[] Колонки, хранящиеся как строка, но используемые как число (limit_rk, scenario_pharma и т.д.) — прямое сравнение без TRIM/CAST для индекса (slow log 19–20). */
    private $numericLikeColumns = [];
    
    public function __construct(array $columns, array $numericColumns = [], array $numericLikeColumns = []) {
        $this->columnsList = $columns;
        $this->numericColumns = $numericColumns;
        $this->numericLikeColumns = $numericLikeColumns;
    }
    
    /**
     * Добавляет поисковый фильтр по нескольким полям
     * 
     * Ищет по login, email и social_url (простое совпадение текста через LIKE).
     * 
     * @param string|null $query Поисковый запрос
     * @return self Возвращает $this для цепочки вызовов
     */
    public function addSearchFilter(?string $query): self {
        if ($query === '' || $query === null) return $this;
        
        $query = trim((string)$query);
        if ($query === '') return $this;
        
        // Поиск только по login, email и social_url
        $like = '%' . $query . '%';
        $searchFields = ['login', 'email', 'social_url'];
        
        // Проверяем, какие поля существуют в таблице
        $availableFields = array_intersect($searchFields, array_keys($this->columnsList));
        
        if (empty($availableFields)) {
            return $this; // Если ни одно поле не найдено, не добавляем условие
        }
        
        $orConds = [];
        foreach ($availableFields as $field) {
            $orConds[] = '`' . $field . '` LIKE ?';
            $this->params[] = $like;
        }
        
        if (!empty($orConds)) {
            $this->conditions[] = '(' . implode(' OR ', $orConds) . ')';
        }
        
        return $this;
    }
    
    /**
     * Добавляет фильтр по статусам (множественный выбор или пустые статусы)
     * 
     * @param array|string|null $statusArray Массив статусов или строка с разделителями
     * @param bool $includeEmpty Включать ли записи с пустым статусом
     * @return self Возвращает $this для цепочки вызовов
     */
    public function addStatusFilter($statusArray, bool $includeEmpty = false): self {
        $statusConditions = [];
        
        // Фильтр по выбранным статусам
        if (!empty($statusArray)) {
            if (is_string($statusArray)) {
                $statusArray = explode(',', $statusArray);
            }
            $statusArray = array_filter(array_map('trim', $statusArray));
            
            if (!empty($statusArray)) {
                $placeholders = implode(',', array_fill(0, count($statusArray), '?'));
                $statusConditions[] = "status IN ($placeholders)";
                foreach ($statusArray as $st) {
                    $this->params[] = $st;
                }
            }
        }
        
        // Фильтр пустых статусов
        if ($includeEmpty) {
            $statusConditions[] = '(status IS NULL OR status = "")';
        }
        
        // Объединяем через OR если есть хотя бы одно условие
        if (!empty($statusConditions)) {
            $this->conditions[] = '(' . implode(' OR ', $statusConditions) . ')';
        }
        
        return $this;
    }
    
    /**
     * Добавляет фильтр по массиву ID
     */
    public function addIdsFilter($idsArray) {
        if (empty($idsArray) || !is_array($idsArray)) {
            return $this;
        }
        
        // Фильтруем и приводим к целым числам
        $idsArray = array_filter(array_map('intval', $idsArray));
        
        if (!empty($idsArray)) {
            $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
            $this->conditions[] = "id IN ($placeholders)";
            foreach ($idsArray as $id) {
                $this->params[] = $id;
            }
        }
        
        return $this;
    }
    
    /**
     * Добавляет фильтр по избранным аккаунтам пользователя
     * 
     * @param string|null $userId ID пользователя
     * @param bool $shouldFilter Применять ли фильтр
     * @return $this
     */
    public function addFavoritesFilter($userId = null, $shouldFilter = false) {
        if (!$shouldFilter || !$userId) {
            return $this;
        }
        
        // Добавляем подзапрос для избранных аккаунтов
        // Используем EXISTS для проверки наличия записи в account_favorites
        $this->conditions[] = "EXISTS (
            SELECT 1 
            FROM account_favorites 
            WHERE account_favorites.account_id = accounts.id 
            AND account_favorites.user_id = ?
        )";
        $this->params[] = $userId;
        
        return $this;
    }
    
    /**
     * Добавляет фильтр по одному полю (равенство)
     */
    public function addEqualFilter($field, $value) {
        if ($value === '' || $value === null || !isset($this->columnsList[$field])) {
            return $this;
        }
        
        $this->conditions[] = "`$field` = ?";
        $this->params[] = $value;
        return $this;
    }
    
    /**
     * Добавляет фильтр "поле не пустое"
     */
    public function addNotEmptyFilter($field, $shouldFilter = false) {
        if (!$shouldFilter || !isset($this->columnsList[$field])) {
            return $this;
        }
        
        $this->conditions[] = "(`$field` IS NOT NULL AND `$field` <> '')";
        return $this;
    }
    
    /**
     * Добавляет фильтр "поле пустое"
     */
    public function addEmptyFilter($field) {
        if (!isset($this->columnsList[$field])) {
            return $this;
        }
        
        $this->conditions[] = "(`$field` IS NULL OR `$field` = '')";
        return $this;
    }
    
    /**
     * Добавляет фильтр "все обязательные поля заполнены"
     */
    public function addFullyFilledFilter($shouldFilter = false) {
        if (!$shouldFilter) return $this;
        
        $requiredFields = ['login', 'email', 'first_name', 'last_name'];
        $conditions = [];
        
        foreach ($requiredFields as $field) {
            if (isset($this->columnsList[$field])) {
                $conditions[] = "`$field` <> ''";
            }
        }
        
        if (!empty($conditions)) {
            $this->conditions[] = '(' . implode(' AND ', $conditions) . ')';
        }
        
        return $this;
    }
    
    /**
     * Добавляет фильтр "числовое поле больше нуля".
     * Для numericColumns и numericLikeColumns — прямое сравнение (индекс); иначе CAST.
     */
    public function addGreaterThanZeroFilter($field, $shouldFilter = false) {
        if (!$shouldFilter || !isset($this->columnsList[$field])) {
            return $this;
        }
        $useDirect = in_array($field, $this->numericColumns, true) || in_array($field, $this->numericLikeColumns, true);
        $this->conditions[] = $useDirect ? "`$field` > 0" : "CAST(`$field` AS UNSIGNED) > 0";
        return $this;
    }
    
    /**
     * Добавляет фильтр по числовому диапазону.
     * Для колонок из numericColumns (INT и т.д.) используем прямое сравнение без CAST,
     * чтобы MySQL мог использовать индекс (slow log: 30 сек при CAST).
     */
    public function addRangeFilter($field, $from = null, $to = null) {
        if (!isset($this->columnsList[$field])) {
            return $this;
        }

        $hasRange = ($from !== null && $from !== '') || ($to !== null && $to !== '');
        $isNumericColumn = in_array($field, $this->numericColumns, true);
        $isNumericLike = in_array($field, $this->numericLikeColumns, true);
        $useDirectComparison = $isNumericColumn || $isNumericLike;

        if ($hasRange) {
            if ($isNumericColumn) {
                $this->conditions[] = "`$field` IS NOT NULL";
            } elseif ($isNumericLike) {
                // Числоподобное поле (VARCHAR с числами): без TRIM, чтобы индекс мог использоваться (slow log 19–20)
                $this->conditions[] = "(`$field` IS NOT NULL AND `$field` <> '')";
            } else {
                $this->conditions[] = "(`$field` IS NOT NULL AND TRIM(`$field`) <> '')";
            }
        }

        if ($from !== null && $from !== '') {
            if ($useDirectComparison) {
                $this->conditions[] = "`$field` >= ?";
            } else {
                $this->conditions[] = "CAST(`$field` AS UNSIGNED) >= ?";
            }
            $this->params[] = (int)$from;
        }

        if ($to !== null && $to !== '') {
            if ($useDirectComparison) {
                $this->conditions[] = "`$field` <= ?";
            } else {
                $this->conditions[] = "CAST(`$field` AS UNSIGNED) <= ?";
            }
            $this->params[] = (int)$to;
        }

        return $this;
    }
    
    /**
     * Добавляет фильтр по диапазону дат
     */
    public function addDateRangeFilter($field, $from = null, $to = null) {
        if (!isset($this->columnsList[$field])) {
            return $this;
        }
        
        if ($from !== null && $from !== '') {
            $this->conditions[] = "`$field` >= ?";
            $this->params[] = $from . ' 00:00:00';
        }
        
        if ($to !== null && $to !== '') {
            $this->conditions[] = "`$field` <= ?";
            $this->params[] = $to . ' 23:59:59';
        }
        
        return $this;
    }
    
    /**
     * Добавляет специальный фильтр для года создания
     */
    public function addYearCreatedFilter($from = null, $to = null) {
        if (!isset($this->columnsList['year_created'])) {
            return $this;
        }
        
        $yearFrom = ($from !== null && $from !== '' && is_numeric($from)) ? (int)$from : 0;
        $yearTo = ($to !== null && $to !== '' && is_numeric($to)) ? (int)$to : 0;
        
        if ($yearFrom > 0 || $yearTo > 0) {
            // Исключаем пустые года; прямое сравнение для использования индекса по year_created
            $this->conditions[] = '`year_created` IS NOT NULL AND `year_created` > 0';
            if ($yearFrom > 0) {
                $this->conditions[] = '`year_created` >= ?';
                $this->params[] = $yearFrom;
            }
            if ($yearTo > 0) {
                $this->conditions[] = '`year_created` <= ?';
                $this->params[] = $yearTo;
            }
        }
        
        return $this;
    }
    
    /**
     * Добавляет фильтр для показа только удалённых записей
     * 
     * @return $this
     */
    public function addDeletedOnly() {
        // Проверяем, существует ли колонка deleted_at
        $hasDeletedAtColumn = false;
        if (isset($this->columnsList['deleted_at'])) {
            $hasDeletedAtColumn = true;
        } else {
            // Проверяем через ColumnMetadata, если доступен
            try {
                require_once __DIR__ . '/ColumnMetadata.php';
                require_once __DIR__ . '/Database.php';
                $mysqli = Database::getInstance()->getConnection();
                if ($mysqli instanceof mysqli) {
                    $metadata = ColumnMetadata::getInstance($mysqli);
                    $hasDeletedAtColumn = $metadata->columnExists('deleted_at');
                }
            } catch (Exception $e) {
                // Игнорируем ошибки проверки
            }
        }

        // Добавляем условие только если колонка существует
        if ($hasDeletedAtColumn) {
            // Проверяем, есть ли уже фильтр по deleted_at
            $hasDeletedFilter = false;
            foreach ($this->conditions as $condition) {
                if (strpos($condition, 'deleted_at') !== false) {
                    $hasDeletedFilter = true;
                    break;
                }
            }
            
            // Добавляем условие для показа только удалённых
            // Для TIMESTAMP колонки достаточно проверки IS NOT NULL (пустая строка там быть не может)
            if (!$hasDeletedFilter) {
                $this->conditions[] = 'deleted_at IS NOT NULL';
            }
        }
        
        return $this;
    }
    
    /**
     * Возвращает WHERE условие для SQL-запроса
     * 
     * Автоматически добавляет фильтр по deleted_at, если не включены удалённые записи.
     * 
     * @param bool|null $includeSoftDelete Включать ли удалённые записи (для корзины)
     * @return string WHERE условие (с ключевым словом WHERE) или пустая строка
     */
    public function getWhereClause(?bool $includeSoftDelete = false): string {
        // Приводим к bool, если передан null
        if ($includeSoftDelete === null) {
            $includeSoftDelete = false;
        }
        $includeSoftDelete = (bool)$includeSoftDelete;
        $conditions = $this->conditions;
        
        // Проверяем, есть ли фильтр для показа только удалённых
        $hasDeletedOnlyFilter = false;
        foreach ($conditions as $condition) {
            if (strpos($condition, 'deleted_at IS NOT NULL') !== false) {
                $hasDeletedOnlyFilter = true;
                break;
            }
        }
        
        // Если есть фильтр для показа только удалённых, не добавляем условие исключения
        if ($hasDeletedOnlyFilter) {
            // Показываем только удалённые - не добавляем условие исключения
        } elseif (!$includeSoftDelete) {
            // Проверяем, существует ли колонка deleted_at перед добавлением условия
            $hasDeletedAtColumn = false;
            if (isset($this->columnsList['deleted_at'])) {
                $hasDeletedAtColumn = true;
            } else {
                // Проверяем через ColumnMetadata, если доступен
                try {
                    require_once __DIR__ . '/ColumnMetadata.php';
                    require_once __DIR__ . '/Database.php';
                    $mysqli = Database::getInstance()->getConnection();
                    if ($mysqli instanceof mysqli) {
                        $metadata = ColumnMetadata::getInstance($mysqli);
                        $hasDeletedAtColumn = $metadata->columnExists('deleted_at');
                    }
                } catch (Exception $e) {
                    // Игнорируем ошибки проверки
                }
            }

            // Добавляем фильтр по deleted_at только если колонка существует
            if ($hasDeletedAtColumn) {
                // Добавляем фильтр по deleted_at, если не включены удалённые и нет фильтра для показа удалённых
                $hasDeletedFilter = false;
                foreach ($conditions as $condition) {
                    if (strpos($condition, 'deleted_at') !== false) {
                        $hasDeletedFilter = true;
                        break;
                    }
                }
                
                // Если нет фильтра по deleted_at, добавляем условие для исключения удалённых
                if (!$hasDeletedFilter) {
                    // Используем только IS NULL, так как deleted_at - это datetime колонка
                    // и сравнение с пустой строкой вызывает ошибку MySQL
                    $conditions[] = 'deleted_at IS NULL';
                }
            }
        }
        
        if (empty($conditions)) {
            return '';
        }
        return 'WHERE ' . implode(' AND ', $conditions);
    }
    
    /**
     * Возвращает параметры для prepared statement
     * 
     * @return array Массив параметров в порядке их использования в WHERE условии
     */
    public function getParams(): array {
        return $this->params;
    }
    
    /**
     * Возвращает строку типов для bind_param на основе типов параметров
     * 
     * @return string Строка типов (например, 'iss' для int, string, string)
     */
    public function getParamTypes(): string {
        $types = '';
        foreach ($this->params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_bool($param)) {
                $types .= 'i'; // bool как int в MySQL
            } else {
                $types .= 's';
            }
        }
        return $types;
    }
    
    /**
     * Возвращает количество условий в фильтре
     * 
     * Используется для проверки, что фильтр не пустой (защита от случайного удаления всех записей)
     * 
     * @return int Количество условий
     */
    public function getConditionsCount(): int {
        return count($this->conditions);
    }
    
    /**
     * Сбрасывает все фильтры
     */
    public function reset() {
        $this->conditions = [];
        $this->params = [];
        return $this;
    }
}




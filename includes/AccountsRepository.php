<?php
/**
 * Репозиторий для работы с аккаунтами в базе данных
 * Содержит методы прямого доступа к БД без бизнес-логики
 * 
 * Отвечает за выполнение SQL-запросов и работу с данными.
 * Не содержит бизнес-логику - только операции с БД.
 * 
 * @package includes
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/FilterBuilder.php';
require_once __DIR__ . '/ColumnMetadata.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/AccountFingerprint.php';

class AccountsRepository {
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
     * Получение списка аккаунтов с фильтрами и пагинацией
     *
     * @param FilterBuilder $filter Фильтр
     * @param string $orderBy SQL выражение для ORDER BY
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @param bool $includeDeleted Включать ли удалённые записи
     * @return array
     */
    public function getAccounts(FilterBuilder $filter, string $orderBy, int $limit, int $offset, bool $includeDeleted = false): array {
        $meta = $this->metadata->getAllColumns();

        $validCols = [];
        foreach ($meta as $col) {
            if (!$this->metadata->columnExists($col)) {
                Logger::warning("Column '$col' does not exist in table '{$this->table}', skipping");
                continue;
            }
            $validCols[] = '`' . $col . '`';
        }

        if (empty($validCols)) {
            $validCols = ['`id`', '`login`', '`status`'];
            Logger::error("No valid columns found, using default columns");
        }

        $selectCols = implode(', ', $validCols);
        $where = $filter->getWhereClause($includeDeleted);
        $params = $filter->getParams();
        
        // Deferred join: внутренний подзапрос выбирает только id — MySQL не читает тяжёлые
        // TEXT/BLOB колонки (cookies, full_cookies, token и т.д.) при фильтрации и сортировке.
        // Полные данные подтягиваются JOIN-ом только для финальных LIMIT строк.
        $innerSql = "SELECT id FROM {$this->table} $where ORDER BY $orderBy LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;

        $sql = "SELECT $selectCols FROM {$this->table} "
             . "INNER JOIN ($innerSql) AS _page USING(id) "
             . "ORDER BY $orderBy";
        
        $cacheKey = null;
        if (Config::FEATURE_STATS_CACHING && $limit <= 100) {
            $cacheKey = 'accounts_' . md5($sql . serialize($params));
        }
        
        return $this->db->prepare($sql, $params, $cacheKey);
    }
    
    /**
     * Подсчет количества записей с фильтрами
     * 
     * @param FilterBuilder $filter Фильтр
     * @param bool $includeDeleted Включать ли удалённые записи
     * @return int
     */
    public function getAccountsCount(FilterBuilder $filter, bool $includeDeleted = false): int {
        $where = $filter->getWhereClause($includeDeleted);
        $params = $filter->getParams();
        $whereClause = str_replace('WHERE ', '', $where);
        
        return (int)$this->db->getCount($this->table, $whereClause, $params);
    }
    
    /**
     * Получение одной записи аккаунта по ID
     * 
     * @param int $id ID аккаунта
     * @return array|null
     */
    public function getAccountById(int $id): ?array {
        if ($id <= 0) {
            return null;
        }
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare select statement');
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to fetch account by id');
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Получение записей для проверки валидности (id, login, id_soc_account, social_url, cookies)
     * Используется для prepare перед вызовом NPPR fbchecker.
     *
     * @param array $ids Массив ID записей
     * @return array
     */
    public function getAccountsByIdsForValidation(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        $ids = array_map('intval', array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        $selectCols = $this->buildValidationSelectColumns();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deletedCond = $this->metadata->columnExists('deleted_at') ? ' AND (deleted_at IS NULL OR deleted_at = 0)' : '';
        $sql = "SELECT $selectCols FROM {$this->table} WHERE id IN ($placeholders)" . $deletedCond;
        $types = str_repeat('i', count($ids));
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare getAccountsByIdsForValidation');
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * SELECT-выражения для validation-запросов.
     * cookies — LONGTEXT, на FB-аккаунтах это 5–10KB JSON. Нам нужен только c_user,
     * который всегда в первых ~4KB. Обрезаем в БД, чтобы не таскать мегабайты данных.
     */
    private function buildValidationSelectColumns(): string {
        $cols = ['`id`', '`login`'];
        if ($this->metadata->columnExists('id_soc_account')) {
            $cols[] = '`id_soc_account`';
        }
        if ($this->metadata->columnExists('social_url')) {
            $cols[] = '`social_url`';
        }
        if ($this->metadata->columnExists('cookies')) {
            $cols[] = 'SUBSTRING(`cookies`, 1, ' . (int)Config::VALIDATE_COOKIES_TRUNCATE . ') AS `cookies`';
        }
        return implode(', ', $cols);
    }
    
    /**
     * Получение записей по фильтру для проверки валидности (только id, login, id_soc_account, cookies)
     *
     * @param FilterBuilder $filter
     * @param string $orderBy
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAccountsByFilterForValidation(FilterBuilder $filter, string $orderBy, int $limit, int $offset): array {
        $selectCols = $this->buildValidationSelectColumns();
        $where = $filter->getWhereClause(false);
        $params = $filter->getParams();
        $innerSql = "SELECT id FROM {$this->table} $where ORDER BY $orderBy LIMIT ? OFFSET ?";
        $params[] = (int) $limit;
        $params[] = (int) $offset;
        $sql = "SELECT $selectCols FROM {$this->table} INNER JOIN ($innerSql) AS _page USING(id) ORDER BY $orderBy";
        return $this->db->prepare($sql, $params);
    }
    
    /**
     * Обновление статуса для выбранных аккаунтов
     * 
     * @param array $ids Массив ID
     * @param string $status Новый статус
     * @return int Количество обновленных записей
     */
    public function updateStatus(array $ids, string $status): int {
        if (empty($ids) || $status === '') {
            throw new InvalidArgumentException('IDs and status are required');
        }

        // Валидация ID
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            throw new InvalidArgumentException('Valid IDs are required');
        }

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';

        // Проверяем наличие поля updated_at
        $updateTimestamp = $this->metadata->columnExists('updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';

        // Исключаем soft-deleted аккаунты из обновления статуса
        $softDeleteClause = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        $sql = "UPDATE {$this->table} SET status = ? $updateTimestamp WHERE id IN ($placeholders)$softDeleteClause";
        $params = array_merge([$status], $ids);

        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare update statement');
        }

        $types = 's' . str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to update status');
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        // Очищаем кэш после изменений
        $this->db->clearCache();

        return $affectedRows;
    }
    
    /**
     * Обновление статуса для всех записей по фильтру
     * 
     * @param FilterBuilder $filter Фильтр
     * @param string $status Новый статус
     * @return int Количество обновленных записей
     */
    public function updateStatusByFilter(FilterBuilder $filter, string $status): int {
        if ($status === '') {
            throw new InvalidArgumentException('Status is required');
        }
        
        // Защита от случайного обновления всех записей
        if ($filter->getConditionsCount() === 0) {
            throw new InvalidArgumentException('Filter is required for bulk update');
        }
        
        $where = $filter->getWhereClause();
        $params = $filter->getParams();
        
        // Добавляем условие - обновлять только те, у кого статус отличается
        // getWhereClause() возвращает либо пустую строку, либо строку с "WHERE" в начале
        if (empty($where)) {
            $where = 'WHERE (status IS NULL OR status <> ?)';
        } else {
            // $where уже содержит "WHERE", просто добавляем AND и наше условие
            $where .= ' AND (status IS NULL OR status <> ?)';
        }
        $params[] = $status;
        
        $updateTimestamp = $this->metadata->columnExists('updated_at') 
            ? ', updated_at = CURRENT_TIMESTAMP' 
            : '';
        
        $sql = "UPDATE {$this->table} SET status = ? $updateTimestamp $where";
        // Формируем массив параметров: сначала статус для SET, потом параметры фильтра (включая статус для WHERE)
        $allParams = array_merge([$status], $params);
        
        // Логируем для отладки (только если Logger доступен)
        if (class_exists('Logger')) {
            Logger::debug('UPDATE STATUS BY FILTER: SQL prepared', [
                'sql' => $sql,
                'params_count' => count($allParams),
                'status' => $status,
                'where_clause' => $where
            ]);
        }
        
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            $error = $this->db->getConnection()->error;
            if (class_exists('Logger')) {
                Logger::error('UPDATE STATUS BY FILTER: Prepare failed', [
                    'sql' => $sql,
                    'error' => $error
                ]);
            }
            throw new Exception('Failed to prepare update statement: ' . $error);
        }
        
        // Формируем строку типов: 's' для статуса в SET, потом типы для всех параметров фильтра
        $types = 's';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        
        // Проверяем соответствие количества параметров и типов
        if (strlen($types) !== count($allParams)) {
            $stmt->close();
            $errorMsg = 'Parameter count mismatch: types=' . strlen($types) . ', params=' . count($allParams);
            if (class_exists('Logger')) {
                Logger::error('UPDATE STATUS BY FILTER: Parameter mismatch', [
                    'types' => $types,
                    'types_length' => strlen($types),
                    'params_count' => count($allParams),
                    'params' => $allParams
                ]);
            }
            throw new Exception($errorMsg);
        }
        
        $stmt->bind_param($types, ...$allParams);
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            if (class_exists('Logger')) {
                Logger::error('UPDATE STATUS BY FILTER: Execute failed', [
                    'sql' => $sql,
                    'error' => $error,
                    'types' => $types,
                    'params_count' => count($allParams)
                ]);
            }
            throw new Exception('Failed to update status by filter: ' . $error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        $this->db->clearCache();
        
        return $affectedRows;
    }
    
    /**
     * Нормализация значения по типу колонки
     * Приводит значение к правильному типу на основе метаданных колонки
     * 
     * @param string $field Имя поля
     * @param mixed $value Значение для нормализации
     * @return array Массив с нормализованным значением и типом для bind_param ['value' => mixed, 'type' => string]
     */
    private function normalizeValueByColumnType(string $field, $value): array {
        $columnInfo = $this->metadata->getColumn($field);
        
        if (!$columnInfo) {
            // Если метаданные недоступны, возвращаем как строку
            return ['value' => (string)$value, 'type' => 's'];
        }
        
        $columnType = strtolower($columnInfo['type']);
        $isNullable = $columnInfo['null'] === 'YES';
        $numericCols = $this->metadata->getNumericColumns();
        $isNumeric = in_array($field, $numericCols, true);
        
        // Обработка пустых значений
        if ($value === '' || $value === null) {
            if ($isNumeric) {
                // Для числовых полей: NULL если разрешено, иначе 0
                if ($isNullable) {
                    return ['value' => null, 'type' => 's']; // NULL будет обработан специально в bind_param
                } else {
                    return ['value' => 0, 'type' => 'i'];
                }
            } else {
                // Для текстовых полей: пустая строка или NULL
                if ($isNullable) {
                    return ['value' => null, 'type' => 's'];
                } else {
                    return ['value' => '', 'type' => 's'];
                }
            }
        } elseif ($value === '0' && $isNumeric) {
            // Специальная обработка для строки '0' в числовых полях
            if (preg_match('/(decimal|float|double|numeric)/', $columnType)) {
                return ['value' => 0.0, 'type' => 'd'];
            } else {
                return ['value' => 0, 'type' => 'i'];
            }
        }
        
        // Нормализация числовых полей
        if ($isNumeric) {
            // Проверяем, является ли значение числом или строкой с числом
            if (is_numeric($value)) {
                // Определяем тип числа на основе типа колонки
                if (preg_match('/(decimal|float|double|numeric)/', $columnType)) {
                    // Для десятичных чисел
                    $normalized = (float)$value;
                    return ['value' => $normalized, 'type' => 'd'];
                } else {
                    // Для целых чисел
                    $normalized = (int)$value;
                    return ['value' => $normalized, 'type' => 'i'];
                }
            } else {
                // Если значение не числовое, но поле числовое - пытаемся преобразовать
                // Убираем пробелы и проверяем снова
                $trimmed = trim((string)$value);
                if ($trimmed === '' || $trimmed === null) {
                    if ($isNullable) {
                        return ['value' => null, 'type' => 's'];
                    } else {
                        return ['value' => 0, 'type' => 'i'];
                    }
                }
                // Если после trim все еще не число - выбрасываем исключение
                throw new InvalidArgumentException("Value for numeric field '{$field}' must be a number, got: " . gettype($value));
            }
        }
        
        // Для текстовых полей просто приводим к строке
        return ['value' => (string)$value, 'type' => 's'];
    }
    
    /**
     * Обновление одного поля для одной записи
     * 
     * @param int $id ID записи
     * @param string $field Имя поля
     * @param mixed $value Значение
     * @return int Количество обновленных записей
     */
    public function updateField(int $id, string $field, $value): int {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid ID');
        }

        if (!$this->metadata->columnExists($field)) {
            throw new InvalidArgumentException('Invalid field');
        }

        // Запрещенные поля
        if ($field === 'id') {
            throw new InvalidArgumentException('Field is read-only');
        }

        // Нормализуем значение по типу колонки
        $normalized = $this->normalizeValueByColumnType($field, $value);
        $normalizedValue = $normalized['value'];
        $valueType = $normalized['type'];
        
        // Исключаем soft-deleted аккаунты из обновления поля
        $softDeleteClause = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        $sql = "UPDATE {$this->table} SET `{$field}` = ? WHERE `id` = ?$softDeleteClause";
        $stmt = $this->db->getConnection()->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Failed to prepare update statement');
        }
        
        // Обработка NULL значений - в mysqli NULL передается через специальную переменную
        if ($normalizedValue === null) {
            // Для NULL используем строковый тип, но передаем null
            $nullVar = null;
            $stmt->bind_param('si', $nullVar, $id);
        } else {
            // Используем нормализованный тип для значения и 'i' для ID
            $paramType = $valueType . 'i';
            $stmt->bind_param($paramType, $normalizedValue, $id);
        }
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to update field: ' . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        // Очищаем кэш после изменений
        $this->db->clearCache();

        return $affectedRows;
    }

    /**
     * Массовое обновление поля
     *
     * @param array $ids Массив ID
     * @param string $field Имя поля
     * @param mixed $value Значение
     * @return int Количество обновленных записей
     */
    public function bulkUpdateField(array $ids, string $field, $value): int {
        if (empty($ids) || $field === '') {
            throw new InvalidArgumentException('IDs and field are required');
        }

        if (!$this->metadata->columnExists($field)) {
            throw new InvalidArgumentException('Invalid field name');
        }

        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            throw new InvalidArgumentException('Valid IDs are required');
        }

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        $updateTimestamp = $this->metadata->columnExists('updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';
        
        // Нормализуем значение по типу колонки
        $normalized = $this->normalizeValueByColumnType($field, $value);
        $normalizedValue = $normalized['value'];
        $valueType = $normalized['type'];
        
        // Исключаем soft-deleted аккаунты из массового обновления
        $softDeleteClause = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        $sql = "UPDATE {$this->table} SET `$field` = ? $updateTimestamp WHERE id IN ($placeholders)$softDeleteClause";
        
        // Обработка NULL для массового обновления
        if ($normalizedValue === null) {
            $params = array_merge([null], $ids);
            $types = 's' . str_repeat('i', count($ids));
        } else {
            $params = array_merge([$normalizedValue], $ids);
            $types = $valueType . str_repeat('i', count($ids));
        }
        
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare update statement');
        }
        
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to bulk update field');
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        $this->db->clearCache();
        
        return $affectedRows;
    }
    
    /**
     * Массовое обновление произвольного поля по фильтру
     * 
     * @param FilterBuilder $filter Фильтр
     * @param string $field Имя поля
     * @param mixed $value Значение
     * @return int Количество обновленных записей
     */
    public function updateFieldByFilter(FilterBuilder $filter, string $field, $value): int {
        if ($field === '') {
            throw new InvalidArgumentException('Field is required');
        }
        if ($filter->getConditionsCount() === 0) {
            throw new InvalidArgumentException('Filter is required for bulk update');
        }
        if (!$this->metadata->columnExists($field)) {
            throw new InvalidArgumentException('Invalid field name');
        }
        if ($field === 'id') {
            throw new InvalidArgumentException('Field is read-only');
        }

        $conn = $this->db->getConnection();
        $conn->begin_transaction();

        try {
            $where = $filter->getWhereClause();
            $params = $filter->getParams();

            // Нормализуем значение по типу колонки
            $normalized = $this->normalizeValueByColumnType($field, $value);
            $normalizedValue = $normalized['value'];
            $valueType = $normalized['type'];

            $updateTimestamp = $this->metadata->columnExists('updated_at')
                ? ', updated_at = CURRENT_TIMESTAMP'
                : '';

            $sql = "UPDATE {$this->table} SET `$field` = ? $updateTimestamp $where";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare update statement');
            }

            // Типы: сначала нормализованное значение, затем параметры фильтра
            $types = $valueType . $filter->getParamTypes();
            $allParams = array_merge([$normalizedValue], $params);
            $stmt->bind_param($types, ...$allParams);

            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to bulk update field by filter');
            }

            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            $this->db->clearCache();

            $conn->commit();
            return $affectedRows;
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        } finally {
            // Ensure rollback if transaction is still active (in case of unexpected exit/errors)
            if ($conn && $conn->in_transaction) {
                $conn->rollback();
            }
        }
    }
    
    /**
     * Массовое обновление поля по всей таблице
     * 
     * @param string $field Имя поля
     * @param mixed $value Значение
     * @return int Количество обновленных записей
     */
    public function updateFieldForAll(string $field, $value): int {
        if ($field === '') {
            throw new InvalidArgumentException('Field is required');
        }
        if (!$this->metadata->columnExists($field)) {
            throw new InvalidArgumentException('Invalid field name');
        }
        if ($field === 'id') {
            throw new InvalidArgumentException('Field is read-only');
        }

        $updateTimestamp = $this->metadata->columnExists('updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';

        // Нормализуем значение по типу колонки
        $normalized = $this->normalizeValueByColumnType($field, $value);
        $normalizedValue = $normalized['value'];
        $valueType = $normalized['type'];

        // Исключаем удалённые аккаунты из глобального обновления
        $softDeleteClause = $this->metadata->columnExists('deleted_at') ? ' WHERE deleted_at IS NULL' : '';
        $sql = "UPDATE {$this->table} SET `$field` = ? $updateTimestamp$softDeleteClause";
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare global update statement');
        }

        // Используем нормализованный тип для значения
        if ($normalizedValue === null) {
            $nullVar = null;
            $types = 's';
            $stmt->bind_param($types, $nullVar);
        } else {
            $types = $valueType;
            $stmt->bind_param($types, $normalizedValue);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to update field globally');
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        $this->db->clearCache();

        Logger::info('BULK UPDATE: updateFieldForAll executed', [
            'field' => $field,
            'affected' => $affectedRows
        ]);

        return $affectedRows;
    }
    
    /**
     * Удаление аккаунтов по ID (Soft Delete - в корзину)
     * 
     * @param array $ids Массив ID
     * @return int Количество удаленных записей
     */
    /**
     * Очистка связанных данных при удалении аккаунтов
     * 
     * @param array $ids Массив ID аккаунтов
     * @return void
     */
    private function cleanupRelatedData(array $ids): void {
        if (empty($ids)) {
            return;
        }
        
        $mysqli = $this->db->getConnection();
        if (!($mysqli instanceof mysqli)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        // Удаляем из избранного
        $sql = "DELETE FROM account_favorites WHERE account_id IN ($placeholders)";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $stmt->close();
        }
        
        // Удаляем историю изменений (опционально, можно оставить для аудита)
        // Раскомментируйте, если нужно удалять историю:
        // $sql = "DELETE FROM account_history WHERE account_id IN ($placeholders)";
        // $stmt = $mysqli->prepare($sql);
        // if ($stmt) {
        //     $stmt->bind_param($types, ...$ids);
        //     $stmt->execute();
        //     $stmt->close();
        // }
    }
    
    public function deleteAccounts(array $ids): int {
        if (empty($ids)) {
            throw new InvalidArgumentException('IDs are required');
        }
        
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            throw new InvalidArgumentException('Valid IDs are required');
        }
        
        // Проверяем, поддерживается ли Soft Delete
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        if ($supportsSoftDelete) {
            // Soft Delete - помечаем как удалённые
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $sql = "UPDATE {$this->table} SET deleted_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders) AND deleted_at IS NULL";
            
            $stmt = $this->db->getConnection()->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare delete statement');
            }
            
            $types = str_repeat('i', count($ids));
            $stmt->bind_param($types, ...$ids);
            
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to delete accounts');
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
        } else {
            // Hard Delete - физическое удаление
            // Сначала очищаем связанные данные
            $this->cleanupRelatedData($ids);
            
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $sql = "DELETE FROM {$this->table} WHERE id IN ($placeholders)";
            
            $stmt = $this->db->getConnection()->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare delete statement');
            }
            
            $types = str_repeat('i', count($ids));
            $stmt->bind_param($types, ...$ids);
            
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to delete accounts');
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
        }
        
        $this->db->clearCache();
        
        return $affectedRows;
    }
    
    /**
     * Удаление аккаунтов по фильтру (Soft Delete - в корзину)
     * 
     * @param FilterBuilder $filter Фильтр
     * @return int Количество удаленных записей
     */
    public function deleteAccountsByFilter(FilterBuilder $filter): int {
        // Защита от случайного удаления всех записей
        if ($filter->getConditionsCount() === 0) {
            throw new InvalidArgumentException('Filter is required for bulk delete');
        }
        
        // Проверяем, поддерживается ли Soft Delete
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        $where = $filter->getWhereClause(false); // Не включаем удалённые в фильтр
        $params = $filter->getParams();
        
        if ($supportsSoftDelete) {
            // Soft Delete - помечаем как удалённые
            $whereClause = str_replace('WHERE ', '', $where);
            $sql = "UPDATE {$this->table} SET deleted_at = CURRENT_TIMESTAMP WHERE $whereClause AND deleted_at IS NULL";
        } else {
            // Hard Delete - физическое удаление
            $sql = "DELETE FROM {$this->table} $where";
        }
        
        $stmt = $this->db->getConnection()->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Failed to prepare delete statement');
        }
        
        if ($params) {
            $types = '';
            foreach ($params as $p) { 
                $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's'); 
            }
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to delete accounts by filter');
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        $this->db->clearCache();
        
        return $affectedRows;
    }
    
    /**
     * Восстановление аккаунтов из корзины (Soft Delete)
     * 
     * @param array $ids Массив ID аккаунтов для восстановления
     * @return int Количество восстановленных аккаунтов
     */
    public function restoreAccounts(array $ids): int {
        if (empty($ids)) {
            throw new InvalidArgumentException('IDs are required');
        }
        
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            throw new InvalidArgumentException('Valid IDs are required');
        }
        
        // Проверяем, поддерживается ли Soft Delete
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        if (!$supportsSoftDelete) {
            throw new Exception('Soft Delete не поддерживается. Поле deleted_at не существует.');
        }
        
        // Проверяем, что аккаунты действительно удалены
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        // Для TIMESTAMP колонки достаточно проверки IS NOT NULL (пустая строка там быть не может)
        $checkSql = "SELECT id FROM {$this->table} WHERE id IN ($placeholders) AND deleted_at IS NOT NULL";
        $checkStmt = $this->db->getConnection()->prepare($checkSql);
        
        if (!$checkStmt) {
            throw new Exception('Failed to prepare check statement');
        }
        
        $types = str_repeat('i', count($ids));
        $checkStmt->bind_param($types, ...$ids);
        
        if (!$checkStmt->execute()) {
            $checkStmt->close();
            throw new Exception('Failed to execute check statement');
        }
        
        $result = $checkStmt->get_result();
        
        $validIds = [];
        while ($row = $result->fetch_assoc()) {
            $validIds[] = (int)$row['id'];
        }
        $checkStmt->close();
        
        if (empty($validIds)) {
            return 0; // Нет удалённых аккаунтов для восстановления
        }
        
        // Восстанавливаем аккаунты (устанавливаем deleted_at в NULL)
        $updateTimestamp = $this->metadata->columnExists('updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';
        
        $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
        $sql = "UPDATE {$this->table} SET deleted_at = NULL $updateTimestamp WHERE id IN ($placeholders)";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare restore statement');
        }
        
        $types = str_repeat('i', count($validIds));
        $stmt->bind_param($types, ...$validIds);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to restore accounts');
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        $this->db->clearCache();
        
        return $affectedRows;
    }
    
    /**
     * Создание нового аккаунта
     * 
     * @param array $data Массив данных аккаунта (ключ - имя поля, значение - значение)
     * @return int ID созданного аккаунта
     * @throws InvalidArgumentException При ошибках валидации или дубликатах
     * @throws Exception При ошибках БД
     */
    public function createAccount(array $data): int {
        // Валидация обязательных полей
        if (empty($data['login']) || trim((string)$data['login']) === '') {
            throw new InvalidArgumentException('Login is required');
        }
        
        if (empty($data['status']) || trim((string)$data['status']) === '') {
            throw new InvalidArgumentException('Status is required');
        }
        
        $conn = $this->db->getConnection();
        $loginValue = trim((string)$data['login']);

        // Дедуп по fingerprint: совпадение login / id_soc_account / FB ID из
        // social_url / c_user внутри cookies = тот же FB-аккаунт под другим
        // именем. Один SELECT даже на 10K строк отрабатывает <100ms.
        $existingMatch = $this->findExistingByFingerprint($data);
        if ($existingMatch !== null) {
            $kind = $existingMatch['match_kind'];
            $val  = $existingMatch['match_value'];
            throw new InvalidArgumentException(
                "Аккаунт '{$loginValue}' дублирует существующий #{$existingMatch['id']} ('{$existingMatch['login']}') по совпадению {$kind}: {$val}"
            );
        }
        
        // Фильтруем данные: убираем системные поля и проверяем существование колонок
        $allowedFields = [];
        $fieldData = []; // Массив с данными полей [field => normalized_value]
        $types = '';
        
        foreach ($data as $field => $value) {
            // Пропускаем системные поля
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            
            // Проверяем существование колонки
            if (!$this->metadata->columnExists($field)) {
                Logger::warning("Column '$field' does not exist, skipping", ['field' => $field]);
                continue;
            }
            
            // Нормализуем значение по типу колонки
            try {
                $normalized = $this->normalizeValueByColumnType($field, $value);
                $allowedFields[] = $field;
                $fieldData[$field] = $normalized;
                $types .= $normalized['type'];
            } catch (InvalidArgumentException $e) {
                // Если не удалось нормализовать значение для числового поля - пропускаем поле
                Logger::warning("Failed to normalize value for field '$field', skipping", [
                    'field' => $field,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        if (empty($allowedFields)) {
            throw new InvalidArgumentException('No valid fields to insert');
        }
        
        // Начинаем транзакцию
        $conn->begin_transaction();
        
        try {
            // Формируем SQL запрос для INSERT
            $fieldsList = '`' . implode('`, `', $allowedFields) . '`';
            $placeholders = str_repeat('?,', count($allowedFields) - 1) . '?';
            
            $sql = "INSERT INTO {$this->table} ($fieldsList) VALUES ($placeholders)";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare insert statement: ' . $conn->error);
            }
            
            // Привязываем параметры с учетом NULL значений
            // Пересоздаем types строку и массив параметров в правильном порядке
            $paramTypes = '';
            $paramValues = [];
            
            foreach ($allowedFields as $field) {
                $normalized = $fieldData[$field];
                $val = $normalized['value'];
                
                if ($val === null) {
                    // Для NULL в mysqli нужно использовать тип 's' и переменную null
                    $paramTypes .= 's';
                    $paramValues[] = null;
                } else {
                    // Используем тип из нормализации (i, d, s)
                    $paramTypes .= $normalized['type'];
                    $paramValues[] = $val;
                }
            }
            
            // В mysqli bind_param требует ссылки на переменные
            // Но spread operator работает с NULL значениями в PHP 7.1+
            // Для надежности используем прямой вызов с spread operator
            if (count($paramValues) > 0) {
                $stmt->bind_param($paramTypes, ...$paramValues);
            }
            
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new Exception('Failed to execute insert statement: ' . $error);
            }
            
            $newId = (int)$conn->insert_id;
            $stmt->close();
            
            // Коммитим транзакцию
            $conn->commit();
            
            // Очищаем кэш после создания
            $this->db->clearCache();
            
            Logger::info('Account created successfully', [
                'id' => $newId,
                'login' => $loginValue
            ]);
            
            return $newId;
            
        } catch (Exception $e) {
            // Откатываем транзакцию при ошибке
            $conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Массовое создание аккаунтов
     * 
     * @param array $accountsData Массив массивов данных аккаунтов
     * @param string $duplicateAction Действие при дубликате: 'skip', 'error', 'update'
     * @return array Статистика: [
     *   'created' => int,
     *   'updated' => int, 
     *   'skipped' => int,
     *   'skipped_details' => array, // Детали пропущенных: [{row, login, reason, message}]
     *   'errors' => array,
     *   'created_ids' => array
     * ]
     * @throws Exception При ошибках БД
     */
    public function createAccountsBulk(array $accountsData, string $duplicateAction = 'skip'): array {
        if (empty($accountsData)) {
            throw new InvalidArgumentException('Accounts data is required');
        }
        
        // Поддержка нового режима 'update' для обновления существующих записей
        $duplicateAction = in_array($duplicateAction, ['skip', 'error', 'update'], true) ? $duplicateAction : 'skip';
        
        $conn = $this->db->getConnection();
        
        $created = 0;
        $skipped = 0;
        $updated = 0;
        $errors = [];
        $skippedDetails = []; // НОВОЕ: Детали пропущенных строк
        $createdIds = [];
        $updatedLogins = []; // Для audit log: логины обновлённых аккаунтов
        
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');

        // ОПТИМИЗАЦИЯ: один SELECT собирает fingerprint-индекс всей БД, дальше
        // проверка дубликата для каждой строки — O(1) lookup в массиве.
        // Fingerprint включает login + id_soc_account + FB ID из social_url +
        // c_user из cookies — см. AccountFingerprint::extract().
        $fingerprintIndex = $this->getExistingFingerprintIndex();

        Logger::info('CREATE ACCOUNTS BULK: Начало импорта', [
            'total_rows' => count($accountsData),
            'duplicate_action' => $duplicateAction,
            'existing_fingerprints' => count($fingerprintIndex)
        ]);
        
        // Батчевые транзакции: одна транзакция на IMPORT_BATCH_TX_SIZE строк.
        // Внутри каждой транзакции используются savepoints для изоляции ошибок отдельных строк —
        // ошибка в одной строке не откатывает весь батч.
        $importBatchSize = 500;
        $batchRowIdx = 0;      // счётчик строк от начала файла
        $batchTxOpen = false;  // открыта ли сейчас транзакция
        $batchSpName = '';     // имя текущего savepoint
        
        foreach ($accountsData as $rowNum => $data) {
            // Открываем новую транзакцию в начале каждого батча
            if ($batchRowIdx % $importBatchSize === 0) {
                if ($batchTxOpen) {
                    $conn->commit();
                }
                $conn->begin_transaction();
                $batchTxOpen = true;
            }
            // Savepoint для изоляции ошибок отдельной строки внутри батча
            $batchSpName = 'sp_row_' . $batchRowIdx;
            $conn->savepoint($batchSpName);
            $batchRowIdx++;
                try {
                    Logger::debug('CREATE ACCOUNTS BULK: Обработка строки', [
                        'row_num' => $rowNum + 1,
                        'data_keys' => array_keys($data),
                        'data' => $data
                    ]);
                    
                    // Валидация обязательных полей
                    $loginValue = isset($data['login']) ? trim((string)$data['login']) : '';
                    $statusValue = isset($data['status']) ? trim((string)$data['status']) : '';
                    
                    Logger::debug('CREATE ACCOUNTS BULK: Проверка обязательных полей', [
                        'row_num' => $rowNum + 1,
                        'login' => $loginValue,
                        'login_empty' => empty($loginValue),
                        'status' => $statusValue,
                        'status_empty' => empty($statusValue)
                    ]);
                    
                    if (empty($loginValue)) {
                        Logger::warning('CREATE ACCOUNTS BULK: Login пустой', ['row' => $rowNum + 1, 'data' => $data]);
                        $errors[] = [
                            'row' => $rowNum + 1,
                            'message' => 'Login is required'
                        ];
                        $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                        continue;
                    }
                    
                    if (empty($statusValue)) {
                        Logger::warning('CREATE ACCOUNTS BULK: Status пустой', ['row' => $rowNum + 1, 'data' => $data]);
                        $errors[] = [
                            'row' => $rowNum + 1,
                            'message' => 'Status is required'
                        ];
                        $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                        continue;
                    }
                    
                    // Фильтруем и нормализуем данные СНАЧАЛА (для использования в update)
                    $allowedFields = [];
                    $fieldData = [];
                    
                    foreach ($data as $field => $value) {
                        if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                            continue;
                        }
                        
                        if (!$this->metadata->columnExists($field)) {
                            continue;
                        }
                        
                        try {
                            $normalized = $this->normalizeValueByColumnType($field, $value);
                            $allowedFields[] = $field;
                            $fieldData[$field] = $normalized;
                        } catch (InvalidArgumentException $e) {
                            continue;
                        }
                    }
                    
                    // Проверяем обязательные поля (NOT NULL без DEFAULT) и добавляем значения по умолчанию
                    $allColumns = $this->metadata->getAllColumns();
                    $metadataInfo = $this->metadata->getMetadata();
                    
                    foreach ($allColumns as $columnName) {
                        // Пропускаем системные поля и уже обработанные поля
                        if (in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                            continue;
                        }
                        
                        if (in_array($columnName, $allowedFields, true)) {
                            continue; // Поле уже есть в данных
                        }
                        
                        $columnInfo = $this->metadata->getColumn($columnName);
                        if (!$columnInfo) {
                            continue;
                        }
                        
                        // Проверяем, является ли поле обязательным (NOT NULL) без DEFAULT
                        $isNullable = $columnInfo['null'] === 'YES';
                        $hasDefault = $columnInfo['default'] !== null || 
                                     stripos($columnInfo['extra'] ?? '', 'auto_increment') !== false ||
                                     stripos($columnInfo['extra'] ?? '', 'on update') !== false;
                        
                        // Если поле NOT NULL и без DEFAULT, добавляем значение по умолчанию
                        if (!$isNullable && !$hasDefault) {
                            Logger::debug('CREATE ACCOUNTS BULK: Добавление обязательного поля без DEFAULT', [
                                'row' => $rowNum + 1,
                                'field' => $columnName,
                                'column_info' => $columnInfo
                            ]);
                            
                            try {
                                // Устанавливаем значение по умолчанию в зависимости от типа
                                $columnType = strtolower($columnInfo['type'] ?? '');
                                $defaultValue = '';
                                
                                if (preg_match('/(int|decimal|float|double|numeric)/', $columnType)) {
                                    $defaultValue = 0;
                                } elseif (preg_match('/(char|varchar|text)/', $columnType)) {
                                    $defaultValue = '';
                                }
                                
                                $normalized = $this->normalizeValueByColumnType($columnName, $defaultValue);
                                $allowedFields[] = $columnName;
                                $fieldData[$columnName] = $normalized;
                            } catch (Exception $e) {
                                Logger::warning('CREATE ACCOUNTS BULK: Ошибка при добавлении обязательного поля', [
                                    'row' => $rowNum + 1,
                                    'field' => $columnName,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
                    
                    if (empty($allowedFields)) {
                        $errors[] = [
                            'row' => $rowNum + 1,
                            'message' => 'No valid fields to insert'
                        ];
                        $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                        continue;
                    }
                    
                    // Проверяем дубликат по fingerprint: совпадение login / id_soc_account /
                    // FB ID из social_url / c_user из cookies = дубль того же FB-аккаунта.
                    // Берём raw $data (а не нормализованный fieldData) — extract сам нормализует.
                    $newTokens = AccountFingerprint::extract($data);
                    $matchedToken = null;
                    $matchedExisting = null;
                    foreach ($newTokens as $tok) {
                        if (isset($fingerprintIndex[$tok])) {
                            $matchedToken = $tok;
                            $matchedExisting = $fingerprintIndex[$tok];
                            break;
                        }
                    }
                    $isDuplicate = $matchedExisting !== null;

                    if ($isDuplicate) {
                        // Расшифровка типа совпадения для понятного сообщения юзеру.
                        $matchKind = strpos($matchedToken, 'login:') === 0 ? 'login'
                                   : (strpos($matchedToken, 'fbid:') === 0 ? 'FB ID' : 'fingerprint');
                        $matchValue = substr($matchedToken, strpos($matchedToken, ':') + 1);

                        Logger::debug('CREATE ACCOUNTS BULK: Дубликат найден', [
                            'row' => $rowNum + 1,
                            'login' => $loginValue,
                            'match_kind' => $matchKind,
                            'match_value' => $matchValue,
                            'existing_id' => $matchedExisting['id'],
                            'existing_login' => $matchedExisting['login'],
                            'duplicate_action' => $duplicateAction
                        ]);

                        if ($duplicateAction === 'error') {
                            $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                            $errors[] = [
                                'row' => $rowNum + 1,
                                'message' => "Аккаунт '{$loginValue}' дублирует существующий #{$matchedExisting['id']} ('{$matchedExisting['login']}') по совпадению {$matchKind}: {$matchValue}"
                            ];
                            continue;
                        } elseif ($duplicateAction === 'update') {
                            // Обновляем существующую запись по её login (берём из индекса —
                            // могла быть найдена не по login, а по id_soc_account/cookies).
                            $existingLoginForUpdate = $matchedExisting['login'];
                            try {
                                $this->updateAccountByLogin($existingLoginForUpdate, $data, $allowedFields, $fieldData);
                                $conn->release_savepoint($batchSpName);
                                $updated++;
                                $updatedLogins[] = $existingLoginForUpdate;
                                Logger::info('CREATE ACCOUNTS BULK: Запись обновлена', [
                                    'row' => $rowNum + 1,
                                    'login' => $loginValue,
                                    'existing_login' => $existingLoginForUpdate,
                                    'match_kind' => $matchKind,
                                ]);
                            } catch (Exception $updateError) {
                                $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                                $errors[] = [
                                    'row' => $rowNum + 1,
                                    'message' => 'Failed to update: ' . $updateError->getMessage()
                                ];
                            }
                            continue;
                        } else {
                            // skip mode — пропускаем дубликат, кладём в skipped_details
                            // подробности (какой существующий аккаунт и по чему совпало),
                            // чтобы юзер увидел в результатах импорта что именно дублируется.
                            $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                            $skipped++;
                            $skippedDetails[] = [
                                'row' => $rowNum + 1,
                                'login' => $loginValue,
                                'reason' => 'Duplicate (' . $matchKind . ')',
                                'message' => "Аккаунт '{$loginValue}' дублирует существующий #{$matchedExisting['id']} ('{$matchedExisting['login']}') по совпадению {$matchKind}: {$matchValue}",
                                'existing_id'    => $matchedExisting['id'],
                                'existing_login' => $matchedExisting['login'],
                                'match_kind'     => $matchKind,
                                'match_value'    => $matchValue,
                            ];
                            Logger::debug('CREATE ACCOUNTS BULK: Дубликат пропущен (skip)', [
                                'row' => $rowNum + 1,
                                'login' => $loginValue,
                                'match_kind' => $matchKind,
                            ]);
                            continue;
                        }
                    }
                    
                    // Формируем и выполняем INSERT
                    $fieldsList = '`' . implode('`, `', $allowedFields) . '`';
                    $placeholders = str_repeat('?,', count($allowedFields) - 1) . '?';
                    $sql = "INSERT INTO {$this->table} ($fieldsList) VALUES ($placeholders)";
                    
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $errors[] = [
                            'row' => $rowNum + 1,
                            'message' => 'Failed to prepare insert statement: ' . $conn->error
                        ];
                        $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                        continue;
                    }
                    
                    // Привязываем параметры
                    $paramTypes = '';
                    $paramValues = [];
                    
                    foreach ($allowedFields as $field) {
                        $normalized = $fieldData[$field];
                        $val = $normalized['value'];
                        
                        if ($val === null) {
                            $paramTypes .= 's';
                            $paramValues[] = null;
                        } else {
                            $paramTypes .= $normalized['type'];
                            $paramValues[] = $val;
                        }
                    }
                    
                    if (count($paramValues) > 0) {
                        if (!$stmt->bind_param($paramTypes, ...$paramValues)) {
                            $error = $stmt->error;
                            $errorCode = $stmt->errno;
                            $stmt->close();
                            $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                            Logger::error('CREATE ACCOUNTS BULK: Failed to bind parameters', [
                                'row' => $rowNum + 1,
                                'error_code' => $errorCode,
                                'error' => $error
                            ]);
                            $errors[] = [
                                'row' => $rowNum + 1,
                                'message' => 'Failed to bind parameters: ' . $error
                            ];
                            continue;
                        }
                    }

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $errorCode = $stmt->errno; // Исправлено: используем errno от statement, а не от connection
                        $stmt->close();

                        // Проверяем, является ли ошибка дубликатом уникального ключа (error code 1062)
                        // или нарушением уникального индекса (error code 1169)
                        $isDuplicateError = ($errorCode === 1062 || $errorCode === 1169) ||
                                           (stripos($error, 'Duplicate entry') !== false) ||
                                           (stripos($error, 'duplicate') !== false && stripos($error, 'login') !== false);

                        Logger::info('CREATE ACCOUNTS BULK: Ошибка при вставке', [
                            'row' => $rowNum + 1,
                            'login' => $loginValue,
                            'stmt_error_code' => $errorCode,
                            'conn_error_code' => $conn->errno,
                            'error' => $error,
                            'is_duplicate' => $isDuplicateError,
                            'duplicate_action' => $duplicateAction
                        ]);

                        // откат только этой строки через savepoint
                        $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");

                        if ($isDuplicateError) {
                            // Это дубликат login - обрабатываем в зависимости от duplicateAction
                            if ($duplicateAction === 'error') {
                                $errors[] = [
                                    'row' => $rowNum + 1,
                                    'message' => "Account with login '{$loginValue}' already exists"
                                ];
                                Logger::debug('CREATE ACCOUNTS BULK: Дубликат добавлен в ошибки', [
                                    'row' => $rowNum + 1,
                                    'login' => $loginValue
                                ]);
                            } else {
                                // Пропускаем дубликат
                                $skipped++;
                                $skippedDetails[] = [
                                    'row' => $rowNum + 1,
                                    'login' => $loginValue,
                                    'reason' => 'Duplicate login (INSERT)',
                                    'message' => "Аккаунт с логином '{$loginValue}' уже существует в базе данных"
                                ];
                                Logger::info('CREATE ACCOUNTS BULK: Дубликат пропущен (skip mode)', [
                                    'row' => $rowNum + 1,
                                    'login' => $loginValue
                                ]);
                            }
                        } else {
                            // Другая ошибка БД
                            Logger::error('CREATE ACCOUNTS BULK: Другая ошибка при вставке', [
                                'row' => $rowNum + 1,
                                'error_code' => $errorCode,
                                'error' => $error
                            ]);
                            $errors[] = [
                                'row' => $rowNum + 1,
                                'message' => 'Failed to execute insert: ' . $error
                            ];
                        }
                        continue;
                    }
                    
                    $newId = (int)$conn->insert_id;
                    $stmt->close();

                    // Фиксируем savepoint этой строки (данные войдут в батч-коммит)
                    $conn->release_savepoint($batchSpName);

                    $created++;
                    $createdIds[] = $newId;

                    // Intra-batch dedup: добавляем все токены новой строки в индекс,
                    // чтобы вторая строка в файле с тем же FB ID (но другим login)
                    // была корректно опознана как дубль.
                    $insertedPayload = ['id' => $newId, 'login' => $loginValue];
                    foreach ($newTokens as $tok) {
                        if (!isset($fingerprintIndex[$tok])) {
                            $fingerprintIndex[$tok] = $insertedPayload;
                        }
                    }

                    Logger::debug('CREATE ACCOUNTS BULK: Строка успешно добавлена', [
                        'row' => $rowNum + 1,
                        'id' => $newId,
                        'login' => $loginValue
                    ]);
                    
                } catch (Exception $e) {
                    // Откатываем только эту строку, остальные в батче сохраняются
                    $conn->query("ROLLBACK TO SAVEPOINT $batchSpName");
                    
                    Logger::error('CREATE ACCOUNTS BULK: Исключение при обработке строки', [
                        'row' => $rowNum + 1,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors[] = [
                        'row' => $rowNum + 1,
                        'message' => $e->getMessage()
                    ];
                    // Продолжаем обработку остальных строк
                }
            }
        
        // Коммитим последний открытый батч
        if ($batchTxOpen) {
            $conn->commit();
        }
            
            // Очищаем кэш после создания
            $this->db->clearCache();
            
            Logger::info('Bulk account creation completed', [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'skipped_details_count' => count($skippedDetails),
                'errors' => count($errors),
                'total' => count($accountsData)
            ]);
            
            return [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'skipped_details' => $skippedDetails, // НОВОЕ: Детали пропущенных
                'errors' => $errors,
                'created_ids' => $createdIds,
                'updated_logins' => $updatedLogins
            ];
    }
    
    /**
     * Точечная проверка на дубликат для одиночного createAccount.
     * Делает узкий SELECT по индексу login + один по id_soc_account + один по
     * c_user (если найден в cookies нового аккаунта). Не строит индекс по всей
     * БД — для одиночного создания это перебор.
     *
     * @return array{id:int, login:string, match_kind:string, match_value:string}|null
     */
    public function findExistingByFingerprint(array $row): ?array {
        $conn = $this->db->getConnection();
        $hasDeleted = $this->metadata->columnExists('deleted_at');
        $deletedClause = $hasDeleted ? ' AND deleted_at IS NULL' : '';

        // 1. Точное совпадение по login (case-insensitive — MySQL collation
        // utf8mb4_general_ci/0900_ai_ci уже делает это автоматически).
        $login = trim((string)($row['login'] ?? ''));
        if ($login !== '') {
            $sql = "SELECT id, login FROM {$this->table} WHERE login = ?{$deletedClause} LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $login);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'login',
                        'match_value' => $login,
                    ];
                }
                $stmt->close();
            }
        }

        // 2. FB IDs (id_soc_account / social_url / c_user в cookies).
        $fbIds = AccountFingerprint::extractFbIds($row);
        if (empty($fbIds)) {
            return null;
        }

        $hasIdSoc     = $this->metadata->columnExists('id_soc_account');
        $hasSocialUrl = $this->metadata->columnExists('social_url');
        $hasCookies   = $this->metadata->columnExists('cookies');

        // Точные матчи по id_soc_account для каждого FB ID — один IN-запрос.
        if ($hasIdSoc) {
            $placeholders = implode(',', array_fill(0, count($fbIds), '?'));
            $sql = "SELECT id, login, id_soc_account FROM {$this->table} "
                 . "WHERE id_soc_account IN ($placeholders){$deletedClause} LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(str_repeat('s', count($fbIds)), ...$fbIds);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'FB ID (id_soc_account)',
                        'match_value' => (string)$found['id_soc_account'],
                    ];
                }
                $stmt->close();
            }
        }

        // social_url — LIKE %fbid% (на больших таблицах потребует full scan,
        // но social_url обычно short — допустимо при единичном createAccount).
        if ($hasSocialUrl) {
            foreach ($fbIds as $fbId) {
                $like = '%' . $fbId . '%';
                $sql = "SELECT id, login, social_url FROM {$this->table} "
                     . "WHERE social_url LIKE ?{$deletedClause} LIMIT 1";
                $stmt = $conn->prepare($sql);
                if (!$stmt) continue;
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'FB ID (social_url)',
                        'match_value' => $fbId,
                    ];
                }
                $stmt->close();
            }
        }

        // cookies — LIKE %c_user=<id>% по preview-области (первые 4KB).
        // Полный LONGTEXT scan убил бы перформанс на большой БД.
        if ($hasCookies) {
            $cookiesTrunc = (int)Config::VALIDATE_COOKIES_TRUNCATE;
            foreach ($fbIds as $fbId) {
                $like = '%c_user%' . $fbId . '%';
                $sql = "SELECT id, login FROM {$this->table} "
                     . "WHERE SUBSTRING(cookies, 1, $cookiesTrunc) LIKE ?{$deletedClause} LIMIT 1";
                $stmt = $conn->prepare($sql);
                if (!$stmt) continue;
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'FB ID (cookies c_user)',
                        'match_value' => $fbId,
                    ];
                }
                $stmt->close();
            }
        }

        return null;
    }

    /**
     * Строит индекс fingerprint-токенов всех АКТИВНЫХ аккаунтов из БД.
     *
     * Возвращает map: token → ['id' => existingId, 'login' => existingLogin].
     * Используется createAccountsBulk и createAccount для O(1)-проверки дубликатов
     * без N SQL-запросов (один SELECT для всей БД на старте импорта).
     *
     * Что считается дубликатом — см. AccountFingerprint::extract():
     *   - совпал login (lowercase)
     *   - совпал id_soc_account (FB user ID)
     *   - совпал FB ID из social_url
     *   - совпал c_user внутри cookies
     *
     * cookies при выборе обрезаются SUBSTRING(..., 1, VALIDATE_COOKIES_TRUNCATE)
     * (4 KB) — c_user всегда лежит в первых ~4 KB FB-cookies, а LONGTEXT
     * на 10K строк = 50–100 МБ при чтении полностью.
     *
     * @return array<string, array{id:int, login:string}>
     */
    private function getExistingFingerprintIndex(): array {
        $conn = $this->db->getConnection();

        $hasIdSoc       = $this->metadata->columnExists('id_soc_account');
        $hasSocialUrl   = $this->metadata->columnExists('social_url');
        $hasCookies     = $this->metadata->columnExists('cookies');
        $hasDeletedAt   = $this->metadata->columnExists('deleted_at');
        // Жмём preview cookies до 1KB. c_user всегда в первых ~200 байт
        // FB-cookies, 1KB с большим запасом. Раньше был 4KB — на больших
        // БД (50K+ аккаунтов) это ~200MB и memory exhaustion при импорте.
        $cookiesTrunc   = 1024;

        $cols = ['`id`', '`login`'];
        if ($hasIdSoc)     $cols[] = '`id_soc_account`';
        if ($hasSocialUrl) $cols[] = '`social_url`';
        if ($hasCookies)   $cols[] = "SUBSTRING(`cookies`, 1, $cookiesTrunc) AS `cookies`";

        $where = $hasDeletedAt ? 'WHERE deleted_at IS NULL' : '';
        $sql   = 'SELECT ' . implode(', ', $cols) . " FROM {$this->table} $where";

        // Streaming через real_query + use_result — НЕ буферизирует результат
        // в памяти PHP. Для каждой строки извлекаем fingerprint-токены и
        // сразу выбрасываем сами поля. Cookies LONGTEXT не остаётся в массиве
        // даже на момент обработки.
        if (!$conn->real_query($sql)) {
            Logger::warning('GET EXISTING FINGERPRINTS: real_query failed', ['error' => $conn->error]);
            return [];
        }
        $stream = $conn->use_result();
        if (!$stream) {
            Logger::warning('GET EXISTING FINGERPRINTS: use_result failed', ['error' => $conn->error]);
            return [];
        }

        $index = [];
        while ($row = $stream->fetch_assoc()) {
            $tokens = AccountFingerprint::extract($row);
            if (empty($tokens)) continue;
            $payload = [
                'id'    => (int)$row['id'],
                'login' => (string)($row['login'] ?? ''),
            ];
            foreach ($tokens as $tok) {
                // Если по одному токену уже есть запись (два FB-ID совпали у
                // двух старых аккаунтов — дубль в самой БД), оставляем первый
                // встретившийся. Существующие дубли лечатся через admin_duplicates.php.
                if (!isset($index[$tok])) {
                    $index[$tok] = $payload;
                }
            }
        }
        $stream->free();

        Logger::debug('GET EXISTING FINGERPRINTS: index built', [
            'tokens' => count($index),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
        return $index;
    }
    
    /**
     * Обновляет существующий аккаунт по логину
     * Используется при duplicate_action = 'update'
     * 
     * @param string $login Логин аккаунта для обновления
     * @param array $data Исходные данные
     * @param array $allowedFields Список полей для обновления
     * @param array $fieldData Нормализованные данные полей
     * @throws Exception При ошибках обновления
     */
    private function updateAccountByLogin(string $login, array $data, array &$allowedFields, array &$fieldData): void {
        // Повторно фильтруем и нормализуем данные (на случай если это вызвано до основной обработки)
        if (empty($allowedFields)) {
            $allowedFields = [];
            $fieldData = [];
            
            foreach ($data as $field => $value) {
                if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }
                
                if (!$this->metadata->columnExists($field)) {
                    continue;
                }
                
                try {
                    $normalized = $this->normalizeValueByColumnType($field, $value);
                    $allowedFields[] = $field;
                    $fieldData[$field] = $normalized;
                } catch (InvalidArgumentException $e) {
                    continue;
                }
            }
        }
        
        if (empty($allowedFields)) {
            throw new Exception('No valid fields to update');
        }
        
        // Формируем SET часть запроса
        $setParts = [];
        foreach ($allowedFields as $field) {
            if ($field === 'login') {
                // Не обновляем login (это ключ для поиска)
                continue;
            }
            $setParts[] = "`{$field}` = ?";
        }
        
        if (empty($setParts)) {
            throw new Exception('No fields to update (only login provided)');
        }
        
        $setClause = implode(', ', $setParts);
        // Обновляем только активные (не удалённые) аккаунты
        $softDeleteClause = $this->metadata->columnExists('deleted_at') ? ' AND deleted_at IS NULL' : '';
        $sql = "UPDATE {$this->table} SET {$setClause} WHERE login = ?$softDeleteClause";
        
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Failed to prepare update statement: ' . $conn->error);
        }
        
        // Привязываем параметры
        $paramTypes = '';
        $paramValues = [];
        
        foreach ($allowedFields as $field) {
            if ($field === 'login') {
                continue;
            }
            
            $normalized = $fieldData[$field];
            $val = $normalized['value'];
            
            if ($val === null) {
                $paramTypes .= 's';
                $paramValues[] = null;
            } else {
                $paramTypes .= $normalized['type'];
                $paramValues[] = $val;
            }
        }
        
        // Добавляем login в конец (для WHERE)
        $paramTypes .= 's';
        $paramValues[] = $login;
        
        if (count($paramValues) > 0) {
            $stmt->bind_param($paramTypes, ...$paramValues);
        }
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Failed to execute update: ' . $error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if ($affectedRows === 0) {
            Logger::warning('UPDATE ACCOUNT BY LOGIN: No rows affected', [
                'login' => $login
            ]);
        }
    }
}


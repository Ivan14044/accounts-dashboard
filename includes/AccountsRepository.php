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

class AccountsRepository {
    private $db;
    private $table = 'accounts';
    private $metadata;
    
    public function __construct() {
        global $mysqli;
        
        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            throw new Exception('Database connection not initialized. Please check config.php');
        }
        
        $this->db = Database::getInstance();
        $this->metadata = ColumnMetadata::getInstance($mysqli);
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
        
        // Формируем SQL - проверяем существование колонок перед использованием
        $validCols = [];
        foreach ($meta as $col) {
            if ($this->metadata->columnExists($col)) {
                $validCols[] = '`' . $col . '`';
            } else {
                Logger::warning("Column '$col' does not exist in table '{$this->table}', skipping");
            }
        }
        
        // Если нет валидных колонок, используем минимум
        if (empty($validCols)) {
            $validCols = ['`id`', '`login`', '`status`'];
            Logger::error("No valid columns found, using default columns");
        }
        
        $selectCols = implode(', ', $validCols);
        $where = $filter->getWhereClause($includeDeleted);
        $params = $filter->getParams();
        
        $sql = "SELECT $selectCols FROM {$this->table} $where ORDER BY $orderBy LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        
        // Кэшируем запросы с фильтрами (кроме пагинации, которая меняется)
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
        
        $sql = "UPDATE {$this->table} SET status = ? $updateTimestamp WHERE id IN ($placeholders)";
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
        
        $sql = "UPDATE {$this->table} SET `{$field}` = ? WHERE `id` = ?";
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
        
        $sql = "UPDATE {$this->table} SET `$field` = ? $updateTimestamp WHERE id IN ($placeholders)";
        
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

        $sql = "UPDATE {$this->table} SET `$field` = ? $updateTimestamp";
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
        
        global $mysqli;
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
        
        // Проверка дубликатов по login (только среди неудаленных аккаунтов)
        $loginValue = trim((string)$data['login']);
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        $checkSql = "SELECT id FROM {$this->table} WHERE login = ?";
        if ($supportsSoftDelete) {
            $checkSql .= " AND (deleted_at IS NULL OR deleted_at = '')";
        }
        $checkSql .= " LIMIT 1";
        
        $checkStmt = $conn->prepare($checkSql);
        if (!$checkStmt) {
            throw new Exception('Failed to prepare duplicate check statement');
        }
        
        $checkStmt->bind_param('s', $loginValue);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $checkStmt->close();
            throw new InvalidArgumentException("Account with login '{$loginValue}' already exists");
        }
        $checkStmt->close();
        
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
     * @param string $duplicateAction Действие при дубликате: 'skip', 'error'
     * @return array Статистика: ['created' => int, 'skipped' => int, 'errors' => array]
     * @throws Exception При ошибках БД
     */
    public function createAccountsBulk(array $accountsData, string $duplicateAction = 'skip'): array {
        if (empty($accountsData)) {
            throw new InvalidArgumentException('Accounts data is required');
        }
        
        $duplicateAction = in_array($duplicateAction, ['skip', 'error'], true) ? $duplicateAction : 'skip';
        
        $conn = $this->db->getConnection();
        $conn->begin_transaction();
        
        $created = 0;
        $skipped = 0;
        $errors = [];
        $createdIds = [];
        
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        try {
            foreach ($accountsData as $rowNum => $data) {
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
                        continue;
                    }
                    
                    if (empty($statusValue)) {
                        Logger::warning('CREATE ACCOUNTS BULK: Status пустой', ['row' => $rowNum + 1, 'data' => $data]);
                        $errors[] = [
                            'row' => $rowNum + 1,
                            'message' => 'Status is required'
                        ];
                        continue;
                    }
                    
                    // Проверка дубликатов (проверяем все записи, так как login имеет UNIQUE индекс в БД)
                    // Важно: даже если запись удалена (soft delete), login должен быть уникальным
                    $checkSql = "SELECT id FROM {$this->table} WHERE login = ? LIMIT 1";
                    
                    Logger::debug('CREATE ACCOUNTS BULK: Проверка дубликатов', [
                        'row' => $rowNum + 1,
                        'login' => $loginValue,
                        'sql' => $checkSql
                    ]);
                    
                    $checkStmt = $conn->prepare($checkSql);
                    if (!$checkStmt) {
                        Logger::error('CREATE ACCOUNTS BULK: Ошибка подготовки проверки дубликатов', [
                            'row' => $rowNum + 1,
                            'error' => $conn->error
                        ]);
                        // Не прерываем выполнение, так как MySQL сам проверит дубликат при вставке
                    } else {
                        $checkStmt->bind_param('s', $loginValue);
                        if ($checkStmt->execute()) {
                            $result = $checkStmt->get_result();
                            
                            if ($result && $result->num_rows > 0) {
                                $checkStmt->close();
                                Logger::debug('CREATE ACCOUNTS BULK: Дубликат найден при проверке', [
                                    'row' => $rowNum + 1,
                                    'login' => $loginValue,
                                    'duplicate_action' => $duplicateAction
                                ]);
                                
                                if ($duplicateAction === 'error') {
                                    $errors[] = [
                                        'row' => $rowNum + 1,
                                        'message' => "Account with login '{$loginValue}' already exists"
                                    ];
                                } else {
                                    $skipped++;
                                    Logger::debug('CREATE ACCOUNTS BULK: Дубликат пропущен (skip)', [
                                        'row' => $rowNum + 1,
                                        'login' => $loginValue
                                    ]);
                                }
                                continue;
                            }
                        } else {
                            Logger::warning('CREATE ACCOUNTS BULK: Ошибка выполнения проверки дубликатов', [
                                'row' => $rowNum + 1,
                                'error' => $checkStmt->error
                            ]);
                        }
                        $checkStmt->close();
                    }
                    
                    // Фильтруем и нормализуем данные (аналогично createAccount)
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
                        continue;
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
                        $stmt->bind_param($paramTypes, ...$paramValues);
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
                    
                    $created++;
                    $createdIds[] = $newId;
                    
                } catch (Exception $e) {
                    Logger::error('CREATE ACCOUNTS BULK: Исключение при обработке строки', [
                        'row' => $rowNum + 1,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors[] = [
                        'row' => $rowNum + 1,
                        'message' => $e->getMessage()
                    ];
                    // Не прерываем выполнение, продолжаем обработку остальных строк
                }
            }
            
            // Коммитим транзакцию
            $conn->commit();
            
            // Очищаем кэш после создания
            $this->db->clearCache();
            
            Logger::info('Bulk account creation completed', [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => count($errors),
                'total' => count($accountsData)
            ]);
            
            return [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
                'created_ids' => $createdIds
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
}


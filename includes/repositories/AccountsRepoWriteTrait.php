<?php
/**
 * UPDATE-операции AccountsRepository.
 *
 * Обновление статуса (по списку ID или по фильтру) и обновление произвольных
 * полей (одна запись, список ID, по фильтру, для всей таблицы) с нормализацией
 * значения через {@see AccountsRepository::normalizeValueByColumnType()}.
 *
 * Подключается в {@see AccountsRepository} через `use`.
 */
trait AccountsRepoWriteTrait {
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
}

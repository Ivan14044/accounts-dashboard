<?php
/**
 * Удаление и восстановление аккаунтов.
 *
 * Soft Delete используется, если в таблице есть колонка `deleted_at`
 * (CURRENT_TIMESTAMP на удаление, NULL на восстановление). Иначе —
 * физический DELETE с предварительной очисткой `account_favorites`.
 *
 * Подключается в {@see AccountsRepository} через `use`.
 */
trait AccountsRepoDeleteTrait {
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

        // История изменений (account_history) намеренно НЕ удаляется — сохраняется для аудита.
    }

    /**
     * Удаление аккаунтов по ID (Soft Delete — в корзину).
     * Если колонки `deleted_at` нет, выполняется hard delete с предварительной
     * очисткой связанных записей.
     *
     * @param array $ids Массив ID
     * @return int Количество удалённых/помеченных записей
     */
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
}

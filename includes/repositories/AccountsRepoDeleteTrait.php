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

    /**
     * Восстановление всех записей под фильтром (Soft Delete → deleted_at = NULL).
     *
     * Один UPDATE — MySQL делает всё на стороне сервера, без загрузки id в PHP
     * (нет риска 256M OOM). Фильтр обязан содержать `deleted_at IS NOT NULL`
     * (его добавляет createTrashFilterFromRequest()→addDeletedOnly), иначе бросаем.
     *
     * @param FilterBuilder $filter Фильтр корзины
     * @return int Кол-во восстановленных записей
     */
    public function restoreAccountsByFilter(FilterBuilder $filter): int {
        if (!$this->metadata->columnExists('deleted_at')) {
            throw new Exception('Soft Delete не поддерживается. Поле deleted_at не существует.');
        }

        $where = $filter->getWhereClause(true); // includeSoftDelete=true: не добавлять deleted_at IS NULL
        if (strpos($where, 'deleted_at IS NOT NULL') === false) {
            // Защита: без deleted-only условия можно случайно затронуть живые записи.
            throw new InvalidArgumentException('Trash filter must be scoped to deleted rows');
        }

        $whereClause = str_replace('WHERE ', '', $where);
        $params = $filter->getParams();

        $updateTimestamp = $this->metadata->columnExists('updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';

        $sql = "UPDATE {$this->table} SET deleted_at = NULL $updateTimestamp WHERE $whereClause";
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare restore-by-filter statement');
        }

        if (!empty($params)) {
            $stmt->bind_param($filter->getParamTypes(), ...$params);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to restore accounts by filter');
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        $this->db->clearCache();

        return $affectedRows;
    }

    /**
     * Окончательное удаление записей под фильтром (Hard Delete) чанками.
     *
     * Память ограничена одним чанком id (по умолчанию 1000), а не всем набором,
     * — поэтому безопасно для десятков тысяч строк. За один вызов удаляет не более
     * $maxRows строк (кэп против 120s-таймаута HTTP); остаток клиент дочищает
     * повторными запросами (как chunked-export).
     *
     * @param FilterBuilder $filter Фильтр корзины (обязан быть scoped к deleted_at IS NOT NULL)
     * @param int $maxRows Максимум строк за один вызов (0 = без лимита)
     * @param int $chunkSize Размер чанка
     * @return int Кол-во физически удалённых записей за этот вызов
     */
    public function permanentlyDeleteByFilter(FilterBuilder $filter, int $maxRows = 50000, int $chunkSize = 1000): int {
        if (!$this->metadata->columnExists('deleted_at')) {
            throw new Exception('Soft Delete не поддерживается. Поле deleted_at не существует.');
        }

        $where = $filter->getWhereClause(true);
        if (strpos($where, 'deleted_at IS NOT NULL') === false) {
            throw new InvalidArgumentException('Trash filter must be scoped to deleted rows');
        }

        $whereClause = str_replace('WHERE ', '', $where);
        $params = $filter->getParams();
        $types = $filter->getParamTypes();

        return $this->deleteScopedInChunks($whereClause, $params, $types, $maxRows, $chunkSize);
    }

    /**
     * Очистка корзины от записей старше N дней (retention purge) чанками.
     *
     * @param int $days   Порог в днях (deleted_at < NOW() - INTERVAL N DAY)
     * @param int $maxRows Максимум строк за один вызов (0 = без лимита)
     * @param int $chunkSize Размер чанка
     * @return int Кол-во удалённых записей за этот вызов
     */
    public function purgeOlderThan(int $days, int $maxRows = 50000, int $chunkSize = 1000): int {
        if (!$this->metadata->columnExists('deleted_at')) {
            return 0;
        }
        $days = max(1, $days);
        // INTERVAL не биндится как параметр в старых MySQL → инлайним проверенный int.
        $whereClause = "deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL $days DAY)";
        return $this->deleteScopedInChunks($whereClause, [], '', $maxRows, $chunkSize);
    }

    /**
     * Кол-во записей в корзине старше N дней (для UI и определения "есть что чистить").
     */
    public function countOlderThan(int $days): int {
        if (!$this->metadata->columnExists('deleted_at')) {
            return 0;
        }
        $days = max(1, $days);
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->table}
                WHERE deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL $days DAY)";
        $res = $this->db->getConnection()->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_assoc();
        $res->close();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Общий чанкованный hard-delete по готовому WHERE-условию.
     * На каждой итерации: SELECT id LIMIT chunk → DELETE favorites → DELETE accounts.
     * Удалённые строки перестают матчиться (deleted_at-условие), поэтому LIMIT без OFFSET
     * корректно перебирает набор. Память ограничена одним чанком.
     *
     * @param string $whereClause WHERE без ключевого слова WHERE (должен содержать deleted_at-условие)
     * @param array  $params      Параметры для prepared statement
     * @param string $types       Типы для bind_param ('' если параметров нет)
     * @param int    $maxRows     Кэп строк за вызов (0 = без лимита)
     * @param int    $chunkSize   Размер чанка
     * @return int Всего удалено за вызов
     */
    private function deleteScopedInChunks(string $whereClause, array $params, string $types, int $maxRows, int $chunkSize): int {
        $mysqli = $this->db->getConnection();
        $chunkSize = max(1, $chunkSize);
        $totalDeleted = 0;

        while (true) {
            // Сколько ещё можно за этот вызов
            $limit = $chunkSize;
            if ($maxRows > 0) {
                $remainingCap = $maxRows - $totalDeleted;
                if ($remainingCap <= 0) break;
                $limit = min($chunkSize, $remainingCap);
            }

            // 1) Выбираем id очередного чанка
            $selectSql = "SELECT id FROM {$this->table} WHERE $whereClause ORDER BY id LIMIT ?";
            $selStmt = $mysqli->prepare($selectSql);
            if (!$selStmt) {
                throw new Exception('Failed to prepare chunk select: ' . $mysqli->error);
            }
            $selTypes = $types . 'i';
            $selParams = $params;
            $selParams[] = $limit;
            $selStmt->bind_param($selTypes, ...$selParams);
            if (!$selStmt->execute()) {
                $err = $selStmt->error;
                $selStmt->close();
                throw new Exception('Failed to execute chunk select: ' . $err);
            }
            $res = $selStmt->get_result();
            $ids = [];
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['id'];
            }
            $selStmt->close();

            if (empty($ids)) break;

            // 2) Атомарно: favorites + accounts для этого чанка
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $chunkTypes = str_repeat('i', count($ids));

            $mysqli->begin_transaction();
            try {
                $favSql = "DELETE FROM account_favorites WHERE account_id IN ($ph)";
                $favStmt = $mysqli->prepare($favSql);
                if ($favStmt) {
                    $favStmt->bind_param($chunkTypes, ...$ids);
                    $favStmt->execute();
                    $favStmt->close();
                }

                $delSql = "DELETE FROM {$this->table} WHERE id IN ($ph)";
                $delStmt = $mysqli->prepare($delSql);
                if (!$delStmt) {
                    throw new Exception('Failed to prepare chunk delete: ' . $mysqli->error);
                }
                $delStmt->bind_param($chunkTypes, ...$ids);
                if (!$delStmt->execute()) {
                    $err = $delStmt->error;
                    $delStmt->close();
                    throw new Exception('Failed to execute chunk delete: ' . $err);
                }
                $totalDeleted += $delStmt->affected_rows;
                $delStmt->close();

                $mysqli->commit();
            } catch (Throwable $txErr) {
                $mysqli->rollback();
                throw $txErr;
            }

            // Чанк меньше лимита → набор исчерпан
            if (count($ids) < $limit) break;
        }

        if ($totalDeleted > 0) {
            $this->db->clearCache();
        }

        return $totalDeleted;
    }
}

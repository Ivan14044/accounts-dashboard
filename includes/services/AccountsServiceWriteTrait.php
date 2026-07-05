<?php
/**
 * Изменение и удаление аккаунтов с audit-логированием.
 *
 * Здесь живут update*, delete*, restoreAccounts — все они:
 *   1) собирают старое состояние или ID-список под фильтром,
 *   2) делегируют изменение в {@see AccountsRepository},
 *   3) пишут event в {@see AuditLogger} (ошибки логирования не ломают UPDATE).
 *
 * Подключается в {@see AccountsService} через `use`.
 */
trait AccountsServiceWriteTrait {
    /**
     * Обновление статуса для выбранных аккаунтов
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateStatus(array $ids, string $status): int {
        // Логируем изменения в audit log ДО обновления (чтобы сохранить старые значения)
        $auditLogger = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->beginAction('update_status', $this->table,
                    'Смена статуса на «' . $status . '» (' . count($ids) . ' акк.)');
                $auditLogger->logBulkChange($ids, 'status', null, $status, null, $this->table);
            }
        } catch (Exception $e) {
            Logger::warning('Audit log failed for updateStatus', ['error' => $e->getMessage()]);
        }

        // Делегируем в репозиторий
        $affectedRows = 0;
        try {
            $affectedRows = $this->repository->updateStatus($ids, $status);
        } finally {
            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affectedRows); } catch (Exception $e) {}
            }
        }
        return $affectedRows;
    }

    /**
     * Обновление статуса для всех записей по фильтру
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateStatusByFilter(FilterBuilder $filter, string $status): int {
        // Получаем ID аккаунтов, которые будут обновлены, для audit log
        $auditLogger = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $ids = $this->collectIdsByFilter($filter);
                if (!empty($ids)) {
                    $auditLogger->beginAction('update_status', $this->table,
                        'Смена статуса по фильтру на «' . $status . '» (' . count($ids) . ' акк.)');
                    $auditLogger->logBulkChange($ids, 'status', null, $status, null, $this->table);
                }
            }
        } catch (Exception $e) {
            Logger::warning('Audit log failed for updateStatusByFilter', ['error' => $e->getMessage()]);
        }

        // Делегируем в репозиторий
        $affectedRows = 0;
        try {
            $affectedRows = $this->repository->updateStatusByFilter($filter, $status);
        } finally {
            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affectedRows); } catch (Exception $e) {}
            }
        }
        return $affectedRows;
    }

    /**
     * ID записей, попадающих под фильтр (для audit log перед массовой операцией).
     *
     * @param FilterBuilder $filter
     * @param string $extraWhere Дополнительное условие (без ведущего AND)
     * @return int[]
     */
    private function collectIdsByFilter(FilterBuilder $filter, string $extraWhere = ''): array {
        $where = $filter->getWhereClause();
        $params = $filter->getParams();
        if ($extraWhere !== '') {
            $where = $where ? ($where . ' AND ' . $extraWhere) : ('WHERE ' . $extraWhere);
        }
        $sql = "SELECT id FROM {$this->table} " . ($where ?: '');
        $mysqli = $this->db->getConnection();
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if (!empty($params)) {
            $stmt->bind_param($filter->getParamTypes(), ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
        return $ids;
    }

    /**
     * Обновление одного поля для одной записи
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateField(int $id, string $field, $value): int {
        // Получаем старое значение для audit log
        $oldValue = null;
        $auditLogger = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $oldAccount = $this->getAccountById($id);
                $oldValue = $oldAccount[$field] ?? null;
                $auditLogger->beginAction('update_field', $this->table,
                    'Изменение поля «' . $field . '» у аккаунта #' . $id);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }

        // Делегируем в репозиторий
        $affectedRows = $this->repository->updateField($id, $field, $value);

        // Логируем изменение в audit log
        if ($auditLogger !== null && $auditLogger->isEnabled()) {
            try {
                if ($affectedRows > 0) {
                    $auditLogger->logChange($id, $field, $oldValue, $value);
                }
                $auditLogger->finishAction($affectedRows);
            } catch (Exception $e) {
                // Игнорируем ошибки audit log
            }
        }

        return $affectedRows;
    }

    /**
     * Массовое обновление поля
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function bulkUpdateField(array $ids, string $field, $value): int {
        // Логируем изменения в audit log
        $auditLogger = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->beginAction('bulk_update_field', $this->table,
                    'Массовое изменение поля «' . $field . '» (' . count($ids) . ' акк.)');
                $auditLogger->logBulkChange($ids, $field, null, $value, null, $this->table);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }

        // Делегируем в репозиторий
        $affectedRows = 0;
        try {
            $affectedRows = $this->repository->bulkUpdateField($ids, $field, $value);
        } finally {
            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affectedRows); } catch (Exception $e) {}
            }
        }
        return $affectedRows;
    }

    /**
     * Массовое обновление произвольного поля по фильтру
     * Делегирует работу в AccountsRepository, предварительно фиксируя старые значения в audit log.
     */
    public function updateFieldByFilter(FilterBuilder $filter, string $field, $value): int {
        // AUDIT: зеркалит схему updateStatusByFilter — сначала собираем ID по фильтру,
        // потом logBulkChange (который сам прочитает старые значения через $field),
        // потом основной UPDATE.
        $auditLogger = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $ids = $this->collectIdsByFilter($filter);
                if (!empty($ids)) {
                    $auditLogger->beginAction('bulk_update_field', $this->table,
                        'Изменение поля «' . $field . '» по фильтру (' . count($ids) . ' акк.)');
                    $auditLogger->logBulkChange($ids, $field, null, $value, null, $this->table);
                }
            }
        } catch (Exception $e) {
            Logger::warning('Audit log failed for updateFieldByFilter', [
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }

        $affectedRows = 0;
        try {
            $affectedRows = $this->repository->updateFieldByFilter($filter, $field, $value);
        } finally {
            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affectedRows); } catch (Exception $e) {}
            }
        }
        return $affectedRows;
    }

    /**
     * Массовое обновление поля по всей таблице (использовать только после явного подтверждения)
     * Делегирует работу в AccountsRepository
     */
    public function updateFieldForAll(string $field, $value): int {
        return $this->repository->updateFieldForAll($field, $value);
    }

    /**
     * Удаление аккаунтов по ID (Soft Delete - в корзину)
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function deleteAccounts(array $ids): int {
        // Проверяем, поддерживается ли Soft Delete для логирования
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');

        $auditLogger = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->beginAction('delete', $this->table,
                    'Удаление в корзину (' . count($ids) . ' акк.)');
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }

        // Делегируем в репозиторий
        $affectedRows = 0;
        try {
            $affectedRows = $this->repository->deleteAccounts($ids);

            // Логируем удаление в audit log
            if ($auditLogger !== null && $auditLogger->isEnabled()) {
                foreach ($ids as $accountId) {
                    $auditLogger->logChange($accountId, 'deleted_at', null, $supportsSoftDelete ? date('Y-m-d H:i:s') : 'DELETED');
                }
            }
        } finally {
            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affectedRows); } catch (Exception $e) {}
            }
        }

        return $affectedRows;
    }

    /**
     * Удаление аккаунтов по фильтру (Soft Delete - в корзину)
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function deleteAccountsByFilter(FilterBuilder $filter): int {
        // Проверяем, поддерживается ли Soft Delete для логирования
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');

        // AUDIT: собираем ID ДО удаления. Раньше ID добирались после удаления
        // эвристикой «deleted_at за последнюю минуту LIMIT 100» — при массовом
        // удалении история теряла всё сверх 100 строк и могла захватить чужие удаления.
        $auditLogger = null;
        if ($supportsSoftDelete) {
            try {
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    // Логируем только строки, которые реально перейдут в корзину
                    $ids = $this->collectIdsByFilter($filter, 'deleted_at IS NULL');
                    if (!empty($ids)) {
                        $auditLogger->beginAction('delete', $this->table,
                            'Удаление в корзину по фильтру (' . count($ids) . ' акк.)');
                        $auditLogger->logBulkChange($ids, 'deleted_at', null, date('Y-m-d H:i:s'), null, $this->table);
                    } else {
                        $auditLogger = null;
                    }
                }
            } catch (Exception $e) {
                $auditLogger = null;
                // Игнорируем ошибки audit log
            }
        }

        // Делегируем в репозиторий
        $affectedRows = 0;
        try {
            $affectedRows = $this->repository->deleteAccountsByFilter($filter);
        } finally {
            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affectedRows); } catch (Exception $e) {}
            }
        }

        return $affectedRows;
    }

    /**
     * Восстановление аккаунтов из корзины (Soft Delete)
     * Делегирует работу в AccountsRepository с логированием в audit log
     *
     * @param array $ids Массив ID аккаунтов для восстановления
     * @return int Количество восстановленных аккаунтов
     */
    public function restoreAccounts(array $ids): int {
        // Делегируем в репозиторий
        $affectedRows = $this->repository->restoreAccounts($ids);

        // Логируем восстановление в audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled() && $affectedRows > 0) {
                // Получаем ID восстановленных аккаунтов для логирования (используем prepared statement)
                $validIds = array_filter(array_map('intval', $ids));
                if (!empty($validIds)) {
                    $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
                    $restoredQuery = "SELECT id FROM {$this->table} WHERE id IN ($placeholders) AND deleted_at IS NULL LIMIT 100";
                    $restoredStmt = $this->db->getConnection()->prepare($restoredQuery);
                    if ($restoredStmt) {
                        $types = str_repeat('i', count($validIds));
                        $restoredStmt->bind_param($types, ...$validIds);
                        $restoredStmt->execute();
                        $restoredResult = $restoredStmt->get_result();
                        if ($restoredResult) {
                            while ($row = $restoredResult->fetch_assoc()) {
                                $auditLogger->logChange($row['id'], 'deleted_at', date('Y-m-d H:i:s'), null);
                            }
                        }
                        $restoredStmt->close();
                    }
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }

        return $affectedRows;
    }

    /**
     * Восстановление всех записей под фильтром корзины (Soft Delete → NULL).
     * Один UPDATE на стороне БД. Аудит — суммарной строкой в Logger (не per-row,
     * чтобы не плодить десятки тысяч записей account_history на массовой операции).
     *
     * @param FilterBuilder $filter Фильтр корзины (scoped к deleted_at IS NOT NULL)
     * @return int Кол-во восстановленных
     */
    public function restoreAccountsByFilter(FilterBuilder $filter): int {
        $affectedRows = $this->repository->restoreAccountsByFilter($filter);

        if ($affectedRows > 0) {
            Logger::info('Trash: bulk restore by filter', [
                'user'  => $_SESSION['username'] ?? 'unknown',
                'count' => $affectedRows,
            ]);
        }

        return $affectedRows;
    }

    /**
     * Окончательное удаление записей под фильтром корзины (Hard Delete) чанками.
     * За вызов удаляет не более $maxRows строк; остаток клиент дочищает повторно.
     *
     * @param FilterBuilder $filter  Фильтр корзины (scoped к deleted_at IS NOT NULL)
     * @param int           $maxRows Кэп строк за вызов (0 = без лимита)
     * @return int Кол-во удалённых за этот вызов
     */
    public function permanentlyDeleteByFilter(FilterBuilder $filter, int $maxRows = 50000): int {
        $deleted = $this->repository->permanentlyDeleteByFilter($filter, $maxRows);

        if ($deleted > 0) {
            Logger::warning('Trash: bulk permanent delete by filter', [
                'user'  => $_SESSION['username'] ?? 'unknown',
                'count' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * Retention-purge: удаление записей корзины старше N дней (чанками).
     *
     * @param int $days    Порог в днях
     * @param int $maxRows  Кэп строк за вызов (0 = без лимита)
     * @return int Кол-во удалённых за этот вызов
     */
    public function purgeTrashOlderThan(int $days, int $maxRows = 50000): int {
        $deleted = $this->repository->purgeOlderThan($days, $maxRows);

        if ($deleted > 0) {
            Logger::warning('Trash: retention purge', [
                'user'  => $_SESSION['username'] ?? 'system',
                'days'  => $days,
                'count' => $deleted,
            ]);
        }

        return $deleted;
    }

    /** Кол-во записей в корзине старше N дней. */
    public function countTrashOlderThan(int $days): int {
        return $this->repository->countOlderThan($days);
    }
}

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
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->logBulkChange($ids, 'status', null, $status);
            }
        } catch (Exception $e) {
            Logger::warning('Audit log failed for updateStatus', ['error' => $e->getMessage()]);
        }

        // Делегируем в репозиторий
        return $this->repository->updateStatus($ids, $status);
    }

    /**
     * Обновление статуса для всех записей по фильтру
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateStatusByFilter(FilterBuilder $filter, string $status): int {
        // Получаем ID аккаунтов, которые будут обновлены, для audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                // Находим ID аккаунтов, попадающих под фильтр, со старыми статусами
                $where = $filter->getWhereClause();
                $params = $filter->getParams();
                $sql = "SELECT id FROM {$this->table} " . ($where ?: '');
                $mysqli = $this->db->getConnection();
                $stmt = $mysqli->prepare($sql);
                if ($stmt) {
                    if (!empty($params)) {
                        $types = $filter->getParamTypes();
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $ids = [];
                    while ($row = $result->fetch_assoc()) {
                        $ids[] = (int)$row['id'];
                    }
                    $stmt->close();

                    if (!empty($ids)) {
                        $auditLogger->logBulkChange($ids, 'status', null, $status);
                    }
                }
            }
        } catch (Exception $e) {
            Logger::warning('Audit log failed for updateStatusByFilter', ['error' => $e->getMessage()]);
        }

        // Делегируем в репозиторий
        return $this->repository->updateStatusByFilter($filter, $status);
    }

    /**
     * Обновление одного поля для одной записи
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateField(int $id, string $field, $value): int {
        // Получаем старое значение для audit log
        $oldValue = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $oldAccount = $this->getAccountById($id);
                $oldValue = $oldAccount[$field] ?? null;
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }

        // Делегируем в репозиторий
        $affectedRows = $this->repository->updateField($id, $field, $value);

        // Логируем изменение в audit log
        if ($affectedRows > 0 && isset($auditLogger) && $auditLogger->isEnabled()) {
            try {
                $auditLogger->logChange($id, $field, $oldValue, $value);
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
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->logBulkChange($ids, $field, null, $value);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }

        // Делегируем в репозиторий
        return $this->repository->bulkUpdateField($ids, $field, $value);
    }

    /**
     * Массовое обновление произвольного поля по фильтру
     * Делегирует работу в AccountsRepository, предварительно фиксируя старые значения в audit log.
     */
    public function updateFieldByFilter(FilterBuilder $filter, string $field, $value): int {
        // AUDIT: зеркалит схему updateStatusByFilter — сначала собираем ID по фильтру,
        // потом logBulkChange (который сам прочитает старые значения через $field),
        // потом основной UPDATE.
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $where = $filter->getWhereClause();
                $params = $filter->getParams();
                $sql = "SELECT id FROM {$this->table} " . ($where ?: '');
                $mysqli = $this->db->getConnection();
                $stmt = $mysqli->prepare($sql);
                if ($stmt) {
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

                    if (!empty($ids)) {
                        $auditLogger->logBulkChange($ids, $field, null, $value);
                    }
                }
            }
        } catch (Exception $e) {
            Logger::warning('Audit log failed for updateFieldByFilter', [
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->repository->updateFieldByFilter($filter, $field, $value);
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

        // Делегируем в репозиторий
        $affectedRows = $this->repository->deleteAccounts($ids);

        // Логируем удаление в audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                foreach ($ids as $accountId) {
                    $auditLogger->logChange($accountId, 'deleted_at', null, $supportsSoftDelete ? date('Y-m-d H:i:s') : 'DELETED');
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
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

        // Делегируем в репозиторий
        $affectedRows = $this->repository->deleteAccountsByFilter($filter);

        // Логируем удаление в audit log
        if ($affectedRows > 0 && $supportsSoftDelete) {
            try {
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    // Получаем ID удалённых аккаунтов для логирования (недавно удалённые)
                    $deletedQuery = "SELECT id FROM {$this->table} WHERE deleted_at IS NOT NULL AND deleted_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 100";
                    $deletedResult = $this->db->getConnection()->query($deletedQuery);
                    if ($deletedResult) {
                        while ($row = $deletedResult->fetch_assoc()) {
                            $auditLogger->logChange($row['id'], 'deleted_at', null, date('Y-m-d H:i:s'));
                        }
                        $deletedResult->close();
                    }
                }
            } catch (Exception $e) {
                // Игнорируем ошибки audit log
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
}

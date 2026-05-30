<?php
/**
 * Создание аккаунтов: одиночное и массовый импорт.
 *
 * Делегирует {@see AccountsRepository::createAccount()} /
 * {@see AccountsRepository::createAccountsBulk()} и пишет события в
 * `account_history` через {@see AuditLogger}. Bulk-логи режутся
 * batch-INSERT-ом (одна вставка на все новые/обновлённые аккаунты),
 * чтобы не нагружать БД при импорте десятков тысяч строк.
 *
 * Подключается в {@see AccountsService} через `use`.
 */
trait AccountsServiceCreateTrait {
    /**
     * Создание нового аккаунта
     * Делегирует работу в AccountsRepository с логированием в audit log
     *
     * @param array $data Массив данных аккаунта
     * @return array Массив с данными созданного аккаунта и его ID
     * @throws InvalidArgumentException При ошибках валидации
     * @throws Exception При ошибках БД
     */
    public function createAccount(array $data): array {
        // Валидация обязательных полей на уровне сервиса
        if (empty($data['login']) || trim((string)$data['login']) === '') {
            throw new InvalidArgumentException('Login is required');
        }

        if (empty($data['status']) || trim((string)$data['status']) === '') {
            throw new InvalidArgumentException('Status is required');
        }

        // Проверяем, что все поля существуют в метаданных
        $meta = $this->getColumnMetadata();
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $meta['all'], true) && !in_array($field, ['csrf'], true)) {
                // Пропускаем служебные поля (csrf) и предупреждаем о неизвестных
                if ($field !== 'csrf') {
                    Logger::warning("Unknown field in createAccount data", ['field' => $field]);
                }
            }
        }

        // Делегируем создание в репозиторий
        $newId = $this->repository->createAccount($data);

        // Получаем созданный аккаунт
        $newAccount = $this->getAccountById($newId);

        if (!$newAccount) {
            throw new Exception('Failed to retrieve created account');
        }

        // Логируем создание через AuditLogger
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                // Логируем создание каждого заполненного поля как изменение (old_value = null, new_value = значение)
                foreach ($data as $field => $value) {
                    // Пропускаем служебные поля
                    if (in_array($field, ['csrf'], true)) {
                        continue;
                    }

                    // Пропускаем пустые значения для сокращения логов
                    if ($value === '' || $value === null) {
                        continue;
                    }

                    // Проверяем, что поле существует
                    if (!in_array($field, $meta['all'], true)) {
                        continue;
                    }

                    try {
                        $auditLogger->logChange($newId, $field, null, $value);
                    } catch (Exception $e) {
                        // Игнорируем ошибки audit log для отдельных полей
                        Logger::warning("Failed to log field creation in audit log", [
                            'field' => $field,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Логируем общее событие создания аккаунта
                Logger::info('Account created', [
                    'id' => $newId,
                    'login' => $data['login'],
                    'created_by' => $_SESSION['username'] ?? 'unknown'
                ]);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log, но логируем их
            Logger::warning("Audit logging failed for account creation", [
                'account_id' => $newId,
                'error' => $e->getMessage()
            ]);
        }

        return [
            'id' => $newId,
            'account' => $newAccount
        ];
    }

    /**
     * Массовое создание аккаунтов
     * Делегирует работу в AccountsRepository с логированием в audit log
     *
     * @param array $accountsData Массив массивов данных аккаунтов
     * @param string $duplicateAction Действие при дубликате: 'skip', 'error'
     * @return array Статистика создания с деталями
     * @throws InvalidArgumentException При ошибках валидации
     * @throws Exception При ошибках БД
     */
    public function createAccountsBulk(array $accountsData, string $duplicateAction = 'skip'): array {
        if (empty($accountsData)) {
            throw new InvalidArgumentException('Accounts data is required');
        }

        // Валидация структуры данных
        if (!is_array($accountsData)) {
            throw new InvalidArgumentException('Accounts data must be an array');
        }

        // Проверяем, что каждый элемент - массив
        foreach ($accountsData as $idx => $accountData) {
            if (!is_array($accountData)) {
                throw new InvalidArgumentException("Account data at index $idx must be an array");
            }
        }

        // Делегируем создание в репозиторий
        $result = $this->repository->createAccountsBulk($accountsData, $duplicateAction);

        // Логируем массовое создание в общий лог
        try {
            Logger::info('Bulk accounts created', [
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'errors_count' => count($result['errors']),
                'created_ids_count' => count($result['created_ids'] ?? []),
                'created_by' => $_SESSION['username'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }

        // Логируем каждый созданный аккаунт в audit log (история изменений)
        $createdIds = $result['created_ids'] ?? [];
        if (!empty($createdIds)) {
            try {
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    $changedBy = $_SESSION['username'] ?? 'system';
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                    $meta = $this->getColumnMetadata();
                    $conn = Database::getInstance()->getConnection();

                    // Собираем все audit записи батчем (одна INSERT вставка)
                    $insertValues = [];
                    $insertParams = [];
                    $insertTypes = '';

                    // Строим маппинг login → data из входного массива
                    $dataByLogin = [];
                    foreach ($accountsData as $data) {
                        $login = trim((string)($data['login'] ?? ''));
                        if ($login !== '') {
                            $dataByLogin[$login] = $data;
                        }
                    }

                    // Получаем login для каждого created_id из БД
                    if (!empty($createdIds)) {
                        $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
                        $stmt = $conn->prepare("SELECT id, login FROM accounts WHERE id IN ($placeholders)");
                        if ($stmt) {
                            $stmt->bind_param(str_repeat('i', count($createdIds)), ...$createdIds);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $idToLogin = [];
                            while ($row = $res->fetch_assoc()) {
                                $idToLogin[(int)$row['id']] = $row['login'];
                            }
                            $stmt->close();

                            foreach ($createdIds as $newId) {
                                $login = $idToLogin[$newId] ?? '';
                                $accountData = $dataByLogin[$login] ?? [];

                                // Логируем событие создания аккаунта
                                $insertValues[] = "(?, 'account_created', NULL, ?, ?, ?)";
                                $insertParams[] = $newId;
                                $insertParams[] = 'Импорт: ' . ($login ?: "ID#$newId");
                                $insertParams[] = $changedBy;
                                $insertParams[] = $ipAddress;
                                $insertTypes .= 'issss';

                                // Логируем ключевые поля (login, status)
                                foreach (['login', 'status'] as $keyField) {
                                    $val = $accountData[$keyField] ?? null;
                                    if ($val !== null && $val !== '') {
                                        $insertValues[] = "(?, ?, NULL, ?, ?, ?)";
                                        $insertParams[] = $newId;
                                        $insertParams[] = $keyField;
                                        $insertParams[] = (string)$val;
                                        $insertParams[] = $changedBy;
                                        $insertParams[] = $ipAddress;
                                        $insertTypes .= 'issss';
                                    }
                                }
                            }
                        }
                    }

                    // Массовая вставка всех audit записей
                    if (!empty($insertValues)) {
                        $sql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address) VALUES "
                             . implode(', ', $insertValues);
                        $insertStmt = $conn->prepare($sql);
                        if ($insertStmt) {
                            $insertStmt->bind_param($insertTypes, ...$insertParams);
                            $insertStmt->execute();
                            $insertStmt->close();
                        }
                    }
                }
            } catch (Exception $e) {
                // Audit log ошибки не должны ломать импорт
                Logger::warning('Audit logging failed for bulk create', [
                    'error' => $e->getMessage(),
                    'created_count' => count($createdIds)
                ]);
            }
        }

        // Логируем обновлённые аккаунты (duplicateAction === 'update') в audit log
        $updatedLogins = $result['updated_logins'] ?? [];
        if (!empty($updatedLogins)) {
            try {
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    $changedBy = $_SESSION['username'] ?? 'system';
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                    $conn = Database::getInstance()->getConnection();

                    // Строим маппинг login → data из входного массива
                    $dataByLogin = [];
                    foreach ($accountsData as $data) {
                        $login = trim((string)($data['login'] ?? ''));
                        if ($login !== '') {
                            $dataByLogin[$login] = $data;
                        }
                    }

                    // Получаем id для каждого обновлённого логина
                    $placeholders = implode(',', array_fill(0, count($updatedLogins), '?'));
                    $stmt = $conn->prepare("SELECT id, login FROM accounts WHERE login IN ($placeholders)");
                    if ($stmt) {
                        $stmt->bind_param(str_repeat('s', count($updatedLogins)), ...$updatedLogins);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $loginToId = [];
                        while ($row = $res->fetch_assoc()) {
                            $loginToId[$row['login']] = (int)$row['id'];
                        }
                        $stmt->close();

                        $insertValues = [];
                        $insertParams = [];
                        $insertTypes = '';

                        foreach ($updatedLogins as $login) {
                            $accountId = $loginToId[$login] ?? null;
                            if (!$accountId) continue;

                            $accountData = $dataByLogin[$login] ?? [];

                            // Логируем событие обновления
                            $insertValues[] = "(?, 'bulk_update', NULL, ?, ?, ?)";
                            $insertParams[] = $accountId;
                            $insertParams[] = 'Импорт (обновление): ' . $login;
                            $insertParams[] = $changedBy;
                            $insertParams[] = $ipAddress;
                            $insertTypes .= 'issss';

                            // Логируем каждое изменённое поле (кроме login — он ключ поиска)
                            foreach ($accountData as $field => $val) {
                                if ($field === 'login') continue;
                                if ($val === null || $val === '') continue;
                                if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) continue;

                                // Для чувствительных полей скрываем значение
                                $sensitiveFields = ['password', 'email_password', 'token', 'cookies'];
                                $newVal = in_array($field, $sensitiveFields, true) ? '[СКРЫТО]' : (string)$val;

                                $insertValues[] = "(?, ?, NULL, ?, ?, ?)";
                                $insertParams[] = $accountId;
                                $insertParams[] = $field;
                                $insertParams[] = $newVal;
                                $insertParams[] = $changedBy;
                                $insertParams[] = $ipAddress;
                                $insertTypes .= 'issss';
                            }
                        }

                        // Массовая вставка audit записей для обновлённых аккаунтов
                        if (!empty($insertValues)) {
                            $sql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address) VALUES "
                                 . implode(', ', $insertValues);
                            $insertStmt = $conn->prepare($sql);
                            if ($insertStmt) {
                                $insertStmt->bind_param($insertTypes, ...$insertParams);
                                $insertStmt->execute();
                                $insertStmt->close();
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                Logger::warning('Audit logging failed for bulk update', [
                    'error' => $e->getMessage(),
                    'updated_count' => count($updatedLogins)
                ]);
            }
        }

        return $result;
    }
}

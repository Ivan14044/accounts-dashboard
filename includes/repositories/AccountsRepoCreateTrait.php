<?php
/**
 * Создание одного аккаунта.
 *
 * Точечная проверка дубликата через
 * {@see AccountsRepository::findExistingByFingerprint()} перед INSERT.
 * Bulk-импорт (createAccountsBulk) — в {@see AccountsRepoBulkTrait}.
 *
 * Подключается в {@see AccountsRepository} через `use`.
 */
trait AccountsRepoCreateTrait {
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
}

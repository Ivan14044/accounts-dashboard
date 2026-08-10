<?php
/**
 * Массовый импорт аккаунтов.
 *
 * Один SELECT строит fingerprint-индекс активной БД (login / id_soc_account /
 * FB ID из social_url / c_user из cookies), затем построчная вставка с
 * savepoint-ами внутри батчевых транзакций — ошибка одной строки не откатывает
 * весь батч. Дубликаты обрабатываются в режимах skip / error / update.
 *
 * Подключается в {@see AccountsRepository} через `use`.
 */
trait AccountsRepoBulkTrait {
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
     * Какие поля реально обновлять при импорте в режиме «Обновить».
     *
     * ПРАВИЛО: пустое значение в файле означает «не трогать это поле», а не
     * «очистить его». Обновляются только колонки, которые есть в загруженной
     * строке и непустые.
     *
     * Зачем так (баг, найденный 2026-08-10). Пользователь скачивает шаблон — а в
     * нём в заголовке ВСЕ колонки — заполняет login и status и выбирает
     * «Обновить». До этой функции у существующего аккаунта обнулялись password,
     * email, first_name, id_soc_account, extra_info_1 и все прочие, а отчёт
     * говорил «Обновлено: 1» без предупреждений. Причин было две:
     *  1. CsvParser добивает короткую строку пустыми значениями и присваивает ''
     *     каждому заголовку, а AccountsRepository превращает '' в NULL для
     *     nullable-колонок — UPDATE честно писал NULL поверх данных;
     *  2. $allowedFields собирается для СОЗДАНИЯ записи и дополнительно добирает
     *     значения по умолчанию для NOT NULL колонок, которых в файле нет вовсе;
     *     в UPDATE они переписывали существующие значения дефолтами.
     * Восстановить было нечем: импорт не пишет в журнал отмены.
     *
     * Осознанный размен: очистить поле импортом теперь нельзя. Цена ошибки в
     * другую сторону — молчаливая невосстановимая потеря данных — несопоставимо
     * выше. Очистка поля делается точечно в интерфейсе.
     *
     * Ноль (`0` и строка `'0'`) пустотой НЕ считается — это значимое значение,
     * поэтому проверяем именно на пустую строку, а не через empty().
     *
     * @param array    $data Загруженная строка: колонка => значение из файла
     * @param string[] $allowedFields Поля-кандидаты, отобранные под создание записи
     * @return string[] Поля для SET, в порядке $allowedFields, без повторов
     */
    public static function fieldsToUpdateFromImport(array $data, array $allowedFields): array {
        $skip = array('id', 'created_at', 'updated_at', 'deleted_at', 'login');
        $result = array();

        foreach ($allowedFields as $field) {
            // login — ключ поиска, системные поля не из файла
            if (in_array($field, $skip, true) || in_array($field, $result, true)) {
                continue;
            }
            // Колонки нет в файле — значит про неё ничего не сказано.
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $result[] = $field;
        }

        return $result;
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

        // Что именно обновляем. Список считается ОДИН раз и дальше используется
        // и для SET, и для привязки параметров — иначе два цикла разъедутся и
        // значения уедут не в свои колонки.
        // Пустые поля сюда не попадают: см. докблок fieldsToUpdateFromImport.
        $updateFields = self::fieldsToUpdateFromImport($data, $allowedFields);

        if (empty($updateFields)) {
            throw new Exception(
                'В строке нет ни одного заполненного поля для обновления '
                . '(кроме login). Пустые значения при обновлении игнорируются, '
                . 'чтобы не затереть данные.'
            );
        }

        // Формируем SET часть запроса
        $setParts = [];
        foreach ($updateFields as $field) {
            $setParts[] = "`{$field}` = ?";
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

        foreach ($updateFields as $field) {
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

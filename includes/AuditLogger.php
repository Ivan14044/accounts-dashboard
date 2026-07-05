<?php
/**
 * Класс для логирования изменений аккаунтов (Audit Log)
 * Записывает все изменения в таблицу account_history
 */
require_once __DIR__ . '/Database.php';

class AuditLogger {
    private static $instance = null;
    private $mysqli;
    private $enabled = true;

    /** Текущее «действие» (user_actions.id) — проставляется в строки account_history */
    private $currentActionId = null;
    /** Таблица, над которой идёт текущее действие */
    private $currentActionTable = null;

    private function __construct() {
        $this->mysqli = Database::getInstance()->getConnection();
        $this->ensureTableExists();
    }

    /**
     * Автоматическое создание таблицы account_history, если она не существует
     */
    private function ensureTableExists(): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $result = $this->mysqli->query("SHOW TABLES LIKE 'account_history'");
        if ($result && $result->num_rows === 0) {
            $sql = "CREATE TABLE IF NOT EXISTS `account_history` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `account_id` INT NOT NULL,
                `field_name` VARCHAR(255) NOT NULL,
                `old_value` TEXT,
                `new_value` TEXT,
                `changed_by` VARCHAR(255) NOT NULL,
                `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `ip_address` VARCHAR(45),
                INDEX `idx_account_id` (`account_id`),
                INDEX `idx_changed_at` (`changed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $this->mysqli->query($sql);
        }

        // Миграция под undo: action_id/table_name в account_history + журнал user_actions.
        // Колонки NULL-able — старые строки истории не трогаем.
        $col = $this->mysqli->query("SHOW COLUMNS FROM `account_history` LIKE 'action_id'");
        if ($col && $col->num_rows === 0) {
            $this->mysqli->query(
                "ALTER TABLE `account_history`
                    ADD COLUMN `action_id` INT NULL DEFAULT NULL AFTER `ip_address`,
                    ADD COLUMN `table_name` VARCHAR(64) NULL DEFAULT NULL AFTER `action_id`,
                    ADD INDEX `idx_action_id` (`action_id`)"
            );
        }

        $this->mysqli->query("CREATE TABLE IF NOT EXISTS `user_actions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL,
            `action_type` VARCHAR(32) NOT NULL,
            `table_name` VARCHAR(64) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `affected_count` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `undone_at` TIMESTAMP NULL DEFAULT NULL,
            `undo_action_id` INT NULL DEFAULT NULL,
            INDEX `idx_user_created` (`username`, `id`),
            INDEX `idx_user_undone` (`username`, `undone_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Начало логического «действия» пользователя (для undo и журнала операций).
     * Все последующие logChange/logBulkChange до finishAction() получат этот action_id.
     *
     * @param string $type      Тип: update_status | update_field | bulk_update_field | delete | mass_transfer | undo
     * @param string $tableName Таблица, над которой идёт действие
     * @param string $description Человекочитаемое описание для UI
     * @return int|null ID действия или null при ошибке (логирование не должно ломать операцию)
     */
    public function beginAction(string $type, string $tableName, string $description): ?int {
        if (!$this->enabled || !$this->isValidTableName($tableName)) {
            return null;
        }
        $username = $_SESSION['username'] ?? 'system';
        $description = mb_substr($description, 0, 500);

        $stmt = $this->mysqli->prepare(
            "INSERT INTO user_actions (username, action_type, table_name, description) VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ssss', $username, $type, $tableName, $description);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return null;
        }

        $this->currentActionId = (int)$this->mysqli->insert_id;
        $this->currentActionTable = $tableName;
        return $this->currentActionId;
    }

    /**
     * Завершение действия: фиксирует фактическое число затронутых строк.
     * Действие без затронутых строк удаляется из журнала (нечего отменять).
     */
    public function finishAction(int $affectedCount): void {
        if ($this->currentActionId === null) {
            return;
        }
        $actionId = $this->currentActionId;
        $this->currentActionId = null;
        $this->currentActionTable = null;

        if ($affectedCount <= 0) {
            $stmt = $this->mysqli->prepare("DELETE FROM user_actions WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $actionId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $stmt = $this->mysqli->prepare("UPDATE user_actions SET affected_count = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $affectedCount, $actionId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Валидация имени таблицы (как в TableResolver — защита от SQL-инъекций)
     */
    private function isValidTableName(string $name): bool {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $name) && strlen($name) <= 64;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Список полей, которые не должны логироваться (чувствительные данные).
     * Публичный static — используется UndoService для исключения полей из отката.
     */
    public static function sensitiveFields(): array {
        return [
            'password',
            'email_password',
            'token',
            'cookies',
            'first_cookie',
            'two_fa',
            'api_key',
            'secret',
            'auth_token',
            'access_token',
            'private_key'
        ];
    }
    
    /**
     * Проверка, является ли поле чувствительным
     * 
     * @param string $fieldName Название поля
     * @return bool
     */
    private function isSensitiveField(string $fieldName): bool {
        return in_array(strtolower($fieldName), self::sensitiveFields(), true);
    }
    
    /**
     * Логирование изменения поля
     * 
     * @param int $accountId ID аккаунта
     * @param string $fieldName Название поля
     * @param mixed $oldValue Старое значение
     * @param mixed $newValue Новое значение
     * @param string $changedBy Пользователь, который внёс изменение
     * @return bool
     */
    public function logChange(int $accountId, string $fieldName, $oldValue, $newValue, string $changedBy = null): bool {
        if (!$this->enabled) {
            return false;
        }
        
        // НЕ логируем чувствительные поля (пароли, токены и т.д.)
        if ($this->isSensitiveField($fieldName)) {
            // Логируем только факт изменения, без значений
            $oldValueStr = '[СКРЫТО]';
            $newValueStr = '[СКРЫТО]';
        } else {
            // Преобразуем значения в строки для хранения
            $oldValueStr = $this->valueToString($oldValue);
            $newValueStr = $this->valueToString($newValue);
        }
        
        // Получаем IP адрес
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        
        // Получаем пользователя из сессии
        if ($changedBy === null) {
            $changedBy = $_SESSION['username'] ?? 'system';
        }
        
        // Пропускаем, если значения не изменились (только для нечувствительных полей)
        if (!$this->isSensitiveField($fieldName) && $oldValueStr === $newValueStr) {
            return false;
        }
        
        $sql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address, action_id, table_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            require_once __DIR__ . '/Logger.php';
            Logger::error('Audit log: Failed to prepare statement', ['error' => $this->mysqli->error]);
            return false;
        }

        $stmt->bind_param('isssssis', $accountId, $fieldName, $oldValueStr, $newValueStr, $changedBy, $ipAddress, $this->currentActionId, $this->currentActionTable);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Логирование массового изменения
     * 
     * @param array $accountIds Массив ID аккаунтов
     * @param string $fieldName Название поля
     * @param mixed $oldValue Старое значение (может быть массивом)
     * @param mixed $newValue Новое значение
     * @param string $changedBy Пользователь
     * @param string|null $tableName Таблица, из которой читать старые значения.
     *                    Если не передана — берётся таблица текущего действия (beginAction).
     * @return int Количество записанных логов
     */
    public function logBulkChange(array $accountIds, string $fieldName, $oldValue, $newValue, string $changedBy = null, ?string $tableName = null): int {
        if (!$this->enabled || empty($accountIds)) {
            return 0;
        }

        // Защита от SQL injection через имя колонки
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
            return 0;
        }

        // Раньше старые значения всегда читались из `accounts` — для других таблиц
        // (?table=xxx) история получалась неверной. Теперь таблица обязана быть явной.
        $tableName = $tableName ?? $this->currentActionTable ?? 'accounts';
        if (!$this->isValidTableName($tableName)) {
            return 0;
        }
        
        $count = 0;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        
        if ($changedBy === null) {
            $changedBy = $_SESSION['username'] ?? 'system';
        }
        
        // Проверяем, является ли поле чувствительным
        $isSensitive = $this->isSensitiveField($fieldName);
        
        if ($isSensitive) {
            // Для чувствительных полей не получаем старые значения из БД
            $oldValueStr = '[СКРЫТО]';
        } else {
            $oldValueStr = null; // Будет получено из БД
        }
        
        $newValueStr = $isSensitive ? '[СКРЫТО]' : $this->valueToString($newValue);
        
        // Для массовых изменений получаем старые значения из БД (только для нечувствительных полей)
        if (!$isSensitive) {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $sql = "SELECT id, `$fieldName` FROM `$tableName` WHERE id IN ($placeholders)";
            
            $stmt = $this->mysqli->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            
            $types = str_repeat('i', count($accountIds));
            $stmt->bind_param($types, ...$accountIds);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // Сохраняем старые значения для каждого аккаунта
            $oldValues = [];
            while ($row = $result->fetch_assoc()) {
                $oldValues[$row['id']] = $this->valueToString($row[$fieldName] ?? null);
            }
            $stmt->close();
        }
        
        // Собираем «нормализованные» строки. Для нечувствительных полей пропускаем
        // записи без фактического изменения.
        $rowsToInsert = [];
        foreach ($accountIds as $accountId) {
            if (!$isSensitive) {
                $accountOldValue = $oldValues[$accountId] ?? '';
                if ($accountOldValue === $newValueStr) {
                    continue;
                }
                $rowOldValue = $accountOldValue;
            } else {
                $rowOldValue = $oldValueStr; // уже '[СКРЫТО]'
            }
            $rowsToInsert[] = [$accountId, $fieldName, $rowOldValue, $newValueStr, $changedBy, $ipAddress];
        }

        if (empty($rowsToInsert)) {
            return 0;
        }

        // Чанкуем INSERT, чтобы не упереться в max_allowed_packet
        // при массовых операциях на 10k+ записей.
        $CHUNK = 500;
        $insertSql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address, action_id, table_name) VALUES ";
        $count = 0;

        foreach (array_chunk($rowsToInsert, $CHUNK) as $chunk) {
            $placeholders = [];
            $params       = [];
            $types        = '';
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = $row[0]; // account_id
                $params[] = $row[1]; // field_name
                $params[] = $row[2]; // old_value
                $params[] = $row[3]; // new_value
                $params[] = $row[4]; // changed_by
                $params[] = $row[5]; // ip_address
                $params[] = $this->currentActionId;
                $params[] = $tableName;
                $types   .= 'isssssis';
            }

            $stmt = $this->mysqli->prepare($insertSql . implode(', ', $placeholders));
            if (!$stmt) {
                // Не прерываем — логируем и пробуем следующий чанк.
                require_once __DIR__ . '/Logger.php';
                Logger::error('Audit log: failed to prepare chunked INSERT', [
                    'error' => $this->mysqli->error,
                    'chunk_size' => count($chunk),
                ]);
                continue;
            }
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $count += $stmt->affected_rows;
            } else {
                require_once __DIR__ . '/Logger.php';
                Logger::error('Audit log: chunked INSERT failed', [
                    'error' => $stmt->error,
                    'chunk_size' => count($chunk),
                ]);
            }
            $stmt->close();
        }

        return $count;
    }
    
    /**
     * Получение истории изменений аккаунта
     * 
     * @param int $accountId ID аккаунта
     * @param int $limit Лимит записей
     * @return array
     */
    public function getAccountHistory(int $accountId, int $limit = 100): array {
        $sql = "SELECT * FROM account_history 
                WHERE account_id = ? 
                ORDER BY changed_at DESC 
                LIMIT ?";
        
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param('ii', $accountId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        $stmt->close();
        return $history;
    }
    
    /**
     * Карта "кто/когда удалил" для набора аккаунтов — один запрос к account_history.
     * Берёт самое свежее событие field_name='deleted_at' с непустым new_value на аккаунт.
     * Используется страницей корзины, чтобы не делать запрос на каждую строку.
     *
     * @param array $accountIds
     * @return array<int,array{changed_by:string,changed_at:string}>
     */
    public function getDeletedByForAccounts(array $accountIds): array {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (empty($accountIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        // Самое свежее deleted-событие на аккаунт: сортируем DESC и берём первое встреченное.
        $sql = "SELECT account_id, changed_by, changed_at
                FROM account_history
                WHERE field_name = 'deleted_at'
                  AND new_value <> ''
                  AND account_id IN ($placeholders)
                ORDER BY changed_at DESC, id DESC";
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $types = str_repeat('i', count($accountIds));
        $stmt->bind_param($types, ...$accountIds);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $map = [];
        while ($row = $res->fetch_assoc()) {
            $aid = (int)$row['account_id'];
            if (isset($map[$aid])) {
                continue; // уже взяли самое свежее
            }
            $map[$aid] = [
                'changed_by' => (string)($row['changed_by'] ?? ''),
                'changed_at' => (string)($row['changed_at'] ?? ''),
            ];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Преобразование значения в строку для хранения
     *
     * @param mixed $value
     * @return string
     */
    private function valueToString($value): string {
        if ($value === null) {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }
    
    /**
     * Включение/отключение логирования
     * 
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }
    
    /**
     * Проверка, включено ли логирование
     * 
     * @return bool
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
}



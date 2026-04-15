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
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Список полей, которые не должны логироваться (чувствительные данные)
     */
    private function getSensitiveFields(): array {
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
        return in_array(strtolower($fieldName), $this->getSensitiveFields(), true);
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
        
        $sql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            require_once __DIR__ . '/Logger.php';
            Logger::error('Audit log: Failed to prepare statement', ['error' => $this->mysqli->error]);
            return false;
        }
        
        $stmt->bind_param('isssss', $accountId, $fieldName, $oldValueStr, $newValueStr, $changedBy, $ipAddress);
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
     * @return int Количество записанных логов
     */
    public function logBulkChange(array $accountIds, string $fieldName, $oldValue, $newValue, string $changedBy = null): int {
        if (!$this->enabled || empty($accountIds)) {
            return 0;
        }

        // Защита от SQL injection через имя колонки
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
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
            $sql = "SELECT id, `$fieldName` FROM accounts WHERE id IN ($placeholders)";
            
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
        
        $insertSql = "INSERT INTO account_history (account_id, field_name, old_value, new_value, changed_by, ip_address) VALUES ";
        $insertValues = [];
        $insertParams = [];
        $insertTypes = '';
        
        foreach ($accountIds as $accountId) {
            if (!$isSensitive) {
                // Для нечувствительных полей проверяем изменение
                $accountOldValue = $oldValues[$accountId] ?? '';
                if ($accountOldValue === $newValueStr) {
                    continue; // Пропускаем, если значение не изменилось
                }
                $oldValueStr = $accountOldValue;
            }
            
            // Для чувствительных полей всегда логируем факт изменения
            
            $insertValues[] = "(?, ?, ?, ?, ?, ?)";
            $insertParams[] = $accountId;
            $insertParams[] = $fieldName;
            $insertParams[] = $oldValueStr;
            $insertParams[] = $newValueStr;
            $insertParams[] = $changedBy;
            $insertParams[] = $ipAddress;
            $insertTypes .= 'isssss';
        }
        
        if (empty($insertValues)) {
            return 0;
        }
        
        // Массовая вставка
        $fullSql = $insertSql . implode(', ', $insertValues);
        $insertStmt = $this->mysqli->prepare($fullSql);
        if (!$insertStmt) {
            return 0;
        }
        
        $insertStmt->bind_param($insertTypes, ...$insertParams);
        $insertStmt->execute();
        $count = $insertStmt->affected_rows;
        $insertStmt->close();
        
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



<?php
/**
 * Класс для автоматической проверки и настройки структуры БД
 * Проверяет наличие таблиц, колонок, индексов и автоматически создает недостающие
 * 
 * Автоматически запускается при авторизации пользователя через auth.php
 * 
 * Что проверяется и создается:
 * - Таблицы: accounts, account_history, saved_filters, account_favorites, user_settings
 * - Колонки: все необходимые поля в каждой таблице
 * - Индексы: все необходимые индексы для производительности
 * 
 * Безопасность:
 * - Только добавление (не удаляет существующие данные)
 * - Валидация всех имен таблиц, колонок, индексов
 * - Использование prepared statements где возможно
 * - Проверка через INFORMATION_SCHEMA
 * 
 * Использование:
 * $schemaManager = new DatabaseSchemaManager($mysqli);
 * $results = $schemaManager->validateAndMigrate();
 * 
 * Результат:
 * [
 *   'tables_created' => ['accounts', 'account_history'],
 *   'columns_added' => ['accounts.deleted_at', 'accounts.updated_at'],
 *   'indexes_created' => ['accounts.idx_status', 'accounts.idx_deleted_at'],
 *   'errors' => []
 * ]
 */
class DatabaseSchemaManager {
    private $mysqli;
    private $dbName;
    
    /**
     * Конструктор
     * 
     * @param mysqli $mysqli Подключение к БД
     */
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
        // Получаем имя текущей БД
        $result = $this->mysqli->query("SELECT DATABASE()");
        if ($result) {
            $row = $result->fetch_row();
            $this->dbName = $row[0] ?? '';
        } else {
            $this->dbName = '';
        }
    }
    
    /**
     * Эталонная схема БД - все необходимые таблицы с их структурами
     * 
     * @return array Массив с определением всех необходимых таблиц
     */
    private function getRequiredSchema(): array {
        return [
            'accounts' => [
                'columns' => [
                    'id' => 'INT AUTO_INCREMENT',
                    'login' => 'VARCHAR(255) NOT NULL',
                    'password' => 'VARCHAR(255)',
                    'email' => 'VARCHAR(255)',
                    'email_password' => 'VARCHAR(255)',
                    'first_name' => 'VARCHAR(255)',
                    'last_name' => 'VARCHAR(255)',
                    'social_url' => 'TEXT',
                    'birth_day' => 'INT',
                    'birth_month' => 'INT',
                    'birth_year' => 'INT',
                    'token' => 'TEXT',
                    'ads_id' => 'VARCHAR(255)',
                    'cookies' => 'TEXT',
                    'user_agent' => 'TEXT',
                    'two_fa' => 'VARCHAR(255)',
                    'extra_info_1' => 'TEXT',
                    'extra_info_2' => 'TEXT',
                    'extra_info_3' => 'TEXT',
                    'extra_info_4' => 'TEXT',
                    'status' => 'VARCHAR(100)',
                    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                    'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    'deleted_at' => 'TIMESTAMP NULL DEFAULT NULL',
                    'PRIMARY KEY' => '(id)'
                ],
                'indexes' => [
                    'idx_status' => 'status',
                    'idx_created_at' => 'created_at',
                    'idx_updated_at' => 'updated_at',
                    'idx_deleted_at' => 'deleted_at',
                    'idx_status_deleted' => 'status, deleted_at'
                ],
                'engine' => 'InnoDB',
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci'
            ],
            'account_history' => [
                'columns' => [
                    'id' => 'INT AUTO_INCREMENT',
                    'account_id' => 'INT NOT NULL',
                    'field_name' => 'VARCHAR(255) NOT NULL',
                    'old_value' => 'TEXT',
                    'new_value' => 'TEXT',
                    'changed_by' => 'VARCHAR(255) NOT NULL',
                    'changed_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                    'ip_address' => 'VARCHAR(45)',
                    'PRIMARY KEY' => '(id)'
                ],
                'indexes' => [
                    'idx_account_id' => 'account_id',
                    'idx_changed_at' => 'changed_at',
                    'idx_changed_by' => 'changed_by',
                    'idx_account_changed' => 'account_id, changed_at'
                ],
                'engine' => 'InnoDB',
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci'
            ],
            'saved_filters' => [
                'columns' => [
                    'id' => 'INT AUTO_INCREMENT',
                    'user_id' => 'VARCHAR(255) NOT NULL',
                    'name' => 'VARCHAR(255) NOT NULL',
                    'filters' => 'JSON NOT NULL',
                    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                    'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    'PRIMARY KEY' => '(id)'
                ],
                'indexes' => [
                    'idx_user_id' => 'user_id',
                    'idx_created_at' => 'created_at'
                ],
                'engine' => 'InnoDB',
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci'
            ],
            'account_favorites' => [
                'columns' => [
                    'user_id' => 'VARCHAR(255) NOT NULL',
                    'account_id' => 'INT NOT NULL',
                    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                    'PRIMARY KEY' => '(user_id, account_id)'
                ],
                'indexes' => [
                    'idx_user_id' => 'user_id',
                    'idx_account_id' => 'account_id'
                ],
                'engine' => 'InnoDB',
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci'
            ],
            'user_settings' => [
                'columns' => [
                    'id' => 'INT AUTO_INCREMENT',
                    'username' => 'VARCHAR(255) NOT NULL',
                    'setting_type' => 'VARCHAR(100) NOT NULL',
                    'setting_value' => 'TEXT',
                    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                    'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    'PRIMARY KEY' => '(id)',
                    'UNIQUE KEY unique_user_setting' => '(username, setting_type)'
                ],
                'indexes' => [
                    'idx_username' => 'username',
                    'idx_setting_type' => 'setting_type'
                ],
                'engine' => 'InnoDB',
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci'
            ]
        ];
    }
    
    /**
     * Основной метод - проверяет и настраивает БД
     * 
     * @return array Результаты миграции с информацией о созданных объектах
     */
    public function validateAndMigrate(): array {
        $results = [
            'tables_created' => [],
            'columns_added' => [],
            'indexes_created' => [],
            'errors' => []
        ];
        
        $schema = $this->getRequiredSchema();
        
        foreach ($schema as $tableName => $tableDef) {
            // 1. Проверяем и создаем таблицу
            if (!$this->tableExists($tableName)) {
                if ($this->createTable($tableName, $tableDef)) {
                    $results['tables_created'][] = $tableName;
                } else {
                    $results['errors'][] = "Не удалось создать таблицу: $tableName";
                }
            } else {
                // 2. Проверяем и добавляем недостающие колонки
                foreach ($tableDef['columns'] as $columnName => $columnDef) {
                    // Пропускаем PRIMARY KEY и UNIQUE KEY - они обрабатываются отдельно
                    if ($columnName === 'PRIMARY KEY' || strpos($columnName, 'UNIQUE KEY') !== false) {
                        continue;
                    }
                    
                    if (!$this->columnExists($tableName, $columnName)) {
                        require_once __DIR__ . '/Logger.php';
                        Logger::info('DB Schema: Adding missing column', [
                            'table' => $tableName,
                            'column' => $columnName,
                            'definition' => $columnDef
                        ]);
                        
                        if ($this->addColumn($tableName, $columnName, $columnDef)) {
                            $results['columns_added'][] = "$tableName.$columnName";
                            Logger::info('DB Schema: Column added successfully', [
                                'table' => $tableName,
                                'column' => $columnName
                            ]);
                        } else {
                            $error = "Не удалось добавить колонку: $tableName.$columnName";
                            $results['errors'][] = $error;
                            Logger::error('DB Schema: Failed to add column', [
                                'table' => $tableName,
                                'column' => $columnName,
                                'error' => $this->mysqli->error ?? 'Unknown error'
                            ]);
                        }
                    } else {
                        require_once __DIR__ . '/Logger.php';
                        Logger::debug('DB Schema: Column already exists', [
                            'table' => $tableName,
                            'column' => $columnName
                        ]);
                    }
                }
                
                // 3. Проверяем и создаем недостающие индексы
                if (isset($tableDef['indexes'])) {
                    foreach ($tableDef['indexes'] as $indexName => $indexColumns) {
                        if (!$this->indexExists($tableName, $indexName)) {
                            if ($this->createIndex($tableName, $indexName, $indexColumns)) {
                                $results['indexes_created'][] = "$tableName.$indexName";
                            } else {
                                $results['errors'][] = "Не удалось создать индекс: $tableName.$indexName";
                            }
                        }
                    }
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Проверка существования таблицы
     * 
     * @param string $tableName Имя таблицы
     * @return bool
     */
    private function tableExists(string $tableName): bool {
        // Валидация имени таблицы
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            return false;
        }
        
        if (empty($this->dbName)) {
            // Fallback
            $result = $this->mysqli->query("SHOW TABLES LIKE '$tableName'");
            return $result && $result->num_rows > 0;
        }
        
        $sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param('ss', $this->dbName, $tableName);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return ($row['cnt'] ?? 0) > 0;
        }
        
        // Fallback
        $result = $this->mysqli->query("SHOW TABLES LIKE '$tableName'");
        return $result && $result->num_rows > 0;
    }
    
    /**
     * Проверка существования колонки
     * 
     * @param string $tableName Имя таблицы
     * @param string $columnName Имя колонки
     * @return bool
     */
    private function columnExists(string $tableName, string $columnName): bool {
        // Валидация имен
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
            return false;
        }
        
        if (empty($this->dbName)) {
            // Fallback
            $result = $this->mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            return $result && $result->num_rows > 0;
        }
        
        $sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param('sss', $this->dbName, $tableName, $columnName);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return ($row['cnt'] ?? 0) > 0;
        }
        
        // Fallback
        $result = $this->mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return $result && $result->num_rows > 0;
    }
    
    /**
     * Проверка существования индекса
     * 
     * @param string $tableName Имя таблицы
     * @param string $indexName Имя индекса
     * @return bool
     */
    private function indexExists(string $tableName, string $indexName): bool {
        // Валидация имен
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
            return false;
        }
        
        if (empty($this->dbName)) {
            // Fallback
            $result = $this->mysqli->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
            return $result && $result->num_rows > 0;
        }
        
        $sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param('sss', $this->dbName, $tableName, $indexName);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return ($row['cnt'] ?? 0) > 0;
        }
        
        // Fallback
        $result = $this->mysqli->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
        return $result && $result->num_rows > 0;
    }
    
    /**
     * Создание таблицы
     * 
     * @param string $tableName Имя таблицы
     * @param array $tableDef Определение таблицы
     * @return bool
     */
    private function createTable(string $tableName, array $tableDef): bool {
        // Валидация имени таблицы
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            return false;
        }
        
        $columns = [];
        $primaryKey = null;
        $uniqueKeys = [];
        
        // Формируем список колонок
        foreach ($tableDef['columns'] as $columnName => $columnDef) {
            if ($columnName === 'PRIMARY KEY') {
                $primaryKey = $columnDef;
            } elseif (strpos($columnName, 'UNIQUE KEY') !== false) {
                $uniqueKeys[] = $columnDef;
            } else {
                // Валидация имени колонки
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
                    continue;
                }
                $columns[] = "`$columnName` $columnDef";
            }
        }
        
        // Добавляем PRIMARY KEY
        if ($primaryKey) {
            $columns[] = "PRIMARY KEY $primaryKey";
        }
        
        // Добавляем UNIQUE KEY
        foreach ($uniqueKeys as $uniqueKey) {
            $columns[] = "UNIQUE KEY $uniqueKey";
        }
        
        $engine = $tableDef['engine'] ?? 'InnoDB';
        $charset = $tableDef['charset'] ?? 'utf8mb4';
        $collate = $tableDef['collate'] ?? 'utf8mb4_unicode_ci';
        
        $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (\n    " . 
               implode(",\n    ", $columns) . 
               "\n) ENGINE=$engine DEFAULT CHARSET=$charset COLLATE=$collate";
        
        if ($this->mysqli->query($sql)) {
            // Создаем индексы после создания таблицы
            if (isset($tableDef['indexes'])) {
                foreach ($tableDef['indexes'] as $indexName => $indexColumns) {
                    $this->createIndex($tableName, $indexName, $indexColumns);
                }
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Добавление колонки в таблицу
     * 
     * @param string $tableName Имя таблицы
     * @param string $columnName Имя колонки
     * @param string $columnDef Определение колонки
     * @return bool
     */
    private function addColumn(string $tableName, string $columnName, string $columnDef): bool {
        // Валидация имен
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
            return false;
        }
        
        // Определяем позицию для новой колонки (после последней существующей)
        $position = '';
        if ($columnName === 'deleted_at') {
            // Для deleted_at добавляем после updated_at, если он существует
            if ($this->columnExists($tableName, 'updated_at')) {
                $position = ' AFTER `updated_at`';
            }
        } elseif ($columnName === 'updated_at') {
            // Для updated_at добавляем после created_at, если он существует
            if ($this->columnExists($tableName, 'created_at')) {
                $position = ' AFTER `created_at`';
            }
        }
        
        $sql = "ALTER TABLE `$tableName` ADD COLUMN `$columnName` $columnDef$position";
        
        return $this->mysqli->query($sql) !== false;
    }
    
    /**
     * Создание индекса
     * 
     * @param string $tableName Имя таблицы
     * @param string $indexName Имя индекса
     * @param string $columns Колонки для индекса
     * @return bool
     */
    private function createIndex(string $tableName, string $indexName, string $columns): bool {
        // Валидация имен
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
            return false;
        }
        
        // Валидация колонок (разрешаем только буквы, цифры, подчеркивания, запятые и пробелы)
        if (!preg_match('/^[a-zA-Z0-9_,\s()]+$/', $columns)) {
            return false;
        }
        
        $sql = "CREATE INDEX `$indexName` ON `$tableName` ($columns)";
        
        return $this->mysqli->query($sql) !== false;
    }
}


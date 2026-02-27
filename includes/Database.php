<?php
/**
 * Оптимизированный класс для работы с базой данных
 * 
 * Реализует паттерн Singleton для единого подключения к БД.
 * Предоставляет методы для выполнения prepared statements с кэшированием.
 * Обеспечивает безопасную работу с SQL-запросами.
 * 
 * @package includes
 */
class Database {
    private static $instance = null;
    private $mysqli;
    private $queryCache = [];
    private $cacheEnabled = true;
    private $cacheTimeout = 300; // 5 минут
    private $maxCacheSize = 100; // Максимальное количество записей в кэше
    
    private function __construct() {
        // Используем уже созданное глобальное подключение из config.php, чтобы избежать дублирования соединений
        // и обеспечить единые настройки для всего приложения.
        // При отсутствии глобального подключения — создаем новое.
        global $mysqli, $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT;

        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $this->mysqli = $mysqli;
        } else {
            // Если глобальное подключение не установлено, проверяем параметры в сессии
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['db_config']) && is_array($_SESSION['db_config'])) {
                $dbConfig = $_SESSION['db_config'];
                $host = $dbConfig['host'] ?? 'localhost';
                $user = $dbConfig['user'] ?? '';
                $password = $dbConfig['password'] ?? '';
                $database = $dbConfig['database'] ?? '';
                $port = $dbConfig['port'] ?? 3306;
                $charset = $dbConfig['charset'] ?? 'utf8mb4';
                
                $this->mysqli = new mysqli($host, $user, $password, $database, $port);
                if ($this->mysqli->connect_errno) {
                    require_once __DIR__ . '/Logger.php';
                    Logger::error('DB connect failed', ['error' => $this->mysqli->connect_error]);
                    throw new Exception('Database connection failed');
                }
                $this->mysqli->set_charset($charset);
            } else {
                // Используем глобальные переменные (fallback)
                $this->mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
                if ($this->mysqli->connect_errno) {
                    require_once __DIR__ . '/Logger.php';
                    Logger::error('DB connect failed', ['error' => $this->mysqli->connect_error]);
                    throw new Exception('Database connection failed');
                }
                $this->mysqli->set_charset('utf8mb4');
            }
        }

        // Единые настройки кодировки и сессионных параметров (повторный вызов безопасен)
        // Кодировка уже установлена выше, но повторный вызов безопасен
        if (!isset($this->mysqli->charset) || $this->mysqli->charset !== 'utf8mb4') {
            $this->mysqli->set_charset('utf8mb4');
        }
        $this->mysqli->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        $this->mysqli->query("SET SESSION innodb_lock_wait_timeout = 5");
        $this->mysqli->query("SET SESSION max_execution_time = 30000");
    }
    
    /**
     * Получение единственного экземпляра класса (Singleton)
     * 
     * @return Database Экземпляр класса Database
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Получение подключения к БД
     * 
     * @return mysqli Объект подключения MySQLi
     */
    public function getConnection(): mysqli {
        return $this->mysqli;
    }
    
    /**
     * Выполнение подготовленного SQL-запроса с кэшированием
     * 
     * @param string $sql SQL-запрос с плейсхолдерами (?)
     * @param array $params Параметры для подстановки в запрос
     * @param string|null $cacheKey Ключ кэша (опционально)
     * @return array Массив результатов запроса
     * @throws Exception При ошибке выполнения запроса
     */
    public function prepare(string $sql, array $params = [], ?string $cacheKey = null): array {
        // Проверяем кэш для SELECT запросов
        if ($cacheKey && $this->cacheEnabled && strpos(strtoupper(trim($sql)), 'SELECT') === 0) {
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            require_once __DIR__ . '/Logger.php';
            Logger::error('SQL prepare error', [
                'error' => $this->mysqli->error,
                'sql' => substr($sql, 0, 200) // Обрезаем для безопасности
            ]);
            throw new Exception('SQL prepare error: ' . $this->mysqli->error);
        }
        
        if ($params) {
            $types = '';
            foreach ($params as $p) {
                $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
            }
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            require_once __DIR__ . '/Logger.php';
            Logger::error('SQL execute error', [
                'error' => $stmt->error,
                'sql' => substr($sql, 0, 200) // Обрезаем для безопасности
            ]);
            $stmt->close();
            throw new Exception('SQL execute error: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        
        // Кэшируем результат SELECT запросов
        if ($cacheKey && $this->cacheEnabled && strpos(strtoupper(trim($sql)), 'SELECT') === 0) {
            $this->setCache($cacheKey, $data);
        }
        
        return $data;
    }
    
    /**
     * Быстрый подсчет строк в таблице с кэшированием
     * 
     * @param string $table Имя таблицы
     * @param string $where Условие WHERE (без ключевого слова WHERE)
     * @param array $params Параметры для подстановки
     * @param string|null $cacheKey Ключ кэша (опционально)
     * @return int Количество строк
     */
    public function getCount(string $table, string $where = '', array $params = [], ?string $cacheKey = null): int {
        $sql = "SELECT COUNT(*) as count FROM `$table`";
        if ($where) {
            $sql .= " WHERE $where";
        }
        
        $result = $this->prepare($sql, $params, $cacheKey);
        return $result[0]['count'] ?? 0;
    }
    
    /**
     * Оптимизированная пагинация записей
     * 
     * @param string $table Имя таблицы
     * @param string $columns Список колонок для выборки
     * @param string $where Условие WHERE (без ключевого слова WHERE)
     * @param array $params Параметры для подстановки
     * @param string $orderBy Условие ORDER BY
     * @param int $limit Количество записей
     * @param int $offset Смещение
     * @return array Массив записей
     */
    public function getPaginated(string $table, string $columns = '*', string $where = '', array $params = [], string $orderBy = 'id ASC', int $limit = 100, int $offset = 0): array {
        $sql = "SELECT $columns FROM `$table`";
        if ($where) {
            $sql .= " WHERE $where";
        }
        $sql .= " ORDER BY $orderBy LIMIT $limit OFFSET $offset";
        
        return $this->prepare($sql, $params);
    }
    
    // Простое кэширование в памяти (для одного запроса)
    private function getFromCache($key) {
        if (isset($this->queryCache[$key])) {
            $cache = $this->queryCache[$key];
            $ttl = $cache['ttl'] ?? $this->cacheTimeout;
            if (time() - $cache['time'] < $ttl) {
                return $cache['data'];
            }
            unset($this->queryCache[$key]);
        }
        return null;
    }
    
    private function setCache($key, $data, $ttl = null) {
        // Ограничиваем размер кэша - удаляем самые старые записи
        if (count($this->queryCache) >= $this->maxCacheSize) {
            // Сортируем по времени и удаляем самые старые
            uasort($this->queryCache, function($a, $b) {
                return $a['time'] <=> $b['time'];
            });
            // Оставляем только половину самых свежих
            $this->queryCache = array_slice($this->queryCache, -($this->maxCacheSize / 2), null, true);
        }
        
        $this->queryCache[$key] = [
            'data' => $data,
            'time' => time(),
            'ttl' => $ttl ?? $this->cacheTimeout
        ];
    }
    
    public function clearCache() {
        $this->queryCache = [];
    }
    
    /**
     * Публичный метод для получения данных из кэша
     * 
     * @param string $key Ключ кэша
     * @return mixed|null Данные или null если не найдено/истекло
     */
    public function getCached($key) {
        return $this->getFromCache($key);
    }
    
    /**
     * Публичный метод для сохранения данных в кэш
     * 
     * @param string $key Ключ кэша
     * @param mixed $data Данные для кэширования
     * @param int $ttl Время жизни в секундах (опционально)
     */
    public function cache($key, $data, $ttl = null) {
        $this->setCache($key, $data, $ttl);
    }
    
    /**
     * Проверка и создание индексов для производительности.
     * Если флаг .optimization_applied есть (индексы уже применялись через apply_indexes_safe.php),
     * проверка пропускается — иначе при каждом запросе выполняется 12+ запросов к INFORMATION_SCHEMA.
     *
     * @return void
     */
    public function ensureIndexes(): void {
        $flagFile = dirname(__DIR__) . '/.optimization_applied';
        $fallbackFlag = sys_get_temp_dir() . '/dashboard_opt_' . md5(dirname(__DIR__)) . '.applied';
        if (file_exists($flagFile) || file_exists($fallbackFlag)) {
            return;
        }

        require_once __DIR__ . '/Logger.php';

        $indexes = [
            'accounts' => [
                'idx_status' => 'status',
                'idx_created_at' => 'created_at',
                'idx_updated_at' => 'updated_at',
                'idx_email' => 'email(255)',
                'idx_login' => 'login(255)',
                // Индексы для быстрых точных совпадений при переносе по внешним ID
                'idx_ads_id' => 'ads_id',
                'idx_id_soc_account' => 'id_soc_account',
                'idx_status_created' => 'status, created_at',
                'idx_compound_search' => 'status, created_at, updated_at',
                // НОВЫЕ индексы для оптимизации фильтров (2-5x ускорение)
                'idx_email_status' => 'email(255), status',
                'idx_two_fa' => 'two_fa(100)',
                'idx_token' => 'token(255)',
                // Индекс для soft delete - критически важен для производительности
                'idx_deleted_at' => 'deleted_at'
            ]
        ];
        
        Logger::debug('DATABASE: Checking and creating indexes');
        
        foreach ($indexes as $table => $tableIndexes) {
            foreach ($tableIndexes as $indexName => $columns) {
                $this->createIndexIfNotExists($table, $indexName, $columns);
            }
        }
        
        Logger::debug('DATABASE: Index check completed');
    }
    
    private function createIndexIfNotExists($table, $indexName, $columns) {
        // Безопасная проверка существования индекса через INFORMATION_SCHEMA
        $dbName = $this->mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        
        // Валидация имени таблицы (только буквы, цифры, подчеркивания)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            require_once __DIR__ . '/Logger.php';
            Logger::error("DATABASE: Invalid table name", ['table' => $table]);
            return;
        }
        
        // Валидация имени индекса
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
            require_once __DIR__ . '/Logger.php';
            Logger::error("DATABASE: Invalid index name", ['index' => $indexName]);
            return;
        }
        
        // Проверяем существование индекса через INFORMATION_SCHEMA
        $sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param('sss', $dbName, $table, $indexName);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $indexExists = ($row['cnt'] ?? 0) > 0;
            $stmt->close();
        } else {
            // Fallback на старый способ - используем prepared statement для безопасности
            $stmt = $this->mysqli->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
            if ($stmt) {
                $stmt->bind_param('s', $indexName);
                $stmt->execute();
                $result = $stmt->get_result();
                $indexExists = $result && $result->num_rows > 0;
                $stmt->close();
            } else {
                $indexExists = false;
            }
        }
        
        if (!$indexExists) {
            require_once __DIR__ . '/Logger.php';
            
            // DDL запросы не поддерживают prepared statements, но мы валидировали имена
            $sql = "CREATE INDEX `$indexName` ON `$table` ($columns)";
            if ($this->mysqli->query($sql)) {
                Logger::info("DATABASE: Created index $indexName on table $table");
            } else {
                Logger::error("DATABASE: Failed to create index $indexName", [
                    'table' => $table,
                    'error' => $this->mysqli->error
                ]);
            }
        }
    }
    
    /**
     * Проверка существования таблицы
     * 
     * @param string $tableName Имя таблицы
     * @return bool
     */
    public function tableExists(string $tableName): bool {
        // Валидация имени таблицы
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            return false;
        }
        
        // Кеш на уровне запроса — INFORMATION_SCHEMA не меняется между вызовами внутри одного PHP-скрипта
        static $tableExistsCache = [];
        if (isset($tableExistsCache[$tableName])) {
            return $tableExistsCache[$tableName];
        }
        
        $dbName = $this->mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        $sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param('ss', $dbName, $tableName);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            $tableExistsCache[$tableName] = ($row['cnt'] ?? 0) > 0;
            return $tableExistsCache[$tableName];
        }
        
        // Fallback - используем prepared statement для безопасности
        $stmt = $this->mysqli->prepare("SHOW TABLES LIKE ?");
        if ($stmt) {
            $pattern = $tableName;
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result && $result->num_rows > 0;
            $stmt->close();
            $tableExistsCache[$tableName] = $exists;
            return $exists;
        }
        $tableExistsCache[$tableName] = false;
        return false;
    }
    
    /**
     * Проверка существования индекса
     * 
     * @param string $tableName Имя таблицы
     * @param string $indexName Имя индекса
     * @return bool
     */
    public function indexExists(string $tableName, string $indexName): bool {
        // Валидация имен
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
            return false;
        }
        
        $dbName = $this->mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        $sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?";
        $stmt = $this->mysqli->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param('sss', $dbName, $tableName, $indexName);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return ($row['cnt'] ?? 0) > 0;
        }
        
        // Fallback - используем prepared statement для безопасности
        $stmt = $this->mysqli->prepare("SHOW INDEX FROM `$tableName` WHERE Key_name = ?");
        if ($stmt) {
            $stmt->bind_param('s', $indexName);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result && $result->num_rows > 0;
            $stmt->close();
            return $exists;
        }
        return false;
    }
    
    /**
     * Безопасное выполнение DDL-запросов с валидацией
     * 
     * @param string $sql SQL-запрос (CREATE TABLE, CREATE INDEX и т.д.)
     * @param array $allowedTables Whitelist разрешенных имен таблиц
     * @return bool Успешность выполнения
     */
    public function executeDDL(string $sql, array $allowedTables = []): bool {
        // Валидация: проверяем, что запрос содержит только разрешенные таблицы
        if (!empty($allowedTables)) {
            // Извлекаем имена таблиц из SQL
            preg_match_all('/`?([a-zA-Z0-9_]+)`?/', $sql, $matches);
            $foundTables = array_unique($matches[1] ?? []);
            
            foreach ($foundTables as $table) {
                if (!in_array($table, $allowedTables, true)) {
                    require_once __DIR__ . '/Logger.php';
                    Logger::error("DATABASE: Table not in whitelist", ['table' => $table]);
                    return false;
                }
            }
        }
        
        // DDL запросы не поддерживают prepared statements, но мы валидировали имена
        return $this->mysqli->query($sql) !== false;
    }
    
    public function __destruct() {
        if ($this->mysqli) {
            $this->mysqli->close();
        }
    }
}

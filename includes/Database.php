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
    
    /** @var bool Если true — подключение было создано нами, можно закрыть в __destruct */
    private $ownsConnection = false;

    private function __construct() {
        // Используем уже созданное глобальное подключение из config.php, чтобы избежать дублирования соединений
        // и обеспечить единые настройки для всего приложения.
        // При отсутствии глобального подключения — создаем новое.
        global $mysqli, $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT;

        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $this->mysqli = $mysqli;
            // НЕ закрываем чужое подключение в __destruct
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
                $this->ownsConnection = true;
            } else {
                // Используем глобальные переменные (fallback)
                $this->mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
                if ($this->mysqli->connect_errno) {
                    require_once __DIR__ . '/Logger.php';
                    Logger::error('DB connect failed', ['error' => $this->mysqli->connect_error]);
                    throw new Exception('Database connection failed');
                }
                $this->ownsConnection = true;
                $this->mysqli->set_charset('utf8mb4');
            }
        }

        // Единые настройки кодировки и сессионных параметров (повторный вызов безопасен)
        // Кодировка уже установлена выше, но повторный вызов безопасен
        if (!isset($this->mysqli->charset) || $this->mysqli->charset !== 'utf8mb4') {
            $this->mysqli->set_charset('utf8mb4');
        }
        // STRICT_TRANS_TABLES убран намеренно: функциональный индекс (CAST(login AS UNSIGNED))
        // бросает "Data truncated" в strict mode для строковых логинов ('user@email.com' etc.).
        // Данные защищены prepared statements + PHP-валидацией в AccountsRepository.
        // NO_ZERO_DATE и ERROR_FOR_DIVISION_BY_ZERO сохраняем для защиты дат и деления на 0.
        $this->mysqli->query("SET SESSION sql_mode = 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        // innodb_lock_wait_timeout = 5 сек: если строка заблокирована другим запросом,
        // быстро возвращаем ошибку вместо бесконечного ожидания
        $this->mysqli->query("SET SESSION innodb_lock_wait_timeout = 5");
        // max_execution_time = 120 000 мс (2 мин): защита от зависших SELECT запросов.
        // Только для SELECT — UPDATE/INSERT/DELETE этот параметр не ограничивает.
        // Для mass_transfer.php отдельно вызывается set_time_limit(0).
        $this->mysqli->query("SET SESSION max_execution_time = 120000");
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
        // Ограничиваем размер кэша - удаляем самые старые 10% записей
        if (count($this->queryCache) >= $this->maxCacheSize) {
            // Удаляем самые старые 10% записей (более эффективно чем array_slice)
            $keysToDelete = (int)ceil($this->maxCacheSize * 0.1);
            $sortedKeys = array_keys($this->queryCache);
            usort($sortedKeys, function($a, $b) {
                return $this->queryCache[$a]['time'] <=> $this->queryCache[$b]['time'];
            });
            $keysToRemove = array_slice($sortedKeys, 0, $keysToDelete);
            foreach ($keysToRemove as $k) {
                unset($this->queryCache[$k]);
            }
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
     * Индексы, которые приложение создаёт на лету (ensureIndexes()).
     *
     * ВАЖНО: каждая колонка отсюда обязана присутствовать в эталонной схеме
     * DatabaseSchemaManager::getRequiredSchema(), иначе на свежесозданной БД
     * CREATE INDEX падает с «Key column ... doesn't exist in table» на каждом
     * запросе. Это проверяет tests/test_schema_index_columns.php.
     */
    private const MANAGED_INDEXES = [
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
            'idx_deleted_at' => 'deleted_at',
            // (deleted_at, status) — именно в таком порядке. Все агрегаты дашборда
            // фильтруют по deleted_at и группируют по status; с обратным порядком
            // (idx_status_deleted) оптимизатор их не использует и строит temporary table.
            // Замер на 180 300 строк: 497 мс → 18 мс. (проверено 2026-08-08)
            'idx_deleted_status' => 'deleted_at, status',
            // (deleted_at, status, updated_at, created_at) — idx_deleted_status
            // с хвостом из дат. Нужен потому, что главный агрегат дашборда
            // считает не только COUNT по статусу, но и «сколько за 24 часа»:
            //   SUM(CASE WHEN COALESCE(updated_at, created_at) >= NOW()-1day ...)
            // Двухколоночного индекса для этого мало — за датами приходится
            // лезть в сами строки (Using index condition), и запрос стоил 0,788 с.
            // С хвостом он читается целиком из индекса (Using index): 0,042 с,
            // то есть в 19 раз быстрее. Тот же индекс закрывает и оба запроса
            // спарклайна (COUNT по created_at) — отдельный idx_deleted_created
            // проверен и оказался лишним, поэтому его здесь нет.
            // Замер на 182 021 строке. (проверено 2026-08-10)
            'idx_deleted_status_dates' => 'deleted_at, status, updated_at, created_at',
            // Покрывающие индексы под сбор значений фильтров
            // (StatisticsService::computeUniqueFilterValues — UNION ALL из пяти
            // GROUP BY). Порядок тот же, что у idx_deleted_status и по той же
            // причине: сначала deleted_at, по которому фильтруем, потом колонка,
            // по которой группируем — тогда запрос читается прямо из индекса.
            // Замер на стенде с прод-формой данных (182 021 строка): весь
            // UNION ALL 1,195 с → 0,100 с. Разница именно в покрытии: у status
            // индекс был и он отрабатывал 25 мс, четыре остальные колонки шли
            // полным сканом с Using temporary по 124–147 мс каждая.
            // (проверено 2026-08-10)
            'idx_deleted_status_marketplace' => 'deleted_at, status_marketplace',
            'idx_deleted_currency'           => 'deleted_at, currency',
            'idx_deleted_geo'                => 'deleted_at, geo',
            'idx_deleted_status_rk'          => 'deleted_at, status_rk',
        ]
    ];

    /**
     * Колонки, которые есть на проде, но которых НЕТ в эталонной схеме
     * DatabaseSchemaManager::getRequiredSchema().
     *
     * Так исторически сложилось: их когда-то завели на боевой БД руками, а в
     * эталон не внесли. Весь читающий код (StatisticsService, FilterBuilder)
     * ходит к ним через columnExists(), поэтому на стенде, поднятом эталоном,
     * соответствующие фильтры просто отсутствуют. Из-за этого прод и не
     * воспроизводился локально: тяжёлые запросы по этим колонкам там были,
     * а на стенде их не было вовсе.
     *
     * Здесь список нужен ровно для двух вещей:
     *  1. ensureIndexes() молча пропускает индекс, если колонки в этой БД нет —
     *     иначе CREATE INDEX падал бы и писал ERROR в лог на каждом стенде;
     *  2. tests/test_schema_index_columns.php знает, что для этих колонок
     *     расхождение с эталоном — осознанное, а не забытая колонка.
     *
     * ВАЖНО: добавлять сюда колонку можно, только если ВСЕ её читатели идут
     * через columnExists(). Иначе колонку надо заводить в эталон, а не сюда.
     *
     * @var string[]
     */
    public const OPTIONAL_INDEX_COLUMNS = [
        'status_marketplace',
        'currency',
        'geo',
        'status_rk',
    ];

    /**
     * Проверка и создание индексов для производительности.
     *
     * Проверка стоит по одному запросу к INFORMATION_SCHEMA на индекс (замер на
     * MySQL 8: ~5,7 мс каждый, то есть ~74 мс на запрос страницы), поэтому она
     * должна выполняться один раз, а не на каждый заход. Раньше пропуск зависел
     * от флага `.optimization_applied`, который создавался ТОЛЬКО вручную через
     * tools/migrations/create_optimization_flag.php — то есть на любой установке,
     * где этот скрипт не запускали, 13 лишних запросов платились вечно.
     *
     * Теперь флаг ставится автоматически после первого полного прохода, а его имя
     * содержит отпечаток самого списка MANAGED_INDEXES. Из этого следует главное
     * свойство: добавили или изменили индекс в списке — отпечаток поменялся,
     * старый флаг больше не подходит, и новый индекс будет создан при следующем
     * заходе без ручных действий.
     *
     * @return void
     */
    public function ensureIndexes(): void {
        $flagFile = $this->indexFlagPath();
        if (file_exists($flagFile)) {
            return;
        }

        require_once __DIR__ . '/Logger.php';

        Logger::debug('DATABASE: Checking and creating indexes');

        foreach (self::MANAGED_INDEXES as $table => $tableIndexes) {
            foreach ($tableIndexes as $indexName => $columns) {
                $this->createIndexIfNotExists($table, $indexName, $columns);
            }
        }

        // Флаг ставим и после «всё уже есть», и после создания: цель — не платить
        // за проверку повторно. Ошибку записи глотаем осознанно: не смогли
        // положить флаг — просто проверим ещё раз в следующий запрос, это дороже,
        // но не ломает ничего.
        if (@file_put_contents($flagFile, date('c') . "\n") === false) {
            Logger::debug('DATABASE: Не удалось записать флаг проверки индексов', ['file' => $flagFile]);
        }

        Logger::debug('DATABASE: Index check completed');
    }

    /**
     * Путь к флагу «индексы проверены», привязанный к отпечатку списка индексов.
     *
     * @return string
     */
    private function indexFlagPath(): string {
        $signature = substr(md5(json_encode(self::MANAGED_INDEXES)), 0, 12);
        $project   = md5(dirname(__DIR__));
        // Имя БД обязано входить в ключ. Вход в панель — это строка подключения,
        // то есть одна установка обслуживает СКОЛЬКО УГОДНО разных баз. Пока
        // ключа по базе не было, флаг, поставленный первой посещённой базой,
        // глушил ensureIndexes() для всех остальных: они оставались вообще без
        // управляемых индексов и работали полным сканом.
        // Воспроизведено на стенде 2026-08-10: вторая база получила только те
        // индексы, что создаёт сама схема (6 штук), и ни одного из MANAGED_INDEXES
        // (11 штук); после сброса флага все создались.
        $db = md5((string)self::nameOf($this->mysqli));

        return sys_get_temp_dir() . '/dashboard_idx_' . $project . '_' . $db . '_' . $signature . '.applied';
    }
    
    /**
     * Какие из колонок индекса отсутствуют в таблице.
     *
     * Спецификация колонок в MANAGED_INDEXES бывает с длиной префикса
     * («email(255), status») — её надо отрезать, в INFORMATION_SCHEMA лежит
     * голое имя колонки.
     *
     * @param string $table Имя таблицы (уже провалидировано вызывающим)
     * @param string $columns Спецификация колонок индекса через запятую
     * @return string[] Отсутствующие колонки; пустой массив — все на месте
     */
    private function missingIndexColumns($table, $columns) {
        $names = [];
        foreach (explode(',', $columns) as $part) {
            $name = trim(preg_replace('/\(\d+\)\s*$/', '', trim($part)), " \t`");
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return [];
        }

        $existing = $this->tableColumnNames($table);
        // Не смогли прочитать список колонок — не выдумываем, ведём себя как
        // раньше: пусть CREATE INDEX сам решает. Молчаливый пропуск нужного
        // индекса хуже, чем одна строчка в логе.
        if ($existing === null) {
            return [];
        }

        $missing = [];
        foreach ($names as $name) {
            if (!isset($existing[strtolower($name)])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /**
     * Имена колонок таблицы в нижнем регистре, в виде набора для isset().
     *
     * Кэшируется на процесс: ensureIndexes() зовёт это для каждого индекса,
     * а список колонок за один запрос не меняется.
     *
     * @param string $table Имя таблицы (уже провалидировано вызывающим)
     * @return array<string, true>|null null — не удалось прочитать
     */
    private function tableColumnNames($table) {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $cache[$table] = null;
        $stmt = $this->mysqli->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            return null;
        }
        $dbName = self::nameOf($this->mysqli);
        $stmt->bind_param('ss', $dbName, $table);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $names = [];
            while ($res && ($row = $res->fetch_assoc())) {
                $names[strtolower($row['COLUMN_NAME'])] = true;
            }
            $cache[$table] = $names === [] ? null : $names;
        }
        $stmt->close();

        return $cache[$table];
    }

    private function createIndexIfNotExists($table, $indexName, $columns) {
        // Безопасная проверка существования индекса через INFORMATION_SCHEMA
        $dbName = self::nameOf($this->mysqli);
        
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
        
        // Индекс по колонке, которой в этой БД нет, создать нельзя: MySQL ответит
        // «Key column '...' doesn't exist in table», а мы напишем ERROR в лог.
        // Ровно так уже ломался idx_id_soc_account. Для колонок из
        // OPTIONAL_INDEX_COLUMNS отсутствие — норма (см. докблок константы),
        // поэтому просто молча пропускаем индекс.
        $missing = $this->missingIndexColumns($table, $columns);
        if ($missing !== []) {
            require_once __DIR__ . '/Logger.php';
            Logger::debug('DATABASE: index skipped, columns absent', [
                'index'   => $indexName,
                'columns' => implode(', ', $missing),
            ]);
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
        
        $dbName = self::nameOf($this->mysqli);
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
        
        $dbName = self::nameOf($this->mysqli);
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
        // Закрываем только собственное подключение; глобальный $mysqli не трогаем
        if ($this->ownsConnection && $this->mysqli) {
            $this->mysqli->close();
        }
    }

    /**
     * Имя текущей БД с кэшем на соединение.
     *
     * `SELECT DATABASE()` вызывался в коде 19 раз за одну загрузку дашборда —
     * каждый раз это полноценный round-trip к серверу ради значения, которое в
     * рамках соединения не меняется. Кэш привязан к объекту соединения, поэтому
     * переключение БД (новый connect) даёт новое значение.
     *
     * @param mysqli $conn
     * @return string Имя базы или '' если получить не удалось
     */
    public static function nameOf($conn): string {
        static $cache = [];

        $key = spl_object_hash($conn);
        if (!array_key_exists($key, $cache)) {
            $res = $conn->query('SELECT DATABASE()');
            $row = $res ? $res->fetch_row() : null;
            $cache[$key] = (string)($row[0] ?? '');
        }

        return $cache[$key];
    }
}

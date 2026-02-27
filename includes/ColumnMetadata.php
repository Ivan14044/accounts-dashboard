<?php
/**
 * Класс для кэширования метаданных колонок таблицы accounts
 * Решает проблему множественных SHOW COLUMNS запросов
 */
class ColumnMetadata {
    private static $instance = null;
    private $metadata = null;
    private $cacheFile = null;
    private $mysqli = null;
    
    private function __construct($mysqli) {
        $this->mysqli = $mysqli;
        // Создаем уникальный кэш-файл для каждой БД (host+database+user)
        $dbName = $mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? 'unknown';
        $dbHost = $mysqli->host_info;
        $dbUser = $mysqli->user ?? 'unknown';
        $cacheKey = md5($dbHost . '|' . $dbName . '|' . $dbUser);
        $this->cacheFile = sys_get_temp_dir() . '/accounts_metadata_cache_' . $cacheKey . '.json';
        $this->loadMetadata();
    }
    
    public static function getInstance($mysqli) {
        // Сбрасываем instance, если БД изменилась (для поддержки разных БД)
        $dbName = $mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? 'unknown';
        $dbHost = $mysqli->host_info;
        $dbUser = $mysqli->user ?? 'unknown';
        $currentKey = md5($dbHost . '|' . $dbName . '|' . $dbUser);
        
        if (self::$instance === null) {
            self::$instance = new self($mysqli);
        } else {
            // Проверяем, что текущая БД совпадает с БД из кэша
            $cachedKey = md5($mysqli->host_info . '|' . ($dbName ?? 'unknown') . '|' . ($mysqli->user ?? 'unknown'));
            $currentCacheFile = sys_get_temp_dir() . '/accounts_metadata_cache_' . $cachedKey . '.json';
            if ($currentCacheFile !== self::$instance->cacheFile) {
                // БД изменилась, создаем новый instance
                self::$instance = new self($mysqli);
            }
        }
        return self::$instance;
    }
    
    /**
     * Загрузка метаданных из кэша или базы данных
     */
    private function loadMetadata() {
        // Попытка загрузить из кэша
        if (file_exists($this->cacheFile)) {
            $cacheData = @file_get_contents($this->cacheFile);
            if ($cacheData) {
                $cached = @json_decode($cacheData, true);
                // Проверяем что кэш свежий (не старше 1 часа) и что он для текущей БД
                if ($cached && isset($cached['timestamp']) && (time() - $cached['timestamp']) < 3600) {
                    // Проверяем, что структура таблицы не изменилась (проверяем наличие колонки id)
                    if (isset($cached['data']['allCols']) && in_array('id', $cached['data']['allCols'], true)) {
                        // Проверяем актуальность кэша: сравниваем количество колонок с реальным в БД.
                        // Это обнаруживает добавление/удаление колонок без ожидания истечения TTL (1 час).
                        $cacheIsValid = false;
                        try {
                            $dbName = $this->mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
                            $countStmt = $this->mysqli->prepare(
                                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'accounts'"
                            );
                            if ($countStmt) {
                                $countStmt->bind_param('s', $dbName);
                                $countStmt->execute();
                                $actualCount = (int)$countStmt->get_result()->fetch_row()[0];
                                $countStmt->close();
                                $cachedCount = count($cached['data']['allCols']);
                                if ($actualCount === $cachedCount) {
                                    $cacheIsValid = true;
                                } else {
                                    // Число колонок изменилось — кэш устарел
                                    require_once __DIR__ . '/Logger.php';
                                    Logger::info('ColumnMetadata: column count changed, refreshing cache', [
                                        'cached' => $cachedCount,
                                        'actual' => $actualCount,
                                    ]);
                                }
                            }
                        } catch (Exception $e) {
                            require_once __DIR__ . '/Logger.php';
                            Logger::warning('ColumnMetadata: cache validation failed, refreshing', [
                                'message' => $e->getMessage(),
                            ]);
                        }
                        if ($cacheIsValid) {
                            $this->metadata = $cached['data'];
                            return;
                        }
                    }
                }
            }
        }
        
        // Загружаем из базы данных
        $this->refreshMetadata();
    }
    
    /**
     * Обновление метаданных из базы данных
     * Использует INFORMATION_SCHEMA для безопасного получения метаданных
     */
    public function refreshMetadata() {
        $columns = [];
        $numericCols = [];
        $textCols = [];
        $allCols = [];
        
        // Используем INFORMATION_SCHEMA вместо SHOW COLUMNS для большей безопасности
        // Имя таблицы экранируем через обратные кавычки
        $tableName = 'accounts';
        $dbName = $this->mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        
        // Безопасный запрос через INFORMATION_SCHEMA
        $sql = "SELECT 
                    COLUMN_NAME as Field,
                    COLUMN_TYPE as Type,
                    IS_NULLABLE as Null,
                    COLUMN_KEY as Key,
                    COLUMN_DEFAULT as Default,
                    EXTRA as Extra
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION";
        
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $dbName, $tableName);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $name = $row['Field'];
                $type = strtolower($row['Type']);
                
                $allCols[] = $name;
                $columns[$name] = [
                    'type' => $type,
                    'null' => $row['Null'],
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra']
                ];
                
                // Определяем числовые поля
                if (preg_match('/(int|decimal|float|double|numeric)/', $type)) {
                    $numericCols[] = $name;
                }
                
                // Определяем текстовые поля
                if (preg_match('/(char|text|varchar)/', $type)) {
                    $textCols[] = $name;
                }
            }
            $stmt->close();
        } else {
            // Fallback на SHOW COLUMNS если INFORMATION_SCHEMA недоступен
            $result = $this->mysqli->query("SHOW COLUMNS FROM `accounts`");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $name = $row['Field'];
                    $type = strtolower($row['Type']);
                    
                    $allCols[] = $name;
                    $columns[$name] = [
                        'type' => $type,
                        'null' => $row['Null'],
                        'key' => $row['Key'],
                        'default' => $row['Default'],
                        'extra' => $row['Extra']
                    ];
                    
                    // Определяем числовые поля
                    if (preg_match('/(int|decimal|float|double|numeric)/', $type)) {
                        $numericCols[] = $name;
                    }
                    
                    // Определяем текстовые поля
                    if (preg_match('/(char|text|varchar)/', $type)) {
                        $textCols[] = $name;
                    }
                }
                $result->close();
            }
        }
        
        $this->metadata = [
            'columns' => $columns,
            'allCols' => $allCols,
            'numericCols' => $numericCols,
            'textCols' => $textCols,
            'timestamp' => time()
        ];
        
        // Сохраняем в кэш
        $this->saveCache();
    }
    
    /**
     * Сохранение кэша в файл
     */
    private function saveCache() {
        $cacheData = json_encode([
            'timestamp' => time(),
            'data' => $this->metadata
        ], JSON_UNESCAPED_UNICODE);
        
        @file_put_contents($this->cacheFile, $cacheData, LOCK_EX);
    }
    
    /**
     * Получение всех колонок
     */
    public function getAllColumns() {
        return $this->metadata['allCols'] ?? [];
    }
    
    /**
     * Получение числовых колонок
     */
    public function getNumericColumns() {
        return $this->metadata['numericCols'] ?? ['id', 'birth_day', 'birth_month', 'birth_year'];
    }
    
    /**
     * Получение текстовых колонок
     */
    public function getTextColumns() {
        return $this->metadata['textCols'] ?? [];
    }
    
    /**
     * Проверка существования колонки
     */
    public function columnExists($columnName) {
        return in_array($columnName, $this->metadata['allCols'] ?? [], true);
    }
    
    /**
     * Получение информации о колонке
     */
    public function getColumn($columnName) {
        return $this->metadata['columns'][$columnName] ?? null;
    }
    
    /**
     * Получение всех метаданных
     */
    public function getMetadata() {
        return $this->metadata;
    }
    
    /**
     * Очистка кэша (всех или конкретной БД)
     */
    public static function clearCache($mysqli = null) {
        if ($mysqli !== null) {
            // Очищаем кэш для конкретной БД
            $dbName = $mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? 'unknown';
            $dbHost = $mysqli->host_info;
            $dbUser = $mysqli->user ?? 'unknown';
            $cacheKey = md5($dbHost . '|' . $dbName . '|' . $dbUser);
            $cacheFile = sys_get_temp_dir() . '/accounts_metadata_cache_' . $cacheKey . '.json';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
        } else {
            // Очищаем все кэши
            $cacheDir = sys_get_temp_dir();
            $files = glob($cacheDir . '/accounts_metadata_cache_*.json');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        self::$instance = null;
    }
    
    /**
     * Получение человекочитаемых названий колонок
     */
    public function getColumnTitles() {
        $knownTitles = [
            'id' => 'ID',
            'login' => 'Логин',
            'password' => 'Пароль',
            'email' => 'Email',
            'email_password' => 'Пароль Email',
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
            'social_url' => 'Соцсеть URL',
            'birth_day' => 'День рождения',
            'birth_month' => 'Месяц рождения',
            'birth_year' => 'Год рождения',
            'token' => 'Token',
            'ads_id' => 'Ads ID',
            'id_soc_account' => 'ID соц. аккаунта',
            'cookies' => 'Cookies',
            'user_agent' => 'User-Agent',
            'two_fa' => '2FA',
            'extra_info_1' => 'Extra 1',
            'extra_info_2' => 'Extra 2',
            'extra_info_3' => 'Extra 3',
            'extra_info_4' => 'Extra 4',
            'status' => 'Статус',
            'status_marketplace' => 'Статус Marketplace',
            'created_at' => 'Создан',
            'updated_at' => 'Обновлен',
            'avatar' => 'Аватар',
            'cover' => 'Обложка',
            'scenario_pharma' => 'Сценарий Pharma',
            'quantity_friends' => 'Количество друзей',
            'quantity_fp' => 'Количество FP',
            'quantity_bm' => 'Количество BM',
            'quantity_photo' => 'Количество фото',
            'selectedFolderPath' => 'Путь к папке',
            'currency' => 'Валюта',
            'limit_rk' => 'Limit RK'
        ];
        
        $titles = [];
        foreach ($this->getAllColumns() as $col) {
            $titles[$col] = $knownTitles[$col] ?? ucfirst(str_replace('_', ' ', $col));
        }
        
        return $titles;
    }
}



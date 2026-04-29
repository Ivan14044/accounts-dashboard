<?php
/**
 * Конфигурация приложения - константы и настройки
 * 
 * Централизует все константы приложения для легкой настройки
 * и улучшенной читабельности кода.
 */
class Config {
    // ========================================
    // РАЗМЕРЫ И ЛИМИТЫ
    // ========================================
    
    /**
     * Максимальный размер тела запроса (20MB)
     */
    const MAX_REQUEST_SIZE = 20 * 1024 * 1024;
    
    /**
     * Максимальное количество ID в одном массовом запросе
     */
    const MAX_IDS_PER_REQUEST = 50000;
    
    /**
     * Максимальный размер экспортируемых данных за раз (записей)
     */
    const MAX_EXPORT_RECORDS = 100000;
    
    // ========================================
    // ПАГИНАЦИЯ
    // ========================================
    
    /**
     * Размер страницы по умолчанию (оптимизировано для производительности)
     */
    const DEFAULT_PAGE_SIZE = 50;
    
    /**
     * Максимальный размер страницы (уменьшено для производительности)
     */
    const MAX_PAGE_SIZE = 200;
    
    /**
     * Минимальный размер страницы
     */
    const MIN_PAGE_SIZE = 10;
    
    // ========================================
    // ТАЙМАУТЫ И TTL
    // ========================================
    
    /**
     * Время жизни сессии (30 дней в секундах)
     */
    const SESSION_LIFETIME = 30 * 24 * 60 * 60;
    
    /**
     * TTL для кэша метаданных БД (1 час в секундах)
     */
    const CACHE_TTL = 3600;
    
    /**
     * TTL для кэша статистики (5 минут в секундах)
     */
    const STATS_CACHE_TTL = 300;
    
    /**
     * TTL для кэша результатов запросов (10 минут в секундах)
     */
    const QUERY_CACHE_TTL = 600;
    
    /**
     * Таймаут выполнения PHP скрипта при экспорте (5 минут в секундах)
     */
    const EXPORT_TIMEOUT = 300;
    
    // ========================================
    // RATE LIMITING
    // ========================================
    
    /**
     * Лимит запросов к обычным API endpoints (запросов в минуту)
     */
    const API_RATE_LIMIT = 100;
    
    /**
     * Лимит запросов к endpoint экспорта (запросов в минуту)
     */
    const EXPORT_RATE_LIMIT = 10;
    
    /**
     * Лимит попыток входа (запросов в минуту)
     */
    const LOGIN_RATE_LIMIT = 5;
    
    /**
     * Окно времени для rate limiting (секунд)
     */
    const RATE_LIMIT_WINDOW = 60;
    
    // ========================================
    // ИМПОРТ АККАУНТОВ
    // ========================================
    
    /**
     * Максимальный размер файла для импорта (20MB)
     */
    const MAX_IMPORT_FILE_SIZE = 20 * 1024 * 1024;
    
    /**
     * Максимальное количество строк в CSV для импорта
     */
    const MAX_IMPORT_ROWS = 10000;
    
    /**
     * Размер батча для массовой вставки при импорте
     */
    const IMPORT_BATCH_SIZE = 100;
    
    /**
     * Лимит импортов на пользователя (импортов в минуту)
     */
    const IMPORT_RATE_LIMIT = 5;
    
    /**
     * Время блокировки при превышении лимита входа (секунд)
     */
    const LOGIN_BLOCK_TIME = 300; // 5 минут
    
    // ========================================
    // ПРОВЕРКА ВАЛИДНОСТИ АККАУНТОВ (NPPR Services API)
    // https://npprservices.pro/apidoc
    // ========================================

    /**
     * URL для проверки FB аккаунтов (NPPR fbchecker)
     */
    const NPPR_FBCHECK_URL = 'https://npprservices.pro/api/services/fbchecker';

    /**
     * Размер батча FB ID за один запрос к NPPR
     */
    const NPPR_BATCH_SIZE = 100;

    /**
     * Таймаут запроса к NPPR (секунд).
     * 15 сек — компромисс: достаточно для нормального ответа, но при сбое
     * быстрее проваливаемся в retry, не блокируя UI.
     */
    const NPPR_TIMEOUT = 15;

    /**
     * ENV-переменная и fallback файл с токеном NPPR API.
     * При деплое (.github/workflows/deploy.yml) GitHub Secret NPPR_API_TOKEN
     * материализуется в файл .nppr_token в корне проекта (gitignored).
     */
    const NPPR_TOKEN_ENV  = 'NPPR_API_TOKEN';
    const NPPR_TOKEN_FILE = '.nppr_token';

    /**
     * Максимум записей за один запрос validate/check.
     * 200 (вместо 500) — балансируем число запросов и отзывчивость UI:
     * один запрос ≈ 2 параллельных curl к NPPR ≈ 5-10 сек.
     * Прогресс на фронте обновляется в 2.5 раза чаще.
     */
    const VALIDATE_CHECK_MAX_ITEMS = 200;

    /**
     * Максимум записей за один запрос validate/prepare (по фильтру)
     */
    const VALIDATE_PREPARE_LIMIT = 2000;

    /**
     * Сколько байт cookies тянуть из БД для validation prepare.
     * cookies — LONGTEXT (5–10KB на FB-аккаунт), но c_user всегда в первых
     * нескольких КБ. SUBSTRING в БД снижает трафик в 5–10 раз и ускоряет
     * парсинг в PHP. 4096 покрывает любую реалистичную FB cookie-структуру.
     */
    const VALIDATE_COOKIES_TRUNCATE = 4096;
    
    // ========================================
    // БЕЗОПАСНОСТЬ
    // ========================================
    
    /**
     * Длина CSRF токена
     */
    const CSRF_TOKEN_LENGTH = 32;
    
    /**
     * Минимальная длина пароля
     */
    const PASSWORD_MIN_LENGTH = 8;
    
    /**
     * Максимальная длина пароля
     */
    const PASSWORD_MAX_LENGTH = 255;
    
    /**
     * Количество раундов bcrypt для хэширования паролей
     */
    const PASSWORD_BCRYPT_COST = 12;
    
    // ========================================
    // ПУТИ И ДИРЕКТОРИИ
    // ========================================
    
    /**
     * Директория для кэша (вычисляется в runtime через getTempBase())
     */
    const CACHE_DIR = 'dashboard_cache';

    /**
     * Директория для логов
     */
    const LOG_DIR = 'dashboard_logs';

    /**
     * Директория для rate limiting данных
     */
    const RATELIMIT_DIR = 'dashboard_ratelimit';

    /**
     * Директория для временных файлов
     */
    const TEMP_DIR = 'dashboard_temp';

    /**
     * Базовая временная директория (кроссплатформенная)
     */
    public static function getTempBase(): string {
        return sys_get_temp_dir();
    }

    /**
     * Полный путь к директории по константе
     */
    public static function getDir(string $subdir): string {
        return self::getTempBase() . DIRECTORY_SEPARATOR . $subdir;
    }
    
    // ========================================
    // БАЗА ДАННЫХ
    // ========================================
    
    /**
     * Максимальное количество попыток переподключения к БД
     */
    const DB_MAX_RETRIES = 3;
    
    /**
     * Задержка между попытками переподключения (секунд)
     */
    const DB_RETRY_DELAY = 2;
    
    /**
     * Таймаут подключения к БД (секунд)
     */
    const DB_CONNECT_TIMEOUT = 10;
    
    /**
     * Таймаут выполнения запроса к БД (секунд)
     */
    const DB_QUERY_TIMEOUT = 30;
    
    // ========================================
    // ИНТЕГРАЦИЯ И API
    // ========================================
    
    /**
     * Версия API
     */
    const API_VERSION = '1.0';
    
    /**
     * Таймаут для внешних API запросов (секунд)
     */
    const API_REQUEST_TIMEOUT = 30;
    
    // ========================================
    // FEATURE FLAGS
    // ========================================
    
    /**
     * Включена ли функция rate limiting
     */
    const FEATURE_RATE_LIMITING = true;
    
    /**
     * Включена ли функция кэширования статистики
     */
    const FEATURE_STATS_CACHING = true;
    
    /**
     * Включено ли детальное логирование
     */
    const FEATURE_DETAILED_LOGGING = false;
    
    // ========================================
    // МЕТОДЫ УТИЛИТЫ
    // ========================================
    
    /**
     * Создает необходимые директории если они не существуют
     */
    public static function ensureDirectories() {
        $dirs = [
            self::CACHE_DIR,
            self::LOG_DIR,
            self::RATELIMIT_DIR,
            self::TEMP_DIR
        ];

        foreach ($dirs as $subdir) {
            $fullPath = self::getDir($subdir);
            if (!is_dir($fullPath)) {
                @mkdir($fullPath, 0755, true);
            }
        }
    }
    
    /**
     * Возвращает конфигурацию в виде массива (для отладки)
     * 
     * @return array
     */
    public static function toArray() {
        $reflection = new ReflectionClass(__CLASS__);
        return $reflection->getConstants();
    }
    
    /**
     * Проверяет, включена ли определенная функция
     * 
     * @param string $feature Название функции (например, 'RATE_LIMITING')
     * @return bool
     */
    public static function isFeatureEnabled($feature) {
        $constantName = 'Config::FEATURE_' . strtoupper($feature);
        return defined($constantName) && constant($constantName);
    }
    
    /**
     * Структура CSV файла для импорта аккаунтов
     * Это единственное место, где определены все поля CSV
     */
    public const CSV_STRUCTURE = [
        'login' => [
            'required' => true,
            'type' => 'string',
            'max_length' => 255,
            'label' => 'Логин',
            'description' => 'Уникальный идентификатор аккаунта'
        ],
        'status' => [
            'required' => true,
            'type' => 'string',
            'max_length' => 100,
            'label' => 'Статус',
            'description' => 'Текущий статус аккаунта (любое значение)'
        ],
        'password' => [
            'required' => false,
            'type' => 'string',
            'max_length' => 255,
            'label' => 'Пароль',
            'sensitive' => true
        ],
        'email' => [
            'required' => false,
            'type' => 'email',
            'max_length' => 255,
            'label' => 'Email'
        ],
        'email_password' => [
            'required' => false,
            'type' => 'string',
            'max_length' => 255,
            'label' => 'Пароль от Email',
            'sensitive' => true
        ],
        'cookies' => [
            'required' => false,
            'type' => 'text',
            'label' => 'Cookies',
            'sensitive' => true
        ],
        'first_cookie' => [
            'required' => false,
            'type' => 'text',
            'label' => 'Первые куки',
            'description' => 'Первые куки аккаунта (альтернатива полным cookies)',
            'sensitive' => true
        ],
        'token' => [
            'required' => false,
            'type' => 'string',
            'max_length' => 500,
            'label' => 'Token',
            'sensitive' => true
        ],
        'two_fa' => [
            'required' => false,
            'type' => 'string',
            'max_length' => 255,
            'label' => '2FA код',
            'sensitive' => true
        ]
    ];
    
    /**
     * Rate limiting для импорта
     */
    public const IMPORT_RATE_LIMIT_PER_MINUTE = 5;
    public const IMPORT_RATE_LIMIT_PER_HOUR = 20;
    
    /**
     * Получить обязательные поля CSV
     * 
     * @return array
     */
    public static function getRequiredCsvFields(): array {
        return array_keys(array_filter(self::CSV_STRUCTURE, function($field) {
            return $field['required'] ?? false;
        }));
    }
    
    /**
     * Получить все поля CSV
     * 
     * @return array
     */
    public static function getAllCsvFields(): array {
        return array_keys(self::CSV_STRUCTURE);
    }
    
    /**
     * Получить чувствительные поля (пароли, cookies и т.п.)
     *
     * @return array
     */
    public static function getSensitiveCsvFields(): array {
        return array_keys(array_filter(self::CSV_STRUCTURE, function($field) {
            return $field['sensitive'] ?? false;
        }));
    }

    /**
     * Получить токен NPPR API.
     * Приоритет: переменная окружения NPPR_API_TOKEN → файл .nppr_token в корне проекта.
     * Возвращает пустую строку, если токен не задан.
     */
    public static function getNpprToken(): string {
        $token = (string)getenv(self::NPPR_TOKEN_ENV);
        if ($token === '' && defined('PROJECT_ROOT')) {
            $tokenFile = PROJECT_ROOT . DIRECTORY_SEPARATOR . self::NPPR_TOKEN_FILE;
            if (is_file($tokenFile) && is_readable($tokenFile)) {
                $token = trim((string)@file_get_contents($tokenFile));
            }
        }
        return preg_replace('/[^A-Za-z0-9]/', '', $token);
    }
}

// Создать необходимые директории при загрузке
Config::ensureDirectories();





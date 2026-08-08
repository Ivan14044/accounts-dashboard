<?php
/**
 * Система уровневого логирования
 * 
 * Предоставляет методы для логирования с разными уровнями важности.
 * Debug сообщения логируются только когда DEBUG=true в окружении.
 * 
 * Использование:
 * Logger::init(); // вызывается один раз при загрузке приложения
 * Logger::debug('Отладочное сообщение', ['user_id' => 123]);
 * Logger::info('Информационное сообщение');
 * Logger::warning('Предупреждение');
 * Logger::error('Ошибка', ['exception' => $e->getMessage()]);
 */
class Logger {
    /**
     * Включен ли debug режим
     * @var bool
     */
    private static $debugMode = false;
    
    /**
     * Включен ли info режим
     * @var bool
     */
    private static $infoMode = true;
    
    /**
     * Инициализация logger из переменных окружения
     */
    public static function init() {
        self::$debugMode = getenv('DEBUG') === 'true' || getenv('DEBUG') === '1';
        $logLevel = getenv('LOG_LEVEL') ?: 'info';
        
        // Определяем какие уровни включены
        switch (strtolower($logLevel)) {
            case 'debug':
                self::$debugMode = true;
                self::$infoMode = true;
                break;
            case 'info':
                self::$debugMode = false;
                self::$infoMode = true;
                break;
            case 'warning':
            case 'error':
                self::$debugMode = false;
                self::$infoMode = false;
                break;
        }
    }
    
    /**
     * Debug логирование (только в development)
     * 
     * @param string $message Сообщение для логирования
     * @param array $context Дополнительный контекст
     */
    public static function debug($message, $context = []) {
        if (self::$debugMode) {
            self::log('DEBUG', $message, $context);
        }
    }
    
    /**
     * Информационное логирование
     * 
     * @param string $message Сообщение для логирования
     * @param array $context Дополнительный контекст
     */
    public static function info($message, $context = []) {
        if (self::$infoMode) {
            self::log('INFO', $message, $context);
        }
    }
    
    /**
     * Предупреждение (всегда логируется)
     * 
     * @param string $message Сообщение для логирования
     * @param array $context Дополнительный контекст
     */
    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }
    
    /**
     * Ошибка (всегда логируется)
     * 
     * @param string $message Сообщение для логирования
     * @param array $context Дополнительный контекст
     */
    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }
    
    /**
     * Идентификатор текущего запроса.
     *
     * Нужен, чтобы в общем логе можно было выделить все записи одного запроса:
     * `grep 'req=a1b2c3d4' logs/2026-08-08.log`. Живёт в рамках процесса, в
     * CLI-скриптах работает так же.
     *
     * @return string 8 hex-символов
     */
    public static function requestId(): string {
        static $id = null;

        if ($id === null) {
            // Не для криптографии — только для связывания строк лога,
            // поэтому uniqid без random_bytes достаточно и не требует энтропии.
            $id = substr(md5(uniqid('', true)), 0, 8);
        }

        return $id;
    }

    /**
     * Сколько миллисекунд прошло с начала запроса.
     *
     * @return int
     */
    private static function elapsedMs(): int {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (!is_float($start) && !is_int($start)) {
            return 0;
        }

        return (int)round((microtime(true) - (float)$start) * 1000);
    }

    /**
     * Путь к директории логов
     * @var string
     */
    private static $logDir = null;
    
    /**
     * Инициализация директории логов
     */
    private static function initLogDir() {
        if (self::$logDir === null) {
            // Определяем директорию для логов (в корне проекта)
            $baseDir = dirname(__DIR__);
            self::$logDir = $baseDir . '/logs';
            
            // Создаем директорию, если её нет
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
            }
        }
    }
    
    /**
     * Получение пути к файлу лога
     * 
     * @param string $level Уровень логирования
     * @return string Путь к файлу
     */
    private static function getLogFile($level = 'app') {
        self::initLogDir();
        $date = date('Y-m-d');
        $filename = $date . '.log';
        return self::$logDir . '/' . $filename;
    }
    
    /**
     * Внутренний метод для записи лога
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private static function log($level, $message, $context) {
        $timestamp = date('Y-m-d H:i:s');

        // Фильтруем чувствительные данные из контекста
        $contextStr = '';
        if (!empty($context)) {
            $context = self::filterSensitiveData($context);
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        // req=<id> +<мс от начала запроса> — без этого записи одного запроса
        // невозможно отделить от чужих в общем логе, а медленную страницу нельзя
        // найти по логам вообще. Оба значения дешёвые: id генерируется один раз,
        // время берётся из REQUEST_TIME_FLOAT.
        $logMessage = sprintf(
            '[%s] [%s] [req=%s +%dms] %s%s',
            $timestamp,
            $level,
            self::requestId(),
            self::elapsedMs(),
            $message,
            $contextStr
        );
        
        // Записываем в системный лог PHP
        error_log($logMessage);
        
        // Записываем в файл логов
        try {
            $logFile = self::getLogFile($level);
            $logEntry = $logMessage . PHP_EOL;
            @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Игнорируем ошибки записи в файл, чтобы не сломать приложение
        }
    }
    
    /**
     * Получение логов из файла
     * 
     * @param string $date Дата в формате Y-m-d (по умолчанию сегодня)
     * @param int $limit Количество последних строк
     * @param string $level Фильтр по уровню (DEBUG, INFO, WARNING, ERROR)
     * @return array Массив строк логов
     */
    public static function getLogs($date = null, $limit = 1000, $level = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        self::initLogDir();
        $logFile = self::$logDir . '/' . $date . '.log';
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        // Читаем файл построчно
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        
        // Фильтруем по уровню, если указан
        if ($level !== null) {
            $lines = array_filter($lines, function($line) use ($level) {
                return strpos($line, "[$level]") !== false;
            });
        }
        
        // Берем последние N строк
        $lines = array_slice($lines, -$limit);
        
        return array_reverse($lines); // Новые сверху
    }
    
    /**
     * Получение списка доступных дат с логами
     * 
     * @return array Массив дат
     */
    public static function getAvailableDates() {
        self::initLogDir();
        
        if (!is_dir(self::$logDir)) {
            return [];
        }
        
        $files = glob(self::$logDir . '/*.log');
        $dates = [];
        
        foreach ($files as $file) {
            $basename = basename($file, '.log');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $basename)) {
                $dates[] = $basename;
            }
        }
        
        rsort($dates); // Новые даты сверху
        return $dates;
    }
    
    /**
     * Фильтрует чувствительные данные из контекста логирования
     * 
     * @param array $context Контекст для фильтрации
     * @return array Отфильтрованный контекст
     */
    private static function filterSensitiveData($context) {
        $sensitiveKeys = [
            'password',
            'password_hash',
            'csrf_token',
            'csrf',
            'session_id',
            'session',
            'token',
            'auth_token',
            'api_key',
            'secret',
            'cookie',
            'cookies',
            'first_cookie'
        ];
        
        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***FILTERED***';
            }
        }
        
        return $context;
    }
    
    /**
     * Проверяет, включен ли debug режим
     * 
     * @return bool
     */
    public static function isDebugEnabled() {
        return self::$debugMode;
    }
    
    /**
     * Инициализация директории логов (публичный метод для доступа извне)
     */
    public static function ensureLogDir() {
        self::initLogDir();
    }
    
    /**
     * Получение пути к директории логов
     * 
     * @return string
     */
    public static function getLogDir() {
        self::initLogDir();
        return self::$logDir;
    }
}

// Автоматическая инициализация при подключении файла
Logger::init();









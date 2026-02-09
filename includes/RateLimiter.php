<?php
/**
 * Rate Limiter - Ограничение количества запросов
 * 
 * Защита от brute-force атак и DoS.
 * Использует файловый кэш для хранения счётчиков запросов.
 * 
 * Использование:
 * $limiter = new RateLimiter();
 * if (!$limiter->checkLimit('api_' . $ip, 100, 60)) {
 *     // Лимит превышен
 *     http_response_code(429);
 *     die('Too Many Requests');
 * }
 */
class RateLimiter {
    private $cacheDir;
    private $enabled = true;
    
    /**
     * Конструктор
     * 
     * @param string|null $cacheDir Директория для хранения данных
     */
    public function __construct($cacheDir = null) {
        require_once __DIR__ . '/Config.php';
        
        $this->cacheDir = $cacheDir ?? Config::RATELIMIT_DIR;
        $this->enabled = Config::FEATURE_RATE_LIMITING;
        
        // Создаём директорию если не существует
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Проверка лимита запросов
     * 
     * @param string $key Уникальный ключ (обычно IP или user_id)
     * @param int $maxRequests Максимум запросов
     * @param int $timeWindow Временное окно в секундах
     * @return bool true если в пределах лимита, false если превышен
     */
    public function checkLimit($key, $maxRequests, $timeWindow) {
        // Если rate limiting отключен
        if (!$this->enabled) {
            return true;
        }
        
        $file = $this->getCacheFile($key);
        $now = time();
        
        // Читаем существующие записи
        $records = $this->readRecords($file);
        
        // Удаляем устаревшие записи (старше временного окна)
        $records = array_filter($records, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        // Проверяем лимит
        if (count($records) >= $maxRequests) {
            require_once __DIR__ . '/Logger.php';
            Logger::warning('RATE LIMIT: Exceeded', [
                'key' => $this->sanitizeKey($key),
                'count' => count($records),
                'max' => $maxRequests
            ]);
            
            // Сохраняем обновлённые записи
            $this->writeRecords($file, $records);
            
            return false; // Лимит превышен
        }
        
        // Добавляем новую запись
        $records[] = $now;
        $this->writeRecords($file, $records);
        
        return true; // В пределах лимита
    }
    
    /**
     * Получение количества оставшихся запросов
     * 
     * @param string $key Уникальный ключ
     * @param int $maxRequests Максимум запросов
     * @param int $timeWindow Временное окно в секундах
     * @return int Количество оставшихся запросов
     */
    public function getRemainingAttempts($key, $maxRequests, $timeWindow) {
        if (!$this->enabled) {
            return $maxRequests;
        }
        
        $file = $this->getCacheFile($key);
        $now = time();
        
        $records = $this->readRecords($file);
        
        // Удаляем устаревшие
        $records = array_filter($records, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        return max(0, $maxRequests - count($records));
    }
    
    /**
     * Получение времени до сброса лимита
     * 
     * @param string $key Уникальный ключ
     * @param int $timeWindow Временное окно в секундах
     * @return int Секунд до сброса или 0
     */
    public function getTimeUntilReset($key, $timeWindow) {
        if (!$this->enabled) {
            return 0;
        }
        
        $file = $this->getCacheFile($key);
        $now = time();
        
        $records = $this->readRecords($file);
        
        if (empty($records)) {
            return 0;
        }
        
        // Находим самую старую запись в окне
        $oldestInWindow = min($records);
        $resetTime = $oldestInWindow + $timeWindow;
        
        return max(0, $resetTime - $now);
    }
    
    /**
     * Сброс лимита для ключа
     * 
     * @param string $key Уникальный ключ
     */
    public function resetLimit($key) {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    
    /**
     * Очистка старых файлов кэша
     * 
     * @param int $olderThan Удалить файлы старше X секунд (по умолчанию 1 час)
     */
    public function cleanup($olderThan = 3600) {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        
        $now = time();
        $files = glob($this->cacheDir . '/ratelimit_*');
        
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $olderThan) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Получение имени файла кэша
     * 
     * @param string $key Ключ
     * @return string Путь к файлу
     */
    private function getCacheFile($key) {
        $hash = md5($key);
        return $this->cacheDir . '/ratelimit_' . $hash . '.dat';
    }
    
    /**
     * Чтение записей из файла
     * 
     * @param string $file Путь к файлу
     * @return array Массив timestamp'ов
     */
    private function readRecords($file) {
        if (!file_exists($file)) {
            return [];
        }
        
        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }
        
        $records = @unserialize($content);
        return is_array($records) ? $records : [];
    }
    
    /**
     * Запись записей в файл
     * 
     * @param string $file Путь к файлу
     * @param array $records Массив timestamp'ов
     */
    private function writeRecords($file, array $records) {
        $content = serialize($records);
        @file_put_contents($file, $content, LOCK_EX);
    }
    
    /**
     * Санитизация ключа для логирования (убираем IP)
     * 
     * @param string $key Ключ
     * @return string Безопасный ключ для логов
     */
    private function sanitizeKey($key) {
        // Заменяем IP адреса на маску
        return preg_replace('/\d+\.\d+\.\d+\.\d+/', 'xxx.xxx.xxx.xxx', $key);
    }
}









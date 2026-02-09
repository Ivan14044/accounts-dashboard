<?php
/**
 * Централизованное управление сессиями
 * Устраняет дублирование логики инициализации сессий
 */
class SessionManager {
    /**
     * Инициализация сессии с безопасными параметрами
     * 
     * @return void
     */
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // Сессия уже запущена
        }
        
        // Определяем параметры безопасности
        $isLocalhost = self::isLocalhost();
        $secure = !$isLocalhost && self::isHttps();
        $lifetime = 30 * 24 * 60 * 60; // 30 дней
        
        // Устанавливаем параметры cookie
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params($lifetime, '/; samesite=Lax', '', $secure, true);
        }
        
        session_start();
    }
    
    /**
     * Проверка, является ли хост localhost
     * 
     * @return bool
     */
    private static function isLocalhost(): bool {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
    
    /**
     * Проверка, используется ли HTTPS
     * Учитывает прокси и заголовки X-Forwarded-Proto
     * 
     * @return bool
     */
    private static function isHttps(): bool {
        // Проверка через заголовок X-Forwarded-Proto (для прокси)
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        
        // Проверка через HTTPS переменную
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        
        // Проверка через порт
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Регенерация ID сессии (защита от фиксации)
     * 
     * @return void
     */
    public static function regenerateId(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    
    /**
     * Уничтожение сессии
     * 
     * @return void
     */
    public static function destroy(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            // Удаляем cookie
            if (isset($_COOKIE[session_name()])) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            
            session_destroy();
        }
    }
    
    /**
     * Проверка, активна ли сессия
     * 
     * @return bool
     */
    public static function isActive(): bool {
        return session_status() === PHP_SESSION_ACTIVE;
    }
    
    /**
     * Безопасное получение значения из сессии
     * 
     * @param string $key Ключ
     * @param mixed $default Значение по умолчанию
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        if (!self::isActive()) {
            return $default;
        }
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Установка значения в сессию
     * 
     * @param string $key Ключ
     * @param mixed $value Значение
     * @return void
     */
    public static function set(string $key, $value): void {
        if (!self::isActive()) {
            self::start();
        }
        $_SESSION[$key] = $value;
    }
    
    /**
     * Проверка наличия ключа в сессии
     * 
     * @param string $key Ключ
     * @return bool
     */
    public static function has(string $key): bool {
        if (!self::isActive()) {
            return false;
        }
        return isset($_SESSION[$key]);
    }
    
    /**
     * Удаление ключа из сессии
     * 
     * @param string $key Ключ
     * @return void
     */
    public static function remove(string $key): void {
        if (self::isActive() && isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
}


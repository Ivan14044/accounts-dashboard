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
    /** 30 дней в секундах — максимальное время жизни remember_me сессии */
    public const REMEMBER_ME_LIFETIME = 30 * 24 * 60 * 60; // 2 592 000

    /** 8 часов — время жизни обычной сессии */
    public const DEFAULT_LIFETIME = 8 * 60 * 60; // 28 800

    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // Сессия уже запущена
        }

        // Определяем параметры безопасности
        $isLocalhost = self::isLocalhost();
        $secure = !$isLocalhost && self::isHttps();

        // gc_maxlifetime должен покрывать максимально возможную длительность сессии,
        // иначе PHP garbage collector удалит файл сессии раньше, чем истечёт cookie.
        ini_set('session.gc_maxlifetime', (string) self::REMEMBER_ME_LIFETIME);

        // По умолчанию — session cookie (lifetime=0, удаляется при закрытии браузера).
        // Долгосрочный cookie (30 дней) выставляется в authenticate() только при remember_me=true.
        $lifetime = 0;

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
        // Проверяем по REMOTE_ADDR (не подделывается), а не по HTTP_HOST (клиентский заголовок)
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($remoteAddr, ['127.0.0.1', '::1'], true);
    }
    
    /**
     * Проверка, является ли источник доверенным прокси
     *
     * @return bool
     */
    private static function isTrustedProxy(): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return (
            strpos($ip, '127.') === 0 ||
            strpos($ip, '10.') === 0 ||
            strpos($ip, '192.168.') === 0 ||
            preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip) ||
            $ip === '::1'
        );
    }

    /**
     * Проверка, используется ли HTTPS
     * Учитывает прокси и заголовки X-Forwarded-Proto, но только от доверенных источников
     *
     * @return bool
     */
    private static function isHttps(): bool {
        // Проверка через заголовок X-Forwarded-Proto (для прокси), только если источник доверен
        if (self::isTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Проверка через HTTPS переменную
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Проверка через порт
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }
    
    /**
     * Продлить cookie сессии для remember_me.
     * Вызывается из checkSessionTimeout() при активности пользователя.
     */
    public static function refreshRememberMeCookie(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (empty($_SESSION['remember_me'])) {
            return;
        }

        $isLocalhost = self::isLocalhost();
        $secure = !$isLocalhost && self::isHttps();
        $cookieExpires = time() + self::REMEMBER_ME_LIFETIME;

        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), [
                'expires'  => $cookieExpires,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(session_name(), session_id(), $cookieExpires, '/; samesite=Lax', '', $secure, true);
        }
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
            
            // Удаляем cookie безусловно (даже если $_COOKIE пуст — клиент мог его иметь)
            $params = session_get_cookie_params();
            if (PHP_VERSION_ID >= 70300) {
                setcookie(session_name(), '', [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly'  => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            } else {
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'] . '; samesite=Lax',
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


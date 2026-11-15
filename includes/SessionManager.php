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
    
    /** @var bool Запрос отпустил замок сессии через releaseLock() */
    private static $lockReleased = false;

    /** @var bool После долгой операции надо записать в сессию отметку «сам менял данные» */
    private static $pendingSelfWrite = false;

    /**
     * Отпустить замок сессии на время долгой операции.
     *
     * PHP держит файл сессии запертым до конца запроса, и пока он заперт,
     * любой другой запрос из того же браузера ждёт: новая вкладка панели,
     * F5, фоновое обновление таблицы «просто грузятся». На проде удаление
     * 1000 аккаунтов держало замок до 57 с, массовый перенос — до 5 минут
     * (повторное нажатие переноса 21.10.2026 ждало первый 96 с, прежде чем
     * начать работу). После вызова $_SESSION остаётся читаемым снимком —
     * проверка CSRF и имя пользователя для журнала работают, — но запись в
     * $_SESSION до конца запроса уже НЕ сохраняется.
     *
     * Звать сразу после requireAuth() + checkSessionTimeout() + checkRateLimit():
     * они обновляют время активности и cookie, это должно успеть записаться.
     *
     * @param bool $willWrite true — операция меняет аккаунты: в конце запроса
     *                        finishReleasedRequest() поставит отметку, по которой
     *                        счётчики панели покажут свежие числа сразу
     *                        (см. StatisticsService::viewerJustWrote). false —
     *                        только чтение (выгрузка).
     * @return void
     */
    public static function releaseLock(bool $willWrite = true): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_write_close();
        self::$lockReleased = true;
        self::$pendingSelfWrite = $willWrite;
    }

    /**
     * Запрос отпустил замок сессии через releaseLock().
     *
     * @return bool
     */
    public static function isLockReleased(): bool {
        return self::$lockReleased;
    }

    /**
     * Дописать в сессию то, что накопилось после releaseLock().
     *
     * Сейчас это одна вещь — отметка «пользователь только что менял данные»
     * (Config::STATS_SELF_WRITE_FLAG). Обычно её ставит Database::prepare()
     * прямо во время записи, но при отпущенной сессии писать некуда. Ставить
     * её заранее, перед долгой операцией, нельзя: отметка живёт одно окно
     * округления (30 с) и к концу минутного удаления уже истекла бы.
     * Поэтому сессия открывается на миг здесь, в конце: замок берётся, отметка
     * пишется, замок отпускается.
     *
     * Вызывается из json_response() — то есть автоматически перед любым
     * JSON-ответом. Если заголовки уже ушли (потоковая выгрузка), сессию не
     * открыть — тогда молча ничего не делаем: худшее последствие — счётчики
     * обновятся не сразу, а в пределах окна округления.
     *
     * @return void
     */
    public static function finishReleasedRequest(): void {
        if (!self::$lockReleased || !self::$pendingSelfWrite) {
            return;
        }
        self::$pendingSelfWrite = false;

        if (headers_sent() || session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $id = session_id();
        if ($id === '') {
            return;
        }

        require_once __DIR__ . '/Config.php';

        // Повторный session_start() в том же запросе заново шлёт браузеру
        // Set-Cookie — без срока годности. У входа с «Запомнить меня» это
        // затёрло бы 30-дневную cookie сессионной, и после перезапуска
        // браузера человека выкинуло бы на логин (на стенде видно глазами:
        // без этой защиты delete.php отдавал лишний Set-Cookie). Поэтому
        // открываем сессию по уже известному id, не трогая cookie.
        $useCookies = ini_get('session.use_cookies');
        ini_set('session.use_cookies', '0');
        try {
            session_id($id);
            if (@session_start() && session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[Config::STATS_SELF_WRITE_FLAG] = time() + Config::STATS_FINGERPRINT_BUCKET;
                session_write_close();
            }
        } finally {
            ini_set('session.use_cookies', (string)$useCookies);
        }
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


<?php
/**
 * PanelPassword — второй пароль на входе в панель, поверх строки подключения к БД.
 *
 * Зачем. До 30.09.2026 входом в панель была одна строка подключения к БД: кто её
 * знает (или у кого она осталась в браузере на общем компьютере) — тот внутри,
 * и с «Запомнить меня» ещё 30 дней без вопросов. Теперь после строки
 * подключения нужен отдельный пароль панели.
 *
 * Где лежит пароль. Репозиторий ПУБЛИЧНЫЙ, поэтому ни пароля, ни даже его хэша
 * в git нет: хэш живёт в GitHub Secret `PANEL_PASSWORD_HASH`, а шаг деплоя
 * (`tools/panel_password.php write`) превращает его в файл `.panel_password.php`
 * в корне сайта. Файл закрыт от HTTP дважды: имя начинается с точки
 * (`.htaccess` отдаёт 403 на скрытые файлы), а если правило когда-нибудь
 * отвалится — это PHP, который при запуске ничего не печатает.
 *
 * Как «разлогинить всех». В сессию при входе кладётся отпечаток хэша, под
 * которым человек вошёл ({@see stampFor()}), и на каждом запросе он сверяется
 * с текущим. Сменили пароль → отпечатки у всех устарели → все на странице
 * входа. Сессии, открытые до появления пароля, отпечатка не имеют вовсе и
 * выкидываются при первой же выкладке.
 *
 * Если файла нет или он битый — вход закрыт ВСЕМ (а не открыт всем).
 * Деплой без секрета поэтому падает до заливки, а не выкладывает такую панель.
 *
 * Чего здесь осознанно нет:
 *   - учётных записей: пароль один на всех, как и вход в БД (см. CLAUDE.md,
 *     «Все работают под одним входом в базу»);
 *   - ограничения числа попыток — его делает login.php общим RateLimiter
 *     (5 попыток в минуту с адреса) до того, как дойдёт до пароля.
 */
class PanelPassword
{
    /** Имя файла с хэшем в корне проекта. Точка в начале — 403 по .htaccess. */
    const FILE_NAME = '.panel_password.php';

    /** Ключ в $_SESSION с отпечатком хэша, под которым вошёл пользователь. */
    const SESSION_KEY = 'panel_password_stamp';

    /** @var string|null Подменённый путь к файлу (тесты); null — путь по умолчанию. */
    private static $file = null;

    /** @var array<string, string|null> Прочитанный хэш по пути файла — один раз на запрос. */
    private static $cache = [];

    /**
     * Подменить путь к файлу с хэшем. Нужно тестам: они не должны трогать
     * настоящий файл в корне. Сбрасывает кэш прочитанного хэша.
     *
     * @param string|null $path Путь к файлу; null — вернуть путь по умолчанию.
     * @return void
     */
    public static function setFile(?string $path): void
    {
        self::$file = $path;
        self::$cache = [];
    }

    /**
     * Путь к файлу с хэшем, который читается сейчас.
     *
     * @return string
     */
    public static function file(): string
    {
        return self::$file !== null
            ? self::$file
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * Прочитать хэш пароля панели.
     *
     * Файл читается один раз за запрос (isAuthenticated() зовут на каждой
     * странице и в каждом AJAX).
     *
     * @return string|null Хэш; null — пароль не настроен (файла нет, он пустой,
     *                     вернул не строку или строку, не похожую на хэш).
     *                     null означает «не пускать никого».
     */
    public static function loadHash(): ?string
    {
        $file = self::file();
        if (array_key_exists($file, self::$cache)) {
            return self::$cache[$file];
        }

        $hash = null;
        if (is_file($file) && is_readable($file)) {
            $value = include $file;
            if (is_string($value) && self::isValidHash($value)) {
                $hash = $value;
            }
        }

        self::$cache[$file] = $hash;
        return $hash;
    }

    /**
     * Похожа ли строка на хэш, выданный password_hash().
     *
     * Страховка от ошибки при заведении секрета: если туда положить сам пароль,
     * а не хэш, деплой упадёт, а не выложит панель, в которую не войти.
     *
     * @param string $hash
     * @return bool
     */
    public static function isValidHash(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        $info = password_get_info($hash);
        return isset($info['algoName']) && $info['algoName'] !== 'unknown';
    }

    /**
     * Проверить введённый пароль.
     *
     * @param string      $input Что ввёл человек (без trim: пробел — часть пароля).
     * @param string|null $hash  Результат {@see loadHash()}.
     * @return bool false и при неверном пароле, и когда пароль не настроен.
     */
    public static function verify(string $input, ?string $hash): bool
    {
        if ($hash === null || $input === '') {
            return false;
        }
        return password_verify($input, $hash);
    }

    /**
     * Отпечаток хэша для сессии.
     *
     * В сессию кладётся не сам хэш, а его SHA-256: сравнивать достаточно, а
     * файл сессии на диске хостинга не должен хранить материал для подбора.
     *
     * @param string $hash
     * @return string 64 hex-символа.
     */
    public static function stampFor(string $hash): string
    {
        return hash('sha256', $hash);
    }

    /**
     * Сделан ли вход в этой сессии под ТЕКУЩИМ паролем панели.
     *
     * @param array       $session Обычно $_SESSION.
     * @param string|null $hash    Результат {@see loadHash()}.
     * @return bool false, если пароль не настроен, отпечатка нет или он от
     *              прежнего пароля.
     */
    public static function sessionMatches(array $session, ?string $hash): bool
    {
        if ($hash === null) {
            return false;
        }
        $stamp = $session[self::SESSION_KEY] ?? null;
        return is_string($stamp) && hash_equals(self::stampFor($hash), $stamp);
    }

    /**
     * Содержимое файла `.panel_password.php` для данного хэша.
     *
     * var_export, а не склейка строк: в bcrypt-хэше есть `$`, и любая
     * «ручная» подстановка в кавычки рискует его испортить.
     *
     * @param string $hash Проверенный {@see isValidHash()} хэш.
     * @return string PHP-код, который при include возвращает $hash.
     */
    public static function renderFile(string $hash): string
    {
        return "<?php\n"
            . "// Сгенерировано tools/panel_password.php при деплое. Не править руками,\n"
            . "// не коммитить (файл в .gitignore). Источник — GitHub Secret PANEL_PASSWORD_HASH.\n"
            . 'return ' . var_export($hash, true) . ";\n";
    }
}

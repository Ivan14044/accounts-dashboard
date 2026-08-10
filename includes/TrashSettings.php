<?php
/**
 * Глобальные настройки корзины (retention / автоочистка).
 *
 * Хранятся в существующей таблице `user_settings` под сентинел-пользователем
 * `__system__`, setting_type=`trash_retention`. Значение — JSON:
 *   { "enabled": bool, "days": int, "last_purge_at": "Y-m-d H:i:s"|null }
 *
 * Миграции схемы не требуется — `user_settings` уже есть в проекте
 * (см. api/routes/settings.php).
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';

class TrashSettings {
    const SENTINEL_USER = '__system__';
    const SETTING_TYPE  = 'trash_retention';
    const DEFAULT_DAYS  = 30;
    const MIN_DAYS      = 1;
    const MAX_DAYS      = 3650;
    const PURGE_INTERVAL_SECONDS = 86400; // авто-purge не чаще раза в сутки

    private static function db(): mysqli {
        return Database::getInstance()->getConnection();
    }

    /**
     * Идемпотентно гарантирует наличие таблицы user_settings.
     * Схема зеркалит api/routes/settings.php::ensureUserSettingsTable().
     */
    private static function ensureTable(mysqli $mysqli): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        $mysqli->query("CREATE TABLE IF NOT EXISTS `user_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL,
            `setting_type` VARCHAR(100) NOT NULL,
            `setting_value` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_user_setting` (`username`, `setting_type`),
            INDEX `idx_username` (`username`),
            INDEX `idx_setting_type` (`setting_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Текущие настройки с дефолтами. Никогда не бросает — при любой ошибке
     * возвращает безопасные значения по умолчанию.
     *
     * @return array{enabled:bool,days:int,last_purge_at:?string,last_purge_deleted:int}
     */
    public static function get(): array {
        $defaults = [
            'enabled'            => true,
            'days'               => self::DEFAULT_DAYS,
            'last_purge_at'      => null,
            // Сколько записей снёс прошлый прогон. Нужен, чтобы сообщить об этом
            // пользователю: очистка идёт в shutdown, уже ПОСЛЕ отрисовки страницы,
            // поэтому на текущей странице показать результат физически нельзя —
            // только на следующем заходе.
            'last_purge_deleted' => 0,
        ];
        try {
            $mysqli = self::db();
            self::ensureTable($mysqli);
            $stmt = $mysqli->prepare(
                "SELECT setting_value FROM user_settings WHERE username = ? AND setting_type = ? LIMIT 1"
            );
            if (!$stmt) return $defaults;
            $u = self::SENTINEL_USER;
            $t = self::SETTING_TYPE;
            $stmt->bind_param('ss', $u, $t);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!$row) return $defaults;

            $val = json_decode($row['setting_value'] ?? '', true);
            if (!is_array($val)) return $defaults;

            return [
                'enabled'       => array_key_exists('enabled', $val) ? (bool)$val['enabled'] : true,
                'days'          => self::clampDays($val['days'] ?? self::DEFAULT_DAYS),
                'last_purge_at' => isset($val['last_purge_at']) && $val['last_purge_at'] !== '' ? (string)$val['last_purge_at'] : null,
                'last_purge_deleted' => isset($val['last_purge_deleted']) ? max(0, (int)$val['last_purge_deleted']) : 0,
            ];
        } catch (Throwable $e) {
            Logger::warning('TrashSettings::get failed', ['error' => $e->getMessage()]);
            return $defaults;
        }
    }

    /**
     * Сохраняет enabled + days, сохраняя текущую метку last_purge_at.
     *
     * @return array{enabled:bool,days:int,last_purge_at:?string,last_purge_deleted:int} новое состояние
     */
    public static function save(bool $enabled, int $days): array {
        $current = self::get();
        $payload = [
            'enabled'            => $enabled,
            'days'               => self::clampDays($days),
            'last_purge_at'      => $current['last_purge_at'],
            'last_purge_deleted' => $current['last_purge_deleted'],
        ];
        self::write($payload);
        return $payload;
    }

    /**
     * Обновляет метку последнего прогона авто-purge и его результат.
     *
     * Число удалённых сохраняется, чтобы страница корзины могла сообщить о нём
     * на следующем заходе: сама очистка выполняется в shutdown, когда страница
     * уже отдана пользователю, и показать результат сразу невозможно.
     *
     * @param string|null $when Метка времени; по умолчанию — сейчас
     * @param int $deleted Сколько записей удалено этим прогоном
     * @return void
     */
    public static function markPurged(?string $when = null, int $deleted = 0): void {
        $current = self::get();
        $current['last_purge_at'] = $when ?? date('Y-m-d H:i:s');
        $current['last_purge_deleted'] = max(0, $deleted);
        self::write($current);
    }

    private static function write(array $payload): void {
        try {
            $mysqli = self::db();
            self::ensureTable($mysqli);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $mysqli->prepare(
                "INSERT INTO user_settings (username, setting_type, setting_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP"
            );
            if (!$stmt) return;
            $u = self::SENTINEL_USER;
            $t = self::SETTING_TYPE;
            $stmt->bind_param('sss', $u, $t, $json);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            Logger::warning('TrashSettings::write failed', ['error' => $e->getMessage()]);
        }
    }

    public static function clampDays($days): int {
        $days = (int)$days;
        if ($days < self::MIN_DAYS) $days = self::MIN_DAYS;
        if ($days > self::MAX_DAYS) $days = self::MAX_DAYS;
        return $days;
    }

    /**
     * Пора ли запускать авто-purge: включено И (не было прогонов ИЛИ прошло >= суток).
     */
    public static function shouldAutoPurge(): bool {
        $s = self::get();
        if (empty($s['enabled'])) return false;
        if (empty($s['last_purge_at'])) return true;
        $last = strtotime($s['last_purge_at']);
        if ($last === false) return true;
        return (time() - $last) >= self::PURGE_INTERVAL_SECONDS;
    }
}

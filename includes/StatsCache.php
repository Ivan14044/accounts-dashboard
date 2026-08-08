<?php
/**
 * Файловый кэш агрегатов дашборда с коротким TTL.
 *
 * Зачем он есть. Счётчики, карточки статусов, значения фильтров и график
 * «за 7 дней» — это полные сканы таблицы. Замер на 180 300 строк: 412, 497, 290
 * и 250–516 мс соответственно. Они пересчитывались на КАЖДЫЙ показ страницы,
 * включая переход со страницы 1 на страницу 2 того же фильтра, хотя результат
 * при этом идентичен.
 *
 * Почему файлы, а не APCu/Redis. Хостинг общий, наличие APCu не гарантировано,
 * новых зависимостей заводить нельзя. Файл в системной temp-директории работает
 * везде; если писать туда нельзя — кэш просто выключается сам, и всё считается
 * как раньше (медленно, но правильно).
 *
 * Чего здесь осознанно НЕТ: инвалидации «руками» из кода мутаций. Ключ включает
 * отпечаток данных (MAX(updated_at) таблицы), поэтому любая вставка или правка
 * строки автоматически делает старый ключ недействительным — счётчики
 * обновляются сразу после действия пользователя, а не через TTL. TTL остаётся
 * страховкой для случаев, которые отпечаток не ловит (жёсткое удаление строк).
 *
 * @see StatisticsService — единственный потребитель
 */
class StatsCache
{
    /** @var bool Выключается на первой же неудачной записи, чтобы не долбить диск */
    private static $writable = true;

    /**
     * Вернуть значение из кэша или посчитать и запомнить.
     *
     * Любая ошибка кэша (нет прав, битый файл, кривой JSON) означает «считаем
     * заново» — кэш никогда не должен становиться причиной неверных данных или
     * падения страницы.
     *
     * @param string   $key      Ключ; должен включать отпечаток данных, см. шапку
     * @param int      $ttl      Время жизни в секундах
     * @param callable $producer Вычисление значения; вызывается только при промахе
     * @return mixed Значение из кэша или свежевычисленное
     */
    public static function remember(string $key, int $ttl, callable $producer)
    {
        if ($ttl <= 0) {
            return $producer();
        }

        $file = self::pathFor($key);

        $cached = self::read($file);
        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        self::write($file, $value, $ttl);

        return $value;
    }

    /**
     * Читает значение, если файл есть и не протух.
     *
     * @param string $file
     * @return mixed|null null — промах (нет файла, протух, битый)
     */
    private static function read(string $file)
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['expires'], $payload['data'])) {
            return null;
        }

        if ((int)$payload['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $payload['data'];
    }

    /**
     * Пишет значение атомарно: сначала во временный файл, потом rename.
     * Без этого параллельный запрос может прочитать наполовину записанный JSON.
     *
     * @param string $file
     * @param mixed  $value
     * @param int    $ttl
     * @return void
     */
    private static function write(string $file, $value, int $ttl): void
    {
        if (!self::$writable) {
            return;
        }

        $payload = json_encode(
            ['expires' => time() + $ttl, 'data' => $value],
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            return; // значение не сериализуется — молча не кэшируем
        }

        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            self::$writable = false;
            return;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            self::$writable = false;
        }
    }

    /**
     * Путь к файлу кэша. Имя проекта в пути — чтобы два экземпляра дашборда
     * на одном хостинге не читали кэш друг друга.
     *
     * @param string $key
     * @return string
     */
    private static function pathFor(string $key): string
    {
        $project = substr(md5(dirname(__DIR__)), 0, 8);

        return sys_get_temp_dir() . '/dashboard_stats_' . $project . '_' . md5($key) . '.json';
    }
}

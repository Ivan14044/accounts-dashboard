<?php
/**
 * JobProgress — файловое хранилище для streaming прогресса долгих операций.
 *
 * Используется в validate/check: сервер инкрементально пишет прогресс
 * после каждого sub-batch acctool, фронт читает через polling /progress.
 *
 * На shared FTP-хостинге нет Redis/Memcached. Атомарность гарантируется
 * записью во временный файл с последующим rename — на POSIX rename атомарен,
 * читатель никогда не увидит частично записанный JSON.
 *
 * Файлы живут в logs/jobs/<jobId>.json и удаляются через час (cleanup
 * вызывается на старте /check, поскольку cron на shared недоступен).
 */

require_once __DIR__ . '/Logger.php';

class JobProgress
{
    private const TTL_SECONDS = 3600;
    private const ID_PATTERN  = '/^[a-zA-Z0-9-]{1,64}$/';

    /**
     * Инкрементально увеличивает числовые поля. Файл создаётся автоматически,
     * если ещё не существует. Используется для +checked после каждого sub-batch.
     */
    public static function update(string $jobId, array $delta): void
    {
        if (!self::isValidId($jobId)) return;

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            Logger::warning('JobProgress: cannot create dir', ['dir' => $dir]);
            return;
        }

        $current = self::read($jobId) ?? [
            'checked'    => 0,
            'created_at' => time(),
        ];
        foreach ($delta as $k => $v) {
            $current[$k] = (int)($current[$k] ?? 0) + (int)$v;
        }
        $current['updated_at'] = time();
        self::write($jobId, $current);
    }

    public static function read(string $jobId): ?array
    {
        if (!self::isValidId($jobId)) return null;
        $file = self::path($jobId);
        if (!is_file($file)) return null;

        $content = @file_get_contents($file);
        if ($content === false || $content === '') return null;

        $data = @json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public static function delete(string $jobId): void
    {
        if (!self::isValidId($jobId)) return;
        @unlink(self::path($jobId));
    }

    /**
     * Удаляет старые job-файлы. Вызывается на старте /check.
     */
    public static function cleanup(int $maxAgeSeconds = self::TTL_SECONDS): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) return;

        $cutoff = time() - $maxAgeSeconds;
        $files  = @glob($dir . '/*.json') ?: [];
        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) @unlink($file);
        }
    }

    public static function isValidId(string $jobId): bool
    {
        return $jobId !== '' && preg_match(self::ID_PATTERN, $jobId) === 1;
    }

    private static function write(string $jobId, array $data): void
    {
        $file = self::path($jobId);
        $tmp  = $file . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) return;

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            Logger::warning('JobProgress: write failed', ['job' => $jobId]);
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    private static function dir(): string
    {
        return __DIR__ . '/../logs/jobs';
    }

    private static function path(string $jobId): string
    {
        return self::dir() . '/' . $jobId . '.json';
    }
}

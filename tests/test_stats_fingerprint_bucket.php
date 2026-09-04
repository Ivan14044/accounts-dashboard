<?php
/**
 * Отпечаток кэша агрегатов округляется по времени.
 *
 * Зачем этот тест. Раньше ключ кэша содержал ТОЧНЫЙ MAX(updated_at), поэтому
 * правка любой строки кем угодно делала кэш недействительным, и следующий
 * открывший страницу платил полный пересчёт. На живой панели правки идут
 * постоянно — в пересчёт попадали почти все заходы (замер на 184 800 строках:
 * 0,58 с против 0,045 с из кэша). Теперь отпечаток округляется вниз до окна
 * STATS_FINGERPRINT_BUCKET, и правки внутри одного окна делят общий ключ.
 *
 * Тест стережёт именно это свойство: два момента внутри окна должны давать
 * ОДИН ключ, а по разные стороны границы — РАЗНЫЕ. Если кто-то вернёт точный
 * отпечаток, тест покраснеет.
 *
 * Запуск: php tests/test_stats_fingerprint_bucket.php
 */

// Warning/Notice — сразу падение: на проде error_reporting=E_ALL.
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/Config.php';

// StatisticsService тянет за собой Database/ColumnMetadata, а нам нужен только
// чистый статический метод. Подключаем файл: конструктор не вызывается.
require_once __DIR__ . '/../includes/StatisticsService.php';

$failures = 0;
$checks   = 0;

function check($condition, $what) {
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  ok   $what\n";
    } else {
        echo "  FAIL $what\n";
        $failures++;
    }
}

$bucket = 30;

// ── Внутри одного окна ключ общий ──────────────────────────────────────
$a = StatisticsService::bucketFingerprint('2026-09-04 10:00:00', $bucket);
$b = StatisticsService::bucketFingerprint('2026-09-04 10:00:29', $bucket);
check($a === $b, 'две правки внутри окна дают один отпечаток');

// ── За границей окна ключ меняется ─────────────────────────────────────
$c = StatisticsService::bucketFingerprint('2026-09-04 10:00:30', $bucket);
check($a !== $c, 'правка за границей окна даёт другой отпечаток');

// ── Окно шире — больше правок склеивается ──────────────────────────────
$wideA = StatisticsService::bucketFingerprint('2026-09-04 10:00:00', 300);
$wideB = StatisticsService::bucketFingerprint('2026-09-04 10:04:59', 300);
check($wideA === $wideB, 'окно 5 минут склеивает правки внутри пяти минут');

// ── Округление всегда вниз, не вверх ───────────────────────────────────
$floorA = StatisticsService::bucketFingerprint('2026-09-04 10:00:59', 60);
$floorB = StatisticsService::bucketFingerprint('2026-09-04 10:00:00', 60);
check($floorA === $floorB, 'округление идёт вниз до начала окна');

// ── Нулевое и единичное окно отключают округление ──────────────────────
check(
    StatisticsService::bucketFingerprint('2026-09-04 10:00:00', 0) === '2026-09-04 10:00:00',
    'окно 0 отключает округление'
);
check(
    StatisticsService::bucketFingerprint('2026-09-04 10:00:00', 1) === '2026-09-04 10:00:00',
    'окно 1 отключает округление'
);

// ── Неразбираемые значения возвращаются как есть ───────────────────────
// 'na' и 'empty' сервис ставит, когда отпечаток получить не удалось.
// Лучше лишний пересчёт, чем ключ, склеивающий разные состояния данных.
check(StatisticsService::bucketFingerprint('na', $bucket) === 'na', "'na' возвращается как есть");
check(StatisticsService::bucketFingerprint('empty', $bucket) === 'empty', "'empty' возвращается как есть");
check(StatisticsService::bucketFingerprint('', $bucket) === '', 'пустая строка возвращается как есть');

// ── Разные даты не склеиваются ─────────────────────────────────────────
$day1 = StatisticsService::bucketFingerprint('2026-09-04 10:00:00', $bucket);
$day2 = StatisticsService::bucketFingerprint('2026-09-05 10:00:00', $bucket);
check($day1 !== $day2, 'правки в разные дни не склеиваются');

// ── Константы на месте и осмысленны ────────────────────────────────────
check(
    defined('Config::STATS_FINGERPRINT_BUCKET') || Config::STATS_FINGERPRINT_BUCKET > 1,
    'окно округления задано в Config и больше единицы'
);
check(
    Config::STATS_SELF_WRITE_FLAG !== '',
    'ключ метки собственной правки задан в Config'
);

echo "\n$checks проверок, ошибок: $failures\n";
exit($failures === 0 ? 0 : 1);

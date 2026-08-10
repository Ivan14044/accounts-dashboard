<?php
/**
 * Тест: все тяжёлые агрегаты дашборда идут через файловый кэш.
 *
 * Запуск:  php tests/test_stats_aggregates_cached.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10 замером на стенде с прод-формой
 * данных: 182 021 строка, 72 статуса).
 *
 * Открытый вопрос владельца звучал так: локально прогретая страница отдаётся за
 * 0,04 с, а на проде — за 1,6–1,7 с, и причина неизвестна. Причина нашлась:
 * четырёх колонок, по которым дашборд считает фильтры (status_marketplace,
 * currency, geo, status_rk), НЕТ в эталонной схеме
 * DatabaseSchemaManager::getRequiredSchema(). На проде они есть (заведены руками
 * когда-то раньше), а на любом стенде, поднятом эталоном, — нет. Весь код читает
 * их через columnExists(), поэтому локально соответствующие запросы просто не
 * выполнялись, и воспроизвести прод было нечем.
 *
 * Стенд с этими колонками (замер curl, 182 021 строка):
 *   без колонок:  холодная 1,91 с / прогретая 0,05 с
 *   с колонками:  холодная 2,72 с / прогретая 0,29–0,35 с
 * То есть прод медленный ровно из-за них.
 *
 * Что нашлось при разборе по запросам:
 *   getEmptyFilterCounts() — 0,23 с, скан 84 042 строк с доступом к данным строки,
 *   и он выполняется на КАЖДЫЙ заход, даже когда все остальные агрегаты пришли
 *   из кэша: единственный из тяжёлых агрегатов, не обёрнутый в StatsCache.
 *   На прогретой странице это была почти вся её стоимость.
 *
 * Инвариант, который стережёт тест: каждый метод StatisticsService, делающий
 * полный проход по таблице, обязан ходить через StatsCache::remember с ключом
 * от cacheKey() (в ключе — отпечаток MAX(updated_at), поэтому кэш сам
 * инвалидируется после правки данных). Забыть обёртку легко — это уже случилось
 * один раз и стоило 0,23 с на каждый заход.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * Фиксирует результат проверки.
 *
 * @param bool $ok Прошла ли проверка
 * @param string $name Что проверяли
 * @param string $detail Подробности при провале
 * @return void
 */
function sacCheck($ok, $name, $detail = '')
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Вырезает комментарии из PHP-исходника, чтобы проверки не срабатывали
 * на тексте docblock'ов.
 *
 * @param string $code Исходник
 * @return string Код без комментариев
 */
function sacStripComments($code)
{
    $out = '';
    foreach (token_get_all($code) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= "\n";
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/**
 * Возвращает тело метода класса из уже очищенного от комментариев исходника.
 *
 * @param string $code Исходник без комментариев
 * @param string $method Имя метода
 * @return string|null Тело метода или null, если метод не найден
 */
function sacMethodBody($code, $method)
{
    $at = strpos($code, 'function ' . $method);
    if ($at === false) {
        return null;
    }
    $open = strpos($code, '{', $at);
    if ($open === false) {
        return null;
    }
    $depth = 0;
    $len = strlen($code);
    for ($i = $open; $i < $len; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }
    }
    return null;
}

echo "\n=== Тяжёлые агрегаты дашборда обёрнуты в StatsCache ===\n\n";

$statsPath = $ROOT . '/includes/StatisticsService.php';
$stats     = sacStripComments(file_get_contents($statsPath));

/*
 * Публичные методы StatisticsService, каждый из которых делает полный проход
 * по accounts. Для каждого — имя «сырого» вычислителя, который обязан
 * вызываться только из замыкания кэша.
 */
$aggregates = array(
    'getStatistics'          => 'computeStatistics',
    'getUniqueFilterValues'  => 'computeUniqueFilterValues',
    'getDailyTotals'         => 'computeDailyTotals',
    'getEmptyFilterCounts'   => 'computeEmptyFilterCounts',
);

foreach ($aggregates as $public => $compute) {
    $body = sacMethodBody($stats, $public);
    if ($body === null) {
        sacCheck(false, "StatisticsService::$public() существует", 'метод не найден — тест устарел?');
        continue;
    }

    sacCheck(
        strpos($body, 'StatsCache::remember') !== false,
        "$public() идёт через StatsCache::remember",
        'без обёртки этот агрегат сканирует таблицу на каждый заход'
    );

    sacCheck(
        preg_match('~\$this->cacheKey\s*\(~', $body) === 1,
        "$public() строит ключ через cacheKey() (в нём отпечаток MAX(updated_at))",
        'без отпечатка кэш не инвалидируется после правки данных'
    );

    // Тяжёлый расчёт не должен вызываться напрямую из публичного метода мимо кэша.
    $direct = preg_match_all('~(?<![\w>])' . preg_quote($compute, '~') . '\s*\(~', $body);
    $inClosure = preg_match('~function\s*\([^)]*\)[^{]*\{[^}]*' . preg_quote($compute, '~') . '~s', $body);
    sacCheck(
        $direct === 0 || $inClosure === 1,
        "$public() зовёт $compute() только из замыкания кэша",
        'прямой вызов мимо StatsCache сводит кэш на нет'
    );
}

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

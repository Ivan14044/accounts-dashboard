<?php
/**
 * Тест: счётчики пустых фильтров считаются отдельными запросами по одной
 * колонке, а не одним общим с четырьмя SUM(CASE ...).
 *
 * Запуск:  php tests/test_empty_filter_counts_split.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-09-03 разбором slow log прода).
 *
 * Общий запрос с SUM(CASE ...) по четырём разным колонкам одним индексом не
 * покрывается, поэтому он читал таблицу целиком: 198 запусков в журнале,
 * 182 087 строк за запуск, в среднем 5,9 с и до 8,2 с — второй по стоимости
 * запрос самой панели (1177 секунд суммарно).
 *
 * Четыре отдельных COUNT(*) ложатся каждый на свой уже существующий индекс
 * (deleted_at, колонка) и читаются прямо из индекса. Замер на стенде
 * (185 000 строк прод-формы, mysql:8.0, прогретый кэш): 373–460 мс против
 * 32–35 мс, то есть в 12 раз быстрее.
 *
 * Соблазн «схлопнуть обратно в один запрос, чтобы не гонять четыре» тут
 * ошибочен — это и была исходная беда. Совпадение чисел со старым запросом
 * проверяет tests/integration_empty_filter_counts_mysql.php.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

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
function efsCheck($ok, $name, $detail = '')
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
function efsStripComments($code)
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
function efsMethodBody($code, $method)
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

echo "\n=== Счётчики пустых фильтров считаются по одной колонке за запрос ===\n\n";

$code = efsStripComments(file_get_contents($ROOT . '/includes/StatisticsService.php'));
$body = efsMethodBody($code, 'computeEmptyFilterCounts');

efsCheck($body !== null, 'метод computeEmptyFilterCounts() найден', 'тест устарел?');

if ($body === null) {
    echo "\nРезультат: $passed пройдено, " . ($failures + 1) . " провалено\n";
    exit(1);
}

efsCheck(
    strpos($body, 'SUM(CASE') === false,
    'общий запрос с SUM(CASE ...) больше не строится',
    'он читает таблицу целиком — ровно то, из-за чего на проде было 5,9 с на заход'
);

efsCheck(
    substr_count($body, 'COUNT(*)') >= 1 && strpos($body, 'foreach') !== false,
    'счётчики собираются перебором колонок отдельными COUNT(*)',
    'каждая колонка обязана лечь на свой индекс (deleted_at, колонка)'
);

// Условие «пусто» должно остаться прежним: NULL ИЛИ пустая строка.
efsCheck(
    preg_match('~IS NULL OR .*= \'\'~', $body) === 1,
    'смысл «пусто» не изменился: NULL или пустая строка',
    'иначе цифры на карточках разъедутся с тем, что показывает фильтр'
);

// Удалённые в корзину по-прежнему не считаются.
efsCheck(
    strpos($body, 'deleted_at IS NULL') !== false,
    'записи из корзины по-прежнему исключаются',
    'иначе счётчики начнут включать удалённые аккаунты'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

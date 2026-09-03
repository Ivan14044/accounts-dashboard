<?php
/**
 * Тест: подключение к БД поднимает лимит памяти оптимизатора диапазонов.
 *
 * Запуск:  php tests/test_range_optimizer_session.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-09-03 разбором slow log прода).
 *
 * В медленном журнале боевой БД лежали 42 подряд идущих массовых смены статуса
 * вида «UPDATE accounts SET status = ? , updated_at = CURRENT_TIMESTAMP
 * WHERE id IN (<1000 штук>) AND deleted_at IS NULL». Каждая шла 66–68 секунд и
 * читала РОВНО 185 037 строк — всю таблицу целиком, хотя менялась тысяча строк
 * по первичному ключу. То же самое в удалении в корзину: 193 001 строка,
 * до 67 секунд на запрос.
 *
 * Причина воспроизведена на стенде (mysql:5.7, 185 000 строк прод-формы,
 * настоящий prepared statement из PHP, счётчик Handler_read_rnd_next):
 * серверная переменная range_optimizer_max_mem_size ограничивает память,
 * которую оптимизатор тратит на разбор диапазонов. Длинный список IN в неё
 * не помещается — и MySQL молча ОТКАЗЫВАЕТСЯ от плана по первичному ключу и
 * читает таблицу целиком. Замер:
 *   лимит 16 КБ:  1000 id → 5,01 с, Handler_read_rnd_next = 185 001 (полный скан)
 *   лимит 8 МБ:   1000 id → 0,93 с, Handler_read_rnd_next = 0 (1000 поисков по PK)
 * Уменьшение пачки не спасает: при лимите 16 КБ даже 200 id читали всю таблицу.
 *
 * Поэтому подключение выставляет лимит не ниже дефолта MySQL (8 МБ) на свою
 * сессию. Инвариант, который стережёт тест: строка SET SESSION
 * range_optimizer_max_mem_size из Database::__construct не должна исчезнуть.
 * Живую проверку самого механизма делает
 * tests/integration_range_optimizer_mysql.php.
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
function romCheck($ok, $name, $detail = '')
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

echo "\n=== Лимит памяти оптимизатора диапазонов поднимается на сессию ===\n\n";

$code = file_get_contents($ROOT . '/includes/Database.php');

romCheck(
    strpos($code, 'range_optimizer_max_mem_size') !== false,
    'Database.php вообще упоминает range_optimizer_max_mem_size',
    'без этого длинный IN(...) на проде читает таблицу целиком'
);

// Настройка обязана применяться там же, где остальные SET SESSION — в конструкторе,
// то есть один раз на подключение и до любых запросов приложения.
$at = strpos($code, 'function __construct');
$end = strpos($code, 'public static function getInstance');
$ctor = ($at !== false && $end !== false && $end > $at) ? substr($code, $at, $end - $at) : '';

romCheck(
    $ctor !== '' && strpos($ctor, 'range_optimizer_max_mem_size') !== false,
    'настройка выставляется в конструкторе Database (один раз на подключение)',
    'иначе часть запросов уйдёт в БД до неё'
);

// Значение не должно опускать уже поднятый лимит и не должно ломать «0 = без лимита».
romCheck(
    preg_match('~GREATEST\s*\(\s*@@SESSION\.range_optimizer_max_mem_size~i', $ctor) === 1,
    'лимит только поднимается (GREATEST), а не переписывается вслепую',
    'сервер мог быть настроен щедрее нашего минимума'
);

romCheck(
    preg_match('~IF\s*\(\s*@@SESSION\.range_optimizer_max_mem_size\s*=\s*0~i', $ctor) === 1,
    'значение 0 («без лимита») сохраняется как есть',
    'GREATEST(0, 8МБ) превратил бы «без лимита» в 8 МБ'
);

romCheck(
    strpos($ctor, '8388608') !== false,
    'минимум равен дефолту MySQL — 8 388 608 байт',
    'берём ровно то, что MySQL считает нормой, а не выдуманное число'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

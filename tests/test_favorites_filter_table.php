<?php
/**
 * Тест: фильтр «только избранные» работает не только на таблице accounts.
 *
 * Запуск:  php tests/test_favorites_filter_table.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (воспроизведено на стенде 2026-08-10).
 *
 * Вход в панель — это строка подключения, и одна установка обслуживает сколько
 * угодно таблиц: имя выбирается параметром ?table=. Но подзапрос избранного в
 * FilterBuilder был написан с жёстким `accounts.id`:
 *
 *   EXISTS (SELECT 1 FROM account_favorites
 *           WHERE account_favorites.account_id = accounts.id AND ...)
 *
 * Итог на стенде со второй таблицей:
 *   ?table=accounts2                   → 200
 *   ?table=accounts2&favorites_only=1  → 500
 *   в логе: Unknown column 'accounts.id' in 'where clause'
 *
 * Имя таблицы подставляется в SQL строкой (иначе никак — имена таблиц не
 * биндятся), поэтому тест отдельно проверяет, что мусорное имя отвергается,
 * а не уезжает в запрос.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/FilterBuilder.php';

$failures = 0;
$passed   = 0;

/**
 * Фиксирует результат проверки.
 *
 * @param string $name Что проверяли
 * @param bool $ok Прошло ли
 * @param string $detail Подробности при провале
 * @return void
 */
function fftCheck($name, $ok, $detail = '')
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
 * Текст подзапроса избранного для указанной таблицы.
 *
 * Проверяем именно чистую функцию, а не getWhereClause(): та ходит в БД за
 * метаданными (нужна для авто-добавления deleted_at), и в тесте без соединения
 * не выполнима.
 *
 * @param string|null $table Имя таблицы или null — взять умолчание
 * @return string SQL-условие
 */
function fftWhere($table)
{
    return $table === null
        ? FilterBuilder::favoritesExistsClause()
        : FilterBuilder::favoritesExistsClause($table);
}

echo "\n=== Фильтр «только избранные» на произвольной таблице ===\n\n";

// ── Основная таблица: поведение не меняется ──
$where = fftWhere('accounts');
fftCheck('accounts: подзапрос ссылается на `accounts`.id',
    strpos($where, '`accounts`.id') !== false, $where);
fftCheck('accounts: обращается к account_favorites',
    strpos($where, 'account_favorites') !== false, $where);

// ── Вторая таблица: должна подставиться она, а не accounts ──
$where = fftWhere('accounts2');
fftCheck(
    'accounts2: подзапрос ссылается на `accounts2`.id',
    strpos($where, '`accounts2`.id') !== false,
    $where
);
fftCheck(
    'accounts2: НЕТ обращения к accounts.id',
    strpos($where, '`accounts`.id') === false,
    'осталось жёсткое accounts.id: ' . $where
);

// ── Совместимость: без явного имени работаем как раньше ──
$where = fftWhere(null);
fftCheck(
    'без указания таблицы по умолчанию accounts',
    strpos($where, '`accounts`.id') !== false,
    $where
);

// ── Имя таблицы уходит в SQL строкой, поэтому мусор недопустим ──
$rejected = 0;
$attempts = array('acc ounts', 'accounts; DROP TABLE x', "acc'ounts", 'accounts`', '');
foreach ($attempts as $bad) {
    try {
        fftWhere($bad);
    } catch (InvalidArgumentException $e) {
        $rejected++;
    } catch (Throwable $e) {
        // любое другое исключение тоже означает «не пропустили»
        $rejected++;
    }
}
fftCheck(
    'недопустимое имя таблицы отвергается, а не уезжает в SQL',
    $rejected === count($attempts),
    "отвергнуто $rejected из " . count($attempts)
);

// ── Нормальные имена принимаются ──
$okNames = 0;
foreach (array('accounts', 'accounts2', 'my_table_1') as $good) {
    try {
        fftWhere($good);
        $okNames++;
    } catch (Throwable $e) {
        // не должно
    }
}
fftCheck('обычные имена таблиц принимаются', $okNames === 3, "принято $okNames из 3");

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

<?php
/**
 * Тест: выгруженный CSV читается обратно импортом без потери колонок.
 *
 * Запуск:  php tests/test_csv_roundtrip_headers.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10).
 *
 * Экспорт писал в заголовок человекочитаемые названия («First Name»,
 * «Social URL», «2FA», «Id soc account»), а импорт сопоставлял заголовок с
 * ИМЕНЕМ КОЛОНКИ В БД в нижнем регистре. Совпадали только те девять колонок,
 * у которых название и имя колонки случайно одинаковы (login, password, email,
 * token, cookies, status, avatar, cover). Остальные 35 импорт молча пропускал:
 * неизвестная колонка писалась в лог и всё.
 *
 * Итог типового сценария «выгрузил → поправил в Excel → залил обратно»:
 * возвращались только эти девять полей, остальное не обновлялось, а в режиме
 * «Обновить» файл выглядел так, будто пользователь осознанно оставил их пустыми.
 * Ни одной ошибки при этом не показывалось.
 *
 * Корень — две несвязанные карты названий: своя в export.php и своя в
 * ColumnMetadata (там вообще русские названия для таблицы). Классическая для
 * этого проекта болезнь «одна сущность в двух местах», из-за которой уже
 * расходились схема accounts и разметка форм. Поэтому чинится не сопоставлением
 * «на глазок», а общим источником правды: CsvColumnTitles.
 *
 * Главная проверка ниже — инвариант round-trip: для КАЖДОЙ колонки эталонной
 * схемы resolve(titleFor($col)) обязан вернуть ровно $col. Именно он и был
 * нарушен, и именно он не даст разъехаться картам снова.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/CsvColumnTitles.php';
require_once __DIR__ . '/../includes/DatabaseSchemaManager.php';

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
function crtCheck($name, $ok, $detail = '')
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
 * Колонки эталонной схемы accounts.
 *
 * getRequiredSchema() приватный и не трогает $this — берём рефлексией без
 * вызова конструктора (тот ходит в БД).
 *
 * @return string[]
 */
function crtSchemaColumns()
{
    $ref = new ReflectionClass('DatabaseSchemaManager');
    $obj = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('getRequiredSchema');
    $m->setAccessible(true);
    $schema = $m->invoke($obj);

    $cols = array();
    foreach (array_keys($schema['accounts']['columns']) as $col) {
        if ($col === 'PRIMARY KEY' || strpos($col, 'UNIQUE KEY') !== false) {
            continue;
        }
        $cols[] = $col;
    }
    return $cols;
}

echo "\n=== CSV: экспорт → импорт без потери колонок ===\n\n";

$columns = crtSchemaColumns();
crtCheck('колонки эталонной схемы прочитаны', count($columns) > 30, 'получили ' . count($columns));

// ── ГЛАВНОЕ: round-trip по каждой колонке ──
$broken = array();
foreach ($columns as $col) {
    $title = CsvColumnTitles::titleFor($col);
    $back  = CsvColumnTitles::resolve($title, $columns);
    if ($back !== $col) {
        $broken[] = $col . ' → "' . $title . '" → ' . var_export($back, true);
    }
}
crtCheck(
    'каждая колонка переживает round-trip: колонка → заголовок → колонка',
    $broken === array(),
    count($broken) . ' колонок теряется: ' . implode('; ', array_slice($broken, 0, 6))
);

// ── Имя колонки в БД по-прежнему принимается (старые файлы не ломаем) ──
$rawBroken = array();
foreach ($columns as $col) {
    if (CsvColumnTitles::resolve($col, $columns) !== $col) {
        $rawBroken[] = $col;
    }
}
crtCheck(
    'заголовок с именем колонки БД по-прежнему распознаётся',
    $rawBroken === array(),
    'сломались: ' . implode(', ', array_slice($rawBroken, 0, 6))
);

// ── Отдельные случаи, на которых простое преобразование не работает ──
$cases = array(
    'First Name'      => 'first_name',
    'Social URL'      => 'social_url',
    '2FA'             => 'two_fa',
    'Id soc account'  => 'id_soc_account',
    'ID'              => 'id',
    'Email Password'  => 'email_password',
    'login'           => 'login',
    'LOGIN'           => 'login',
    '  first_name  '  => 'first_name',
);
foreach ($cases as $header => $expect) {
    $got = CsvColumnTitles::resolve($header, $columns);
    // Скобки обязательны: «»» в UTF-8 — байты >= 0x80, и PHP втягивает их
    // в имя переменной, превращая $header» в неопределённую переменную.
    crtCheck("заголовок «{$header}» → {$expect}", $got === $expect, 'получили ' . var_export($got, true));
}

// ── Шаблон помечает обязательные поля звёздочкой ──
crtCheck(
    'звёздочка обязательного поля не мешает: «login*» → login',
    CsvColumnTitles::resolve('login*', $columns) === 'login'
);
crtCheck(
    'звёздочка на человекочитаемом: «First Name*» → first_name',
    CsvColumnTitles::resolve('First Name*', $columns) === 'first_name'
);

// ── BOM в начале первого заголовка (Excel его ставит) ──
crtCheck(
    'BOM перед первым заголовком не мешает',
    CsvColumnTitles::resolve("\xEF\xBB\xBFlogin", $columns) === 'login'
);

// ── Неизвестное остаётся неизвестным: молча угадывать нельзя ──
crtCheck(
    'неизвестный заголовок → null',
    CsvColumnTitles::resolve('Совершенно посторонняя колонка', $columns) === null
);
crtCheck(
    'пустой заголовок → null',
    CsvColumnTitles::resolve('   ', $columns) === null
);

// ── Колонки, которой нет в этой БД, быть не должно ──
crtCheck(
    'заголовок известной колонки, отсутствующей в БД, → null',
    CsvColumnTitles::resolve('First Name', array('login', 'status')) === null,
    'сопоставление обязано опираться на реальные колонки таблицы'
);

echo "\n  (колонок в эталоне: " . count($columns) . ")\n";
echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

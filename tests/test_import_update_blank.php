<?php
/**
 * Тест: импорт в режиме «Обновить» не затирает колонки, которых нет в файле.
 *
 * Запуск:  php tests/test_import_update_blank.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-10, воспроизведено на живой БД).
 *
 * Сценарий, который ломался. Пользователь скачивает шаблон
 * (download_account_template.php кладёт в заголовок ВСЕ колонки), заполняет в нём
 * login и status, выбирает «Обновить» и загружает. Ожидание: обновятся два поля.
 * Что происходило: у существующего аккаунта обнулялись password, email,
 * first_name, last_name, id_soc_account, extra_info_1, birth_year и все прочие —
 * а отчёт говорил «Обновлено: 1» без единого предупреждения.
 *
 * Почему так выходило — две независимые причины, обе ведут к затиранию:
 *
 *  1. CsvParser заполняет КАЖДЫЙ заголовок значением: если в строке колонок
 *     меньше, чем в заголовке, строка добивается пустыми (`array_pad`), а дальше
 *     каждому заголовку присваивается '' (CsvParser::parse). То есть до
 *     репозитория доезжает не «поля нет», а «поле пустое».
 *     Ниже по цепочке AccountsRepository превращает '' в NULL для nullable-колонок
 *     — и UPDATE честно пишет NULL поверх данных.
 *
 *  2. Список полей для UPDATE (`$allowedFields`) строится для СОЗДАНИЯ записи и
 *     дополнительно добирает значения по умолчанию для NOT NULL колонок, которых
 *     в файле нет вообще. В режиме обновления такие поля тоже попадали в SET и
 *     переписывали существующие значения дефолтами.
 *
 * Правило, которое теперь действует и которое стережёт этот тест:
 * в режиме «Обновить» пустое значение в файле означает «не трогать это поле»,
 * а не «очистить его». Обновляются только те колонки, которые реально присутствуют
 * в файле и непустые. Следствие, о котором надо помнить: очистить поле импортом
 * теперь нельзя — это осознанный размен, потому что цена ошибки в другую сторону
 * (молчаливая потеря данных без возможности отката) несопоставимо выше.
 * Импорт не пишет в журнал отмены, то есть затирание было невосстановимым.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/AccountsRepository.php';

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
function iubCheck($name, $ok, $detail = '')
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

echo "\n=== Импорт «Обновить»: пустое поле не затирает данные ===\n\n";

iubCheck(
    'AccountsRepository::fieldsToUpdateFromImport() существует',
    method_exists('AccountsRepository', 'fieldsToUpdateFromImport'),
    'решение «какие поля обновлять» должно быть отдельной проверяемой функцией'
);
if (!method_exists('AccountsRepository', 'fieldsToUpdateFromImport')) {
    echo "\nБез этой функции остальные проверки бессмысленны.\n";
    exit(1);
}

// ── Главный сценарий: шаблон со всеми колонками, заполнены login и status ──
$row = array(
    'login'          => 'bob',
    'status'         => 'banned',
    'password'       => '',
    'email'          => '',
    'first_name'     => '',
    'last_name'      => '',
    'id_soc_account' => '',
    'extra_info_1'   => '',
    'birth_year'     => '',
);
// В allowedFields попадают все колонки строки плюс добавленный дефолт колонки,
// которой в файле нет вообще (так делает createAccountsBulk для NOT NULL).
$allowed = array_merge(array_keys($row), array('some_required_col'));

$toUpdate = AccountsRepository::fieldsToUpdateFromImport($row, $allowed);

iubCheck(
    'обновляется только status (login — ключ поиска, его не трогаем)',
    $toUpdate === array('status'),
    'получили: ' . json_encode($toUpdate)
);
foreach (array('password', 'email', 'first_name', 'last_name', 'id_soc_account', 'extra_info_1', 'birth_year') as $f) {
    iubCheck(
        "пустое поле `$f` не попадает в UPDATE",
        !in_array($f, $toUpdate, true)
    );
}
iubCheck(
    'колонка, которой нет в файле, не попадает в UPDATE (дефолт NOT NULL не затирает)',
    !in_array('some_required_col', $toUpdate, true),
    'получили: ' . json_encode($toUpdate)
);

// ── Нормальный случай: заполненные поля обновляются ──
$row2 = array('login' => 'bob', 'status' => 'active', 'email' => 'new@example.invalid', 'first_name' => '');
$toUpdate2 = AccountsRepository::fieldsToUpdateFromImport($row2, array_keys($row2));
iubCheck(
    'заполненные поля обновляются, пустое — нет',
    $toUpdate2 === array('status', 'email'),
    'получили: ' . json_encode($toUpdate2)
);

// ── Пробелы — это тоже пусто (CsvParser уже тримит, но не полагаемся на это) ──
$row3 = array('login' => 'bob', 'status' => '   ', 'email' => ' x@example.invalid ');
$toUpdate3 = AccountsRepository::fieldsToUpdateFromImport($row3, array_keys($row3));
iubCheck(
    'поле из одних пробелов считается пустым',
    !in_array('status', $toUpdate3, true),
    'получили: ' . json_encode($toUpdate3)
);
iubCheck(
    'значение с пробелами по краям — не пустое',
    in_array('email', $toUpdate3, true),
    'получили: ' . json_encode($toUpdate3)
);

// ── Ноль и «0» — ЗНАЧИМЫЕ значения, а не пустота ──
$row4 = array('login' => 'bob', 'birth_year' => '0', 'fan_count_1' => 0);
$toUpdate4 = AccountsRepository::fieldsToUpdateFromImport($row4, array_keys($row4));
iubCheck(
    'строка "0" не считается пустотой',
    in_array('birth_year', $toUpdate4, true),
    'получили: ' . json_encode($toUpdate4)
);
iubCheck(
    'число 0 не считается пустотой',
    in_array('fan_count_1', $toUpdate4, true),
    'получили: ' . json_encode($toUpdate4)
);

// ── Строка, где кроме login ничего нет: обновлять нечего ──
$row5 = array('login' => 'bob', 'status' => '', 'email' => '');
iubCheck(
    'строка без единого заполненного поля даёт пустой список',
    AccountsRepository::fieldsToUpdateFromImport($row5, array_keys($row5)) === array(),
    'получили: ' . json_encode(AccountsRepository::fieldsToUpdateFromImport($row5, array_keys($row5)))
);

// ── Системные поля не обновляются никогда ──
$row6 = array('login' => 'bob', 'status' => 'active', 'id' => '5', 'created_at' => '2020-01-01', 'deleted_at' => '2020-01-01');
$toUpdate6 = AccountsRepository::fieldsToUpdateFromImport($row6, array_keys($row6));
foreach (array('id', 'created_at', 'deleted_at', 'login') as $f) {
    iubCheck("системное поле `$f` не обновляется", !in_array($f, $toUpdate6, true), 'получили: ' . json_encode($toUpdate6));
}

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

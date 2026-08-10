<?php
/**
 * Тест: слишком длинное значение не обрезается молча.
 *
 * Запуск:  php tests/test_value_length_guard.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (воспроизведено на стенде 2026-08-10).
 *
 * sql_mode приложения — 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'
 * (includes/Database.php), то есть без STRICT. В таком режиме MySQL не отвергает
 * слишком длинное значение, а молча его обрезает и отдаёт Warning, который никто
 * не читает.
 *
 * Что получалось. Колонка status — VARCHAR(50). Отправляем через update_field.php
 * статус в 60 символов:
 *   ответ: {"success":true,"affected":1}
 *   в БД:  50 символов
 * Пользователь уверен, что сохранил одно, а сохранилось другое.
 *
 * Отдельно неприятно, что после такой правки её нельзя откатить: в
 * account_history пишется отправленное значение (60 символов), а UndoService
 * откатывает, только если текущее значение в БД совпадает с записанным. 50 ≠ 60,
 * и откат уходит в skipped_conflict.
 *
 * Правило: если значение не помещается в колонку — это ошибка ввода, а не повод
 * тихо укоротить данные. Проверяем чистые функции: разбор предела из типа
 * колонки и решение «помещается / нет».
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
function vlgCheck($name, $ok, $detail = '')
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

echo "\n=== Защита от молчаливой обрезки значений ===\n\n";

vlgCheck(
    'AccountsRepository::maxLengthForType() существует',
    method_exists('AccountsRepository', 'maxLengthForType')
);
vlgCheck(
    'AccountsRepository::valueFitsColumnType() существует',
    method_exists('AccountsRepository', 'valueFitsColumnType')
);
if (!method_exists('AccountsRepository', 'maxLengthForType')
    || !method_exists('AccountsRepository', 'valueFitsColumnType')) {
    echo "\nБез них остальные проверки бессмысленны.\n";
    exit(1);
}

// ── Предел из типа колонки ──
$cases = array(
    'varchar(50)'  => 50,
    'VARCHAR(255)' => 255,
    'char(10)'     => 10,
    'tinytext'     => 255,
    'text'         => 65535,
    'mediumtext'   => 16777215,
    // Числовые и прочие — предела по длине не имеют
    'int unsigned' => null,
    'datetime'     => null,
    'tinyint(1)'   => null,
);
foreach ($cases as $type => $expect) {
    $got = AccountsRepository::maxLengthForType($type);
    vlgCheck(
        "предел для «{$type}» = " . var_export($expect, true),
        $got === $expect,
        'получили ' . var_export($got, true)
    );
}

// ── Помещается или нет ──
vlgCheck('50 символов помещаются в varchar(50)', AccountsRepository::valueFitsColumnType(str_repeat('S', 50), 'varchar(50)'));
vlgCheck('51 символ НЕ помещается в varchar(50)', !AccountsRepository::valueFitsColumnType(str_repeat('S', 51), 'varchar(50)'));
vlgCheck('60 символов НЕ помещаются в varchar(50) — исходный баг',
    !AccountsRepository::valueFitsColumnType(str_repeat('S', 60), 'varchar(50)'));

// Кириллица считается символами, а не байтами: VARCHAR(N) в MySQL — это N символов
vlgCheck(
    '50 кириллических символов помещаются в varchar(50)',
    AccountsRepository::valueFitsColumnType(str_repeat('я', 50), 'varchar(50)'),
    'считаем байты вместо символов — валидные значения будут отвергаться'
);
vlgCheck('51 кириллический символ НЕ помещается', !AccountsRepository::valueFitsColumnType(str_repeat('я', 51), 'varchar(50)'));

// TEXT ограничен байтами, а не символами
vlgCheck('65535 однобайтовых символов помещаются в text',
    AccountsRepository::valueFitsColumnType(str_repeat('a', 65535), 'text'));
vlgCheck('65536 однобайтовых НЕ помещаются в text',
    !AccountsRepository::valueFitsColumnType(str_repeat('a', 65536), 'text'));

// Там, где предела нет, ограничивать нечего
vlgCheck('для int предела нет — любое значение проходит',
    AccountsRepository::valueFitsColumnType('123456789012345', 'int unsigned'));

// Краевые
vlgCheck('пустая строка помещается всегда', AccountsRepository::valueFitsColumnType('', 'varchar(1)'));
vlgCheck('не-строка не проверяется на длину', AccountsRepository::valueFitsColumnType(12345, 'varchar(2)'));

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);

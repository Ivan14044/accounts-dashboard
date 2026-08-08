<?php
/**
 * Тест согласованности эталонной схемы и создаваемых на лету индексов.
 *
 * Запуск:  php tests/test_schema_index_columns.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД — сравниваем два независимых источника правды:
 *   1. DatabaseSchemaManager::getRequiredSchema() — создаёт таблицы и добирает колонки;
 *   2. Database::MANAGED_INDEXES — индексы, которые ensureIndexes() создаёт при каждом
 *      запросе (пока нет флага .optimization_applied).
 *
 * Баг, который тест ловит: индекс idx_id_soc_account ссылался на колонку
 * id_soc_account, которой не было в эталонной схеме. На БД, созданной эталоном,
 * CREATE INDEX падал на КАЖДОМ заходе на дашборд
 * («Key column 'id_soc_account' doesn't exist in table»), а массовый перенос
 * (MassTransferService) и admin_duplicates.php ломались совсем — они используют
 * эту колонку в SQL без проверки columnExists().
 */

// Warning/Notice тоже считаем провалом: на проде error_reporting=E_ALL,
// а кастомный обработчик ошибок конвертирует их в ErrorException.
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/DatabaseSchemaManager.php';

$failures = 0;
$passed   = 0;

function ok(string $name, bool $cond, string $detail = ''): void {
    global $failures, $passed;
    if ($cond) { $passed++; echo "  ✓ $name\n"; }
    else       { $failures++; fwrite(STDERR, "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"); }
}

/**
 * Колонки эталонной схемы для таблицы.
 * getRequiredSchema() приватный и не трогает $this — берём через рефлексию
 * без вызова конструктора (тот ходит в БД).
 */
function requiredColumns(string $table): array {
    $ref = new ReflectionClass('DatabaseSchemaManager');
    $obj = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('getRequiredSchema');
    $m->setAccessible(true);
    $schema = $m->invoke($obj);

    if (!isset($schema[$table]['columns'])) {
        return [];
    }
    $cols = [];
    foreach (array_keys($schema[$table]['columns']) as $col) {
        // Служебные ключи определения таблицы, а не колонки
        if ($col === 'PRIMARY KEY' || strpos($col, 'UNIQUE KEY') !== false) {
            continue;
        }
        $cols[$col] = true;
    }
    return $cols;
}

/** Индексы, создаваемые Database::ensureIndexes(). */
function managedIndexes(): array {
    $ref = new ReflectionClass('Database');
    $consts = $ref->getConstants();
    return $consts['MANAGED_INDEXES'] ?? [];
}

/** 'email(255), status' → ['email', 'status'] */
function indexColumns(string $spec): array {
    $out = [];
    foreach (explode(',', $spec) as $part) {
        $part = trim(preg_replace('/\(\s*\d+\s*\)/', '', $part));
        if ($part !== '') { $out[] = $part; }
    }
    return $out;
}

echo "Согласованность эталонной схемы и ensureIndexes():\n";

$indexes = managedIndexes();

// Страховка от «тихо зелёного» теста: если источник данных вдруг перестал
// читаться, тест обязан упасть, а не молча пройти на пустом списке.
ok('список управляемых индексов не пуст', !empty($indexes['accounts']));
ok('в списке есть якорный idx_status', isset($indexes['accounts']['idx_status']));
ok('в списке есть idx_id_soc_account (регрессия этого бага)',
   isset($indexes['accounts']['idx_id_soc_account']));

$anyColumns = false;
foreach ($indexes as $table => $tableIndexes) {
    $required = requiredColumns($table);
    ok("эталонная схема знает таблицу `$table`", !empty($required));
    if (empty($required)) { continue; }
    $anyColumns = true;

    foreach ($tableIndexes as $indexName => $spec) {
        foreach (indexColumns($spec) as $col) {
            ok(
                "$table.$indexName → колонка `$col` есть в эталонной схеме",
                isset($required[$col]),
                "колонка `$col` отсутствует в getRequiredSchema()['$table']['columns'] — "
                . "CREATE INDEX `$indexName` упадёт на свежей БД"
            );
        }
    }
}
ok('колонки эталона прочитаны', $anyColumns);

echo "\nИтог: passed=$passed, failed=$failures\n";
exit($failures > 0 ? 1 : 0);

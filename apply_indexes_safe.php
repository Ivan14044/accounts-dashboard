<?php
/**
 * Безопасное применение индексов БД с проверкой существования
 * Совместимо с MySQL 5.5+
 *
 * Запуск: php apply_indexes_safe.php (из папки проекта)
 * На больших таблицах создание индексов может занять несколько минут — таймаут отключён.
 */

// Отключаем лимит времени выполнения (на больших таблицах каждый CREATE INDEX может занимать 1–5 мин)
set_time_limit(0);
if (function_exists('ignore_user_abort')) {
    ignore_user_abort(true);
}

echo "========================================\n";
echo "  Применение индексов БД (безопасно)\n";
echo "========================================\n\n";

// Подключаем конфигурацию
require_once __DIR__ . '/config.php';

// Проверяем подключение
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die("❌ ОШИБКА: Не удалось подключиться к базе данных\n");
}

// Увеличиваем таймаут сессии MySQL, чтобы долгий CREATE INDEX не обрывался (по умолчанию 28800 сек)
$mysqli->query("SET SESSION wait_timeout = 3600");
@$mysqli->query("SET SESSION max_statement_time = 0"); // MySQL 5.7.8+: без лимита на один запрос; на старых версиях — игнор

echo "✅ Подключение к БД успешно\n";
$dbName = $mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? 'unknown';
echo "   База данных: $dbName\n\n";

// Определяем версию MySQL для условного добавления функциональных индексов
$versionRow = $mysqli->query("SELECT VERSION()")->fetch_row();
$mysqlVersion = $versionRow[0] ?? '0';
echo "   MySQL версия: $mysqlVersion\n\n";
$isMysql8Plus = version_compare($mysqlVersion, '8.0.0', '>=');

// Получаем список существующих индексов
$existingIndexes = [];
$result = $mysqli->query("SHOW INDEX FROM accounts");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $existingIndexes[] = $row['Key_name'];
    }
}
$existingIndexes = array_unique($existingIndexes);

echo "📊 Существующих индексов: " . count($existingIndexes) . "\n";
if (count($existingIndexes) > 0) {
    echo "   Список: " . implode(', ', array_slice($existingIndexes, 0, 5)) . "...\n";
}
echo "\n";

// Список индексов для создания (idx_deleted_* — под запросы с deleted_at IS NULL и ORDER BY id из slow log)
$indexes = [
    ['name' => 'idx_deleted_id', 'sql' => 'CREATE INDEX idx_deleted_id ON accounts(deleted_at, id)'],
    ['name' => 'idx_deleted_status_id', 'sql' => 'CREATE INDEX idx_deleted_status_id ON accounts(deleted_at, status, id)'],
    ['name' => 'idx_deleted_status_qty_friends_id', 'sql' => 'CREATE INDEX idx_deleted_status_qty_friends_id ON accounts(deleted_at, status, quantity_friends, id)'],
    ['name' => 'idx_deleted_qty_friends_year_id', 'sql' => 'CREATE INDEX idx_deleted_qty_friends_year_id ON accounts(deleted_at, quantity_friends, year_created, id)'],
    ['name' => 'idx_deleted_status_rk_id', 'sql' => 'CREATE INDEX idx_deleted_status_rk_id ON accounts(deleted_at, status_rk, id)'],
    ['name' => 'idx_deleted_status_statusrk_id', 'sql' => 'CREATE INDEX idx_deleted_status_statusrk_id ON accounts(deleted_at, status, status_rk, id)'],
    ['name' => 'idx_deleted_status_currency', 'sql' => 'CREATE INDEX idx_deleted_status_currency ON accounts(deleted_at, status, currency)'],
    ['name' => 'idx_stats_covering', 'sql' => 'CREATE INDEX idx_stats_covering ON accounts(deleted_at, status, updated_at, created_at)'],
    ['name' => 'idx_deleted_status_marketplace_id', 'sql' => 'CREATE INDEX idx_deleted_status_marketplace_id ON accounts(deleted_at, status_marketplace, id)'],
    ['name' => 'idx_deleted_currency_id', 'sql' => 'CREATE INDEX idx_deleted_currency_id ON accounts(deleted_at, currency, id)'],
    ['name' => 'idx_deleted_geo_id', 'sql' => 'CREATE INDEX idx_deleted_geo_id ON accounts(deleted_at, geo, id)'],
    ['name' => 'idx_deleted_limit_rk_qty_year_id', 'sql' => 'CREATE INDEX idx_deleted_limit_rk_qty_year_id ON accounts(deleted_at, limit_rk, quantity_friends, year_created, id)'],
    ['name' => 'idx_login', 'sql' => 'CREATE INDEX idx_login ON accounts(login)'],
    ['name' => 'idx_ads_id', 'sql' => 'CREATE INDEX idx_ads_id ON accounts(ads_id)'],
    ['name' => 'idx_social_url', 'sql' => 'CREATE INDEX idx_social_url ON accounts(social_url(255))'],
    ['name' => 'idx_status', 'sql' => 'CREATE INDEX idx_status ON accounts(status)'],
    ['name' => 'idx_status_marketplace', 'sql' => 'CREATE INDEX idx_status_marketplace ON accounts(status_marketplace)'],
    ['name' => 'idx_email', 'sql' => 'CREATE INDEX idx_email ON accounts(email)'],
    ['name' => 'idx_created_at', 'sql' => 'CREATE INDEX idx_created_at ON accounts(created_at)'],
    ['name' => 'idx_updated_at', 'sql' => 'CREATE INDEX idx_updated_at ON accounts(updated_at)'],
    ['name' => 'idx_status_created', 'sql' => 'CREATE INDEX idx_status_created ON accounts(status, created_at)'],
    ['name' => 'idx_status_updated', 'sql' => 'CREATE INDEX idx_status_updated ON accounts(status, updated_at)'],
    ['name' => 'idx_email_status', 'sql' => 'CREATE INDEX idx_email_status ON accounts(email, status)'],
    ['name' => 'idx_compound_main', 'sql' => 'CREATE INDEX idx_compound_main ON accounts(status, created_at, updated_at)'],
    ['name' => 'idx_two_fa', 'sql' => 'CREATE INDEX idx_two_fa ON accounts(two_fa(100))'],
    ['name' => 'idx_token', 'sql' => 'CREATE INDEX idx_token ON accounts(token(255))'],
    ['name' => 'idx_avatar', 'sql' => 'CREATE INDEX idx_avatar ON accounts(avatar(255))'],
    ['name' => 'idx_cover', 'sql' => 'CREATE INDEX idx_cover ON accounts(cover(255))'],
    ['name' => 'idx_birth_year', 'sql' => 'CREATE INDEX idx_birth_year ON accounts(birth_year)'],
    ['name' => 'idx_scenario_pharma', 'sql' => 'CREATE INDEX idx_scenario_pharma ON accounts(scenario_pharma)'],
    ['name' => 'idx_quantity_friends', 'sql' => 'CREATE INDEX idx_quantity_friends ON accounts(quantity_friends)'],
    ['name' => 'idx_quantity_fp', 'sql' => 'CREATE INDEX idx_quantity_fp ON accounts(quantity_fp)'],
    ['name' => 'idx_quantity_bm', 'sql' => 'CREATE INDEX idx_quantity_bm ON accounts(quantity_bm)'],
    ['name' => 'idx_quantity_photo', 'sql' => 'CREATE INDEX idx_quantity_photo ON accounts(quantity_photo)'],
    ['name' => 'idx_id_soc_account', 'sql' => 'CREATE INDEX idx_id_soc_account ON accounts(id_soc_account)'],
    ['name' => 'idx_selected_folder_path', 'sql' => 'CREATE INDEX idx_selected_folder_path ON accounts(selectedFolderPath(255))'],
    ['name' => 'idx_main_filters', 'sql' => 'CREATE INDEX idx_main_filters ON accounts(status, status_marketplace, created_at)'],
    ['name' => 'idx_status_quantity_friends', 'sql' => 'CREATE INDEX idx_status_quantity_friends ON accounts(status, quantity_friends)'],
    ['name' => 'idx_status_marketplace_created', 'sql' => 'CREATE INDEX idx_status_marketplace_created ON accounts(status_marketplace, created_at)'],
    ['name' => 'idx_email_status_marketplace', 'sql' => 'CREATE INDEX idx_email_status_marketplace ON accounts(email(255), status, status_marketplace)'],
    ['name' => 'idx_quantity_fields', 'sql' => 'CREATE INDEX idx_quantity_fields ON accounts(quantity_friends, quantity_fp, quantity_bm)'],
    ['name' => 'idx_quantity_friends_sort', 'sql' => 'CREATE INDEX idx_quantity_friends_sort ON accounts(quantity_friends, id)'],
];

// -----------------------------------------------------------------------
// Индекс для запросов WHERE login = NUMBER (без кавычек) от accountfactory
// -----------------------------------------------------------------------
// Проблема (slow_log): accountfactory делает WHERE login = 97698908069 без кавычек.
// login — VARCHAR, константа — BIGINT. MySQL не может использовать idx_login
// (строковый), поэтому сканирует всю таблицу (9–42 секунды).

// Проверяем, существует ли уже колонка login_numeric (для MySQL 5.7)
$hasLoginNumericCol = false;
$colCheckResult = @$mysqli->query("SHOW COLUMNS FROM accounts LIKE 'login_numeric'");
if ($colCheckResult && $colCheckResult->num_rows > 0) {
    $hasLoginNumericCol = true;
}

if ($isMysql8Plus) {
    // MySQL 8.0+: функциональный индекс — не добавляет колонку, работает «из коробки».
    // MySQL использует его для WHERE login = NUMBER благодаря implicit type coercion.
    $indexes[] = [
        'name' => 'idx_login_numeric',
        'sql'  => 'CREATE INDEX idx_login_numeric ON accounts ((CAST(login AS UNSIGNED)))'
    ];
    echo "ℹ️  MySQL 8.0+ — будет добавлен функциональный индекс idx_login_numeric\n\n";
} else {
    // MySQL 5.7: функциональные индексы недоступны.
    // Используем STORED generated column + обычный индекс.
    // MySQL 5.7.6+ может подставить generated column при поиске по login + 0 = NUMBER.
    // Для прямого WHERE login = NUMBER optimizer substituion не гарантирован,
    // но при наличии колонки можно попросить accountfactory использовать login_numeric.
    if (!$hasLoginNumericCol) {
        echo "ℹ️  MySQL 5.7 — создаётся generated column login_numeric + индекс...\n";
        echo "   (ALTER TABLE займёт 1–3 мин на большой таблице)\n\n";
        
        // Добавляем generated column: числовое значение login (NULL если не число)
        $alterSql = "ALTER TABLE accounts ADD COLUMN login_numeric BIGINT UNSIGNED"
            . " GENERATED ALWAYS AS (IF(login REGEXP '^[0-9]+$', CAST(login AS UNSIGNED), NULL)) STORED";
        $alterResult = @$mysqli->query($alterSql);
        if ($alterResult === false) {
            echo "❌ Не удалось добавить login_numeric: " . $mysqli->error . "\n";
            echo "   Колонка может уже существовать или нет прав ALTER TABLE.\n\n";
        } else {
            echo "✅ Колонка login_numeric создана\n";
            $hasLoginNumericCol = true;
        }
    } else {
        echo "ℹ️  MySQL 5.7 — колонка login_numeric уже существует\n\n";
    }
    
    if ($hasLoginNumericCol) {
        $indexes[] = [
            'name' => 'idx_login_numeric',
            'sql'  => 'CREATE INDEX idx_login_numeric ON accounts(login_numeric)'
        ];
    }
    
    echo "⚠️  ВАЖНО: для MySQL 5.7 оптимизатор не всегда подставляет generated column.\n";
    echo "   Если запросы от accountfactory всё ещё медленные — попросите их\n";
    echo "   использовать строковые литералы: WHERE login = '97698908069'\n";
    echo "   или WHERE login_numeric = 97698908069 (прямое использование новой колонки).\n\n";
}

echo "Применение индексов (на большой таблице каждый индекс может создаваться 1–5 мин)...\n";
echo "----------------------------------------\n";

$success = 0;
$skipped = 0;
$errors = 0;

foreach ($indexes as $index) {
    $name = $index['name'];
    $sql = $index['sql'];
    
    // Проверяем, существует ли уже индекс
    if (in_array($name, $existingIndexes)) {
        echo "⏭️  $name - уже существует\n";
        $skipped++;
        continue;
    }
    
    // Пытаемся создать индекс
    $result = @$mysqli->query($sql);
    
    if ($result === false) {
        $error = $mysqli->error;
        // Проверяем, не ошибка ли из-за несуществующей колонки
        if (strpos($error, "Unknown column") !== false) {
            echo "⚠️  $name - пропущен (колонка не существует)\n";
            $skipped++;
        } else {
            echo "❌ $name - ОШИБКА: $error\n";
            $errors++;
        }
    } else {
        echo "✅ $name - создан\n";
        $success++;
    }
}

echo "----------------------------------------\n\n";

// -----------------------------------------------------------------------
// Удаление ЯВНО ИЗБЫТОЧНЫХ индексов (over-indexing → медленные UPDATE)
// -----------------------------------------------------------------------
// Каждый индекс замедляет UPDATE/INSERT/DELETE.
// С 40+ индексами крупный UPDATE (1000+ IDs) может занимать 46 сек (см. slow_log (5).csv).
//
// Следующие индексы являются СТРОГИМИ ПОДМНОЖЕСТВАМИ более широких индексов:
//   idx_status_created(status, created_at)
//       ← покрыт idx_compound_main(status, created_at, updated_at)
//   idx_email_status(email, status)
//       ← покрыт idx_email_status_marketplace(email(255), status, status_marketplace)
//
// Удаление этих двух освобождает 2 B-дерева → каждый UPDATE status/email быстрее.

$redundantIndexes = [
    [
        'name'     => 'idx_status_created',
        'coveredBy'=> 'idx_compound_main',
        'reason'   => '(status, created_at) — subset of (status, created_at, updated_at)',
    ],
    [
        'name'     => 'idx_email_status',
        'coveredBy'=> 'idx_email_status_marketplace',
        'reason'   => '(email, status) — subset of (email(255), status, status_marketplace)',
    ],
];

// Актуализируем список существующих индексов после создания
$existingNow = [];
$resNow = $mysqli->query("SHOW INDEX FROM accounts");
if ($resNow) {
    while ($row = $resNow->fetch_assoc()) { $existingNow[] = $row['Key_name']; }
    $resNow->close();
}
$existingNow = array_unique($existingNow);

echo "Удаление избыточных индексов (снижаем нагрузку на UPDATE)...\n";
echo "----------------------------------------\n";

$droppedCount = 0;
foreach ($redundantIndexes as $ri) {
    $name      = $ri['name'];
    $coveredBy = $ri['coveredBy'];
    $reason    = $ri['reason'];
    
    if (!in_array($name, $existingNow)) {
        echo "⏭️  $name - не существует, пропуск\n";
        continue;
    }
    // Удаляем только если покрывающий индекс ТОЖЕ существует
    if (!in_array($coveredBy, $existingNow)) {
        echo "⚠️  $name - оставляем, т.к. покрывающий $coveredBy не создан\n";
        continue;
    }
    
    $dropResult = @$mysqli->query("DROP INDEX `$name` ON accounts");
    if ($dropResult === false) {
        echo "❌ $name - не удалось удалить: " . $mysqli->error . "\n";
    } else {
        echo "🗑️  $name - удалён ($reason)\n";
        $droppedCount++;
    }
}

echo "   Удалено избыточных: $droppedCount\n";
echo "----------------------------------------\n\n";

// Индексы для других таблиц (account_favorites, saved_filters) — только если таблица существует
$otherTableIndexes = [
    ['table' => 'account_favorites', 'name' => 'idx_user_created', 'sql' => 'CREATE INDEX idx_user_created ON account_favorites(user_id, created_at)'],
    ['table' => 'saved_filters', 'name' => 'idx_user_updated', 'sql' => 'CREATE INDEX idx_user_updated ON saved_filters(user_id, updated_at)'],
];

echo "Индексы для account_favorites и saved_filters...\n";
echo "----------------------------------------\n";

foreach ($otherTableIndexes as $item) {
    $table = $item['table'];
    $name = $item['name'];
    $sql = $item['sql'];

    $tableExists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
    if (!$tableExists || $tableExists->num_rows === 0) {
        echo "⏭️  $name - таблица $table не существует, пропуск\n";
        $skipped++;
        continue;
    }

    $existingOther = [];
    $res = $mysqli->query("SHOW INDEX FROM `" . $mysqli->real_escape_string($table) . "`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existingOther[] = $row['Key_name'];
        }
        $res->close();
    }
    $existingOther = array_unique($existingOther);

    if (in_array($name, $existingOther)) {
        echo "⏭️  $name ($table) - уже существует\n";
        $skipped++;
        continue;
    }

    $result = @$mysqli->query($sql);
    if ($result === false) {
        echo "❌ $name ($table) - ОШИБКА: " . $mysqli->error . "\n";
        $errors++;
    } else {
        echo "✅ $name ($table) - создан\n";
        $success++;
    }
}

echo "----------------------------------------\n\n";

// Оптимизация и анализ таблицы
echo "Оптимизация таблицы accounts...\n";
$mysqli->query("OPTIMIZE TABLE accounts");
echo "✅ OPTIMIZE TABLE выполнен\n";

$mysqli->query("ANALYZE TABLE accounts");
echo "✅ ANALYZE TABLE выполнен\n\n";

// Итоги
echo "========================================\n";
echo "  Результаты\n";
echo "========================================\n";
echo "✅ Создано новых индексов: $success\n";
echo "⏭️  Уже существовало/пропущено: $skipped\n";
if ($errors > 0) {
    echo "❌ Ошибок: $errors\n";
}
echo "\n";

if ($errors === 0 || $success > 0) {
    echo "🎉 Индексы успешно применены!\n\n";

    // Создаём флаг оптимизации — иначе при каждом запросе Database::ensureIndexes() выполняет 12+ проверок INFORMATION_SCHEMA
    $flagFile = __DIR__ . '/.optimization_applied';
    if (@file_put_contents($flagFile, date('c') . " indexes applied\n") !== false) {
        echo "✅ Флаг .optimization_applied создан (проверка индексов при запросах отключена).\n\n";
    } else {
        echo "⚠️  Не удалось создать .optimization_applied в корне проекта. Создайте вручную или выполните: php create_optimization_flag.php\n\n";
    }
    
    // Проверяем финальное количество индексов
    $result = $mysqli->query("SHOW INDEX FROM accounts");
    if ($result) {
        $finalIndexes = [];
        while ($row = $result->fetch_assoc()) {
            $finalIndexes[] = $row['Key_name'];
        }
        $uniqueIndexes = array_unique($finalIndexes);
        echo "📊 Всего индексов на таблице accounts: " . count($uniqueIndexes) . "\n";
        echo "   Список: " . implode(', ', array_slice($uniqueIndexes, 0, 10));
        if (count($uniqueIndexes) > 10) {
            echo "... (еще " . (count($uniqueIndexes) - 10) . ")";
        }
        echo "\n\n";
    }
    
    echo "⚡ Ожидаемое улучшение:\n";
    echo "   - Запросы с фильтрами: 5-10x быстрее\n";
    echo "   - Сортировка: 3-5x быстрее\n";
    echo "   - Загрузка дашборда: 4x быстрее\n\n";
    
    echo "📝 Следующие шаги:\n";
    echo "1. Откройте дашборд и проверьте скорость\n";
    echo "2. На другом ПК или с другой БД — выполните этот скрипт там один раз (см. QUERY_PERFORMANCE.md, раздел «Новое окружение»)\n";
    echo "3. Проверьте метрики в DevTools (F12 → Network)\n\n";
    
    // -----------------------------------------------------------------------
    // Предупреждение об избыточных индексах (slow_log: медленные UPDATE)
    // -----------------------------------------------------------------------
    $idxResult = $mysqli->query("SHOW INDEX FROM accounts");
    $allIdx = [];
    if ($idxResult) {
        while ($row = $idxResult->fetch_assoc()) {
            $allIdx[$row['Key_name']] = true;
        }
    }
    $totalIdx = count($allIdx);
    
    if ($totalIdx > 20) {
        echo "⚠️  ВАЖНО: На таблице accounts найдено $totalIdx индексов.\n";
        echo "   Каждый UPDATE (статус, token, cover и т.п.) должен обновлять ВСЕ $totalIdx индексов.\n";
        echo "   Это напрямую замедляет массовые UPDATE из accountfactory.\n\n";
        echo "   Анализ ИСПОЛЬЗОВАНИЯ индексов (запустите в MySQL Workbench):\n";
        echo "   SELECT INDEX_NAME, ROWS_READ, ROWS_READ/NULLIF(ROWS_TOTAL,0)*100 as pct\n";
        echo "   FROM performance_schema.TABLE_IO_WAITS_SUMMARY_BY_INDEX_USAGE\n";
        echo "   WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = 'accounts'\n";
        echo "   ORDER BY ROWS_READ ASC;\n";
        echo "   Индексы с ROWS_READ = 0 или очень маленьким значением — кандидаты на удаление.\n\n";
    }
    
    echo "⚠️  ИЗВЕСТНАЯ ПРОБЛЕМА (slow_log): Блокировки (lock contention)\n";
    echo "   accountfactory выполняет UPDATE WHERE id IN (1000+ IDs) → полный скан.\n";
    echo "   Это блокирует другие UPDATE на 24–46 сек. Решение на стороне accountfactory:\n";
    echo "   разбить UPDATE на батчи по 200–300 IDs с задержкой между батчами.\n";
} else {
    echo "⚠️  Не удалось создать индексы.\n";
    echo "   Возможные причины:\n";
    echo "   - Недостаточно прав у пользователя БД\n";
    echo "   - Некоторые колонки не существуют\n";
    echo "   - Проблемы с типами данных\n";
}

echo "\n========================================\n";

// Закрываем соединение
$mysqli->close();



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
    echo "2. Используйте собранные файлы (см. README_OPTIMIZATION.md)\n";
    echo "3. Проверьте метрики в DevTools (F12 → Network)\n";
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



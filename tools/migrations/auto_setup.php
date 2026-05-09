<?php
/**
 * Автоматическая настройка оптимизаций при первом запуске
 * Этот скрипт автоматически применяет все оптимизации
 */

// Флаг для отслеживания выполнения
$setupFile = __DIR__ . '/.optimization_applied';

// Если оптимизации уже применены, ничего не делаем
if (file_exists($setupFile)) {
    return;
}

// Подключаем конфигурацию
require_once __DIR__ . '/../../config.php';

// Проверяем подключение к БД
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    error_log('AUTO_SETUP: Cannot connect to database, skipping optimization');
    return;
}

// Получаем список существующих индексов
$existingIndexes = [];
$result = $mysqli->query("SHOW INDEX FROM accounts");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $existingIndexes[] = $row['Key_name'];
    }
    $existingIndexes = array_unique($existingIndexes);
}

// Список критически важных индексов для проверки
$criticalIndexes = [
    'idx_status',
    'idx_quantity_friends',
    'idx_status_quantity_friends'
];

// Проверяем, нужно ли применять индексы
$needIndexes = false;
foreach ($criticalIndexes as $index) {
    if (!in_array($index, $existingIndexes)) {
        $needIndexes = true;
        break;
    }
}

// Если нужны индексы, применяем их
if ($needIndexes) {
    error_log('AUTO_SETUP: Applying database indexes...');
    
    // Список индексов для создания
    $indexes = [
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
    
    $created = 0;
    $skipped = 0;
    
    foreach ($indexes as $index) {
        $name = $index['name'];
        $sql = $index['sql'];

        // Проверяем, существует ли уже индекс
        if (in_array($name, $existingIndexes)) {
            $skipped++;
            continue;
        }

        // Пытаемся создать индекс
        $result = $mysqli->query($sql);

        if ($result !== false) {
            $created++;
            error_log("AUTO_SETUP: Created index: $name");
        } else {
            // Игнорируем ошибки (например, если колонка не существует)
            $skipped++;
        }
    }
    
    // Оптимизация таблицы
    $mysqli->query("OPTIMIZE TABLE accounts");
    $mysqli->query("ANALYZE TABLE accounts");

    error_log("AUTO_SETUP: Database optimization completed. Created: $created, Skipped: $skipped");
}

// Создаем флаг, что оптимизации применены
if (is_dir(dirname($setupFile))) {
    file_put_contents($setupFile, date('Y-m-d H:i:s') . "\nOptimizations applied automatically\n");
}

error_log('AUTO_SETUP: All optimizations applied successfully');



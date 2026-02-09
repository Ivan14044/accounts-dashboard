<?php
/**
 * Скрипт для создания всех необходимых таблиц в БД
 * Выполняет SQL скрипты для создания таблиц
 */

// Параметры подключения к БД
$config = [
    'host' => 'if592995.mysql.tools',
    'port' => 3306,
    'user' => 'if592995_accountfactory',
    'password' => 'zhA4*4@u8S',
    'database' => 'if592995_accountfactory',
    'charset' => 'utf8mb4',
    'ssl' => [
        'ca' => null,
        'capath' => null,
        'cipher' => null,
        'key' => null,
        'cert' => null,
    ]
];

echo "=== Создание таблиц для Dashboard ===\n\n";

try {
    // Подключение к БД
    echo "Подключение к БД...\n";
    
    $mysqli = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        $config['port']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception('Ошибка подключения: ' . $mysqli->connect_error);
    }
    
    // Устанавливаем кодировку
    $mysqli->set_charset($config['charset']);
    
    // Включаем SSL если требуется
    if ($config['ssl']) {
        mysqli_ssl_set(
            $mysqli,
            $config['ssl']['key'],
            $config['ssl']['cert'],
            $config['ssl']['ca'],
            $config['ssl']['capath'],
            $config['ssl']['cipher']
        );
    }
    
    echo "✓ Подключение установлено\n\n";
    
    // Читаем SQL скрипт
    $sqlFile = __DIR__ . '/sql/create_all_tables.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL файл не найден: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new Exception("Не удалось прочитать SQL файл");
    }
    
    echo "Чтение SQL скрипта...\n";
    echo "✓ SQL скрипт загружен\n\n";
    
    // Разбиваем скрипт на отдельные запросы
    // Исключаем запросы, которые содержат IF NOT EXISTS в CREATE INDEX (MySQL не поддерживает)
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($query) {
            return !empty($query) 
                && !preg_match('/^\s*--/', $query)  // Игнорируем комментарии
                && !preg_match('/^\s*\/\*/', $query) // Игнорируем блочные комментарии
                && !preg_match('/CREATE INDEX IF NOT EXISTS/i', $query) // Исключаем CREATE INDEX IF NOT EXISTS
                && !preg_match('/CREATE.*INDEX.*IF NOT EXISTS/i', $query); // Исключаем другие варианты
        }
    );
    
    echo "Выполнение SQL запросов...\n\n";
    
    $successCount = 0;
    $errorCount = 0;
    $skippedCount = 0;
    
    // Сначала создаём таблицы напрямую
    echo "Создание таблиц напрямую...\n";
    
    // 1. Создание таблицы account_history
    echo "  Создание таблицы account_history...\n";
    $createHistoryTable = "CREATE TABLE IF NOT EXISTS `account_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_id` INT NOT NULL,
        `field_name` VARCHAR(255) NOT NULL,
        `old_value` TEXT,
        `new_value` TEXT,
        `changed_by` VARCHAR(255) NOT NULL,
        `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `ip_address` VARCHAR(45),
        INDEX `idx_account_id` (`account_id`),
        INDEX `idx_changed_at` (`changed_at`),
        INDEX `idx_changed_by` (`changed_by`),
        INDEX `idx_account_changed` (`account_id`, `changed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($mysqli->query($createHistoryTable)) {
        echo "    ✓ Таблица account_history создана\n";
        $successCount++;
    } else {
        $error = $mysqli->error;
        if (preg_match('/already exists|Duplicate|exists/i', $error)) {
            echo "    ⚠ Таблица уже существует\n";
            $skippedCount++;
        } else {
            echo "    ✗ Ошибка: $error\n";
            $errorCount++;
        }
    }
    
    // 2. Создание таблицы saved_filters
    echo "  Создание таблицы saved_filters...\n";
    $createFiltersTable = "CREATE TABLE IF NOT EXISTS `saved_filters` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` VARCHAR(255) NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `filters` JSON NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($mysqli->query($createFiltersTable)) {
        echo "    ✓ Таблица saved_filters создана\n";
        $successCount++;
    } else {
        $error = $mysqli->error;
        if (preg_match('/already exists|Duplicate|exists/i', $error)) {
            echo "    ⚠ Таблица уже существует\n";
            $skippedCount++;
        } else {
            echo "    ✗ Ошибка: $error\n";
            $errorCount++;
        }
    }
    
    echo "\n";
    
    // Выполняем остальные запросы из SQL файла
    echo "Выполнение остальных SQL запросов...\n";
    
    // Выполняем каждый запрос
    foreach ($queries as $index => $query) {
        // Пропускаем SET, PREPARE, EXECUTE, DEALLOCATE - они выполняются отдельно
        if (preg_match('/^\s*(SET|PREPARE|EXECUTE|DEALLOCATE|SELECT)/i', $query)) {
            continue;
        }
        
        $query = trim($query);
        if (empty($query)) {
            continue;
        }
        
        // Пропускаем CREATE TABLE, т.к. уже выполнили выше
        if (preg_match('/CREATE TABLE/i', $query)) {
            continue;
        }
        
        // Показываем первые 50 символов запроса
        $queryPreview = substr($query, 0, 50) . (strlen($query) > 50 ? '...' : '');
        echo "  [$index] $queryPreview\n";
        
        // Выполняем запрос
        $result = $mysqli->query($query);
        
        if ($result === false) {
            $error = $mysqli->error;
            
            // Если ошибка "duplicate" или "already exists", пропускаем
            if (preg_match('/already exists|Duplicate|exists/i', $error)) {
                echo "    ⚠ Пропущено (уже существует)\n";
                $skippedCount++;
            } else {
                echo "    ✗ Ошибка: $error\n";
                $errorCount++;
            }
        } else {
            echo "    ✓ Успешно\n";
            $successCount++;
        }
    }
    
    echo "\n";
    echo "=== Результат выполнения ===\n";
    echo "Успешно: $successCount\n";
    echo "Пропущено: $skippedCount\n";
    echo "Ошибок: $errorCount\n\n";
    
    // Сначала добавляем поле deleted_at если его нет
    echo "\nПроверка поля deleted_at...\n";
    $checkColumn = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'accounts' 
                    AND COLUMN_NAME = 'deleted_at'";
    $result = $mysqli->query($checkColumn);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "  Добавление поля deleted_at...\n";
            
            // Проверяем, есть ли поле updated_at
            $checkUpdatedAt = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                               WHERE TABLE_SCHEMA = DATABASE() 
                               AND TABLE_NAME = 'accounts' 
                               AND COLUMN_NAME = 'updated_at'";
            $result2 = $mysqli->query($checkUpdatedAt);
            $hasUpdatedAt = false;
            if ($result2) {
                $row2 = $result2->fetch_assoc();
                $hasUpdatedAt = $row2['cnt'] > 0;
            }
            
            if ($hasUpdatedAt) {
                $mysqli->query("ALTER TABLE `accounts` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`");
            } else {
                $mysqli->query("ALTER TABLE `accounts` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL");
            }
            
            echo "    ✓ Поле добавлено\n";
        } else {
            echo "  ✓ Поле deleted_at уже существует\n";
        }
    }
    
    // Теперь создаём индексы для Soft Delete (после создания поля)
    echo "\nСоздание индексов с проверкой...\n";
    
    // Проверяем и создаём индекс idx_deleted_at
    $checkIndex = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'accounts' 
                   AND INDEX_NAME = 'idx_deleted_at'";
    $result = $mysqli->query($checkIndex);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "  Создание индекса idx_deleted_at...\n";
            if ($mysqli->query("CREATE INDEX `idx_deleted_at` ON `accounts`(`deleted_at`)")) {
                echo "    ✓ Индекс создан\n";
            } else {
                echo "    ✗ Ошибка создания индекса: " . $mysqli->error . "\n";
            }
        } else {
            echo "  ✓ Индекс idx_deleted_at уже существует\n";
        }
    }
    
    // Проверяем и создаём индекс idx_status_deleted
    $checkIndex = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'accounts' 
                   AND INDEX_NAME = 'idx_status_deleted'";
    $result = $mysqli->query($checkIndex);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "  Создание индекса idx_status_deleted...\n";
            if ($mysqli->query("CREATE INDEX `idx_status_deleted` ON `accounts`(`status`, `deleted_at`)")) {
                echo "    ✓ Индекс создан\n";
            } else {
                echo "    ✗ Ошибка создания индекса: " . $mysqli->error . "\n";
            }
        } else {
            echo "  ✓ Индекс idx_status_deleted уже существует\n";
        }
    }
    
    // Проверяем созданные таблицы
    echo "\n=== Проверка созданных таблиц ===\n";
    $tables = ['account_history', 'saved_filters'];
    
    foreach ($tables as $table) {
        $check = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES 
                  WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = '$table'";
        $result = $mysqli->query($check);
        if ($result) {
            $row = $result->fetch_assoc();
            if ($row['cnt'] > 0) {
                // Получаем количество строк
                $countResult = $mysqli->query("SELECT COUNT(*) as cnt FROM `$table`");
                $countRow = $countResult ? $countResult->fetch_assoc() : ['cnt' => 0];
                echo "✓ Таблица `$table`: создана ({$countRow['cnt']} строк)\n";
            } else {
                echo "✗ Таблица `$table`: не создана\n";
            }
        }
    }
    
    // Проверяем поле deleted_at
    echo "\n=== Проверка поля deleted_at ===\n";
    $check = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
              WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'accounts' 
              AND COLUMN_NAME = 'deleted_at'";
    $result = $mysqli->query($check);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] > 0) {
            echo "✓ Поле `deleted_at` в таблице `accounts`: создано\n";
        } else {
            echo "✗ Поле `deleted_at` в таблице `accounts`: не создано\n";
        }
    }
    
    // Закрываем подключение
    $mysqli->close();
    
    echo "\n=== Завершено успешно! ===\n";
    
} catch (Exception $e) {
    echo "\n✗ ОШИБКА: " . $e->getMessage() . "\n";
    exit(1);
}


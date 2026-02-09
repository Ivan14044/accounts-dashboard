<?php
/**
 * Скрипт подготовки базы данных для корзины (Trash)
 * Создаёт все необходимые таблицы, поля и индексы
 */

// Загружаем конфигурацию
require_once __DIR__ . '/config.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Настройка корзины</title>";
echo "<style>body{font-family:Arial;max-width:800px;margin:50px auto;padding:20px;}";
echo ".success{color:green;}.error{color:red;}.warning{color:orange;}";
echo "pre{background:#f5f5f5;padding:10px;border-radius:5px;}</style></head><body>";
echo "<h1>🔧 Настройка функционала корзины</h1>";

try {
    global $mysqli;
    
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('Подключение к БД не установлено');
    }
    
    echo "<p>✓ Подключение к БД установлено</p>";
    
    // ============================================
    // 1. Проверка и создание поля deleted_at
    // ============================================
    echo "<h2>1. Проверка поля deleted_at</h2>";
    
    $checkColumn = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'accounts' 
                    AND COLUMN_NAME = 'deleted_at'";
    $result = $mysqli->query($checkColumn);
    
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "<p>⚠ Поле deleted_at отсутствует. Создаём...</p>";
            
            // Проверяем, есть ли поле updated_at для правильного размещения
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
                $sql = "ALTER TABLE `accounts` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`";
            } else {
                $sql = "ALTER TABLE `accounts` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL";
            }
            
            if ($mysqli->query($sql)) {
                echo "<p class='success'>✓ Поле deleted_at успешно создано</p>";
            } else {
                throw new Exception('Ошибка создания поля deleted_at: ' . $mysqli->error);
            }
        } else {
            echo "<p class='success'>✓ Поле deleted_at уже существует</p>";
        }
    } else {
        throw new Exception('Ошибка проверки поля deleted_at: ' . $mysqli->error);
    }
    
    // ============================================
    // 2. Создание индексов для Soft Delete
    // ============================================
    echo "<h2>2. Создание индексов</h2>";
    
    // Индекс idx_deleted_at
    $checkIndex = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'accounts' 
                   AND INDEX_NAME = 'idx_deleted_at'";
    $result = $mysqli->query($checkIndex);
    
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "<p>Создание индекса idx_deleted_at...</p>";
            if ($mysqli->query("CREATE INDEX `idx_deleted_at` ON `accounts`(`deleted_at`)")) {
                echo "<p class='success'>✓ Индекс idx_deleted_at создан</p>";
            } else {
                echo "<p class='warning'>⚠ Ошибка создания индекса idx_deleted_at: " . $mysqli->error . "</p>";
            }
        } else {
            echo "<p class='success'>✓ Индекс idx_deleted_at уже существует</p>";
        }
    }
    
    // Индекс idx_status_deleted
    $checkIndex = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'accounts' 
                   AND INDEX_NAME = 'idx_status_deleted'";
    $result = $mysqli->query($checkIndex);
    
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "<p>Создание индекса idx_status_deleted...</p>";
            if ($mysqli->query("CREATE INDEX `idx_status_deleted` ON `accounts`(`status`, `deleted_at`)")) {
                echo "<p class='success'>✓ Индекс idx_status_deleted создан</p>";
            } else {
                echo "<p class='warning'>⚠ Ошибка создания индекса idx_status_deleted: " . $mysqli->error . "</p>";
            }
        } else {
            echo "<p class='success'>✓ Индекс idx_status_deleted уже существует</p>";
        }
    }
    
    // ============================================
    // 3. Создание таблицы account_history (Audit Log)
    // ============================================
    echo "<h2>3. Проверка таблицы account_history</h2>";
    
    $checkTable = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'account_history'";
    $result = $mysqli->query($checkTable);
    
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "<p>Создание таблицы account_history...</p>";
            
            $createTable = "CREATE TABLE IF NOT EXISTS `account_history` (
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
            
            if ($mysqli->query($createTable)) {
                echo "<p class='success'>✓ Таблица account_history создана</p>";
            } else {
                echo "<p class='warning'>⚠ Ошибка создания таблицы account_history: " . $mysqli->error . "</p>";
            }
        } else {
            echo "<p class='success'>✓ Таблица account_history уже существует</p>";
        }
    }
    
    // ============================================
    // 4. Создание таблицы saved_filters
    // ============================================
    echo "<h2>4. Проверка таблицы saved_filters</h2>";
    
    $checkTable = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'saved_filters'";
    $result = $mysqli->query($checkTable);
    
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            echo "<p>Создание таблицы saved_filters...</p>";
            
            $createTable = "CREATE TABLE IF NOT EXISTS `saved_filters` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` VARCHAR(255) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `filters` JSON NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_user_id` (`user_id`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if ($mysqli->query($createTable)) {
                echo "<p class='success'>✓ Таблица saved_filters создана</p>";
            } else {
                echo "<p class='warning'>⚠ Ошибка создания таблицы saved_filters: " . $mysqli->error . "</p>";
            }
        } else {
            echo "<p class='success'>✓ Таблица saved_filters уже существует</p>";
        }
    }
    
    // ============================================
    // 5. Проверка результата
    // ============================================
    echo "<h2>5. Финальная проверка</h2>";
    
    // Проверяем поле deleted_at
    $checkColumn = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'accounts' 
                    AND COLUMN_NAME = 'deleted_at'";
    $result = $mysqli->query($checkColumn);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "<p class='success'>✓ Поле deleted_at: " . $row['DATA_TYPE'] . " (NULL: " . $row['IS_NULLABLE'] . ")</p>";
    } else {
        echo "<p class='error'>✗ Поле deleted_at не найдено</p>";
    }
    
    // Проверяем индексы
    $checkIndexes = "SELECT INDEX_NAME 
                     FROM INFORMATION_SCHEMA.STATISTICS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'accounts' 
                     AND INDEX_NAME IN ('idx_deleted_at', 'idx_status_deleted')
                     GROUP BY INDEX_NAME";
    $result = $mysqli->query($checkIndexes);
    
    if ($result) {
        $indexes = [];
        while ($row = $result->fetch_assoc()) {
            $indexes[] = $row['INDEX_NAME'];
        }
        if (in_array('idx_deleted_at', $indexes)) {
            echo "<p class='success'>✓ Индекс idx_deleted_at создан</p>";
        } else {
            echo "<p class='warning'>⚠ Индекс idx_deleted_at не найден</p>";
        }
        if (in_array('idx_status_deleted', $indexes)) {
            echo "<p class='success'>✓ Индекс idx_status_deleted создан</p>";
        } else {
            echo "<p class='warning'>⚠ Индекс idx_status_deleted не найден</p>";
        }
    }
    
    // Проверяем таблицы
    $checkTables = "SELECT TABLE_NAME 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME IN ('account_history', 'saved_filters')";
    $result = $mysqli->query($checkTables);
    
    if ($result) {
        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row['TABLE_NAME'];
        }
        if (in_array('account_history', $tables)) {
            echo "<p class='success'>✓ Таблица account_history создана</p>";
        } else {
            echo "<p class='warning'>⚠ Таблица account_history не найдена</p>";
        }
        if (in_array('saved_filters', $tables)) {
            echo "<p class='success'>✓ Таблица saved_filters создана</p>";
        } else {
            echo "<p class='warning'>⚠ Таблица saved_filters не найдена</p>";
        }
    }
    
    // Подсчитываем удалённые записи
    // Для TIMESTAMP колонки достаточно проверки IS NOT NULL (пустая строка там быть не может)
    $countDeleted = "SELECT COUNT(*) as cnt FROM accounts WHERE deleted_at IS NOT NULL";
    $result = $mysqli->query($countDeleted);
    if ($result) {
        $row = $result->fetch_assoc();
        $deletedCount = (int)$row['cnt'];
        echo "<p class='success'>✓ Удалённых записей в корзине: " . $deletedCount . "</p>";
    }
    
    echo "<hr>";
    echo "<h2>✅ Готово!</h2>";
    echo "<p>Все необходимые элементы для работы корзины созданы.</p>";
    echo "<p><a href='trash.php'>Перейти к странице корзины</a> | <a href='index.php'>Вернуться к дашборду</a></p>";
    
} catch (Exception $e) {
    echo "<p class='error'>✗ ОШИБКА: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
?>



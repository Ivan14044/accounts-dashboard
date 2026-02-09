-- Скрипт создания всех необходимых таблиц для Dashboard
-- База данных: if592995_accountfactory

-- 1. Таблица для истории изменений (Audit Log)
CREATE TABLE IF NOT EXISTS `account_history` (
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
    INDEX `idx_account_changed` (`account_id`, `changed_at`),
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Таблица для сохранённых фильтров (Presets)
CREATE TABLE IF NOT EXISTS `saved_filters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `filters` JSON NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Добавление поля deleted_at для Soft Delete (Корзина)
-- Проверяем, существует ли поле, перед добавлением
SET @dbname = DATABASE();
SET @tablename = 'accounts';
SET @columnname = 'deleted_at';
SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (TABLE_SCHEMA = @dbname)
            AND (TABLE_NAME = @tablename)
            AND (COLUMN_NAME = @columnname)
    ) > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. Создание индексов для Soft Delete
CREATE INDEX IF NOT EXISTS `idx_deleted_at` ON `accounts`(`deleted_at`);
CREATE INDEX IF NOT EXISTS `idx_status_deleted` ON `accounts`(`status`, `deleted_at`);

-- Проверка создания таблиц
SELECT 
    TABLE_NAME as 'Таблица',
    TABLE_ROWS as 'Строк',
    CREATE_TIME as 'Создана'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME IN ('account_history', 'saved_filters')
ORDER BY TABLE_NAME;

-- Проверка поля deleted_at в таблице accounts
SELECT 
    COLUMN_NAME as 'Колонка',
    DATA_TYPE as 'Тип',
    IS_NULLABLE as 'NULL',
    COLUMN_DEFAULT as 'По умолчанию'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'accounts'
    AND COLUMN_NAME = 'deleted_at';



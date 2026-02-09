-- Создание таблицы для истории изменений (Audit Log)
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



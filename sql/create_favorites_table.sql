-- Создание таблицы для избранных аккаунтов
CREATE TABLE IF NOT EXISTS `account_favorites` (
    `user_id` VARCHAR(255) NOT NULL,
    `account_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `account_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_account_id` (`account_id`),
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



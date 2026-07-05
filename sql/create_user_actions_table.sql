-- Журнал действий пользователей (для функции «отменить последнее действие»).
-- Таблица и колонки создаются автоматически в AuditLogger::ensureTableExists();
-- этот файл — справочная схема для ручного применения.

CREATE TABLE IF NOT EXISTS `user_actions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) NOT NULL,
    -- update_status | update_field | bulk_update_field | delete | mass_transfer | undo
    `action_type` VARCHAR(32) NOT NULL,
    `table_name` VARCHAR(64) NOT NULL,
    `description` VARCHAR(500) NOT NULL DEFAULT '',
    `affected_count` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Когда действие было отменено (NULL = ещё отменяемо)
    `undone_at` TIMESTAMP NULL DEFAULT NULL,
    -- Ссылка на действие-откат (тип undo)
    `undo_action_id` INT NULL DEFAULT NULL,
    INDEX `idx_user_created` (`username`, `id`),
    INDEX `idx_user_undone` (`username`, `undone_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Привязка строк истории к действию (undo идёт по action_id).
-- Старые строки истории остаются с NULL — они не отменяемы.
ALTER TABLE `account_history`
    ADD COLUMN `action_id` INT NULL DEFAULT NULL AFTER `ip_address`,
    ADD COLUMN `table_name` VARCHAR(64) NULL DEFAULT NULL AFTER `action_id`,
    ADD INDEX `idx_action_id` (`action_id`);

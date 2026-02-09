-- Добавление поддержки Soft Delete (Корзина)
-- Добавляем поле deleted_at для мягкого удаления
ALTER TABLE `accounts` 
ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;

-- Создаём индекс для быстрого поиска неудалённых записей
CREATE INDEX IF NOT EXISTS `idx_deleted_at` ON `accounts`(`deleted_at`);

-- Создаём индекс для комбинации статуса и удаления (часто используется вместе)
CREATE INDEX IF NOT EXISTS `idx_status_deleted` ON `accounts`(`status`, `deleted_at`);



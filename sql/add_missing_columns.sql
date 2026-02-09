-- Добавление недостающих колонок в таблицу accounts
-- Выполните эти запросы в вашей базе данных

-- 1. Добавление колонки geo (географическая информация, максимум 20 символов)
ALTER TABLE `accounts` 
ADD COLUMN IF NOT EXISTS `geo` VARCHAR(20) NULL 
COMMENT 'Географическая информация (страна, регион и т.д., макс. 20 символов)' 
AFTER `currency`;

-- 2. Добавление колонки activity_status (статус активности, максимум 1 символ/цифра)
ALTER TABLE `accounts` 
ADD COLUMN IF NOT EXISTS `activity_status` VARCHAR(1) NULL 
COMMENT 'Статус активности аккаунта (1 символ/цифра)' 
AFTER `geo`;

-- 3. Индексы для новых колонок (опционально, для ускорения фильтрации)
CREATE INDEX IF NOT EXISTS `idx_geo` ON `accounts`(`geo`);
CREATE INDEX IF NOT EXISTS `idx_activity_status` ON `accounts`(`activity_status`);

-- Примечание: 
-- Если ваша версия MySQL не поддерживает "IF NOT EXISTS" в ALTER TABLE,
-- используйте версию без проверки (см. ниже):

/*
-- Версия без IF NOT EXISTS (для старых версий MySQL):

-- 1. Добавление колонки geo (максимум 20 символов)
ALTER TABLE `accounts` 
ADD COLUMN `geo` VARCHAR(20) NULL 
COMMENT 'Географическая информация (страна, регион и т.д., макс. 20 символов)' 
AFTER `currency`;

-- 2. Добавление колонки activity_status (максимум 1 символ/цифра)
ALTER TABLE `accounts` 
ADD COLUMN `activity_status` VARCHAR(1) NULL 
COMMENT 'Статус активности аккаунта (1 символ/цифра)' 
AFTER `geo`;

-- 3. Индексы
CREATE INDEX `idx_geo` ON `accounts`(`geo`);
CREATE INDEX `idx_activity_status` ON `accounts`(`activity_status`);
*/


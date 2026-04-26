-- Опциональная миграция: приведение колонок таблицы accounts к числовым типам
-- Цель: ColumnMetadata определит их как numericCols, тогда buildOrderBy и FilterBuilder
-- будут использовать простую сортировку и прямое сравнение (индекс используется).
-- Выполнять вручную после проверки текущих типов (SHOW COLUMNS FROM accounts)
-- и совместимости данных. Перед применением сделайте бэкап БД.
--
-- См. docs/QUERY_INDEX_ANALYSIS.md, раздел 5.2 (тяжёлые выражения).

-- Проверка текущего типа: SHOW COLUMNS FROM accounts LIKE 'quantity_friends';

-- quantity_friends — диапазон и сортировка
-- ALTER TABLE accounts MODIFY COLUMN quantity_friends INT UNSIGNED NULL DEFAULT NULL;

-- year_created — диапазон и сортировка
-- ALTER TABLE accounts MODIFY COLUMN year_created SMALLINT UNSIGNED NULL DEFAULT NULL;

-- limit_rk — диапазон
-- ALTER TABLE accounts MODIFY COLUMN limit_rk INT UNSIGNED NULL DEFAULT NULL;

-- scenario_pharma — диапазон
-- ALTER TABLE accounts MODIFY COLUMN scenario_pharma INT UNSIGNED NULL DEFAULT NULL;

-- quantity_fp, quantity_bm, quantity_photo — фильтры и сортировка
-- ALTER TABLE accounts MODIFY COLUMN quantity_fp INT UNSIGNED NULL DEFAULT NULL;
-- ALTER TABLE accounts MODIFY COLUMN quantity_bm INT UNSIGNED NULL DEFAULT NULL;
-- ALTER TABLE accounts MODIFY COLUMN quantity_photo INT UNSIGNED NULL DEFAULT NULL;

-- birth_day, birth_month, birth_year — сортировка
-- ALTER TABLE accounts MODIFY COLUMN birth_day TINYINT UNSIGNED NULL DEFAULT NULL;
-- ALTER TABLE accounts MODIFY COLUMN birth_month TINYINT UNSIGNED NULL DEFAULT NULL;
-- ALTER TABLE accounts MODIFY COLUMN birth_year SMALLINT UNSIGNED NULL DEFAULT NULL;

-- После миграции: сбросить кэш метаданных (удалить файл кэша ColumnMetadata
-- в sys_get_temp_dir() с именем accounts_metadata_cache_*.json) или подождать 1 час.

-- КРИТИЧЕСКИЕ ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ МЕДЛЕННЫХ ЗАПРОСОВ
-- Выполните эти команды в вашей базе данных для решения проблемы с производительностью

-- ===== ИНДЕКСЫ ДЛЯ ЗАПРОСОВ С deleted_at И СОРТИРОВКОЙ ПО id (slow log 30+ сек) =====
-- Запросы: WHERE deleted_at IS NULL [AND status IN (...)] ORDER BY id LIMIT 50 OFFSET N

-- 0a. Составной индекс: фильтр deleted_at + сортировка по id (без фильтра по status)
CREATE INDEX IF NOT EXISTS idx_deleted_id ON accounts(deleted_at, id);

-- 0b. Составной индекс: deleted_at + status + id (для фильтра по статусам и сортировки по id)
CREATE INDEX IF NOT EXISTS idx_deleted_status_id ON accounts(deleted_at, status, id);

-- 0c. Составной индекс для фильтра по status + диапазон quantity_friends + ORDER BY id (slow log ~30 сек, 90k rows)
CREATE INDEX IF NOT EXISTS idx_deleted_status_qty_friends_id ON accounts(deleted_at, status, quantity_friends, id);

-- 0d. Без status: deleted_at + quantity_friends + year_created + id (slow_log 15: token/avatar + friends/year_created, 90k rows)
CREATE INDEX IF NOT EXISTS idx_deleted_qty_friends_year_id ON accounts(deleted_at, quantity_friends, year_created, id);

-- 0e. Фильтры по статусу RK, marketplace, валюте, geo + ORDER BY id (см. docs/QUERY_INDEX_ANALYSIS.md)
CREATE INDEX IF NOT EXISTS idx_deleted_status_rk_id ON accounts(deleted_at, status_rk, id);
CREATE INDEX IF NOT EXISTS idx_deleted_status_marketplace_id ON accounts(deleted_at, status_marketplace, id);
CREATE INDEX IF NOT EXISTS idx_deleted_currency_id ON accounts(deleted_at, currency, id);
CREATE INDEX IF NOT EXISTS idx_deleted_geo_id ON accounts(deleted_at, geo, id);

-- 0f. Без status: limit_rk + quantity_friends + year_created (slow log 19–20: ~30 сек, 90k rows)
CREATE INDEX IF NOT EXISTS idx_deleted_limit_rk_qty_year_id ON accounts(deleted_at, limit_rk, quantity_friends, year_created, id);

-- ===== ОСНОВНЫЕ ИНДЕКСЫ ДЛЯ МЕДЛЕННЫХ ЗАПРОСОВ =====

-- 1. Индекс для поля login (используется в WHERE IN запросах)
CREATE INDEX IF NOT EXISTS idx_login ON accounts(login);

-- 2. Индекс для поля ads_id (используется в WHERE IN запросах)  
CREATE INDEX IF NOT EXISTS idx_ads_id ON accounts(ads_id);

-- 3. Индекс для поля social_url (используется в LIKE запросах)
CREATE INDEX IF NOT EXISTS idx_social_url ON accounts(social_url(255));

-- 4. Индекс для поля status (основной фильтр)
CREATE INDEX IF NOT EXISTS idx_status ON accounts(status);

-- 5. Индекс для поля status_marketplace
CREATE INDEX IF NOT EXISTS idx_status_marketplace ON accounts(status_marketplace);

-- 6. Индекс для поля email (для фильтрации)
CREATE INDEX IF NOT EXISTS idx_email ON accounts(email);

-- 7. Индексы для временных полей
CREATE INDEX IF NOT EXISTS idx_created_at ON accounts(created_at);
CREATE INDEX IF NOT EXISTS idx_updated_at ON accounts(updated_at);

-- ===== СОСТАВНЫЕ ИНДЕКСЫ ДЛЯ СЛОЖНЫХ ЗАПРОСОВ =====

-- 8. Составной индекс для основных фильтров
CREATE INDEX IF NOT EXISTS idx_status_created ON accounts(status, created_at);

-- 9. Составной индекс для сортировки по статусу и дате
CREATE INDEX IF NOT EXISTS idx_status_updated ON accounts(status, updated_at);

-- 10. Индекс для поиска по email и статусу
CREATE INDEX IF NOT EXISTS idx_email_status ON accounts(email, status);

-- 11. Составной индекс для комплексных запросов
CREATE INDEX IF NOT EXISTS idx_compound_main ON accounts(status, created_at, updated_at);

-- ===== ИНДЕКСЫ ДЛЯ ДОПОЛНИТЕЛЬНЫХ ПОЛЕЙ =====

-- 12. Индекс для two_fa (частичный индекс для TEXT полей)
CREATE INDEX IF NOT EXISTS idx_two_fa ON accounts(two_fa(100));

-- 13. Индекс для token (частичный индекс для TEXT полей)
CREATE INDEX IF NOT EXISTS idx_token ON accounts(token(100));

-- 14. Индекс для avatar (частичный индекс для TEXT полей)
CREATE INDEX IF NOT EXISTS idx_avatar ON accounts(avatar(255));

-- 15. Индекс для cover (частичный индекс для TEXT полей)
CREATE INDEX IF NOT EXISTS idx_cover ON accounts(cover(255));

-- ===== ИНДЕКСЫ ДЛЯ ЧИСЛОВЫХ ПОЛЕЙ =====

-- 16. Индекс для birth_year (если используется)
CREATE INDEX IF NOT EXISTS idx_birth_year ON accounts(birth_year);

-- 17. Индекс для scenario_pharma (часто используется в фильтрах)
CREATE INDEX IF NOT EXISTS idx_scenario_pharma ON accounts(scenario_pharma);

-- 18. Индекс для quantity_friends
CREATE INDEX IF NOT EXISTS idx_quantity_friends ON accounts(quantity_friends);

-- 19. Индекс для quantity_fp
CREATE INDEX IF NOT EXISTS idx_quantity_fp ON accounts(quantity_fp);

-- 20. Индекс для quantity_bm
CREATE INDEX IF NOT EXISTS idx_quantity_bm ON accounts(quantity_bm);

-- 21. Индекс для quantity_photo
CREATE INDEX IF NOT EXISTS idx_quantity_photo ON accounts(quantity_photo);

-- ===== FULLTEXT ИНДЕКСЫ ДЛЯ БЫСТРОГО ПОИСКА =====

-- 22. FULLTEXT индекс для основных полей поиска (MySQL 5.7+)
-- Раскомментируйте если ваша версия MySQL поддерживает FULLTEXT на InnoDB
-- ALTER TABLE accounts ADD FULLTEXT ft_search_main (login, email, first_name, last_name);

-- 23. FULLTEXT индекс для расширенных полей поиска
-- ALTER TABLE accounts ADD FULLTEXT ft_search_extended (social_url, ads_id, user_agent, extra_info_1, extra_info_2);

-- ===== СПЕЦИАЛЬНЫЕ ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ =====

-- 24. Индекс для поля id_soc_account (если используется)
CREATE INDEX IF NOT EXISTS idx_id_soc_account ON accounts(id_soc_account);

-- 25. Индекс для поля selectedFolderPath (если используется, частичный индекс)
CREATE INDEX IF NOT EXISTS idx_selected_folder_path ON accounts(selectedFolderPath(255));

-- 26. Составной индекс для основных полей фильтрации
CREATE INDEX IF NOT EXISTS idx_main_filters ON accounts(status, status_marketplace, created_at);

-- ===== ОПТИМИЗАЦИЯ ТАБЛИЦЫ =====

-- 27. Оптимизация таблицы для освобождения места
OPTIMIZE TABLE accounts;

-- 28. Анализ таблицы для обновления статистики
ANALYZE TABLE accounts;

-- ===== ПРОВЕРКА ИСПОЛЬЗОВАНИЯ ИНДЕКСОВ =====

-- Запустите эти команды после создания индексов для проверки:

-- EXPLAIN SELECT * FROM accounts WHERE status = 'selphie' ORDER BY created_at DESC LIMIT 100;
-- EXPLAIN SELECT COUNT(*) FROM accounts WHERE email IS NOT NULL AND email != '';
-- EXPLAIN SELECT * FROM accounts WHERE login IN ('61582779532026','61582447537580') ORDER BY id DESC LIMIT 100;
-- EXPLAIN SELECT * FROM accounts WHERE ads_id IN ('61582779532026','61582447537580') ORDER BY id DESC LIMIT 100;
-- EXPLAIN SELECT * FROM accounts WHERE social_url LIKE '%61582779532026%' ORDER BY id DESC LIMIT 100;

-- ===== ДОПОЛНИТЕЛЬНЫЕ СОСТАВНЫЕ ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ =====

-- 29. Составной индекс для сортировки по статусу и количеству друзей
CREATE INDEX IF NOT EXISTS idx_status_quantity_friends ON accounts(status, quantity_friends);

-- 30. Составной индекс для сортировки по статусу marketplace и дате создания
CREATE INDEX IF NOT EXISTS idx_status_marketplace_created ON accounts(status_marketplace, created_at);

-- 31. Составной индекс для комплексной фильтрации по email, статусу и marketplace
CREATE INDEX IF NOT EXISTS idx_email_status_marketplace ON accounts(email(255), status, status_marketplace);

-- 32. Составной индекс для сортировки по числовым полям
CREATE INDEX IF NOT EXISTS idx_quantity_fields ON accounts(quantity_friends, quantity_fp, quantity_bm);

-- 33. Составной индекс для фильтрации по статусу и сортировки по дате обновления
CREATE INDEX IF NOT EXISTS idx_status_updated_at ON accounts(status, updated_at DESC);

-- 34. Индекс для сортировки по количеству друзей (для быстрой сортировки)
CREATE INDEX IF NOT EXISTS idx_quantity_friends_sort ON accounts(quantity_friends, id);

-- ===== ДОПОЛНИТЕЛЬНЫЕ РЕКОМЕНДАЦИИ =====

-- 1. Увеличьте innodb_buffer_pool_size в my.cnf до 70-80% от RAM
-- 2. Установите innodb_log_file_size = 256M
-- 3. Включите query_cache (если доступен)
-- 4. Регулярно выполняйте OPTIMIZE TABLE accounts;

-- КРИТИЧЕСКИЕ ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ МЕДЛЕННЫХ ЗАПРОСОВ
-- Совместимая версия для MySQL 5.5+
-- Выполните эти команды в вашей базе данных для решения проблемы с производительностью

-- ===== ОСНОВНЫЕ ИНДЕКСЫ ДЛЯ МЕДЛЕННЫХ ЗАПРОСОВ =====

-- 1. Индекс для поля login (используется в WHERE IN запросах)
CREATE INDEX idx_login ON accounts(login);

-- 2. Индекс для поля ads_id (используется в WHERE IN запросах)  
CREATE INDEX idx_ads_id ON accounts(ads_id);

-- 3. Индекс для поля social_url (используется в LIKE запросах)
CREATE INDEX idx_social_url ON accounts(social_url(255));

-- 4. Индекс для поля status (основной фильтр)
CREATE INDEX idx_status ON accounts(status);

-- 5. Индекс для поля status_marketplace
CREATE INDEX idx_status_marketplace ON accounts(status_marketplace);

-- 6. Индекс для поля email (для фильтрации)
CREATE INDEX idx_email ON accounts(email);

-- 7. Индексы для временных полей
CREATE INDEX idx_created_at ON accounts(created_at);
CREATE INDEX idx_updated_at ON accounts(updated_at);

-- ===== СОСТАВНЫЕ ИНДЕКСЫ ДЛЯ СЛОЖНЫХ ЗАПРОСОВ =====

-- 8. Составной индекс для основных фильтров
CREATE INDEX idx_status_created ON accounts(status, created_at);

-- 9. Составной индекс для сортировки по статусу и дате
CREATE INDEX idx_status_updated ON accounts(status, updated_at);

-- 10. Индекс для поиска по email и статусу
CREATE INDEX idx_email_status ON accounts(email, status);

-- 11. Составной индекс для комплексных запросов
CREATE INDEX idx_compound_main ON accounts(status, created_at, updated_at);

-- ===== ИНДЕКСЫ ДЛЯ ДОПОЛНИТЕЛЬНЫХ ПОЛЕЙ =====

-- 12. Индекс для two_fa (частичный индекс для TEXT полей)
CREATE INDEX idx_two_fa ON accounts(two_fa(100));

-- 13. Индекс для token (частичный индекс для TEXT полей)
CREATE INDEX idx_token ON accounts(token(255));

-- 14. Индекс для avatar (частичный индекс для TEXT полей)
CREATE INDEX idx_avatar ON accounts(avatar(255));

-- 15. Индекс для cover (частичный индекс для TEXT полей)
CREATE INDEX idx_cover ON accounts(cover(255));

-- ===== ИНДЕКСЫ ДЛЯ ЧИСЛОВЫХ ПОЛЕЙ =====

-- 16. Индекс для birth_year (если используется)
CREATE INDEX idx_birth_year ON accounts(birth_year);

-- 17. Индекс для scenario_pharma (часто используется в фильтрах)
CREATE INDEX idx_scenario_pharma ON accounts(scenario_pharma);

-- 18. Индекс для quantity_friends
CREATE INDEX idx_quantity_friends ON accounts(quantity_friends);

-- 19. Индекс для quantity_fp
CREATE INDEX idx_quantity_fp ON accounts(quantity_fp);

-- 20. Индекс для quantity_bm
CREATE INDEX idx_quantity_bm ON accounts(quantity_bm);

-- 21. Индекс для quantity_photo
CREATE INDEX idx_quantity_photo ON accounts(quantity_photo);

-- ===== СПЕЦИАЛЬНЫЕ ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ =====

-- 22. Индекс для поля id_soc_account (если используется)
CREATE INDEX idx_id_soc_account ON accounts(id_soc_account);

-- 23. Индекс для поля selectedFolderPath (если используется, частичный индекс)
CREATE INDEX idx_selected_folder_path ON accounts(selectedFolderPath(255));

-- 24. Составной индекс для основных полей фильтрации
CREATE INDEX idx_main_filters ON accounts(status, status_marketplace, created_at);

-- ===== ДОПОЛНИТЕЛЬНЫЕ СОСТАВНЫЕ ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ =====

-- 25. Составной индекс для сортировки по статусу и количеству друзей
CREATE INDEX idx_status_quantity_friends ON accounts(status, quantity_friends);

-- 26. Составной индекс для сортировки по статусу marketplace и дате создания
CREATE INDEX idx_status_marketplace_created ON accounts(status_marketplace, created_at);

-- 27. Составной индекс для комплексной фильтрации по email, статусу и marketplace
CREATE INDEX idx_email_status_marketplace ON accounts(email(255), status, status_marketplace);

-- 28. Составной индекс для сортировки по числовым полям
CREATE INDEX idx_quantity_fields ON accounts(quantity_friends, quantity_fp, quantity_bm);

-- 29. Составной индекс для фильтрации по статусу и сортировки по дате обновления
CREATE INDEX idx_status_updated_at ON accounts(status, updated_at DESC);

-- 30. Индекс для сортировки по количеству друзей (для быстрой сортировки)
CREATE INDEX idx_quantity_friends_sort ON accounts(quantity_friends, id);



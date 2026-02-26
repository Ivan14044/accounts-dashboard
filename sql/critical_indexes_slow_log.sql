-- =============================================================================
-- Критичные индексы по slow log (slow_log.csv и подобные)
-- Выполните в той же БД, куда пишется slow log (например if592995_accountfactory).
--
-- Ошибка #1061 "Дублирующееся имя ключа" = индекс уже есть, пропустите эту строку.
-- Выполняйте команды по одной; если одна выдала "Duplicate key", переходите к следующей.
-- MySQL 5.5+: без IF NOT EXISTS (доступно только с MySQL 8.0.13+).
-- =============================================================================

-- 1. Поиск по login
CREATE INDEX idx_login ON accounts(login);

-- 2. Список по статусам (базовый)
CREATE INDEX idx_deleted_status_id ON accounts(deleted_at, status, id);

-- 3. Поиск по id_soc_account (Facebook ID)
CREATE INDEX idx_id_soc_account ON accounts(id_soc_account);

-- 4. Комбинированный фильтр status + status_rk (slow log: 18+ запросов по 30 сек)
--    Покрывает: WHERE deleted_at IS NULL AND status IN (...) AND status_rk = 'valid' ORDER BY id
--    MySQL сужает выборку по трём колонкам из индекса, не читая полные строки.
--    ВАЖНО: порядок (deleted_at, status, status_rk, id) — status перед status_rk!
CREATE INDEX idx_deleted_status_statusrk_id ON accounts(deleted_at, status, status_rk, id);

-- 5. UPDATE по status + currency (slow log: UPDATE ... SET status='sale' WHERE status IN (...) AND currency='USD')
--    Источник: accountfactory. Без этого индекса MySQL проверяет currency для каждой строки.
CREATE INDEX idx_deleted_status_currency ON accounts(deleted_at, status, currency);

-- 6. Покрывающий индекс для GROUP BY status (статистика дашборда, 8 сек → <1 сек)
--    MySQL читает только индекс, не обращаясь к полным строкам (140k строк с TEXT/BLOB полями).
CREATE INDEX idx_stats_covering ON accounts(deleted_at, status, updated_at, created_at);

-- Если все команды дали #1061 — индексы уже есть.

-- ⚠ ПРОБЛЕМА accountfactory: WHERE login = 97698908069 (число без кавычек, 11-42 сек)
--    login — VARCHAR, числовое сравнение отключает индекс idx_login.
--    Исправить в коде accountfactory: передавать login как строку: WHERE login = '97698908069'

-- Проверка после создания:
--   EXPLAIN SELECT COALESCE(status,'') as s, COUNT(*) FROM accounts WHERE deleted_at IS NULL GROUP BY status;
--   Ожидается: key = idx_stats_covering, Extra = Using index

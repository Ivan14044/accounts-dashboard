-- =============================================================================
-- Критичные индексы по slow log (slow_log.csv и подобные)
-- Примеры из лога: WHERE login = 97693222055 (6–25 сек); WHERE status IN (...) AND deleted_at IS NULL (10–30 сек).
-- Выполните в той же БД, куда пишется slow log (например if592995_accountfactory).
--
-- Ошибка #1061 "Дублирующееся имя ключа" = индекс уже есть, пропустите эту строку.
-- Выполняйте команды по одной; если одна выдала "Duplicate key", переходите к следующей.
-- MySQL 5.5+: без IF NOT EXISTS (доступно только с MySQL 8.0.13+).
-- =============================================================================

-- 1. Поиск по login (медленные 6–25 сек: WHERE login = число)
--    Индекс используется только если приложение передаёт login как СТРОКУ: bind_param('s', $login).
--    Если запросы идут из другого приложения (например accountfactory) — там исправить тип параметра.
CREATE INDEX idx_login ON accounts(login);

-- 2. Список по статусам (медленные 10–30 сек: WHERE status IN (...) AND deleted_at IS NULL ORDER BY id)
--    В приложении условие deleted_at IS NULL должно быть первым в WHERE (дашборд: FilterBuilder уже так делает).
CREATE INDEX idx_deleted_status_id ON accounts(deleted_at, status, id);

-- Если обе команды дали #1061 — оба индекса уже есть.

-- Проверка после создания (на том же сервере):
--   EXPLAIN SELECT * FROM accounts WHERE login = '97693222055' LIMIT 1;           -- key = idx_login
--   EXPLAIN SELECT id FROM accounts WHERE deleted_at IS NULL AND status IN ('recover_account_5') ORDER BY id LIMIT 50;  -- key = idx_deleted_status_id

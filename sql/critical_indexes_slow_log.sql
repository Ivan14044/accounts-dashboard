-- =============================================================================
-- Критичные индексы по slow log (WHERE login = число, WHERE status IN + deleted_at)
-- Выполните в той же БД, куда пишется slow log (например if592995_accountfactory).
--
-- Ошибка #1061 "Дублирующееся имя ключа" = индекс уже есть, пропустите эту строку.
-- Выполняйте команды по одной; если одна выдала "Duplicate key", переходите к следующей.
-- MySQL 5.5+: без IF NOT EXISTS (доступно только с MySQL 8.0.13+).
-- =============================================================================

-- 1. Поиск по login (#1061 = уже есть, переходите к п. 2)
--    Используется только когда приложение передаёт login как СТРОКУ в prepared statement.
CREATE INDEX idx_login ON accounts(login);

-- 2. Список по статусам (медленные 10–30 сек: WHERE status IN (...) AND deleted_at IS NULL ORDER BY id)
CREATE INDEX idx_deleted_status_id ON accounts(deleted_at, status, id);

-- Если обе команды дали #1061 — оба индекса уже есть, ничего делать не нужно.

-- После создания проверьте план запроса (на том же сервере):
--   EXPLAIN SELECT * FROM accounts WHERE login = '97693222055' LIMIT 1;
--   В колонке key должно быть idx_login.
--   EXPLAIN SELECT id FROM accounts WHERE deleted_at IS NULL AND status IN ('recover_account_5') ORDER BY id LIMIT 50;
--   В колонке key должно быть idx_deleted_status_id.

-- =====================================================================
-- Миграция: UNIQUE(login) + недостающие индексы под FilterBuilder.
-- Дата:     2026-04-16
-- Ветка:    feature/security-and-integrity-fixes
--
-- ВНИМАНИЕ. UNIQUE(login) упадёт с "Duplicate entry", если в таблице
-- уже есть дубли логинов. Перед применением проверьте:
--   SELECT login, COUNT(*) AS c FROM accounts
--   WHERE deleted_at IS NULL
--   GROUP BY login HAVING c > 1;
--
-- Если дубли есть — принимаете решение: смёрджить, мягко удалить или
-- переименовать. Только после этого запускать этот файл.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. UNIQUE(login)
--    Закрывает:
--      * B-1  TOCTOU в createAccount (проверка "уже есть login?" вне транзакции)
--      * B-6  /status/register полагался на ON DUPLICATE KEY UPDATE,
--             но UNIQUE ключа не было → создавались дубли маркеров.
--      * B-7  createAccountsBulk при параллельных импортах пропускал дубли.
-- ---------------------------------------------------------------------

ALTER TABLE `accounts`
    ADD UNIQUE KEY `uq_accounts_login` (`login`);

-- ---------------------------------------------------------------------
-- 2. Композитные и одиночные индексы под FilterBuilder.
--    В performance_indexes.sql уже есть часть (idx_login, idx_status,
--    idx_deleted_status_id и др.). Здесь — только то, чего не хватает.
--    IF NOT EXISTS — чтобы повторный прогон был идемпотентен.
-- ---------------------------------------------------------------------

-- Exact-match поиск по id_soc_account — в searchBySocialUrl и FilterBuilder.
CREATE INDEX IF NOT EXISTS `idx_id_soc_account` ON `accounts`(`id_soc_account`);

-- Fan page IDs — каждый фильтруется отдельно.
CREATE INDEX IF NOT EXISTS `idx_id_fan_page_1` ON `accounts`(`id_fan_page_1`);
CREATE INDEX IF NOT EXISTS `idx_id_fan_page_2` ON `accounts`(`id_fan_page_2`);
CREATE INDEX IF NOT EXISTS `idx_id_fan_page_3` ON `accounts`(`id_fan_page_3`);

-- Фильтр по году создания (часто в range-фильтрах UI).
CREATE INDEX IF NOT EXISTS `idx_year_created` ON `accounts`(`year_created`);

-- Композит под самый частый запрос: WHERE deleted_at IS NULL AND status = ?
-- ORDER BY created_at DESC.  Уже есть idx_deleted_status_id, этот закрывает
-- случай сортировки по created_at (Stats, фильтры по статусу + дате).
CREATE INDEX IF NOT EXISTS `idx_deleted_status_created_at`
    ON `accounts`(`deleted_at`, `status`, `created_at`);

-- Композит под trash-пагинацию: WHERE deleted_at IS NOT NULL ORDER BY id DESC.
CREATE INDEX IF NOT EXISTS `idx_deleted_id_desc`
    ON `accounts`(`deleted_at`, `id`);

-- Статусы BM (status_bm_1..status_bm_4). В UI это отдельные фильтры.
-- Не добавляем все 4, только часто используемый — остальные можно по запросу.
CREATE INDEX IF NOT EXISTS `idx_status_bm_1` ON `accounts`(`status_bm_1`);

-- =====================================================================
-- После применения прогоните ANALYZE TABLE, чтобы оптимизатор увидел
-- новые индексы:
--   ANALYZE TABLE accounts;
-- =====================================================================

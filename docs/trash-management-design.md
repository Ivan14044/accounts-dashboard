# Trash Management — Design (feat/trash-management)

Date: 2026-05-30. Branch: `feat/trash-management` (in-place off `feat/chunked-export-cleanup`).

Adds 4 features to `trash.php` (Корзина). Decisions confirmed with owner:
- Auto-purge: **hybrid, no cron** (manual button + throttled on-load purge).
- Retention default: **30 days**, editable in UI.
- Audit "who/why": **no schema migration** — reuse existing `account_history` table.

## 1. Retention + auto-purge + age column
- **Config storage:** `TrashSettings` (`includes/TrashSettings.php`) → `user_settings`
  (sentinel user `__system__`, type `trash_retention`, JSON `{enabled, days, last_purge_at}`). No migration.
- **Auto-purge:** runs in `register_shutdown_function` on `trash.php` load, throttled to
  once / 24h (`shouldAutoPurge()` + `markPurged()`), capped chunks per run. Deletes
  `deleted_at < NOW() - INTERVAL N DAY` in chunks of 1000 (avoids 256M/120s wall).
- **Manual button** "Удалить старше N дней" → `purge_old.php` (CSRF, chunked, returns `{deleted_count}`).
- **Age column:** PHP-computed from `deleted_at` — "в корзине X дн." + "автоудаление через Y дн.",
  colour-coded (ok / soon / overdue).

## 2. Advanced filters
- New trash filter inputs: deletion date range (`deleted_from`/`deleted_to`), status
  (`status`/`empty_status` — already parsed by `createFilterFromRequest`), and
  "только пустые" (`only_empty` → `login` empty AND `email` empty).
- Centralised in `AccountsService::createTrashFilterFromRequest()` so trash.php and every
  by-filter endpoint build the identical filter (always `+ addDeletedOnly()`).
- New `FilterBuilder::addEmptyAccountFilter()`.

## 3. Select-all-by-filter + bulk actions by filter
- UI banner when "select all on page" is checked: "Выбрано N на странице — выбрать все M по фильтру".
- In filter mode, restore / permanent-delete send the current GET filter params (`scope=filter`)
  instead of `ids[]`.
- Backend (chunked, deleted-scoped, favourites cleanup):
  `AccountsRepository::restoreAccountsByFilter()`, `permanentlyDeleteByFilter()`,
  `purgeOlderThan()`, `countOlderThan()` (in `AccountsRepoDeleteTrait`) + service wrappers
  in `AccountsServiceWriteTrait`.
- **Safety:** filter always contains `deleted_at IS NOT NULL` (live rows untouched);
  `getConditionsCount() === 0` guard; permanent-delete-by-filter requires typed confirmation (enter N).

## 4. Audit in UI (no migration)
- Soft-delete already logs a `deleted_at` event to `account_history` with `changed_by`.
- Inline "кто / когда" from the latest `deleted_at` event (legacy 1 250 rows → "неизвестно").
- History modal per row → `trash_history.php?id=…` → `AuditLogger::getAccountHistory()` timeline.
- "Why": history modal surfaces any `delete_note` audit rows when present. Capturing the reason
  at delete time lives on the dashboard delete flow — small follow-up, out of trash-page scope.

## Files
- New: `includes/TrashSettings.php`, `purge_old.php`, `trash_history.php`, this doc.
- Edit: `includes/FilterBuilder.php`, `includes/repositories/AccountsRepoDeleteTrait.php`,
  `includes/services/AccountsServiceWriteTrait.php`, `includes/services/AccountsServiceFiltersTrait.php`,
  `trash.php`, `templates/trash.php`, `assets/js/trash.js`, `restore.php`, `delete_permanent.php`.

## Scale / safety / verification
- All bulk ops chunked (1000); CSRF on every endpoint; typed-confirm on irreversible.
- Bulk by-filter ops are summary-logged (`Logger`) rather than per-row audited (avoids 100k inserts);
  per-id ops keep full per-row audit.
- Verify: `php -l` on every changed PHP file + live browser check on the 1 250-record trash page
  (filters, age, select-all-by-filter, history modal, purge-old, retention setting).

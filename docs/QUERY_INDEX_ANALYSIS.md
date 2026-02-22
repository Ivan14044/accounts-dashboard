# Детальный анализ: индексы и тяжёлые запросы

## 1. Точки входа запросов к таблице `accounts`

| Место | Запрос | Частота |
|-------|--------|---------|
| **AccountsRepository::getAccounts** | `SELECT ... FROM accounts $where ORDER BY $orderBy LIMIT ? OFFSET ?` | Каждая загрузка/пагинация дашборда |
| **AccountsRepository::getAccountsCount** | `SELECT COUNT(*) FROM accounts $where` | При загрузке и refresh |
| **StatisticsService::getStatistics** | 1–2 запроса: COUNT + SUM(CASE...), затем GROUP BY status с тем же WHERE | При загрузке дашборда |
| **StatisticsService::getUniqueFilterValues** | Один запрос из 5 UNION: по status, status_marketplace, currency, geo, status_rk с `deleted_at IS NULL` | При загрузке дашборда |
| **StatisticsService::getEmptyCounts** | SELECT с SUM(CASE...) по нескольким полям, WHERE из фильтра | При загрузке (если включено) |
| **AuditLogger** | `SELECT id, field FROM accounts WHERE id IN (...)` | По действиям с записями |

Главная нагрузка: **getAccounts** (список + сортировка) и **getAccountsCount** (подсчёт). Оба используют один и тот же `FilterBuilder` → один и тот же WHERE.

---

## 2. Какие условия формирует FilterBuilder (WHERE)

Условия добавляются в произвольном порядке; итоговый WHERE имеет вид:

- `deleted_at IS NULL` (всегда в конце, из getWhereClause)
- Опционально (по параметрам запроса):
  - **status** — `status IN (?, ?, ...)` или пустой статус
  - **status_marketplace** — `status_marketplace = ?` или пусто
  - **currency** — `currency = ?` или пусто
  - **geo** — `geo = ?` или пусто
  - **status_rk** — `status_rk = ?` или пусто
  - **limit_rk** — диапазон (прямое сравнение для numeric, иначе CAST)
  - **has_email** — `(email IS NOT NULL AND email <> '')`
  - **has_two_fa** — `(two_fa IS NOT NULL AND two_fa <> '')`
  - **has_token** — `(token IS NOT NULL AND token <> '')`
  - **has_avatar** — `(avatar IS NOT NULL AND avatar <> '')`
  - **has_cover** — `(cover IS NOT NULL AND cover <> '')`
  - **has_password** — `(password IS NOT NULL AND password <> '')`
  - **has_fan_page** — `quantity_fp > 0` (или CAST для не-numeric)
  - **full_filled** — login/email/first_name/last_name не пустые
  - **favorites_only** — EXISTS (account_favorites)
  - **scenario_pharma** — диапазон
  - **quantity_friends** — диапазон
  - **year_created** — диапазон
  - **q** (поиск) — `(login LIKE ? OR email LIKE ? OR social_url LIKE ?)`
  - **ids** — `id IN (?, ...)`

ORDER BY строится в **AccountsService::buildOrderBy**: по умолчанию `id ASC`, возможна любая колонка из метаданных (sort/dir из запроса).

---

## 3. Текущие индексы (apply_indexes_safe.php)

### 3.1 Составные под список + сортировка по id

| Индекс | Колонки | Покрывает запросы |
|--------|---------|---------------------|
| idx_deleted_id | (deleted_at, id) | Только deleted_at IS NULL, ORDER BY id. Без status. |
| idx_deleted_status_id | (deleted_at, status, id) | deleted_at + status IN + ORDER BY id. |
| idx_deleted_status_qty_friends_id | (deleted_at, status, quantity_friends, id) | deleted_at + status + диапазон quantity_friends + id. |
| idx_deleted_qty_friends_year_id | (deleted_at, quantity_friends, year_created, id) | Без status: deleted_at + quantity_friends + year_created + id. |
| idx_deleted_status_rk_id | (deleted_at, status_rk, id) | Фильтр по status_rk + ORDER BY id. |
| idx_deleted_status_marketplace_id | (deleted_at, status_marketplace, id) | Фильтр по status_marketplace + ORDER BY id. |
| idx_deleted_currency_id | (deleted_at, currency, id) | Фильтр по валюте + ORDER BY id. |
| idx_deleted_geo_id | (deleted_at, geo, id) | Фильтр по geo + ORDER BY id. |

### 3.2 Одиночные / прочие составные

- **idx_login**, **idx_ads_id**, **idx_social_url** — поиск и точечные фильтры.
- **idx_status**, **idx_status_marketplace**, **idx_email**, **idx_created_at**, **idx_updated_at**.
- **idx_email_status**, **idx_compound_main**, **idx_status_created**, **idx_status_updated**.
- **idx_two_fa**, **idx_token**, **idx_avatar**, **idx_cover** — префиксные (длина указана в скрипте).
- **idx_birth_year**, **idx_scenario_pharma**, **idx_quantity_friends**, **idx_quantity_fp**, **idx_quantity_bm**, **idx_quantity_photo**.
- **idx_id_soc_account**, **idx_selected_folder_path**.
- **idx_main_filters** (status, status_marketplace, created_at), **idx_status_quantity_friends**, **idx_status_marketplace_created**, **idx_email_status_marketplace**, **idx_quantity_fields**, **idx_quantity_friends_sort**.

---

## 4. Где индексы не покрывают запросы (пробелы)

### 4.1 Список аккаунтов (getAccounts)

- **Только deleted_at + «не пусто» (email, two_fa, password, token, avatar и т.д.) без status**  
  Используется **idx_deleted_id**: хорошее сужение по deleted_at и id. Дополнительные условия (email, two_fa, token, avatar) проверяются по отобранным строкам — покрытия по ним в одном индексе нет, но сужение уже есть.

- **Фильтр по status_rk, status_marketplace, currency, geo**  
  Составные индексы **(deleted_at, поле, id)** уже добавлены в apply_indexes_safe.php: idx_deleted_status_rk_id, idx_deleted_status_marketplace_id, idx_deleted_currency_id, idx_deleted_geo_id.

- **Сортировка не по id**  
  Составные индексы заканчиваются на `id`. При ORDER BY created_at, updated_at, login и т.д. оптимизатор может использовать одиночный индекс по полю сортировки или другой составной, но не «идеальный» составной под конкретный WHERE + ORDER BY. Это допустимо; тяжёлые CASE/TRIM в ORDER BY уже убраны для числовых колонок.

### 4.2 getAccountsCount

- Тот же WHERE, что и у getAccounts. MySQL для COUNT(*) может использовать тот же индекс; составные idx_deleted_status_rk_id, idx_deleted_status_marketplace_id, idx_deleted_currency_id, idx_deleted_geo_id уже в скрипте.

### 4.3 getUniqueFilterValues (UNION из 5 частей)

- Каждая часть: `WHERE deleted_at IS NULL AND (поле IS NOT NULL AND поле <> '') GROUP BY поле`.
- **idx_deleted_status_id** покрывает первую ветку (status). Для остальных четырёх полей в скрипте есть составные с id: idx_deleted_status_marketplace_id, idx_deleted_currency_id, idx_deleted_geo_id, idx_deleted_status_rk_id — префикс (deleted_at, поле) используется для веток UNION.

### 4.4 Статистика (getStatistics)

- Запросы с тем же WHERE, что и список. Используют те же индексы; пробелы те же.

### 4.5 Документация

- QUERY_PERFORMANCE.md синхронизирован с фактическим набором индексов в apply_indexes_safe.php. Индекс idx_deleted_status_id_email_2fa не добавляется сознательно (размер vs выгода).

---

## 5. Тяжёлые выражения (оставшиеся риски)

### 5.1 Уже исправлено

- **ORDER BY id** — простая сортировка.
- **ORDER BY** по колонкам из `meta['numeric']` — простая сортировка без TRIM/CAST.
- **addRangeFilter** для числовых колонок — прямое сравнение (`field >= ?`, `field <= ?`).
- **addGreaterThanZeroFilter** для числовых колонок — `field > 0`.
- **addYearCreatedFilter** — прямое сравнение по year_created.

### 5.2 Всё ещё тяжёлые (индекс не используется)

- **ORDER BY** по колонкам из **numericLikeColumns**, если тип в БД **не** int (хранятся как строка): в buildOrderBy остаётся выражение с `CASE WHEN ... TRIM(...) = '' THEN 1 ELSE 0 END` и `CAST(COALESCE(NULLIF(TRIM(...), ''), '0') AS UNSIGNED)`. Это даёт filesort по всем строкам; индекс по полю для сортировки не используется.  
  **Рекомендация:** для максимальной скорости эти поля должны быть числового типа (INT, UNSIGNED, SMALLINT и т.д.) в таблице `accounts` — тогда они попадут в meta['numeric'] и будет простая сортировка. См. опциональный скрипт `sql/migrate_numeric_columns.sql`.

- **addRangeFilter** для полей **не** из numericColumns (строка в БД): по-прежнему `TRIM(...) <> ''` и `CAST(... AS UNSIGNED)`. Индекс по такому полю для диапазона не используется.  
  **Рекомендация:** по возможности привести тип колонки к числовому (см. `sql/migrate_numeric_columns.sql`); иначе оставить как есть, но знать, что при больших объёмах фильтр по диапазону будет дорогим.

- **addGreaterThanZeroFilter** для полей не из numericColumns и не из numericLikeColumns: по-прежнему `CAST(field AS UNSIGNED) > 0`. Для quantity_fp и остальных числоподобных полей уже используется прямое сравнение `field > 0`.

- **Поиск (addSearchFilter)** — `login LIKE '%...%' OR email LIKE '%...%' OR social_url LIKE '%...%'`. Префиксные индексы по login/email/social_url для `%...%` не помогают; возможен полный скан по отфильтрованной части таблицы. Это ожидаемо для «поиска по подстроке»; снизить стоимость можно только ограничением других условий (хорошие индексы по deleted_at, status и т.д.) и лимитами.

- **EXISTS (account_favorites)** при «только избранное» — подзапрос по другой таблице; индекс по (user_id, account_id) в account_favorites обязателен (обычно есть как PRIMARY). Со стороны accounts дополнительный индекс не нужен.

### 5.3 Сводка: где в коде остаются TRIM и CAST

| Место | Условие | Статус |
|-------|---------|--------|
| **FilterBuilder::addRangeFilter** | Поле не в numericColumns и не в numericLikeColumns | TRIM + CAST остаются (редкий кейс для неизвестных полей). |
| **FilterBuilder::addGreaterThanZeroFilter** | Поле в numericLikeColumns (quantity_fp и др.) | Исправлено: прямое сравнение `field > 0`. |
| **AccountsService::buildOrderBy** | Сортировка по колонке из numericLikeColumns при типе VARCHAR в БД | TRIM + CAST остаются — без числового типа в БД корректная числовая сортировка по индексу невозможна. Решение: миграция схемы (`sql/migrate_numeric_columns.sql`). |

В WHERE для полей limit_rk, scenario_pharma, quantity_friends, quantity_fp, quantity_bm, quantity_photo, year_created, birth_* уже везде используется прямое сравнение (numericColumns или numericLikeColumns). В ORDER BY для этих полей при VARCHAR в БД по-прежнему тяжёлое выражение — убрать можно только приведением типа колонки к числовому.

### 5.4 Опциональная миграция схемы (числовые колонки)

Если в БД поля `quantity_friends`, `year_created`, `limit_rk`, `scenario_pharma`, `quantity_fp`, `quantity_bm`, `quantity_photo`, `birth_*` имеют тип VARCHAR/TEXT, их можно привести к числовым типам. Тогда ColumnMetadata включит их в `numericCols`, и запросы будут использовать простую сортировку и прямое сравнение без TRIM/CAST. В репозитории есть опциональный скрипт **`sql/migrate_numeric_columns.sql`** с закомментированными примерами `ALTER TABLE ... MODIFY COLUMN`. Выполнять вручную после проверки текущих типов (`SHOW COLUMNS FROM accounts`) и совместимости данных; перед применением — бэкап БД.

### 5.5 Статистика (SUM(CASE ...))

- В StatisticsService условия в SUM(CASE WHEN ...) — это агрегация по уже отобранным строкам; они не формируют отдельный индексный скан. Тяжесть запроса определяется только WHERE; индексы те же, что и для getAccounts/getAccountsCount.

---

## 6. Рекомендации по индексам

### 6.1 Уже в apply_indexes_safe.php

- **idx_deleted_status_rk_id**, **idx_deleted_status_marketplace_id**, **idx_deleted_currency_id**, **idx_deleted_geo_id** — добавлены; составные с id перекрывают и ветки getUniqueFilterValues (префикс deleted_at, поле).

### 6.2 Не добавлять без обоснования

- Слишком длинные покрывающие индексы (много колонок) — рост размера таблицы и замедление INSERT/UPDATE.
- Индексы только под LIKE '%...%' — неэффективны.

### 6.3 Другие таблицы

- **account_favorites**: индекс **idx_user_created** (user_id, created_at) для `WHERE user_id = ? ORDER BY created_at DESC` создаётся в **apply_indexes_safe.php** с проверкой существования таблицы.
- **saved_filters**: индекс **idx_user_updated** (user_id, updated_at) для списка сохранённых фильтров — аналогично, в том же скрипте.

---

## 7. Краткая сводка

| Категория | Статус |
|-----------|--------|
| Основной список: deleted_at + status + id | Покрыт idx_deleted_status_id. |
| Список: + quantity_friends (с/без status) | Покрыт idx_deleted_status_qty_friends_id и idx_deleted_qty_friends_year_id. |
| Список: только deleted_at + id | Покрыт idx_deleted_id. |
| Список/COUNT: фильтр по status_rk, status_marketplace, currency, geo | Покрыт idx_deleted_status_rk_id, idx_deleted_status_marketplace_id, idx_deleted_currency_id, idx_deleted_geo_id. |
| getUniqueFilterValues (UNION) | Покрыт составными индексами (префикс deleted_at, поле). |
| ORDER BY по числовым колонкам (тип INT в БД) | Упрощён, индекс для сортировки может использоваться. |
| ORDER BY по «строкам как числа» (numericLike) | Тяжёлое выражение; решается приведением типа колонки к числовому. |
| Поиск LIKE '%q%' | Индекс не поможет; опора на остальные фильтры и лимиты. |
| COUNT(*) с тем же WHERE | Использует те же индексы, что и список. |

После добавления недостающих индексов и при числовых типах для полей диапазонов/сортировки запросы списка и подсчёта должны стабильно использовать индексы и не давать тяжёлых filesort на полной таблице.

---

## 8. Пошаговая проверка каждого запроса (верификация)

### 8.1 Запросы через FilterBuilder + getAccounts / getAccountsCount

| Точка входа | Как создаётся FilterBuilder | numericLikeColumns | TRIM/CAST в WHERE |
|-------------|-----------------------------|--------------------|---------------------|
| DashboardController | createFilterFromRequest($_GET) | да (через getNumericLikeColumns()) | нет для limit_rk, quantity_*, year_created и т.д. |
| refresh.php | createFilterFromRequest | да | нет |
| favorites.php | createFilterFromRequest($_GET) | да | нет |
| trash.php | createFilterFromRequest($_GET) | да | нет |
| export.php | createFilterFromRequest | да | нет |
| api.php | createFilterFromRequest($_GET) | да | нет |
| api_custom_card.php | createFilterFromArray (→ createFilterFromRequest) | да | нет |
| api/index.php (count, filters) | createFilterFromRequest / createFilterFromArray | да | нет |
| api/index.php (POST /status/register) | new FilterBuilder(..., getNumericLikeColumns()) | да (добавлено) | только addEqualFilter('status') — нет диапазонов |
| api_register_status.php | new FilterBuilder(..., getNumericLikeColumns()) | да (добавлено) | только addEqualFilter('status') |

ORDER BY строится в AccountsService::buildOrderBy; для колонок из meta['numeric'] — простая сортировка; для numericLike при VARCHAR в БД — TRIM/CAST только там (устраняется миграцией схемы).

### 8.2 Прямые SQL к accounts (без FilterBuilder)

| Файл | Запрос | TRIM/CAST |
|------|--------|-----------|
| api/index.php, api_register_status.php | SELECT id FROM accounts WHERE login = ? AND status = ? LIMIT 1 | нет |
| empty_trash.php | SELECT COUNT(*), SELECT id, DELETE FROM accounts WHERE deleted_at IS NOT NULL | нет |
| delete_permanent.php | SELECT id, DELETE FROM accounts WHERE id IN (...) AND deleted_at IS NOT NULL | нет |
| AccountsService (audit) | SELECT id FROM accounts WHERE deleted_at >= ... / WHERE id IN (...) | нет |
| AuditLogger | SELECT id, field FROM accounts WHERE id IN (...) | нет |
| MassTransferService | SELECT id, id_soc_account WHERE id_soc_account IN (...); SELECT id WHERE social_url LIKE ? | нет |
| AccountsRepository (getAccountById и др.) | SELECT BY id, UPDATE/DELETE по where из FilterBuilder | нет в сыром SQL |
| StatisticsService | SELECT COUNT, SUM(CASE...), GROUP BY — WHERE из FilterBuilder или deleted_at IS NULL | нет |
| getUniqueFilterValues | UNION из 5 × (WHERE deleted_at IS NULL AND field IS NOT NULL...) | нет |

### 8.3 Исправленные моменты в коде

- **favorites.php:** вызов getAccounts исправлен с (filter, sort, dir, offset, perPage) на (filter, sort, dir, perPage, offset).
- **AccountsService::getNumericLikeColumns():** единый список числоподобных колонок; используется в createFilterFromRequest и в buildOrderBy; передаётся в api/index.php и api_register_status.php при ручном создании FilterBuilder.

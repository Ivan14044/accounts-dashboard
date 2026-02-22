# Производительность запросов — анализ и рекомендации

## 1. Критичные запросы (hot path)

### 1.1 Загрузка дашборда (`prepareDashboardData`)

| Шаг | Запросы | Индексы / кэш |
|-----|---------|----------------|
| getStatistics | 1–2 SELECT (COUNT, GROUP BY status) по `accounts` с WHERE из фильтра | `idx_deleted_status_id`; файловый кэш 90 с |
| getAccounts | SELECT список колонок + WHERE + ORDER BY + LIMIT/OFFSET | `idx_deleted_status_id` при сортировке по `id`; порядок WHERE: сначала `deleted_at IS NULL` |
| getUniqueFilterValues | 1 запрос UNION из 5 частей (status, status_marketplace, currency, geo, status_rk) | Составные индексы `(deleted_at, колонка)` для каждой части UNION |
| getEmptyCounts | 1 SELECT с SUM(CASE ...) по `accounts` | WHERE `deleted_at IS NULL` — использование `idx_deleted_status_id` |

### 1.2 Обновление без перезагрузки (`refresh.php`)

- **light=1**: только getAccountsCount + getAccounts — минимум запросов.
- **Без light**: те же запросы, что и при полной загрузке (stats, unique values, empty counts).

### 1.3 Другие частые запросы

- **user_settings**: `SELECT ... WHERE username = ? AND setting_type = ?` — UNIQUE (username, setting_type).
- **account_favorites**: `SELECT account_id ... WHERE user_id = ? ORDER BY created_at DESC` — нужен индекс (user_id, created_at).
- **account_history**: выборка по account_id, сортировка по changed_at — индексы в DatabaseSchemaManager.

---

## 2. Индексы

### 2.1 Таблица `accounts`

Применить все индексы из `apply_indexes_safe.php` (один раз на окружении):

```bash
php apply_indexes_safe.php
```

Фактический набор индексов в скрипте:

- **Составные под список/COUNT (deleted_at + ... + id):** idx_deleted_id, idx_deleted_status_id, idx_deleted_status_qty_friends_id, idx_deleted_qty_friends_year_id, idx_deleted_status_rk_id, idx_deleted_status_marketplace_id, idx_deleted_currency_id, idx_deleted_geo_id.
- **Одиночные и прочие составные:** idx_login, idx_ads_id, idx_social_url, idx_status, idx_status_marketplace, idx_email, idx_created_at, idx_updated_at, idx_status_created, idx_status_updated, idx_email_status, idx_compound_main, idx_two_fa, idx_token, idx_avatar, idx_cover, idx_birth_year, idx_scenario_pharma, idx_quantity_*, idx_id_soc_account, idx_selected_folder_path, idx_main_filters, idx_status_quantity_friends, idx_status_marketplace_created, idx_email_status_marketplace, idx_quantity_fields, idx_quantity_friends_sort.

Индекс **idx_deleted_status_id_email_2fa** сознательно не добавлен (размер индекса vs выгода); комбинации закрыты idx_deleted_status_id и фильтрами по полям.

### 2.2 Таблица `account_favorites`

- PRIMARY KEY (user_id, account_id), **idx_user_id**, **idx_account_id**.
- **idx_user_created** (user_id, created_at) — для запроса `WHERE user_id = ? ORDER BY created_at DESC`. Создаётся тем же скриптом **apply_indexes_safe.php** (с проверкой существования таблицы).

### 2.3 Таблица `user_settings`

- UNIQUE (username, setting_type) — запросы по username + setting_type уже используют индекс.
- Кастомные статусы (custom_cards) кэшируются в файл на 120 с (DashboardController::getCustomStatuses); сброс при сохранении через API.

### 2.4 Таблица `saved_filters`

- **idx_user_id**, **idx_created_at**.
- **idx_user_updated** (user_id, updated_at) — для запроса списка сохранённых фильтров `WHERE user_id = ? ORDER BY updated_at DESC`. Создаётся тем же скриптом **apply_indexes_safe.php** (с проверкой существования таблицы).

---

## 3. Порядок условий в WHERE

В `FilterBuilder::getWhereClause()` условие **deleted_at IS NULL** добавляется **первым** в список условий, чтобы запросы вида:

```sql
WHERE deleted_at IS NULL AND (status IN (...)) ORDER BY id
```

использовали индекс `idx_deleted_status_id`. Не менять порядок без необходимости.

---

## 4. Кэширование

- **Статистика (getStatistics)**: файловый кэш (TTL 90 с), опционально in-memory на один запрос.
- **getUniqueFilterValues**: файловый кэш (TTL 300 с).
- **getEmptyCounts**: файловый кэш (TTL 120 с).
- **getCustomStatuses** (кастомные карточки): файловый кэш по пользователю (TTL 120 с), сброс при сохранении настроек типа `custom_cards` в API.
- **Database::prepare**: кэш в рамках одного HTTP-запроса (при передаче cacheKey).
- Сброс файлового кэша: `StatisticsService::clearDashboardFileCache()` после массовых обновлений/импорта.

---

## 5. Рекомендации по запросам

1. **Всегда применять индексы** на продакшене: `php apply_indexes_safe.php`.
2. **Не отключать** условие `deleted_at IS NULL` для списков и счётчиков (корзина — отдельный кейс).
3. **Сортировка по id** — самая быстрая (используется idx_deleted_status_id); сортировка по другим полям может давать filesort.
4. **refresh с light=1** использовать для обновления только таблицы без пересчёта статистики и фильтров.
5. После массового импорта/обновления вызывать `StatisticsService::clearDashboardFileCache()`.

---

## 6. Проверка медленных запросов

- Включить slow query log в MySQL и смотреть запросы к `accounts` с большим временем или числом просканированных строк.
- Убедиться, что в плане запроса используется нужный индекс: `EXPLAIN SELECT ...`.
- При появлении запросов с `status IN (...)` и `deleted_at IS NULL` в slow log — проверить наличие и использование `idx_deleted_status_id` (см. DEVELOPER_GUIDE.md).

---

## 7. Чек-лист «чтобы панель летала»

1. **Один раз выполнить** `php apply_indexes_safe.php`:
   - **По SSH:** в каталоге проекта: `php apply_indexes_safe.php`
   - **На хостинге без SSH:** войдите в дашборд, затем откройте в браузере `https://ваш-сайт/apply_indexes_safe.php` — скрипт проверит авторизацию и выведет отчёт. После успешного выполнения страницу можно больше не открывать.
   В результате создаются индексы по `accounts`, `account_favorites`, `saved_filters`, выполняются OPTIMIZE/ANALYZE и создаётся флаг `.optimization_applied`.
2. Убедиться, что флаг `.optimization_applied` создан (в корне или в `sys_get_temp_dir()`), иначе при каждом запросе может выполняться проверка таблиц в config.
3. Для обновления только таблицы без пересчёта статистики использовать **refresh с `light=1`** (уже используется в коде дашборда где возможно).
4. После массового импорта/обновления вызывать `StatisticsService::clearDashboardFileCache()` (уже вызывается в AccountsService и import_accounts.php).
5. При необходимости замерить шаги загрузки — открывать дашборд с `?profile=1` и смотреть логи (getStatistics, getAccounts, getUniqueFilterValues, getEmptyCounts).

6. **Ссылки с фильтрами:** если открываете сохранённую ссылку с параметрами (фильтры, страница), используйте **loading.php** вместо index.php — сразу покажется экран «Загрузка дашборда», тяжёлая отрисовка пойдёт в фоне. Пример: `https://ваш-сайт/loading.php?per_page=50&page=1&has_email=1&has_two_fa=1&status[]=...`

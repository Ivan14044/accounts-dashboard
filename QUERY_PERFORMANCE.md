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

### 6.0 Устранение по slow log (например slow_log.csv)

Если в slow log видны запросы к БД `if592995_accountfactory` (или другой) с временем 5–30 сек:

| Паттерн в логе | Причина | Что сделать |
|----------------|--------|-------------|
| `SELECT * FROM accounts WHERE login = 97693222055 LIMIT 1` (число без кавычек) | VARCHAR `login` + числовой литерал → индекс не используется | 1) Создать индекс: выполнить **`sql/critical_indexes_slow_log.sql`** в этой БД (или `php apply_indexes_safe.php`). 2) В **приложении**, которое шлёт этот запрос (например accountfactory), передавать login **как строку**: `bind_param('s', $login)` и не подставлять число в SQL. |
| `SELECT ... FROM accounts WHERE (status IN (...)) AND deleted_at IS NULL ORDER BY id` (десятки тысяч строк, 10–30 сек) | Нет индекса или условие `deleted_at` не первое | 1) Создать индекс: **`CREATE INDEX idx_deleted_status_id ON accounts(deleted_at, status, id);`** (есть в `sql/critical_indexes_slow_log.sql`). 2) В коде приложения условие **deleted_at IS NULL** должно быть **первым** в WHERE (в этом дашборде уже так: `FilterBuilder::getWhereClause`). |

На сервере, куда пишется slow log: выполнить один раз в той БД **`sql/critical_indexes_slow_log.sql`** (по одной команде; при ошибке «Duplicate key name» индекс уже есть). Для запросов по login дополнительно исправить приложение, которое делает `WHERE login = число` — передавать login как строку.

### 6.1 Поиск по login (slow log: WHERE login = число)

Если в slow log появляются запросы вида `SELECT * FROM accounts WHERE login = 97693222055 LIMIT 1` с временем 6–25 сек:

- **Причина:** колонка `login` имеет строковый тип (VARCHAR). При сравнении с **числовым** литералом MySQL не использует индекс по `login` и сканирует всю таблицу.
- **Что сделать:**
  1. **Индекс:** на таблице `accounts` должен быть индекс `idx_login`. Создаётся скриптом `apply_indexes_safe.php`. Проверка: `php check_login_index.php` (см. ниже).
  2. **Тип параметра:** в коде приложения, выполняющего запрос, передавать значение login **как строку** в prepared statement: `WHERE login = ?` и `bind_param('s', $login)` (или эквивалент со строкой). Тогда MySQL использует индекс.
- В этом проекте (дашборд) все запросы по login уже привязывают значение как строку (`'s'` в bind_param и явный `(string)`/trim при подготовке). Если slow log идёт с другого приложения (например, скрипт/сервис на том же хосте) — там нужно исправить привязку типа и при необходимости запустить `apply_indexes_safe.php` на той же БД.

### 6.2 Анализ slow_log (1).csv — 2026-03-01

| Строки | Запрос | Время | Причина |
|--------|--------|-------|---------|
| 1–5 | `SELECT * FROM accounts WHERE login = 447405202661 LIMIT 1` (и аналогичные с числом без кавычек) | **41–46 сек** | accountfactory: `login` VARCHAR, сравнение с числовым литералом → полный скан таблицы (~1015 строк × N итераций) |

**Источник:** if592995_accountfactory @ 65.21.233.118

**Рекомендации:**
1. **В accountfactory:** передавать `login` как строку: `WHERE login = ?` и `bind_param('s', $login)` (или `bind_param('s', (string)$login)`), чтобы MySQL использовал индекс `idx_login`.
2. **Если accountfactory править нельзя:** для MySQL 8.0+ запустить `php apply_indexes_safe.php` — создаётся generated column `login_numeric` + `idx_login_numeric`, что частично помогает при запросах с числом. Для MySQL 5.7 единственное решение — исправить приложение.

---

### 6.3 Анализ slow_log (5).csv — 2026-02-26/27

Краткая сводка по 14 медленным запросам:

| Строки | Запрос | Время | Причина | Решение |
|--------|--------|-------|---------|---------|
| 5–9 | `SELECT * WHERE login = NUMBER` (без кавычек) | **9–42 сек** | `login` VARCHAR, сравнение с BIGINT → полный скан таблицы, `idx_login` не используется | MySQL 8.0+: функциональный индекс `idx_login_numeric` добавлен в `apply_indexes_safe.php`. MySQL 5.7: попросить accountfactory передавать login как строку |
| 1–3 | `UPDATE accounts WHERE deleted_at IS NULL AND status IN (...) AND currency = 'USD' AND email...` | **7–8 сек** | `idx_deleted_status_currency` не применён на этой БД | Запустить `php apply_indexes_safe.php` |
| 4 | `SELECT GROUP BY status` статистика | **7.8 сек** / 139k строк | `idx_stats_covering` не применён на этой БД | Запустить `php apply_indexes_safe.php` |
| 11 | `UPDATE SET status WHERE id IN (2000+ IDs)` | **46 сек** / 139k строк | Огромный IN-список → MySQL переключается на полный скан вместо использования PRIMARY KEY | На стороне accountfactory: разбить UPDATE на батчи по 200–300 IDs |
| 10, 12–14 | `UPDATE SET token/cover/status WHERE id = N` | **24–38 сек** (всё — lock_time!) | Строка 11 держит row-level locks ~46 сек, все остальные UPDATE ждут | Исправить строку 11 (батчи на стороне accountfactory) |

#### Детальный разбор

**Проблема №1 — login = NUMBER (критическая)**

Запросы типа `WHERE login = 97698908069` (без кавычек) от accountfactory с IP 65.21.233.118.
MySQL выполняет `CAST(login AS UNSIGNED) = 97698908069` для каждой строки → игнорирует `idx_login`.
Время: 9–42 секунды на запрос (сканирует 35k–113k строк, останавливается по LIMIT 1 когда находит).

Решения:
- **MySQL 8.0+**: `apply_indexes_safe.php` теперь автоматически создаёт:
  ```sql
  CREATE INDEX idx_login_numeric ON accounts ((CAST(login AS UNSIGNED)));
  ```
  MySQL 8.0 может использовать этот функциональный индекс для запросов с числовым литералом.
- **MySQL 5.7** (функциональные индексы не поддерживаются): решение только в accountfactory — передавать login как строку: `WHERE login = '97698908069'`.
- **Дашборд** давно исправлен: `FilterBuilder` передаёт login через `bind_param('s', ...)`.

**Проблема №2 — полный скан в GROUP BY статистика (line 4)**

```sql
SELECT COALESCE(status,''), COUNT(*), SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ...)
FROM accounts WHERE deleted_at IS NULL GROUP BY status ORDER BY status
```
Сканирует все 139k строк → 7.8 сек. Решение: `idx_stats_covering(deleted_at, status, updated_at, created_at)` уже в `apply_indexes_safe.php`. После применения запрос читает только индекс (covering index), не трогая строки данных.

**Проблема №3 — UPDATE с огромным IN-списком (line 11, lock contention)**

accountfactory делает `UPDATE ... WHERE id IN (1000+ IDs)` одним запросом.
При ~1000+ значений в IN MySQL выбирает полный скан вместо index lookup по PRIMARY KEY.
Результат: 46 секунд на UPDATE 369 строк при сканировании 139k.
**Побочный эффект**: все строки, попавшие под UPDATE, получают row-level X-lock на 46 секунд.
Любой другой UPDATE к этим же строкам (lines 10, 12–14) ждёт в очереди.

Рекомендация accountfactory: разбить UPDATE на батчи по ≤300 IDs с небольшой паузой (50–100мс) между батчами.

#### Аудит избыточных индексов

После всех оптимизаций на таблице `accounts` создано **40+ индексов**.
Каждый UPDATE (`status`, `token`, `cover`, etc.) вынужден обновлять все эти B-деревья.
Для выявления реально неиспользуемых индексов выполните (Performance Schema должна быть включена):

```sql
SELECT INDEX_NAME, COUNT_FETCH, COUNT_INSERT, COUNT_UPDATE, COUNT_DELETE
FROM performance_schema.TABLE_IO_WAITS_SUMMARY_BY_INDEX_USAGE
WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = 'accounts'
  AND INDEX_NAME NOT IN ('PRIMARY')
  AND COUNT_FETCH + COUNT_INSERT + COUNT_UPDATE + COUNT_DELETE = 0
ORDER BY INDEX_NAME;
```

Индексы с нулевыми счётчиками — кандидаты на удаление: `DROP INDEX index_name ON accounts;`

---

## 7. Новое окружение / другой ПК / другая БД

Если панель на **другом компьютере** или с **другой базой данных** снова грузится медленно — на каждом таком окружении нужно **один раз** выполнить те же шаги оптимизации:

1. В каталоге проекта (на том ПК, где открываете панель) выполнить: **`php apply_indexes_safe.php`**
   - Скрипт подключается к БД из `config.php` этого окружения и создаёт индексы в **этой** БД.
   - В конце создаётся файл `.optimization_applied` в корне проекта — без него при каждом запросе выполняется лишняя проверка индексов.
2. Если нет доступа к консоли: открыть в браузере (после входа в панель) **`https://ваш-сайт/apply_indexes_safe.php`** — скрипт применит индексы и создаст флаг (если веб-сервер имеет право писать в корень проекта).
3. На большых таблицах первый запуск может занять несколько минут (создание индексов). После этого загрузка дашборда станет быстрой.

---

## 8. Чек-лист «чтобы панель летала»

1. **Один раз выполнить** `php apply_indexes_safe.php`:
   - **По SSH:** в каталоге проекта: `php apply_indexes_safe.php`
   - **На хостинге без SSH:** войдите в дашборд, затем откройте в браузере `https://ваш-сайт/apply_indexes_safe.php` — скрипт проверит авторизацию и выведет отчёт. После успешного выполнения страницу можно больше не открывать.
   В результате создаются индексы по `accounts`, `account_favorites`, `saved_filters`, выполняются OPTIMIZE/ANALYZE и создаётся флаг `.optimization_applied`.
2. Убедиться, что флаг `.optimization_applied` создан (в корне или в `sys_get_temp_dir()`), иначе при каждом запросе может выполняться проверка таблиц в config.
3. Для обновления только таблицы без пересчёта статистики использовать **refresh с `light=1`** (уже используется в коде дашборда где возможно).
4. После массового импорта/обновления вызывать `StatisticsService::clearDashboardFileCache()` (уже вызывается в AccountsService и import_accounts.php).
5. При необходимости замерить шаги загрузки — открывать дашборд с `?profile=1` и смотреть логи (getStatistics, getAccounts, getUniqueFilterValues, getEmptyCounts).
6. **Проверка индексов по slow log:** выполнить `php check_login_index.php` — скрипт проверит наличие `idx_login` и `idx_deleted_status_id`, выведет рекомендации и готовые команды CREATE INDEX. Либо выполнить SQL вручную в той БД, куда пишется slow log: **`sql/critical_indexes_slow_log.sql`** (совместим с MySQL 5.5+; при ошибке «Duplicate key name» индекс уже есть). См. п. 6.1 про тип параметра login.

7. **Ссылки с фильтрами:** если открываете сохранённую ссылку с параметрами (фильтры, страница), используйте **loading.php** вместо index.php — сразу покажется экран «Загрузка дашборда», тяжёлая отрисовка пойдёт в фоне. Пример: `https://ваш-сайт/loading.php?per_page=50&page=1&has_email=1&has_two_fa=1&status[]=...`

---

## 9. Бэкенд: что сделано для «чтобы БД летала»

| Недочёт | Решение |
|--------|--------|
| **ensureIndexes() при каждом запросе** | В `Database::ensureIndexes()` при наличии файла `.optimization_applied` (или флага в `sys_get_temp_dir`) проверка индексов пропускается — не выполняется 12+ запросов к INFORMATION_SCHEMA на каждый запрос. |
| **refresh без light** | При вызове refresh с параметром `light=1` бэкенд не вызывает `getStatistics()` (1–2 тяжёлых запроса), только `getAccountsCount()` + `getAccounts()`. Фронт передаёт `light=1` при частичных обновлениях (например, сброс выделения). |
| **WHERE: порядок условий** | В `FilterBuilder::getWhereClause()` условие `deleted_at IS NULL` добавляется **первым** в список (`array_unshift`), чтобы оптимизатор мог использовать составные индексы `idx_deleted_*`. |
| **Дубли getColumnMetadata / 4× getEmpty*Count** | Уже исправлено ранее: один вызов метаданных в refresh; счётчики пустых полей объединены в один запрос `getEmptyFilterCounts()`. |

### Что проверить вручную

- **config.php:** при каждой загрузке выполняется один запрос к INFORMATION_SCHEMA (существует ли таблица `accounts`). Кэширование не добавлено, чтобы не скрывать ошибки при удалении таблицы.
- **Сортировка по полям «строка как число»:** если в БД колонки `limit_rk`, `quantity_friends` и т.п. имеют тип VARCHAR, в ORDER BY остаётся тяжёлое выражение (TRIM/CAST). Решение: миграция типов (`sql/migrate_numeric_columns.sql`) или сортировка по id.
- **Поиск `q` (LIKE '%...%'):** индекс по полям не используется; опора на остальные фильтры и лимиты.

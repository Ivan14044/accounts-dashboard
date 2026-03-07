# План улучшения проекта Dashboard

> Детальный анализ и план действий для доведения проекта до уровня «лучшего в глазах разработчика».

---

## 0. Выполнено (2026-02)

| Задача | Файлы |
|--------|-------|
| Миграция API на `/api/*` | `favorites.js`, `saved-filters.js`, `quick-search.js`, `init-script.php`, `dashboard-stats.js` — все вызовы переведены на `/api/favorites`, `/api/filters`, `/api/accounts/count`, `/api/settings`, `/api/accounts/custom-card`, `/api/status/register` |
| Удаление мёртвого файла | `test-csv-upload.php` (тестовый скрипт с хардкодом) |
| Деплой: `.optimization_applied` | При деплое выполнить `php apply_indexes_safe.php` или `php create_optimization_flag.php` — см. QUERY_PERFORMANCE.md §8 |
| Документация accountfactory | QUERY_PERFORMANCE.md §6.0–6.3 — рекомендации по `login` как строке, батчам UPDATE |

---

## 1. Резюме текущего состояния

| Аспект | Оценка | Ключевые проблемы |
|--------|--------|-------------------|
| **Архитектура** | 6/10 | Дублирование API, смешение legacy и нового кода |
| **Производительность** | 7/10 | Индексы есть, но legacy-запросы (accountfactory) |
| **Чистота кода** | 5/10 | Мёртвый код, дублирование, init-script 4200+ строк |
| **Безопасность** | 7/10 | CSRF, rate limit есть; чувствительные данные в логах |
| **Фронтенд** | 5/10 | 25+ JS-файлов, бандл не используется по умолчанию |
| **Поддерживаемость** | 5/10 | Сложно найти источник правды, много точек входа |

---

## 2. Детальный анализ по функционалу

### 2.1 Загрузка и отображение дашборда

**Текущий поток:**
```
index.php → config.php → auth.php → DashboardController::getData()
         → AccountsService (getStatistics, getAccounts, getUniqueFilterValues, getEmptyCounts)
         → templates/dashboard.php + init-script.php (4200+ строк inline)
```

**Проблемы:**
- `init-script.php` — огромный inline-скрипт, не кэшируется, блокирует парсинг
- 15+ CSS и 25+ JS подключаются отдельно вместо бандла
- `ensureIndexes()` при каждом запросе (если нет `.optimization_applied`)

**Рекомендации:**
- [ ] Вынести логику из `init-script.php` в `dashboard-init.js` (уже частично есть)
- [ ] Подключать `dashboard.min.js` и `dashboard.min.css` по умолчанию
- [ ] Гарантировать наличие `.optimization_applied` на продакшене

---

### 2.2 Фильтры и поиск

**Текущий поток:**
- `filters-modern.js` — URL, chips, syncFormFromUrl
- `dashboard-filters.js` — слайдеры, select, updateFilterURL
- `FilterBuilder.php` — SQL WHERE, двухфазный поиск, fallback LIKE

**Проблемы:**
- Два источника правды для фильтров (форма vs URL) — частично решено через syncFormFromUrl
- Поиск по `id_fan_page_1/2/3` добавлен; `first_cookie` — в CSV

**Рекомендации:**
- [x] Поиск по id_fan_page — реализовано
- [ ] Единый модуль `filters.js` вместо разбросанной логики в filters-modern + dashboard-filters

---

### 2.3 API и эндпоинты

**Текущее состояние:**
- Унифицированный роутер: `api/index.php` (REST: /api/accounts, /api/favorites, /api/filters, /api/settings)
- Legacy-файлы: `api_favorites.php`, `api_saved_filters.php`, `api_user_settings.php`, `api_custom_card.php`, `api_register_status.php`, `api.php`

**Проблемы:**
- Фронтенд всё ещё вызывает legacy-URL вместо `/api/*`
- Дублирование DDL для `account_favorites`, `user_settings` в 6+ местах
- Разная обработка CSRF, rate limit, ошибок

**Рекомендации:**
- [ ] Миграция всех вызовов на `/api/*`
- [ ] Удаление legacy API-файлов после миграции
- [ ] Централизация DDL в `DatabaseSchemaManager`

---

### 2.4 Импорт/экспорт CSV

**Текущий поток:**
- `download_account_template.php` — шаблон из колонок БД
- `import_accounts.php` → CsvParser → AccountsService::createAccountsBulk
- `dashboard-upload.js` — клиентская валидация, предпросмотр

**Проблемы:**
- `Config::CSV_STRUCTURE` — ограниченный набор; шаблон берёт колонки из БД (расхождение)
- Валидация на клиенте и сервере — разная логика (нормализация заголовков должна совпадать)

**Рекомендации:**
- [ ] Синхронизировать `Config::CSV_STRUCTURE` с реальными колонками БД или явно документировать расхождение
- [ ] Добавить `first_cookie` в инструкцию (уже сделано)

---

### 2.5 Массовые операции (перенос, bulk edit, удаление)

**Текущий поток:**
- `mass_transfer.php` — MassTransferService, батчи по 200
- `status_update.php`, `bulk_update_field.php` — AccountsRepository
- `dashboard-modals.js` — clearSelection после успеха (исправлено)

**Проблемы:**
- `set_time_limit(0)` в mass_transfer — корректно
- Lock contention при больших UPDATE — батчи уменьшены до 200

**Рекомендации:**
- [ ] Мониторинг slow log после изменений
- [ ] Опционально: очередь задач для очень больших переносов (10k+)

---

### 2.6 Избранное, сохранённые фильтры, корзина

**Текущее состояние:**
- Избранное: `api_favorites.php` / `/api/favorites`, `favorites.js`
- Сохранённые фильтры: `api_saved_filters.php` / `/api/filters`, `saved-filters.js`
- Корзина: `trash.php`, `empty_trash.php`, soft delete

**Проблемы:**
- Смешение legacy и нового API
- CSRF, LIMIT, валидация — исправлены в предыдущих сессиях

**Рекомендации:**
- [ ] Перевести favorites и saved-filters на `/api/*` полностью
- [ ] Унифицировать обработку ошибок

---

### 2.7 База данных и производительность

**Текущее состояние:**
- `apply_indexes_safe.php` — индексы, redundant index removal, generated column для login
- `StatisticsService` — файловый кэш 90 с
- `FilterBuilder` — `deleted_at IS NULL` первым, двухфазный поиск

**Проблемы:**
- Внешнее приложение (accountfactory) шлёт `WHERE login = NUMBER` → 40+ сек
- 40+ индексов на accounts — возможен over-indexing для UPDATE

**Рекомендации:**
- [ ] Документировать для accountfactory: передавать login как строку
- [ ] Периодически проверять неиспользуемые индексы через Performance Schema
- [ ] Рассмотреть материализованные представления для тяжёлой статистики (если MySQL 8.0+)

---

### 2.8 Безопасность

**Текущее состояние:**
- CSRF — в формах и API
- Rate limiting — на импорт и API
- AuditLogger — чувствительные поля маскируются
- XSS — `e()` в шаблонах

**Проблемы:**
- `Logger::filterSensitiveData` — добавлен `first_cookie`; проверить полноту списка
- Пароли/cookies в логах при debug — убедиться, что продакшен не логирует

**Рекомендации:**
- [ ] Аудит всех `Logger::debug` с контекстом, содержащим чувствительные данные
- [ ] Content-Security-Policy — уже в .htaccess; проверить актуальность

---

### 2.9 Фронтенд (JS/CSS)

**Текущее состояние:**
- 25+ JS-модулей, 15+ CSS
- `build_assets.php` — собирает dashboard.min.js, dashboard.min.css
- Dashboard подключает отдельные файлы, не бандл

**Проблемы:**
- Много HTTP-запросов при загрузке
- Дублирование: `dashboard.js`, `dashboard-inline.js`, `dashboard-init.js` — пересекающаяся логика
- `longFields`, `LONG_FIELDS` — в 7+ файлах

**Рекомендации:**
- [ ] Переключить dashboard.php на бандл (dashboard.min.js, dashboard.min.css)
- [ ] Вынести константы (LONG_FIELDS, QUICK_FILTER_PARAMS) в один config-модуль
- [ ] Удалить неиспользуемые CSS (dashboard-critical, dashboard-non-critical — проверить)

---

### 2.10 Конфигурация и bootstrap

**Текущее состояние:**
- `config.php` — основной загрузчик, создание таблиц
- `bootstrap.php` — альтернативный загрузчик, не используется в index.php
- `.env` — есть, но DB из сессии (login form)

**Проблемы:**
- Два пути инициализации — путаница
- `UserManager` (users.json) — не используется при DB-аутентификации

**Рекомендации:**
- [ ] Унифицировать: либо везде `bootstrap.php`, либо везде `config.php`
- [ ] Удалить или пометить deprecated: `UserManager`, `bootstrap.php` (если не нужен)

---

## 3. Приоритизированный план улучшений

### Фаза 1: Быстрые победы (1–2 дня)

| # | Задача | Файлы | Эффект |
|---|--------|-------|--------|
| 1 | Подключить бандл по умолчанию | `templates/dashboard.php` | Меньше запросов, быстрее загрузка |
| 2 | Удалить мёртвые/неиспользуемые файлы | `test-csv-upload.php`, `debug.php`, `log.php` (если не нужны) | Меньше мусора |
| 3 | Добавить `first_cookie` в инструкцию (если ещё не везде) | add-account-modal | UX |
| 4 | Проверить `.optimization_applied` при деплое | `apply_indexes_safe.php`, `create_optimization_flag.php` | Меньше проверок при каждом запросе |

### Фаза 2: Консолидация API (3–5 дней)

| # | Задача | Файлы | Эффект |
|---|--------|-------|--------|
| 5 | Мигрировать favorites на `/api/favorites` | `favorites.js`, `init-script.php` | Единый API |
| 6 | Мигрировать saved-filters на `/api/filters` | `saved-filters.js`, `init-script.php` | Единый API |
| 7 | Мигрировать user_settings на `/api/settings` | `hidden-cards.js`, `custom-cards.js`, `dashboard-stats.js` | Единый API |
| 8 | Мигрировать custom-card, register-status на `/api/*` | `custom-cards.js`, `init-script.php` | Единый API |
| 9 | Удалить legacy API-файлы | `api_favorites.php`, `api_saved_filters.php`, и т.д. | Меньше дублирования |

### Фаза 3: Чистота кода (5–7 дней)

| # | Задача | Файлы | Эффект |
|---|--------|-------|--------|
| 10 | Вынести init-script в dashboard-init.js | `init-script.php` → `dashboard-init.js` | Кэширование, читаемость |
| 11 | Централизовать DDL в DatabaseSchemaManager | `api/index.php`, `api_favorites.php`, и т.д. | Один источник правды |
| 12 | Унифицировать bootstrap | `config.php` vs `bootstrap.php` | Понятная инициализация |
| 13 | Вынести константы (LONG_FIELDS, QUICK_FILTER_PARAMS) в config | 7+ JS-файлов | DRY |
| 14 | Удалить дублирующий код copyToClipboard/showToast | `view.php`, `config-script.php` | DRY |

### Фаза 4: Производительность и мониторинг (2–3 дня)

| # | Задача | Файлы | Эффект |
|---|--------|-------|--------|
| 15 | Скрипт проверки неиспользуемых индексов | Новый `check_unused_indexes.php` | Оптимизация UPDATE |
| 16 | Документировать рекомендации для accountfactory | `QUERY_PERFORMANCE.md` | Меньше slow log |
| 17 | Опционально: light=1 по умолчанию для части refresh | `dashboard-refresh.js` | Меньше нагрузки |

### Фаза 5: Архитектура (по желанию, 1–2 недели)

| # | Задача | Файлы | Эффект |
|---|--------|-------|--------|
| 18 | Объединить filters-modern + dashboard-filters в один модуль | `assets/js/` | Меньше связей |
| 19 | Ввести единый API-клиент (fetch-обёртка с CSRF, ошибками) | Новый `api-client.js` | Меньше дублирования |
| 20 | Рассмотреть миграцию на PSR-4 autoload | `includes/` | Современная структура |

---

## 4. Чек-лист «идеальный дашборд»

### Код
- [ ] Нет дублирования API-эндпоинтов
- [ ] Один источник правды для DDL
- [ ] Константы и конфиг в одном месте
- [ ] Нет мёртвого кода (UserManager, bootstrap если не используется)
- [ ] Inline-скрипты вынесены в отдельные JS-файлы

### Производительность
- [ ] Бандл JS/CSS используется по умолчанию
- [ ] Индексы применены, `.optimization_applied` создан
- [ ] Файловый кэш для stats, filter values, empty counts
- [ ] light=1 для части refresh где возможно
- [ ] Нет N+1, батчи для массовых операций

### Безопасность
- [ ] CSRF на всех формах и API
- [ ] Rate limiting на импорт и чувствительные операции
- [ ] Чувствительные данные не попадают в логи
- [ ] Валидация и санитизация входных данных

### UX
- [ ] Понятные сообщения об ошибках
- [ ] Инструкции в модалках (CSV, перенос)
- [ ] Сброс выбора после массовых действий
- [ ] Фильтры синхронизированы с URL

---

## 5. Порядок выполнения (рекомендуемый)

1. **Фаза 1** — быстрые победы, минимальный риск
2. **Фаза 2** — миграция API (требует тестирования каждого эндпоинта)
3. **Фаза 3** — рефакторинг (по одной задаче, с проверкой)
4. **Фаза 4** — мониторинг и тонкая настройка
5. **Фаза 5** — по мере необходимости

---

## 6. Метрики успеха

| Метрика | Текущее | Цель |
|---------|---------|------|
| Время загрузки дашборда | ~800 ms | < 400 ms |
| Количество HTTP-запросов при загрузке | 40+ | < 15 |
| Размер JS (до парсинга) | ~500 KB (раздельно) | ~150 KB (бандл, gzip) |
| Количество PHP entry points | ~35 | ~25 (объединить дубли) |
| Количество legacy API файлов | 8 | 0 |
| Строк в init-script | 4200+ | 0 (всё в модулях) |

---

*Документ создан на основе анализа кодовой базы. Обновлять по мере выполнения задач.*

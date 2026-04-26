# Руководство разработчика — Accounts Dashboard

## Структура проекта

```
dashboard/
├── assets/
│   ├── js/
│   │   ├── core/           # logger.js, dom-cache.js, performance.js
│   │   ├── modules/        # dashboard-*.js, table-module.js
│   │   └── *.js            # standalone скрипты (quick-search, favorites, trash…)
│   ├── css/
│   └── build/              # Минифицированные сборки
├── includes/
│   ├── Database.php        # Singleton для доступа к БД
│   ├── AccountsRepository.php
│   ├── AccountsService.php
│   ├── FilterBuilder.php
│   ├── ColumnMetadata.php
│   ├── StatisticsService.php
│   └── ...
├── templates/
│   ├── dashboard.php       # Каркас страницы
│   └── partials/
│       ├── dashboard/
│       │   ├── config-script.php
│       │   ├── init-script.php
│       │   └── modals/
│       └── table/
├── api/                    # API endpoints (роутинг)
├── sql/                    # Миграции и индексы
├── config.php
├── auth.php
└── index.php
```

## Работа с базой данных

### Единая точка доступа

Все операции с БД выполняются через `Database::getInstance()`:

```php
$mysqli = Database::getInstance()->getConnection();

// Prepared statement
$stmt = $mysqli->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();

// Или через prepare() для SELECT
$rows = Database::getInstance()->prepare("SELECT * FROM accounts WHERE status = ?", [$status]);
```

### Важно

- `config.php` и `auth.php` создают подключение; остальные файлы используют `Database::getInstance()`.
- Не используйте `global $mysqli` в новом коде.

## JavaScript модули

### Порядок загрузки скриптов

1. `config-script.php`, `init-script.php`
2. `logger.js`, `dom-cache.js`, `performance.js`
3. `dashboard-refresh.js`
4. `dashboard-selection.js`, `dashboard-filters.js`, `dashboard-stats.js`, `dashboard-modals.js`, `dashboard-main.js`
5. `sticky-scrollbar.js`, `table-module.js`, `toast.js`, `filters-modern.js`
6. `dashboard-upload.js`, `dashboard.js`, `validation.js`, `quick-search.js`, `saved-filters.js`, `favorites.js`

### Ключевые модули

| Модуль | Назначение |
|--------|------------|
| `dashboard-main.js` | Координация инициализации модулей |
| `dashboard-selection.js` | Выбор строк, selectedIds, selectAll |
| `dashboard-filters.js` | Фильтры, слайдеры pharma/friends |
| `dashboard-refresh.js` | refreshDashboardData, collectRefreshParams, setTableLoadingState |
| `dashboard-stats.js` | Скрытие/показ карточек статистики |
| `table-module.js` | Виртуализация, рендеринг строк |
| `dom-cache.js` | Кеширование DOM-элементов |
| `performance.js` | debounce, batchDOM, BatchUpdater |
| `logger.js` | Логирование (вместо console) |

### Глобальные объекты

- `window.DashboardSelection` — выбор строк
- `window.DashboardFilters` — фильтры, слайдеры
- `window.refreshDashboardData` — обновление данных
- `window.domCache` — кеш DOM
- `window.logger` — логирование

## Настройка окружения

1. `.env` или `config.local.php` — параметры БД.
2. `DEBUG` в config.php — включение отладочных логов.
3. `php apply_indexes_safe.php` — применение индексов БД.

## Добавление новой фичи

1. **Backend:** Используйте `AccountsService`, `FilterBuilder`, `Database::getInstance()`.
2. **Frontend:** Создайте модуль в `assets/js/modules/` или используйте `dashboard-main.js` для координации.
3. **API:** Добавьте endpoint в `api/index.php` или отдельный `api_*.php`.
4. **Логирование:** Используйте `logger.debug/warn/error` вместо `console.log`.

## Полезные ссылки

- [REFACTORING_REPORT.md](REFACTORING_REPORT.md) — ход рефакторинга
- [PERFORMANCE_ANALYSIS_REPORT.md](PERFORMANCE_ANALYSIS_REPORT.md) — анализ производительности

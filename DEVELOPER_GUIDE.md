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
│   ├── Csv.php             # ЕДИНСТВЕННОЕ место записи CSV (RFC 4180)
│   ├── CsvParser.php       # чтение CSV, включая ручной парсер для PHP < 7.4
│   ├── ErrorHandler.php    # выбор HTTP-кода и формата ответа на исключение
│   └── ...
├── templates/
│   ├── dashboard.php       # Каркас страницы
│   └── partials/
│       ├── dashboard/
│       │   ├── config-script.php
│       │   ├── init-script.php
│       │   └── modals/
│       └── table/
├── api/                    # API endpoints
│   ├── index.php           # Единая точка входа, регистрирует маршруты
│   └── routes/             # Группы маршрутов: accounts, favorites, settings, filters, status
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

Список ниже — не «примерно», а фактический порядок `<script src=...>` из
`templates/dashboard.php` (сверено 2026-08-08; проверять так:
`grep -oE '<script src="[^"]*assets/js/[^"?]*' templates/dashboard.php`).
Порядок важен: модуль, который подписывается на чужой API, обязан грузиться
после того, кто этот API объявляет.

1. `core/logger.js`, `core/dom-cache.js`, `core/performance.js`
2. `modules/dashboard-refresh.js` — объявляет `refreshDashboardData` и
   `DashboardRefresh.onAfterRefresh`
3. `pagination.js`
4. `modules/dashboard-selection.js`, `modules/dashboard-export.js`,
   `modules/dashboard-filters.js`, `modules/dashboard-stats.js`,
   `modules/dashboard-modals.js`, `modules/dashboard-validate.js`,
   `modules/dashboard-main.js`
5. `sticky-scrollbar.js`, `table-module.js`, `toast.js`
6. `modules/undo.js`, `modules/cell-actions.js` — undo подписывается на
   `onAfterRefresh`, поэтому идёт после `dashboard-refresh.js`
7. `filters-modern.js`, `modules/dashboard-upload.js`, `dashboard.js`,
   `validation.js`, `quick-search.js`, `saved-filters.js`
8. `favorites.js` — тоже подписчик `onAfterRefresh`
9. `modules/cards-hide-sync.js`, `density-toggle.js`, `per-page.js`,
   `theme-toggle.js`
10. `dashboard-init.js` — последним; в нём же живут кастомные карточки статистики

### Обновление таблицы: подписка, а не обёртка

Нужно что-то сделать после обновления данных — подписывайся, не оборачивай
глобальную функцию:

```js
window.DashboardRefresh.onAfterRefresh(() => {
  // вызывается и при успехе, и при ошибке обновления;
  // падение обработчика логируется и не мешает остальным
});
```

Раньше модули переопределяли `window.refreshDashboardData` своей обёрткой —
цепочка зависела от порядка загрузки, а одна из обёрток глотала `AbortError`
всей цепочки. Так больше не делаем.

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

1. Подключение к БД задаётся через форму логина (`login.php` → сессия `db_config`).
2. `DEBUG` в `config.php` — включение отладочных логов.
3. `php tools/migrations/apply_indexes_safe.php` — применение индексов БД.

## Добавление новой фичи

1. **Backend:** Используйте `AccountsService`, `FilterBuilder`, `Database::getInstance()`.
2. **Frontend:** Создайте модуль в `assets/js/modules/` или используйте `dashboard-main.js` для координации.
3. **API:** Добавьте маршрут в `api/routes/<resource>.php` (роутер — `api/index.php`).
4. **Логирование:** Используйте `logger.debug/warn/error` вместо `console.log`.

## Совместимость с PHP

**Прод работает на PHP старее 7.4** (доказано: стрелочная функция `fn() =>`
давала там Parse error). Поэтому:

- не используйте синтаксис новее 7.3 — ни `fn() =>`, ни `??=`, ни
  типизированных свойств, ни `[$a, $b] = ...`;
- то же относится к тестам: тест, который не парсится на 7.3, врёт о
  совместимости;
- гейт `lint` линтует на 7.3 и гоняет тесты на 7.3 и 8.2.

Различия версий, на которые уже наступали:
`count()` без аргумента — Warning на 7.x и фатальная ошибка на 8.x;
`fgetcsv/fputcsv` умеют `escape=''` только с 7.4, поэтому `Csv`/`CsvParser`
держат ручные реализации для старых версий.

## Выгрузки CSV

Пишите строки **только** через `Csv::writeRow()` — не через `fputcsv()`.
У `fputcsv` по умолчанию `escape='\'`, и значения с обратными слэшами
(cookies, token, пути) не переживают round-trip. Раньше эта обёртка была
скопирована в двух файлах, а третий писал напрямую и портил выгрузку истории.

`Csv::sanitizeCell()` защищает от формул Excel, но **меняет содержимое ячейки** —
применяйте только там, где выгрузка предназначена человеку, а не для обратного
импорта.

## Полезные ссылки

- [PROJECT_AUDIT.md](PROJECT_AUDIT.md) — аудит безопасности и качества кода
- [DEPLOY.md](DEPLOY.md) — деплой через GitHub Actions / FTP

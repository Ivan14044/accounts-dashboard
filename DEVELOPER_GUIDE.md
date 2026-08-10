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

### Порядок загрузки скриптов и бандлы

**Единственный источник правды — `includes/AssetBundles.php`.** Там лежит список
файлов каждого бандла; порядок в списке = порядок выполнения. Раньше этот порядок
жил в разметке двух шаблонов, а описание здесь успело разойтись с кодом (в списке
значилось «`dashboard-init.js` — последним», хотя фактически он грузился седьмым,
до `core/*`). Не переписывай список сюда — смотри манифест.

Что важно знать, не открывая манифест:

- Бандлов три: `core.css` (весь свой CSS, общий для дашборда, избранного и
  корзины), `dashboard.sync.js` (26 обычных скриптов) и `dashboard.defer.js`
  (9 скриптов с `defer`). Разделение sync/defer обязательное: `defer`-скрипты
  выполняются после всех обычных, свалить их в один бандл — поменять порядок.
- Инлайновые `<script>` (`partials/dashboard/config-script.php` и
  `init-script.php`) остаются на своих местах и выполняются ДО бандлов. Они
  объявляют `window.__DASHBOARD_CONFIG__` и `window.DashboardConfig`, а
  `modules/constants.js` читает `DashboardConfig.activeFiltersCount` в момент
  загрузки: окажись бандл выше инлайна — `ACTIVE_FILTERS_COUNT` молча станет нулём.
- `core/logger.js`, `core/dom-cache.js`, `core/performance.js` идут ПЕРВЫМИ.
  Они объявляют `logger`, `domCache`, `batchUpdater` через top-level `const`, а
  в конкатенации такие объявления попадают в temporal dead zone на весь бандл:
  привычная защита `typeof domCache !== 'undefined'` в файле выше не вернёт
  `'undefined'`, а бросит `Cannot access 'domCache' before initialization` и
  оборвёт весь бандл. Инвариант «объявлено раньше, чем использовано» стережёт
  `tests/test_asset_bundles.php`.
- Модуль, который подписывается на чужой API, обязан грузиться после того, кто
  этот API объявляет (`modules/undo.js` и `favorites.js` подписаны на
  `DashboardRefresh.onAfterRefresh` из `modules/dashboard-refresh.js`).

Как собрать бандлы локально и как их убрать:

```bash
docker run --rm -v "$PWD":/app -w /app php:7.3-cli php tools/build_assets.php
```

```bash
docker run --rm -v "$PWD":/app -w /app php:7.3-cli php tools/build_assets.php --clean
```

Шаблон подключает бандл, только если файл реально существует, иначе отдаёт
исходники по одному. Поэтому локальный стенд работает и без сборки, а на проде
недоехавший бандл не ломает страницу. **Грабли:** собранный бандл сам не
пересобирается — поправил JS и не видишь изменений, запусти `--clean` или
пересобери. В проде этого нет: бандлы собираются в GitHub Actions на каждый деплой.

Добавляешь новый JS-файл — добавь его в манифест, а не `<script src>` в шаблон:
`tests/test_asset_bundles.php` падает, если шаблон подключает бандлируемый ассет
мимо `AssetBundles`.

### Слой редизайна: где менять внешний вид панели

Внешний вид всей панели задаётся одним файлом — `assets/css/redesign.css`.
Он подключается **последним**: в бандле `core.css` (дашборд, избранное, корзина)
и отдельной ссылкой на страницах вне бандла (`view.php`, `log.php`,
`history.php`, `admin_logs.php`, `admin_duplicates.php`).

Внутри — сначала токены (`--gray-*`, `--primary-*`, статусные, тени, радиусы,
шрифты) для светлой и тёмной темы, потом точечные правила компонентов. Хочешь
поменять палитру всей панели — правь токены в начале файла, а не отдельные
правила: `core-*.css` почти целиком построен на переменных, поэтому смена
токена доходит до всех страниц сразу.

**Грабли, на которые этот слой уже наступил:**

1. **`!important` в `core-dark.css`.** Часть правил тёмной темы написана с
   `!important` (`.btn-primary` и компания). Перекрыть их можно только тем же
   `!important` — в слое это единственные такие места, и каждое подписано.
2. **Белый текст на «основном» цвете.** В тёмной теме `--primary-600` стал
   бумажным, а `core-*` местами кладёт на него `color:#fff`. Получалось белое по
   белому. Все такие места перечислены в слое явно; если добавляешь новый
   элемент с заливкой `var(--primary-*)`, проверь цвет текста на обеих темах.
3. **Страницы со своими стилями.** `admin_logs.php`, `admin_duplicates.php`,
   `view.php`, `history.php`, `log.php`, `loading.php` держат стили в
   `<style>` внутри себя. Инлайновый блок сильнее подключённого файла, поэтому
   слой их не достаёт — цвета там переведены на токены прямо в странице.
   Пишешь новую страницу — бери токены, а не хекс-коды, иначе она выпадет из
   темы ровно так же.

**Как проверять.** Глазами мало: низкий контраст на тёмной теме не бросается в
глаза, пока не сядешь читать. Быстрый обход DOM в консоли — считает контраст
каждой надписи и показывает нарушения AA:

```js
document.querySelectorAll('body *').length && (function(){var bad=[];document.querySelectorAll('body *').forEach(function(el){var t='';el.childNodes.forEach(function(n){if(n.nodeType===3)t+=n.nodeValue.trim()});if(t.length<2||!el.offsetParent)return;var cs=getComputedStyle(el);bad.push({el:el.className,color:cs.color,size:cs.fontSize})});return bad.length})()
```

Полная версия скрипта (с расчётом яркости и поиском фактического фона) —
в истории PR редизайна; она обошла все страницы в обеих темах и довела число
нарушений до нуля.

### Страница входа живёт отдельно от системы стилей дашборда

`login.php` — единственная страница для неавторизованного человека, и она
**намеренно** не подключает ни `core-*.css`, ни бандлы, ни Bootstrap с
FontAwesome. У неё свои файлы:

| Файл | Что внутри |
|------|-----------|
| `assets/css/login.css` | свои токены с префиксом `--lg-`, обе темы, вся вёрстка |
| `assets/js/login.js` | тема, показания разбора строки, приватный режим, отправка |

Почему так: ради одной формы тянулось ~250 КБ правил дашборда, а любая правка
общих токенов могла молча поехать на экране логина. Иконки — инлайновый SVG,
поэтому страница делает всего два своих запроса (CSS + JS) плюс шрифты.

Тема переключается тем же атрибутом `[data-bs-theme]` и тем же ключом
localStorage `dashboard-theme`, что и на дашборде, — выбор на логине
переносится внутрь панели, и наоборот.

**Грабли, которые стережёт тест.** `login.js` разбирает строку подключения
второй раз (первый — `parseConnectionString()` в `auth.php`), чтобы показать
хост/порт/базу/пользователя ещё до отправки. Это копия знания: добавь синоним
ключа только в PHP — и панель начнёт показывать прочерк там, где сервер строку
понял. Наборы ключей сверяет `tests/test_login_conn_keys.php` (`KEY_MAP` и
`IGNORED_KEYS` в JS против `case` в `auth.php`). Пароль в показания не
выводится никогда — это тоже проверяется.

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
| `touch-gestures.js` | Свайп влево по карточке статистики (мобильные) |
| `dom-cache.js` | Кеширование DOM-элементов |
| `performance.js` | debounce, batchDOM, BatchUpdater |
| `logger.js` | Логирование (вместо console) |

### Чем помечены карточки статистики

Атрибуты у карточек разные, и это стык, на котором уже ломался свайп:

| Откуда карточка | Атрибуты |
|-----------------|----------|
| `templates/partials/dashboard/stats-cards.php` | `data-card="total"`, `data-card="status:<ключ>"` + `data-status="<статус>"`, `data-card="empty_status"` |
| `assets/js/modules/custom-cards.js` (создаёт в рантайме) | `data-card="custom:<ключ>"`, `data-card-type="custom"`, `data-card-key="<ключ>"` |

`data-card-type` есть **только** у кастомных карточек — сервер его не рендерит.
Фильтровать по статусу нужно по `data-status`: в `data-card` лежит ключ,
безопасный для CSS-селектора (всё, кроме `[a-z0-9_]`, схлопнуто в `_`), а не имя
статуса. Стык стережёт `tests/test_card_swipe_contract.php`.

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

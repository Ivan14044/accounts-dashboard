# План рефакторинга и оптимизации фронтенда панели

## 1) Причины медленной загрузки (краткий список)

| Причина | Где | Влияние |
|--------|-----|--------|
| **Огромный inline-скрипт** | `templates/partials/dashboard/init-script.php` (~4084 строки) | Не кэшируется, блокирует парсинг HTML, выполняется при каждой загрузке. Основной фактор задержки. |
| **Дублирование скриптов** | `dashboard.php`: при отсутствии бандла `filters-modern.js` подключается дважды (строки 2860 и 2874). | Лишний парсинг и выполнение. |
| **Много отдельных запросов** | При отсутствии `dashboard.min.js` загружается 18+ отдельных JS-файлов. | Много round-trip, блокирующая загрузка. |
| **Бандл неполный** | При наличии бандла всё равно подключаются: `init-script.php` (inline), `dashboard.min.js`, `filters-modern.js`. В бандл не входят core, modules, table-module, toast и др. | Inline и часть логики остаются «тяжёлыми» при каждой загрузке. |
| **CDN без preload** | Bootstrap и noUiSlider с CDN без `preload`/`modulepreload`. | Задержка первого запроса к CDN. |
| **Критический CSS в шаблоне** | `dashboard.php` содержит сотни строк inline-стилей (тема, утилиты, print). | Увеличение размера HTML и времени до FCP. |
| **Нет разбиения по критичности** | Всё загружается синхронно до интерактивности: модалки, кастомные карточки, загрузка файлов, избранное. | Долгий Time to Interactive. |
| **Глобальное состояние и зависимости** | Модули зависят от глобальных `getElementById`, `getSel`, `logger`, `showToast`, инициализация размазана по inline и файлам. | Сложно выносить куски и тестировать, возможны лишние ререндеры/обработчики. |

Бэкенд (запросы к БД, индексы, кэш, `loading.php`) уже оптимизирован ранее; здесь фокус на фронтенде.

---

## 2) Целевая структура проекта (vanilla JS)

Оставляем стек: PHP (серверный рендер) + ванильный JavaScript без перехода на React/Vue. Целевая структура:

```
assets/
  js/
    core/                    # Уже есть: logger, dom-cache, performance
      logger.js
      dom-cache.js
      performance.js
    api/                     # NEW: обёртки над fetch (refresh, settings, favorites)
      refresh.js
      settings.js
      favorites.js
    store/                   # NEW: единое место состояния (selectedIds, filteredTotal, hiddenCards)
      dashboard-state.js
    features/                # NEW: фичи как модули (один файл = одна фича)
      filters/
      selection/
      stats-cards/
      custom-cards/
      table/
      modals/
    components/              # NEW: переиспользуемые UI-куски (Toast уже есть)
      toast.js
    utils/                   # NEW: copyToClipboard, getElementById, getSel, urlParams
      dom.js
      clipboard.js
    bootstrap.js             # NEW: один entry — инициализация по DOMContentLoaded
    dashboard-init.js        # Минимум: конфиг + вызов bootstrap
build/
  dashboard.min.js           # Собранный бандл (core + api + store + utils + features)
  dashboard-critical.min.js # Только для первого рендера (таблица, фильтры, refresh)
```

- **Dumb (UI):** компоненты, которые только отображают данные и вызывают колбэки (карточки, кнопки, чипы фильтров).
- **Smart (logic):** модули в `features/` и `api/` — состояние, запросы, координация (selection, refresh, custom-cards, modals).

Текущие `modules/dashboard-*.js` и логика из `init-script.php` постепенно переносятся в `features/` и `api/`, с сохранением глобальных имён (`window.DashboardSelection`, `window.refreshDashboardData`) для обратной совместимости.

---

## 3) Конкретные изменения по файлам (план переносов)

| Что | Откуда | Куда | Зачем |
|-----|--------|------|--------|
| Копирование в буфер, showToast fallback | init-script.php (строки ~1–115) | assets/js/utils/clipboard.js, toast уже в toast.js | Убрать дубли, вынести утилиты. |
| Константы LS_KEY_*, ACTIVE_FILTERS_COUNT | init-script.php | store/dashboard-state.js или config в config-script.php | Один источник правды. |
| Логика кастомных карточек (load/save/render, hexToRgb) | init-script.php (~2225–2500+) | features/custom-cards/custom-cards.js | Изоляция фичи, возможность отложенной загрузки. |
| Обработчики кликов по карточкам статусов, adjustForMobile, loadHiddenCards | init-script.php | features/stats-cards/card-actions.js + stats (скрытие уже в dashboard-stats.js) | Меньший inline. |
| Остальная логика в init-script (таблица, форма фильтров, пагинация, модалки) | init-script.php | Разнести по features/table, features/filters, features/modals и оставить в init только вызов инициализации (или убрать в bootstrap.js) | Понятные границы, тестируемость. |
| refreshDashboardData, collectRefreshParams, setTableLoadingState | Уже в dashboard-refresh.js | Оставить, при рефакторинге переименовать в api/refresh.js и экспорт в window | Единый слой API. |
| Дубликат filters-modern.js | dashboard.php (второй тег) | Удалить второй тег | Меньше парсинга. |
| Inline init-script | init-script.php | Заменить на один внешний скрипт `dashboard-init.js` (или два: critical + deferred), подключаемый с `defer` | Кэширование, неблокирующий парсинг. |

После переносов `init-script.php` должен содержать только передачу конфига из PHP в `window.__DASHBOARD_CONFIG__` (или только подключение внешнего `dashboard-init.js`), без тысяч строк логики.

---

## 4) Проверяемые сценарии (после каждого этапа)

- **Загрузка:** открыть дашборд (с фильтрами и без) — страница открывается, нет ошибок в консоли.
- **Таблица:** строки отображаются, пагинация переключается, сортировка по заголовку работает.
- **Фильтры:** применение фильтров (статус, поиск, pharma/friends) обновляет таблицу; сброс фильтров работает.
- **Карточки статистики:** клик по карточке применяет фильтр и обновляет таблицу; скрытие/показ карточек в настройках сохраняется.
- **Кастомные карточки:** создание/редактирование/удаление, переключение видимости, клик по карточке — поведение как раньше.
- **Выбор строк:** чекбоксы строк, «Выбрать все на странице», счётчик выбранных, массовые действия (если есть).
- **Модалки:** открытие/закрытие ячейки, массовое редактирование, удаление, перенос, настройки, импорт — без ошибок.
- **Обновление данных:** кнопка «Обновить» / refresh обновляет таблицу и статистику.
- **Загрузка файла:** импорт CSV — выбор файла, отправка, отображение результата.
- **Избранное, сохранённые фильтры:** добавление в избранное, сохранение/применение фильтра.

---

## 5) Что можно улучшить дальше

- **Виртуализация таблицы:** при большом числе строк (например, 500+) рендерить только видимые строки (уже есть заделы в table-module.js).
- **Lazy-load модалок:** подгружать скрипт модалок (или только тяжёлые) по первому открытию.
- **Сборка через Vite/Rollup:** один бандл с code-splitting (critical vs optional), минификация, tree-shaking.
- **Переход на TypeScript:** типы для конфига, состояния и API — меньше поломок при рефакторинге.
- **Миграция на React/Vue:** отдельный большой этап; текущий план не предполагает смену фреймворка.

---

---

## 6) Выполнено (Фаза 1)

- **Конфиг:** в `config-script.php` добавлены `filteredTotal`, остальное уже было (`csrfToken`, `activeFiltersCount`, `sort`, `dir`, `allColumnKeys`).
- **Вынос inline:** весь код из `init-script.php` (~4084 строки) перенесён во внешний файл `assets/js/dashboard-init.js`. PHP-вставки заменены на чтение из `window.__DASHBOARD_CONFIG__`.
- **Шаблон:** в `dashboard.php` вместо `<?php include init-script.php ?>` подключён `<script src="assets/js/dashboard-init.js" defer></script>`. Добавлен `<link rel="preload" href="...dashboard-init.js" as="script">` в `<head>` для ранней загрузки.
- **Эффект:** инициализация кэшируется браузером, не блокирует парсинг HTML (defer), preload ускоряет начало загрузки скрипта.

Файл `templates/partials/dashboard/init-script.php` оставлен в репозитории для отката; в рендере страницы не используется.

---

## 7) Выполнено (Фаза 2 — модули)

- **Модули:** созданы `dashboard-init-constants.js`, `utils/dom.js`, `utils/clipboard-toast.js`, `features/settings-columns-cards.js`, `features/hidden-cards.js`. Логика скрытия карточек и настроек колонок/карточек вынесена из `dashboard-init.js`.
- **dashboard-init.js:** в начале только алиасы на `window.*` (getElementById, getSel, LS_KEY_*, loadHiddenCards, loadHiddenCardsFromLocalStorage, saveHiddenCards, hideCard, showCard, loadSettings, saveSettings, toggleColumnVisibility, applySavedColumnVisibility, toggleCardVisibility). Дублирующие определения удалены.
- **Порядок скриптов в dashboard.php:** config-script → dashboard-init-constants → utils/dom → utils/clipboard-toast → features/settings-columns-cards → features/hidden-cards → dashboard-init (все с defer).

**Проверка по коду:** экспорты в модулях соответствуют использованию в `dashboard-init.js`; порядок загрузки корректен (toggleCardVisibility задаётся в settings-columns-cards до hidden-cards).

---

## 8) Ручная проверка (раздел 4)

Полная проверка требует окружения с PHP и расширением mysqli (например OpenServer, XAMPP или `php -S` с подключённым mysqli). Чек-лист:

| # | Сценарий | Действие |
|---|----------|----------|
| 1 | Загрузка | Открыть дашборд (с фильтрами и без). Консоль браузера — без ошибок. |
| 2 | Таблица | Пагинация, сортировка по заголовку — работают. |
| 3 | Фильтры | Применение и сброс фильтров обновляют таблицу. |
| 4 | Карточки | Клик по карточке → фильтр; в настройках скрытие/показ карточек сохраняется. |
| 5 | Кастомные карточки | Создание/редактирование/удаление, переключение видимости, клик. |
| 6 | Выбор строк | Чекбоксы, «Выбрать все на странице», счётчик, массовые действия. |
| 7 | Модалки | Ячейка, массовое редактирование, удаление, перенос, настройки, импорт. |
| 8 | Обновление | Кнопка «Обновить» обновляет таблицу и статистику. |
| 9 | Импорт CSV | Выбор файла, отправка, результат. |
| 10 | Избранное и фильтры | Добавление в избранное, сохранение/применение фильтра. |

---

## 9) Выполнено (дополнительно)

- **Быстрые фильтры:** исправлено применение — при переключении быстрых фильтров (Email, 2FA и т.д.) таблица не обновлялась. Теперь `applyFormFiltersWithoutReload` передаёт в `refreshDashboardData` явные параметры (`options.params`), собранные из формы; поддержка `options.params` добавлена в `dashboard-refresh.js`, `dashboard.js`, `dashboard-inline.js`. При снятии быстрого фильтра через чип явно вызывается `applyFormFiltersWithoutReload`, т.к. `change` на чекбоксах не всплывает из‑за `stopPropagation`.

---

## 10) Выполнено (Фаза 2 — custom-cards)

- **Модуль:** создан `assets/js/features/custom-cards.js` с логикой кастомных карточек: `hexToRgb`, `loadCustomCardsFromStorage`, `loadCustomCardsFromLocalStorage`, `saveCustomCardsToStorage`, `renderCustomCardsSettings`, `renderCustomCardsOnDashboard`, `refreshCustomCardCounts`, `createCustomCard`, `registerMissingStatuses`, `initializeCustomCards`. Все экспортируются на `window`.
- **dashboard-init.js:** блок «КАСТОМНЫЕ КАРТОЧКИ СТАТИСТИКИ» (~670 строк) удалён, оставлен комментарий-ссылка на модуль. Вызовы `initializeCustomCards()` и `loadCustomCardsFromLocalStorage()` остаются в init — используют глобальные функции из модуля.
- **Шаблон:** в `dashboard.php` перед `dashboard-init.js` подключён `features/custom-cards.js` (defer).

Дальше: при необходимости — вынос в отдельные модули фич stat-editing, table/pagination, auto-refresh (разделы 2–3).

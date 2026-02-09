## Context
Таблица аккаунтов отображает 20+ колонок, поддерживает сортировку, фильтры, выделение чекбоксов, избранное, массовые действия, виртуализацию и sticky колонны. Текущая реализация состоит из одного громоздкого шаблона и множества CSS/JS файлов с конфликтующими стилями.

## Goals / Non-Goals
- Goals: создать модульную верстку таблицы, устранить дублирование CSS, унифицировать JS API, обеспечить адаптивность и стабильность sticky/virtualization.
- Non-Goals: изменение бэкенд API, переработка данных accounts, внедрение новых UI-фреймворков.

## Decisions

### HTML структура
1. **Контейнер верхнего уровня**
   ```html
   <section class="dashboard-table" id="accountsTableSection" data-module="accounts-table">
     <div class="dashboard-table__toolbar">...</div>
     <div class="dashboard-table__viewport">
       <header class="dashboard-table__header">...</header>
       <div class="dashboard-table__scroll" role="region" aria-label="Список аккаунтов">
         <table class="ac-table" id="accountsTable" role="grid"></table>
       </div>
       <footer class="dashboard-table__footer">...</footer>
     </div>
   </section>
   ```
   - Toolbar: фильтры, счетчики, массовые действия, кнопки density.
   - Header: sticky панель с quick-фильтрами, индикаторами сортировки, дублирующим горизонтальным скроллом.
   - Scroll: единственная зона с overflow; содержит таблицу и sticky scrollbar.
   - Footer: пагинация, summary, массовые операции.

2. **Разбиение таблицы**
   - `<colgroup>` генерируется из `$ALL_COLUMNS` (фиксированные ширины для checkbox/id/favorite/actions).
   - `<thead>` содержит один `<tr>`, каждая `<th>` имеет `data-column`, `data-sort`, `aria-sort`.
   - `<tbody>` генерируется сервером; строки получают `data-row-id`, `role="row"`, состояние selection.
   - Пустое состояние отображается отдельным компонентом `.dashboard-table__empty`.

3. **Data-атрибуты**
   - `data-column-type="sticky-left|sticky-right|normal"`.
   - `data-column-role="checkbox|id|favorite|actions|data"`.
   - `data-row-selected="true"` для выбранных строк.
   - Toolbar кнопки получают `data-action` (`bulk-select`, `refresh`, `density`, т.д.).

### CSS архитектура
1. **Файлы**
   - `assets/css/table-core.css` — макет, периметр таблицы, sticky сетка, responsive.
   - `assets/css/table-theme.css` — цвета, эффекты, тени, анимации (подключается после core).
   - Legacy стили (`table-layout.css`) будут удалены после миграции.
2. **Custom Properties**
   - Цвета: `--table-bg`, `--table-border`, `--table-hover`, `--table-selected`.
   - Размеры: `--row-height`, `--toolbar-height`, `--col-checkbox`, `--col-id`, `--col-favorite`, `--col-actions`.
   - Z-index: `--z-header`, `--z-sticky-left-1`, `--z-sticky-left-2`, `--z-sticky-right`.
3. **BEM-классы**
   - `.dashboard-table`, `__toolbar`, `__viewport`, `__scroll`, `__footer`, модификаторы (`--loading`, `--virtualized`, `--density-compact`).
   - `.ac-table`, `__cell`, `__cell--sticky`, `__cell--favorite`, `__cell--actions`.
4. **Responsive правила**
   - ≥1200px: все sticky активны, ширины по переменным.
   - 992–1199px: отключаем sticky для favorite, уменьшаем padding.
   - ≤768px: убираем sticky, чекбоксы вносим в overflow меню, включаем горизонтальный скролл с indicators.
5. **Feedback**
   - Анимации hover/selection, индикаторы сортировки (`.sort-indicator`), loader `.dashboard-table__loader`.

### JS архитектура
1. **TableModule**
   ```js
   class TableModule {
     constructor(root, options) { ... }
     init() { ... }
     refresh(payload) { ... }
     destroy() { ... }
   }
   window.tableModule = new TableModule(
     document.querySelector('[data-module="accounts-table"]'),
     { virtualization: { threshold: 80, buffer: 15 }, sticky: true }
   );
   ```
2. **Подмодули**
   - `LayoutManager` — рассчитывает ширины, управляет density, синхронизирует sticky scrollbar.
   - `SortManager` — перехватывает клики по `data-sort`, вызывает fetch (`refresh.php`), обновляет `aria-sort`.
   - `VirtualScroller` — использует существующую логику, но принимает `tbody`, `rowTemplate`, `spacers`.
   - `SelectionManager` — хранит выбранные ID, обновляет чекбоксы, тулбар, bulk actions, эмитит `table:selection-change`.
   - `StickyScrollbar` — подключается к `LayoutManager`, синхронизируется с основным scroll.
3. **Event bus**
   - TableModule использует `CustomEvent` (`table:sorted`, `table:rows-updated`, `table:virtualization`, `table:auto-refresh`).
   - Внешние скрипты (filters, dashboard.js) подписываются на события вместо прямого DOM доступа.
4. **Data Source**
   - Обертка `TableDataSource` над `fetch('refresh.php')` управляет AbortController, кешированием запросов и обработкой ошибок (toast, retry).
5. **Fallback**
   - Если `TableModule` не инициализировался (нет JS), все серверные ссылки работают как сейчас: сортировка/пагинация через GET, чекбоксы без selection manager.

### Accessibility
- `<table role="grid" aria-rowcount="filteredTotal">`.
- Заголовки получают `scope="col"`, `aria-sort`.
- Checkbox header: `aria-label="Выделить все аккаунты"`, `aria-checked="mixed"` при частичном выборе.
- Строки: `tabindex="0"` для навигации, `aria-selected`.
- Buttons в actions-колонке получают точные `aria-label`.

### Server-side helpers
- В `templates/partials/table/` создаем файлы `toolbar.php`, `header.php`, `body.php`, `footer.php`.
- PHP функции:
  - `renderTableColumns($columns)` → `<colgroup>` + `<th>`.
  - `renderTableRow($record)` → единообразный шаблон строки.
  - `renderEmptyState($filteredTotal)` → карточка пустого списка.

### Data flow
1. Сервер рендерит базовую таблицу (без JS).
2. `DOMContentLoaded` → инициализация TableModule.
3. Сортировка: клик по `data-sort` → `TableDataSource.fetch(params)` → `TableModule.refresh(data)` → `VirtualScroller.refresh()` → `SelectionManager.reset()`.
4. Автообновление вызывает `tableModule.refresh()` вместо прямого DOM манипулирования.
5. Массовые действия подписываются на `table:selection-change`.

### Migration Plan
1. Создать новую разметку и стили параллельно (без удаления старых) внутри feature flag.
2. Переключить шаблон на новую таблицу и удалить legacy стили/скрипты.
3. Пересобрать assets и обновить документацию.
4. Провести smoke-тесты во всех браузерах.

## Risks / Trade-offs
- Большой объем работы: требуется поэтапное внедрение (progressive enhancement).
- Возможные регрессии в массовых действиях из-за переписывания selection-логики — нужны unit/integration тесты.
- Временное дублирование CSS/JS до удаления старых файлов.

## Open Questions
- Нужны ли дополнительные колонки/виды сортировки в новой версии?
- Должны ли сохраняться пользовательские скрытые колонки/порядок?
- Требуется ли поддержка drag-n-drop для rearrange колонок?

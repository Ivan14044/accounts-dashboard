## ADDED Requirements

### Requirement: Dashboard Table Layout Structure
Dashboard MUST render the accounts table inside a dedicated .dashboard-table container that isolates toolbar, header controls, table head/body and footer actions.

#### Scenario: Render base table shell
- **WHEN** пользователь открывает dashboard
- **THEN** система отображает контейнер с зонами toolbar, sticky header и body
- **AND** таблица содержит все предусмотренные колонки (checkbox, ID, favorite, основные поля, actions)

### Requirement: Stable Sticky Columns
Checkbox, ID, favorite и actions колонки MUST remain sticky with strict z-index order and responsive fallbacks.

#### Scenario: Desktop sticky behavior
- **WHEN** ширина экрана ≥ 1200px и пользователь скроллит по горизонтали
- **THEN** checkbox/ID/favorite остаются прижатыми слева, actions — справа
- **AND** контент не перекрывается и не мерцает

#### Scenario: Mobile fallback
- **WHEN** ширина экрана ≤ 768px
- **THEN** sticky колонки отключаются автоматически, чтобы освободить ширину

### Requirement: Unified TableModule API
JavaScript слой MUST expose window.tableModule с методами init, efresh, destroy, а также событиями для сортировки, виртуализации, выбора строк и автообновления.

#### Scenario: Sorting integration
- **WHEN** пользователь кликает по заголовку сортировки
- **THEN** TableModule перехватывает событие, запрашивает данные через AJAX и обновляет DOM без нарушения верстки

#### Scenario: Virtualization refresh
- **WHEN** после AJAX обновления количество строк > порог
- **THEN** вызывается virtual scroller, который рендерит только видимые строки и синхронизируется со sticky scrollbar

### Requirement: Accessibility & Resilience
Таблица SHALL expose aria-атрибуты (ria-sort, ria-selected, ole="row"/"grid") и оставаться работоспособной при отключенном JS.

#### Scenario: Keyboard navigation
- **WHEN** пользователь перемещается по таблице с клавиатуры
- **THEN** фокус отображается в пределах строки, а выбранные строки имеют ria-selected="true"

#### Scenario: No-JS fallback
- **WHEN** JS недоступен
- **THEN** серверный шаблон рендерит полную таблицу с рабочими ссылками сортировки и пагинацией

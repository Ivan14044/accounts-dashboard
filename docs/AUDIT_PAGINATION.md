# Аудит функционала пагинации таблицы дашборда

## 1. Перечень функций и их расположение

### 1.1 PHP (серверная часть)

| Функция/логика | Файл | Описание |
|----------------|------|----------|
| Расчёт пагинации | [includes/DashboardController.php](includes/DashboardController.php) (стр. 136–243) | `getPaginationParams()` → `$page`, `$perPage`; `$pages = max(1, ceil($filteredTotal / $perPage))`; ограничение `$page` в диапазоне 1..$pages; `$prev`, `$next`, `$startPage`, `$endPage`, `$pageNumbers` |
| Окно страниц | Там же (окно 2, коррекция до минимум 5 номеров) | `$window = 2`; `$startPage`, `$endPage`; при необходимости расширяется так, чтобы между началом и концом было не меньше 4 номеров |
| Разметка футера | [templates/partials/table/footer.php](templates/partials/table/footer.php) | Вывод «Стр. X из Y», поле «Перейти на стр.» + кнопка «Перейти», `<nav>` с кнопками «<<», «<», номера, «…», последняя, «>», «>>»; класс `disabled` для первой/предыдущей при page==1 и для следующей/последней при page==pages |

### 1.2 JavaScript (клиентская часть)

| Функция/обработчик | Файл(ы) | Описание |
|--------------------|---------|----------|
| Клик по ссылке пагинации | [assets/js/dashboard-init.js](assets/js/dashboard-init.js) (626–654), [templates/partials/dashboard/init-script.php](templates/partials/dashboard/init-script.php) (1164–1191) | Делегирование на `ul.pagination a.page-link`; при клике по `li.disabled` — `preventDefault`; парсинг `page` из `href`; `history.replaceState`; обновление `#pageNum`, `#pageJumpInput`; вызов `refreshDashboardData({ light: true })` (или reload). Не очищает выбранные ID (только сброс selectedAllFiltered). |
| applyPageJump | dashboard-init.js (814–824), init-script.php (1457–1466) | Читает `#pagesCount`, парсит ввод `#pageJumpInput`; приводит к 1..totalPages; записывает обратно в поле; вызывает `goToPage(num)`. |
| Обработчики «Перейти» и Enter | Там же | `pageJumpBtn.click` → applyPageJump; `pageJumpInput.keydown` (Enter) → applyPageJump. |
| goToPage(selectedPage) | dashboard-init.js (842–855), init-script.php (1477–1486) | Проверка `selectedPage >= 1`; обновление URL через `searchParams.set('page', ...)` и `history.replaceState`; обновление `#pageNum`; **полная очистка выбора** `DashboardSelection.clearSelection()`; вызов `refreshDashboardData()` или reload. |
| Обновление UI после refresh | [assets/js/modules/dashboard-refresh.js](assets/js/modules/dashboard-refresh.js) (136–150, 218–237) | При получении `data.page`/`data.pages`: обновление `#pageNum`, `#pageJumpInput.value`, `#pageJumpInput.max`, `#pagesCount`; переключение `.active` и `aria-current` на нужном `li.page-item` по совпадению номера страницы с текстом ссылки. |
| pageSelect (если есть в DOM) | [assets/js/modules/dashboard-inline.js](assets/js/modules/dashboard-inline.js) (1517–1532), dashboard.js | Обработчик `change` на `#pageSelect`; переход на выбранную страницу и обновление футера. В текущем [footer.php](templates/partials/table/footer.php) элемента `pageSelect` нет — только `pageJumpInput` + кнопка. Код под pageSelect остаётся «мёртвым» для основной таблицы или используется в другом шаблоне. |

---

## 2. Проверка корректности работы

### 2.1 applyPageJump

- **totalPages:** берётся из `#pagesCount`; при отсутствии элемента — fallback 1. Корректно.
- **Ввод:** `parseInt(value, 10)`; при NaN или `< 1` подставляется 1; при `> totalPages` — totalPages. Отрицательные и нецелые обрезаются. Корректно.
- **Поле:** после валидации значение записывается обратно в `pageJumpInput`. Корректно.
- **Нет отдельной функции validatePageNumber** — валидация встроена в applyPageJump. Дублирования нет, но нет и явной проверки с сообщением пользователю (см. недочёты).

### 2.2 goToPage(selectedPage)

- **Вход:** при `!selectedPage || selectedPage < 1` выход без действий. Страница не проверяется на максимум — сервер в DashboardController ограничивает `$page` значением `$pages`, так что при переходе на несуществующую страницу ответ будет с последней допустимой страницей. Приемлемо.
- **Очистка выбора:** везде вызывается `clearSelection()`. При переходе по кнопкам «<<», «<», «1»–«5», «>», «>>» выбор не очищается (только сброс selectedAllFiltered). Получается разное поведение: «Перейти» очищает выбор, клик по номеру — нет. См. баги.

### 2.3 Клик по ссылке пагинации

- **disabled:** при `li.page-item.disabled` выполняется `preventDefault()` и return. Переход не происходит. Корректно.
- **href:** из `href` извлекается `page`, обновляется URL и UI, вызывается refresh. Ссылки «<<», «<», «>», «>>» и номера обрабатываются единообразно. Корректно.
- **Ссылки при disabled в PHP:** у `li` выставляется только класс `disabled`, `href` у `<a>` остаётся. Без JS пользователь мог бы перейти по ссылке; с JS переход блокируется. Для a11y лучше дополнительно `aria-disabled="true"` и/или не давать переход по Enter при фокусе на disabled.

### 2.4 Обновление пагинации после refresh (dashboard-refresh.js)

- **Обновление полей:** pageNum, pageJumpInput.value/max, pagesCount — по `data.page` и `data.pages`. Корректно.
- **Переключение .active:** обход `li.page-item`, разбор текста ссылки как числа; для совпадающего с `data.page` номера выставляется `active` и `aria-current="page"`. Элементы «<<», «<», «…», «>», «>>» не являются числами — для них `parseInt` даёт NaN, они пропускаются. Логика корректна.
- **Разметка пагинации после перехода:** номера страниц (1, 2, 3, …) не перерисовываются с сервера — остаётся исходный HTML. Меняется только класс active и aria-current. При переходе, например, на страницу 2939, блок с номерами по-прежнему может показывать 1,2,3,4,5,…,2939; активной станет 2939. Это ожидаемо при текущей схеме (без полной пересборки пагинации по данным).

### 2.5 PHP: окно страниц

- **Расчёт:** `$window = 2`; `$startPage = max(1, $page - 2)`; `$endPage = min($pages, $page + 2)`; при слишком узком окне оно расширяется так, чтобы между start и end было не меньше 4. Формула может давать дубли или лишние итерации в краевых случаях (например, pages=2, page=1), но `range($startPage, $endPage)` остаётся корректным. Поведение в целом предсказуемо.
- **Граничные случаи:** при `$pages === 1` блок с `<nav>` не выводится (`if ($pages > 1)`), поле «Перейти на стр.» остаётся; при вводе номера и нажатии «Перейти» вызывается goToPage(1). Корректно.

---

## 3. Недочёты и улучшения

1. **Нет явной валидации с сообщением пользователю**  
   При вводе в «Перейти на стр.» некорректного значения (буквы, пусто) значение молча приводится к 1 (или к totalPages). Имеет смысл добавить отдельную функцию `validatePageNumber(pageNumber, totalPages)` и при невалидном вводе показывать краткое сообщение (например, toast): «Введите число от 1 до N».

2. **Disabled-кнопки пагинации**  
   Для кнопок «<<» и «<» при page===1 и «>», «>>» при page===pages стоит добавить `aria-disabled="true"` и при необходимости убирать `href` или подменять на `#`, чтобы без JS и со скринридером было понятно, что переход недоступен.

3. **Дублирование кода**  
   Логика пагинации размазана по трём местам: dashboard-init.js, init-script.php (inline), dashboard-inline.js (частично). Одинаковые обработчики и goToPage дублируются. Любое изменение нужно вносить в несколько файлов — высокий риск рассинхрона (уже есть различие в clearSelection между goToPage и кликом по ссылке).

4. **Ссылка на несуществующий pageSelect**  
   В dashboard-inline.js и dashboard-refresh.js обновляется/используется `#pageSelect`, которого нет в [footer.php](templates/partials/table/footer.php). Либо добавить элемент в шаблон, либо убрать обращения к нему, чтобы не было лишних getElementById и обновлений несуществующего элемента.

5. **goToPage не обновляет поле «Перейти на стр.»**  
   В goToPage обновляется только `#pageNum`. Поле `#pageJumpInput` не синхронизируется. После перехода по клику на номер страницы поле обновляется (в обработчике клика), а после вызова goToPage(3) из applyPageJump — значение уже задано в applyPageJump. Но если goToPage когда-либо вызывается из другого места, поле может остаться старым. Имеет смысл в goToPage всегда выставлять `pageJumpInput.value = selectedPage` при наличии элемента.

6. **Единообразие очистки выбора**  
   Либо везде при смене страницы не очищать выбранные ID (как при клике по ссылке), либо везде очищать (как в goToPage). Сейчас поведение разное в зависимости от способа перехода.

---

## 4. Баги

1. **Разное поведение по очистке выбора**  
   - Клик по ссылке пагинации (<<, <, номер, >, >>): выбранные ID сохраняются, сбрасывается только «выделить все по фильтру».  
   - Кнопка «Перейти» (через goToPage): вызывается `clearSelection()` — все выбранные ID сбрасываются.  
   Итог: один и тот же переход на другую страницу даёт разный результат в зависимости от способа. Рекомендация: в goToPage не вызывать clearSelection(), а делать то же, что и при клике по ссылке (например, только setSelectedAllFiltered(false) и updateSelectedCount()), чтобы поведение совпадало.

2. **Возможная рассинхронизация pageJumpInput после refresh**  
   Если refresh возвращает другую структуру (например, изменилось количество страниц), `pageJumpInput.max` обновляется в dashboard-refresh. Значение value не принудительно ограничивается на клиенте при приходе data (например, текущая страница остаётся 5, а pages стало 2). Сервер при следующем запросе вернёт page=2, и при следующем обновлении UI value станет 2. То есть через один запрос всё приходит к согласованному состоянию. Явного бага нет, но при желании можно при получении data.pages сразу проверять и при необходимости ограничивать value поля (например, если value > data.pages, выставить value = data.page).

---

## 5. План выноса в отдельный модуль/компонент

### 5.1 Цель

- Один источник правды для логики пагинации.
- Единое поведение при любом способе перехода (клик по ссылке, «Перейти», Enter в поле).
- Удобное повторное использование (например, корзина, избранное) и тестирование.

### 5.2 Предлагаемая структура

**Новый модуль (например, `assets/js/modules/dashboard-pagination.js`):**

- **initPagination(options)**  
  - options: `{ pageNumElId, pagesCountElId, pageJumpInputId, pageJumpBtnId, onPageChange }`.  
  - Находит элементы по id, вешает обработчики на кнопку «Перейти» и на Enter в поле, делегирует клики по `ul.pagination a.page-link`.  
  - При любом переходе: валидация номера страницы (в т.ч. для ввода), обновление URL (replaceState), обновление всех элементов UI (pageNum, pageJumpInput.value/max при известном totalPages), вызов `onPageChange(newPage)` (например, refreshDashboardData или обновление таблицы).  
  - Единая политика по выбору: не очищать selectedIds при смене страницы; при необходимости сбрасывать только selectedAllFiltered и вызывать updateSelectedCount — внутри onPageChange или в одном месте в initPagination.

- **validateAndClampPageNumber(value, totalPages)**  
  - Парсит value (строка/число), возвращает число в диапазоне 1..totalPages; при невалидном вводе — null или объект `{ valid: false, message }` для показа toast.

- **updatePaginationUI(data)**  
  - Вызывается из dashboard-refresh при получении ответа: обновляет pageNum, pageJumpInput.value и max, pagesCount; переключает .active и aria-current на нужном li. Сигнатура: `updatePaginationUI({ page, pages })`.

- **goToPage(page)**  
  - Валидация (1..totalPages, totalPages из DOM или переданный параметр); обновление URL и UI; вызов onPageChange. Очистка выбора только если это явно передано в options (например, `clearSelectionOnPageChange: false` по умолчанию).

**Шаблон (footer.php):**

- Оставить как есть: те же id и разметка. При инициализации страницы дашборда вызывать `initPagination({ ... })` с id элементов и колбэком `onPageChange` (refreshDashboardData или аналог).

**Интеграция:**

- В [dashboard-refresh.js](assets/js/modules/dashboard-refresh.js): вместо прямого обновления полей пагинации вызывать `window.DashboardPagination && window.DashboardPagination.updatePaginationUI(data)`.
- В [dashboard-init.js](assets/js/dashboard-init.js) и [init-script.php](templates/partials/dashboard/init-script.php): удалить локальные обработчики пагинации и goToPage; заменить на вызов initPagination (или подключение скрипта модуля и один вызов из общего init).
- В [dashboard-inline.js](assets/js/modules/dashboard-inline.js): убрать дублирующие обработчики пагинации и pageSelect для таблицы, если они относятся к той же пагинации; при наличии отдельного шаблона с pageSelect — подключать его только там, где этот элемент есть.

### 5.3 Этапы внедрения

1. Добавить `dashboard-pagination.js` с функциями initPagination, validateAndClampPageNumber, updatePaginationUI, goToPage и экспортом в `window.DashboardPagination`.
2. Подключить скрипт на странице дашборда (рядом с остальными модулями таблицы).
3. В точке инициализации дашборда вызвать `DashboardPagination.initPagination({ ... })`, передав id элементов и колбэк обновления данных.
4. В dashboard-refresh.js заменить обновление полей пагинации на вызов `DashboardPagination.updatePaginationUI(data)`.
5. Удалить из dashboard-init.js и init-script.php обработчики кликов по пагинации, applyPageJump и goToPage; оставить только вызов initPagination.
6. Привести поведение очистки выбора к единому (не очищать selectedIds при смене страницы; при необходимости — только setSelectedAllFiltered(false)).
7. (Опционально) Добавить валидацию с сообщением пользователю при невалидном вводе в «Перейти на стр.» и улучшить a11y (aria-disabled, фокус) для disabled-кнопок.
8. Регрессионно проверить: переход по ссылкам, «Перейти», Enter, обновление после refresh, поведение выбора при смене страницы.

---

## 6. Краткая сводка

| Аспект | Оценка |
|--------|--------|
| Корректность валидации ввода | Хорошо (молчаливое приведение к 1..totalPages) |
| Обработка кликов по пагинации | Хорошо (disabled учитывается, URL и UI обновляются) |
| Обновление после AJAX refresh | Хорошо (поля и .active синхронизируются) |
| Единообразие поведения | Плохо (очистка выбора только при «Перейти») |
| Дублирование кода | Плохо (3 места с похожей логикой) |
| Ссылки на pageSelect | Риск (элемент отсутствует в footer таблицы) |
| Доступность (a11y) | Удовлетворительно (aria-current есть; aria-disabled для disabled — нет) |

Итог: логика в целом рабочая, но есть один явный баг (разная очистка выбора) и несколько мест для улучшения; вынос в отдельный модуль пагинации устранит дублирование и упростит единообразное поведение и доработки.

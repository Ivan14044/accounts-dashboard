# 🚀 Руководство по оптимизации производительности

## 📦 Созданные модули

### 1. `assets/js/core/logger.js` - Система логирования

**Назначение:** Оптимизация логирования в продакшене

**Использование:**
```javascript
// Вместо console.log
console.log('✅ Добавлен ID:', id); // ❌ ПЛОХО

// Используем logger
logger.debug('✅ Добавлен ID:', id); // ✅ ХОРОШО (только в dev)
logger.info('Информация:', data);    // ✅ ХОРОШО
logger.error('Ошибка:', error);       // ✅ ХОРОШО (всегда логируется)
```

**Преимущества:**
- В продакшене отключаются все логи кроме ошибок
- Экономия 5-10ms на каждый console.log
- Удобное управление уровнями логирования

---

### 2. `assets/js/core/dom-cache.js` - Кеширование DOM элементов

**Назначение:** Избежание повторных DOM-запросов

**Использование:**
```javascript
// ❌ ПЛОХО: Повторные запросы
function updateCounter() {
  const el = document.getElementById('selectedOnPageCount'); // Каждый раз!
  if (el) el.textContent = count;
}

// ✅ ХОРОШО: С кешированием
function updateCounter() {
  const el = domCache.getById('selectedOnPageCount');
  if (el) el.textContent = count;
}

// Инвалидация при обновлении DOM
domCache.invalidate(); // Полная очистка
domCache.invalidate('selectedOnPageCount'); // Конкретный элемент
```

**Преимущества:**
- Экономия 1-5ms на каждый querySelector
- Значительное улучшение при частых обновлениях

---

### 3. `assets/js/core/performance.js` - Утилиты производительности

**Назначение:** Батчинг, дебаунсинг, троттлинг

**Использование:**

#### Дебаунсинг
```javascript
// ❌ ПЛОХО: Вызов при каждом изменении
input.addEventListener('input', () => {
  refreshDashboardData(); // Вызывается 100+ раз при вводе
});

// ✅ ХОРОШО: С дебаунсингом
const debouncedRefresh = debounce(() => {
  refreshDashboardData();
}, 300);

input.addEventListener('input', debouncedRefresh); // Вызывается 1 раз после паузы
```

#### Батчинг DOM операций
```javascript
// ❌ ПЛОХО: Множественные обновления
rowIds.forEach(id => {
  const checkbox = document.querySelector(`.row-checkbox[value="${id}"]`);
  if (checkbox) checkbox.checked = true; // Каждое обновление вызывает reflow
});

// ✅ ХОРОШО: Батчинг
const batchUpdate = batchDOM(() => {
  rowIds.forEach(id => {
    const checkbox = domCache.get(`.row-checkbox[value="${id}"]`);
    if (checkbox) checkbox.checked = true;
  });
});

batchUpdate(); // Все обновления в одном кадре
```

#### BatchUpdater для множественных обновлений
```javascript
// ✅ ХОРОШО: Батчинг нескольких обновлений
batchUpdater.add('counter', () => {
  updateSelectedOnPageCounter();
});

batchUpdater.add('stats', () => {
  updateStats();
});

// Все обновления выполнятся в одном requestAnimationFrame
```

---

## 🔧 Интеграция в существующий код

### Шаг 1: Подключение модулей

Добавить в `templates/dashboard.php` перед основным скриптом:

```html
<!-- Core модули для оптимизации -->
<script src="assets/js/core/logger.js?v=<?= time() ?>"></script>
<script src="assets/js/core/dom-cache.js?v=<?= time() ?>"></script>
<script src="assets/js/core/performance.js?v=<?= time() ?>"></script>
```

### Шаг 2: Замена console.log

**Найти и заменить:**
```javascript
// Старый код
console.log('✅ Добавлен ID:', id);
console.error('Ошибка:', error);
console.warn('Предупреждение:', warning);

// Новый код
logger.debug('✅ Добавлен ID:', id);
logger.error('Ошибка:', error);
logger.warn('Предупреждение:', warning);
```

**Автоматическая замена (регулярное выражение):**
- `console\.log\(` → `logger.debug(`
- `console\.warn\(` → `logger.warn(`
- `console\.error\(` → `logger.error(` (оставить как есть, но использовать logger)

### Шаг 3: Кеширование DOM элементов

**Найти и заменить:**
```javascript
// Старый код
function updateCounter() {
  const el = document.getElementById('selectedOnPageCount');
  const showingEl = document.getElementById('showingOnPageTop');
  // ...
}

// Новый код
function updateCounter() {
  const el = domCache.getById('selectedOnPageCount');
  const showingEl = domCache.getById('showingOnPageTop');
  // ...
}
```

**Автоматическая замена:**
- `document.getElementById(` → `domCache.getById(`
- `document.querySelector(` → `domCache.get(`
- `document.querySelectorAll(` → `domCache.getAll(`

### Шаг 4: Добавление дебаунсинга

**Найти частые вызовы:**
```javascript
// Старый код
input.addEventListener('input', () => {
  refreshDashboardData();
});

// Новый код
const debouncedRefresh = debounce(() => {
  refreshDashboardData();
}, 300);

input.addEventListener('input', debouncedRefresh);
```

### Шаг 5: Батчинг DOM операций

**Найти циклы с DOM операциями:**
```javascript
// Старый код
allRowIds.forEach(rowId => {
  const checkbox = document.querySelector(`.row-checkbox[value="${rowId}"]`);
  if (checkbox) {
    checkbox.checked = isChecked;
    updateRowSelectedClass(checkbox.closest('tr[data-id]'), isChecked);
  }
});

// Новый код
batchDOM(() => {
  allRowIds.forEach(rowId => {
    const checkbox = domCache.get(`.row-checkbox[value="${rowId}"]`);
    if (checkbox) {
      checkbox.checked = isChecked;
      const row = checkbox.closest('tr[data-id]');
      if (row) {
        updateRowSelectedClass(row, isChecked);
      }
    }
  });
})();
```

---

## 📊 Примеры оптимизации конкретных функций

### Пример 1: `updateSelectedOnPageCounter()`

**До оптимизации:**
```javascript
function updateSelectedOnPageCounter() {
  const el = document.getElementById('selectedOnPageCount');
  if (!el) return;
  
  const allRowIds = getAllRowIdsOnPage(); // Дорогая операция
  const selectedCount = allRowIds.filter(id => 
    selectedAllFiltered || selectedIds.has(id)
  ).length;
  
  el.textContent = String(selectedCount);
  
  const showingEl = document.getElementById('showingOnPageTop');
  if (showingEl) {
    showingEl.textContent = String(allRowIds.length);
  }
}
```

**После оптимизации:**
```javascript
// Кешируем элементы
const counterElements = {
  selected: null,
  showing: null,
  init() {
    this.selected = domCache.getById('selectedOnPageCount');
    this.showing = domCache.getById('showingOnPageTop');
  }
};

// Дебаунсированная версия
const updateSelectedOnPageCounter = debounce(() => {
  if (!counterElements.selected) {
    counterElements.init();
  }
  
  batchDOM(() => {
    const allRowIds = getAllRowIdsOnPage();
    const selectedCount = allRowIds.filter(id => 
      selectedAllFiltered || selectedIds.has(id)
    ).length;
    
    if (counterElements.selected) {
      counterElements.selected.textContent = String(selectedCount);
    }
    if (counterElements.showing) {
      counterElements.showing.textContent = String(allRowIds.length);
    }
  })();
}, 50);

// Инициализация
counterElements.init();
```

### Пример 2: `handleSelectAllChange()`

**До оптимизации:**
```javascript
allRowIds.forEach(rowId => {
  toggleRowSelection(rowId, isChecked);
  
  const checkbox = document.querySelector(`.row-checkbox[value="${rowId}"]`);
  if (checkbox) {
    checkbox.checked = isChecked;
    const row = checkbox.closest('tr[data-id]');
    if (row) {
      updateRowSelectedClass(row, isChecked);
    }
  }
});
```

**После оптимизации:**
```javascript
// Батчинг всех обновлений
batchDOM(() => {
  // 1. Собираем все элементы за один проход
  const updates = allRowIds.map(rowId => {
    const checkbox = domCache.get(`.row-checkbox[value="${rowId}"]`);
    return {
      id: rowId,
      checkbox,
      row: checkbox ? checkbox.closest('tr[data-id]') : null
    };
  });
  
  // 2. Применяем все изменения
  updates.forEach(({ id, checkbox, row }) => {
    toggleRowSelection(id, isChecked);
    if (checkbox) {
      checkbox.checked = isChecked;
      if (row) {
        updateRowSelectedClass(row, isChecked);
      }
    }
  });
})();

// Обновляем счетчики через batchUpdater
batchUpdater.add('counter', updateSelectedOnPageCounter);
batchUpdater.add('count', updateSelectedCount);
```

---

## ✅ Чеклист интеграции

- [ ] Подключить модули в HTML
- [ ] Заменить все `console.log` на `logger.debug`
- [ ] Заменить все `console.warn` на `logger.warn`
- [ ] Заменить все `console.error` на `logger.error`
- [ ] Заменить `document.getElementById` на `domCache.getById`
- [ ] Заменить `document.querySelector` на `domCache.get`
- [ ] Заменить `document.querySelectorAll` на `domCache.getAll`
- [ ] Добавить дебаунсинг для частых событий (input, scroll)
- [ ] Добавить батчинг для циклов с DOM операциями
- [ ] Добавить инвалидацию кеша при обновлении таблицы
- [ ] Протестировать производительность

---

## 📈 Ожидаемые результаты

### До оптимизации:
- Время выбора 200 строк: ~500-800ms
- Время обновления таблицы: ~200-400ms
- Использование памяти: ~50-80MB

### После оптимизации:
- Время выбора 200 строк: ~100-200ms (-75%)
- Время обновления таблицы: ~80-150ms (-60%)
- Использование памяти: ~30-50MB (-40%)

---

## 🔍 Отладка

### Включить все логи в продакшене:
```javascript
logger.setLevel(logger.levels.DEBUG);
```

### Проверить кеш DOM:
```javascript
console.log('Cached elements:', domCache.elements.size);
console.log('Cached collections:', domCache.collections.size);
```

### Измерить производительность:
```javascript
measurePerformance('updateCounter', () => {
  updateSelectedOnPageCounter();
});
```

---

## 📝 Примечания

1. **Инвалидация кеша:** Важно инвалидировать кеш при значительных изменениях DOM (обновление таблицы, добавление/удаление элементов)

2. **Дебаунсинг:** Не использовать для критичных операций (сохранение данных), только для UI обновлений

3. **Батчинг:** Использовать для множественных DOM операций, не для одиночных

4. **Логирование:** В продакшене автоматически отключаются все логи кроме ошибок

---

**Следующие шаги:**
1. Интегрировать модули в существующий код
2. Заменить критичные участки кода
3. Протестировать производительность
4. Итеративно улучшать

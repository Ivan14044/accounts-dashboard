/**
 * Модуль управления выбором строк в таблице
 * Отвечает за выбор/снятие выбора строк, обновление счетчиков, работу с localStorage
 */

// Константы
const LS_KEY_SELECTED = 'dashboard_selected_ids';

// Состояние модуля
let selectedIds = new Set();
let selectedAllFiltered = false;
let filteredTotalLive = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.filteredTotal) || 0;

// Кеш для getAllRowIdsOnPage с TTL
const RowIdsCache = {
  cache: null,
  cacheTime: 0,
  TTL: 100, // 100ms кеш
  
  get() {
    const now = performance.now();
    if (this.cache && (now - this.cacheTime) < this.TTL) {
      return this.cache;
    }
    
    const rowIds = [];
    const v = window.tableVirtualization;

    // Режим виртуализации «из данных»: allRows пуст, ID берём из rowsData
    if (v && v.enabled && v.rowsData && v.rowsData.length > 0) {
      v.rowsData.forEach(function (row) {
        const id = row && (row.id !== undefined) ? parseInt(row.id, 10) : NaN;
        if (Number.isFinite(id)) rowIds.push(id);
      });
    } else if (v && v.enabled && v.allRows && v.allRows.length > 0) {
      // Виртуализация с DOM-строками
      v.allRows.forEach(function (row) {
        const checkbox = row.querySelector('.row-checkbox');
        if (checkbox) {
          const rowId = parseInt(checkbox.value, 10);
          if (Number.isFinite(rowId)) rowIds.push(rowId);
        }
      });
    } else {
      // Виртуализация отключена - используем DOM
      const checkboxes = document.querySelectorAll('.row-checkbox');
      checkboxes.forEach(cb => {
        const rowId = parseInt(cb.value);
        if (Number.isFinite(rowId)) {
          rowIds.push(rowId);
        }
      });
    }
    
    this.cache = rowIds;
    this.cacheTime = now;
    return rowIds;
  },
  
  invalidate() {
    this.cache = null;
    this.cacheTime = 0;
  }
};

// Вспомогательная функция для безопасного получения элемента через dom-cache
function getElementById(id) {
  if (typeof domCache !== 'undefined' && domCache.getById) {
    return domCache.getById(id);
  }
  return document.getElementById(id);
}

// Вспомогательная функция для получения всех ID строк на странице (с учетом виртуализации)
function getAllRowIdsOnPage() {
  return RowIdsCache.get();
}

// Функция для обновления класса выбранной строки
function updateRowSelectedClass(row, isSelected) {
  if (!row) return;
  if (isSelected) {
    row.classList.add('row-selected');
  } else {
    row.classList.remove('row-selected');
  }
}

// Загрузка выбранных ID из localStorage
function loadSelectedIds() {
  try {
    const saved = localStorage.getItem(LS_KEY_SELECTED);
    if (saved) {
      selectedIds = new Set(JSON.parse(saved));
    }
  } catch (e) {
    if (typeof logger !== 'undefined') {
      logger.error('Error loading selected IDs:', e);
    }
  }
}

// Сохранение выбранных ID в localStorage
function saveSelectedIds() {
  try {
    localStorage.setItem(LS_KEY_SELECTED, JSON.stringify(Array.from(selectedIds)));
  } catch (e) {
    if (typeof logger !== 'undefined') {
      logger.error('Error saving selected IDs:', e);
    }
  }
}

// Переключение выбора строки
function toggleRowSelection(id, checked) {
  if (checked) {
    selectedIds.add(id);
    if (typeof logger !== 'undefined') {
      logger.debug('✅ Добавлен ID:', id, '| Всего выбрано:', selectedIds.size);
    }
  } else {
    selectedIds.delete(id);
    if (typeof logger !== 'undefined') {
      logger.debug('❌ Удалён ID:', id, '| Всего выбрано:', selectedIds.size);
    }
  }
  (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(saveSelectedIds);
  updateSelectedCount();
  if (typeof logger !== 'undefined') {
    logger.debug('📦 Список выбранных ID:', Array.from(selectedIds));
  }
}

// Обновление счетчика выбранных строк
function updateSelectedCount() {
  const count = selectedIds.size;
  const selectedCountEl = getElementById('selectedCount');
  if (selectedCountEl) {
    selectedCountEl.textContent = selectedAllFiltered ? 'Все по фильтру' : count;
  }
  const exportBtns = document.querySelectorAll('#exportSelectedCsv, #exportSelectedTxt, #deleteSelected, #changeStatusSelected, #bulkEditFieldBtn, #validateAccountsBtn');
  exportBtns.forEach(btn => btn.disabled = (!selectedAllFiltered && count === 0));
  
  // Показываем/скрываем кнопку "Сбросить выбор"
  const clearAllBtn = getElementById('clearAllSelectedBtn');
  if (clearAllBtn) {
    const hasSelection = selectedAllFiltered || count > 0;
    clearAllBtn.style.display = hasSelection ? '' : 'none';
  }
  
  const notice = getElementById('selectAllNotice');
  if (!notice) {
    if (typeof logger !== 'undefined') {
      logger.warn('[SELECT] Element #selectAllNotice not found in DOM');
    }
    return;
  }
  const noticeText = notice.querySelector('.dashboard-table__selection-text');
  if (!noticeText) {
    if (typeof logger !== 'undefined') {
      logger.warn('[SELECT] Element .dashboard-table__selection-text not found in #selectAllNotice');
    }
    return;
  }
  
  const totalFiltered = filteredTotalLive;
  
  if (typeof logger !== 'undefined') {
    logger.debug('[SELECT] Notice logic:', {
      selectedAllFiltered,
      count,
      totalFiltered,
      shouldShowPartial: !selectedAllFiltered && count > 0 && totalFiltered > count
    });
  }
  
  if (!selectedAllFiltered && count > 0 && totalFiltered > count) {
    notice.classList.remove('d-none');
    noticeText.innerHTML = `Выбраны <strong>${count}</strong> на этой странице. <a href="#" id="selectAllFilteredLink">Выделить все ${totalFiltered.toLocaleString('ru-RU')} по фильтру</a>`;
    if (typeof logger !== 'undefined') {
      logger.debug(`[SELECT] Showing partial notice: ${count} of ${totalFiltered}`);
    }
  } else if (selectedAllFiltered) {
    notice.classList.remove('d-none');
    noticeText.innerHTML = `Выделены все <strong>${totalFiltered.toLocaleString('ru-RU')}</strong> по фильтру. <a href="#" id="clearSelectionLink">Очистить выбор</a>`;
    if (typeof logger !== 'undefined') {
      logger.debug(`[SELECT] Showing full notice: all ${totalFiltered} selected`);
    }
  } else {
    notice.classList.add('d-none');
    noticeText.innerHTML = '';
  }
  
  // Обновляем компактный счётчик "Отмечено X из Y" через batchUpdater
  if (typeof batchUpdater !== 'undefined' && typeof batchUpdater.add === 'function') {
    batchUpdater.add('counter', updateSelectedOnPageCounter);
  } else {
    updateSelectedOnPageCounter();
  }
}

// Дебаунсированная версия updateSelectedOnPageCounter для оптимизации
const updateSelectedOnPageCounterDebounced = (typeof debounce !== 'undefined' && typeof debounce === 'function')
  ? debounce(function updateSelectedOnPageCounter() {
      const el = getElementById('selectedOnPageCount');
      if (!el) return;
      
      const allRowIds = getAllRowIdsOnPage();
      const selectedCount = allRowIds.filter(id => selectedAllFiltered || selectedIds.has(id)).length;
      
      el.textContent = String(selectedCount);
      
      const showingEl = getElementById('showingOnPageTop');
      if (showingEl) {
        showingEl.textContent = String(allRowIds.length);
      }
    }, 50)
  : function updateSelectedOnPageCounter() {
      const el = getElementById('selectedOnPageCount');
      if (!el) return;
      
      const allRowIds = getAllRowIdsOnPage();
      const selectedCount = allRowIds.filter(id => selectedAllFiltered || selectedIds.has(id)).length;
      
      el.textContent = String(selectedCount);
      
      const showingEl = getElementById('showingOnPageTop');
      if (showingEl) {
        showingEl.textContent = String(allRowIds.length);
      }
    };

// Экспортируем функцию для обратной совместимости
function updateSelectedOnPageCounter() {
  updateSelectedOnPageCounterDebounced();
}

// Инициализация состояния чекбоксов.
// Раньше: один цикл с переплетёнными read↔write на 100 строках = layout thrashing
// (браузер пересчитывал layout на каждой итерации, ~40 мс на 100 строках).
// Теперь: 2 фазы — все reads (closest/value) → все writes (checked/classList).
// Браузер группирует writes в один reflow, скорость растёт в 3–5 раз.
function initCheckboxStates() {
  const checkboxes = document.querySelectorAll('.row-checkbox');
  const items = new Array(checkboxes.length);

  // Phase 1 — only reads
  for (let i = 0; i < checkboxes.length; i++) {
    const cb = checkboxes[i];
    const rowId = parseInt(cb.value, 10);
    items[i] = {
      cb: cb,
      row: cb.closest('tr[data-id]'),
      isChecked: selectedAllFiltered || selectedIds.has(rowId)
    };
  }

  // Phase 2 — only writes (no-op skip избегает лишних reflows на checkbox.checked = same)
  for (let i = 0; i < items.length; i++) {
    const it = items[i];
    if (it.cb.checked !== it.isChecked) it.cb.checked = it.isChecked;
    if (it.row) {
      // classList.toggle с force-параметром = один dispatch вместо if/else.
      it.row.classList.toggle('row-selected', it.isChecked);
    }
  }

  // selectAll-checkbox: использует кэшированный RowIdsCache (см. getAllRowIdsOnPage)
  const selectAllCheckbox = getElementById('selectAll');
  if (selectAllCheckbox) {
    const allRowIds = getAllRowIdsOnPage();
    let selectedCount = 0;
    for (let i = 0; i < allRowIds.length; i++) {
      if (selectedAllFiltered || selectedIds.has(allRowIds[i])) selectedCount++;
    }
    const next = allRowIds.length > 0 && selectedCount === allRowIds.length;
    if (selectAllCheckbox.checked !== next) selectAllCheckbox.checked = next;
  }
}

// Обработчик изменения чекбокса "Выбрать все"
// Второй параметр keepAllFilteredMode используется, когда мы переключаемся в режим
// "выделены все по фильтру" и не хотим сбрасывать флаг selectedAllFiltered.
function handleSelectAllChange(isChecked, keepAllFilteredMode = false) {
  // По умолчанию при ручном клике по чекбоксу "Выбрать все" мы выходим
  // из режима "все по фильтру". Если keepAllFilteredMode === true (клик
  // по ссылке "Выделить все по фильтру"), флаг не трогаем.
  if (!keepAllFilteredMode) {
    selectedAllFiltered = false;
  }
  const allRowIds = getAllRowIdsOnPage();
  
  if (typeof logger !== 'undefined') {
    logger.debug(`[SELECT ALL] Выделение всех строк на странице: ${allRowIds.length} строк, checked: ${isChecked}`);
  }
  
  // Используем батчинг для массового обновления чекбоксов
  if (typeof batchDOM !== 'undefined' && typeof batchDOM === 'function') {
    batchDOM(() => {
      // Сначала обновляем selectedIds БЕЗ вызова toggleRowSelection (чтобы не вызывать updateSelectedCount 50+ раз)
      allRowIds.forEach(rowId => {
        if (isChecked) {
          selectedIds.add(rowId);
        } else {
          selectedIds.delete(rowId);
        }
      });
      
      // Затем обновляем DOM элементы батчем
      allRowIds.forEach(rowId => {
        const checkbox = (typeof domCache !== 'undefined' && domCache.get)
          ? domCache.get(`.row-checkbox[value="${rowId}"]`)
          : document.querySelector(`.row-checkbox[value="${rowId}"]`);
        if (checkbox) {
          checkbox.checked = isChecked;
          const row = checkbox.closest('tr[data-id]');
          if (row) {
            updateRowSelectedClass(row, isChecked);
          }
        }
      });
    })();
  } else {
    // Fallback без батчинга
    allRowIds.forEach(rowId => {
      if (isChecked) {
        selectedIds.add(rowId);
      } else {
        selectedIds.delete(rowId);
      }
      const checkbox = document.querySelector(`.row-checkbox[value="${rowId}"]`);
      if (checkbox) {
        checkbox.checked = isChecked;
        const row = checkbox.closest('tr[data-id]');
        if (row) {
          updateRowSelectedClass(row, isChecked);
        }
      }
    });
  }
  
  // После батча сохраняем в localStorage в idle
  (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(saveSelectedIds);
  
  if (typeof logger !== 'undefined') {
    logger.debug('✅ Массовое выделение завершено. Всего выбрано:', selectedIds.size);
  }
  
  // Обновляем счетчики через batchUpdater (если доступен)
  if (typeof batchUpdater !== 'undefined' && typeof batchUpdater.add === 'function') {
    batchUpdater.add('counter', updateSelectedOnPageCounter);
    batchUpdater.add('count', updateSelectedCount);
  } else {
    updateSelectedCount();
    updateSelectedOnPageCounter();
  }
}

// Инициализация модуля
function initSelectionModule() {
  // Загружаем выбранные ID из localStorage
  loadSelectedIds();
  
  // Инициализируем состояние чекбоксов
  initCheckboxStates();
  
  // Обновляем счетчики
  updateSelectedCount();
  updateSelectedOnPageCounter();
  
  // Регистрируем обработчики событий
  document.addEventListener('change', function(e) {
    // Обработка чекбокса "Выбрать все"
    if (e.target && e.target.id === 'selectAll') {
      handleSelectAllChange(e.target.checked);
      return;
    }
    
    // Обработка индивидуальных чекбоксов строк
    if (e.target && e.target.classList.contains('row-checkbox')) {
      const rowId = parseInt(e.target.value);
      if (Number.isFinite(rowId)) {
        toggleRowSelection(rowId, e.target.checked);
        const row = e.target.closest('tr[data-id]');
        if (row) {
          updateRowSelectedClass(row, e.target.checked);
        }
        updateSelectedOnPageCounter();
      }
    }
  });
}

// Экспорт функций для глобального использования
window.DashboardSelection = {
  init: initSelectionModule,
  loadSelectedIds: loadSelectedIds,
  saveSelectedIds: saveSelectedIds,
  toggleRowSelection: toggleRowSelection,
  updateSelectedCount: updateSelectedCount,
  updateSelectedOnPageCounter: updateSelectedOnPageCounter,
  initCheckboxStates: initCheckboxStates,
  handleSelectAllChange: handleSelectAllChange,
  getAllRowIdsOnPage: getAllRowIdsOnPage,
  updateRowSelectedClass: updateRowSelectedClass,
  getSelectedIds: () => selectedIds,
  getSelectedAllFiltered: () => selectedAllFiltered,
  setSelectedAllFiltered: (value) => { selectedAllFiltered = value; },
  getFilteredTotalLive: () => filteredTotalLive,
  setFilteredTotalLive: (value) => { filteredTotalLive = value; },
  clearSelection: () => {
    selectedIds.clear();
    selectedAllFiltered = false;
    (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(saveSelectedIds);
    updateSelectedCount();
    updateSelectedOnPageCounter();
  },
  invalidateCache: () => {
    RowIdsCache.invalidate();
    if (typeof domCache !== 'undefined' && typeof domCache.invalidate === 'function') {
      domCache.invalidate();
    }
  }
};

/**
 * Модуль управления фильтрами дашборда
 * Отвечает за обработку изменений фильтров, обновление URL, дебаунсинг input полей
 */

// Вспомогательная функция для безопасного получения элемента через dom-cache
function getElementById(id) {
  if (typeof domCache !== 'undefined' && domCache.getById) {
    return domCache.getById(id);
  }
  return document.getElementById(id);
}

// Дебаунсированная функция обновления URL и данных
const updateFiltersDebounced = (typeof debounce !== 'undefined' && typeof debounce === 'function')
  ? debounce(function updateFiltersAndRefresh() {
      if (typeof refreshDashboardData === 'function') {
        refreshDashboardData();
      }
    }, 300)
  : function updateFiltersAndRefresh() {
      if (typeof refreshDashboardData === 'function') {
        refreshDashboardData();
      }
    };

// Обновление URL параметров фильтра
function updateFilterURL(filterName, value, min = null, max = null) {
  const url = new URL(window.location);
  
  // Обработка различных типов фильтров
  switch (filterName) {
    case 'q':
      if (value && value.trim()) {
        url.searchParams.set('q', value.trim());
      } else {
        url.searchParams.delete('q');
      }
      break;
    case 'pharma':
      if (value !== null && value !== undefined) {
        if (typeof value === 'object' && value.from !== undefined && value.to !== undefined) {
          if (value.from > min) {
            url.searchParams.set('pharma_from', String(value.from));
          } else {
            url.searchParams.delete('pharma_from');
          }
          if (value.to < max) {
            url.searchParams.set('pharma_to', String(value.to));
          } else {
            url.searchParams.delete('pharma_to');
          }
        }
      }
      break;
    case 'friends':
      if (value !== null && value !== undefined) {
        if (typeof value === 'object' && value.from !== undefined && value.to !== undefined) {
          if (value.from > min) {
            url.searchParams.set('friends_from', String(value.from));
          } else {
            url.searchParams.delete('friends_from');
          }
          if (value.to < max) {
            url.searchParams.set('friends_to', String(value.to));
          } else {
            url.searchParams.delete('friends_to');
          }
        }
      }
      break;
    case 'bm':
      if (value !== null && value !== undefined) {
        if (typeof value === 'object' && value.from !== undefined && value.to !== undefined) {
          if (value.from) {
            url.searchParams.set('bm_from', String(value.from));
          } else {
            url.searchParams.delete('bm_from');
          }
          if (value.to) {
            url.searchParams.set('bm_to', String(value.to));
          } else {
            url.searchParams.delete('bm_to');
          }
        }
      }
      break;
    case 'status':
      if (Array.isArray(value) && value.length > 0) {
        url.searchParams.delete('status[]');
        value.forEach(status => {
          url.searchParams.append('status[]', status);
        });
      } else {
        url.searchParams.delete('status[]');
      }
      break;
    case 'status_marketplace':
      if (value) {
        url.searchParams.set('status_marketplace', value);
      } else {
        url.searchParams.delete('status_marketplace');
      }
      break;
    case 'currency':
      if (value) {
        url.searchParams.set('currency', value);
      } else {
        url.searchParams.delete('currency');
      }
      break;
    case 'geo':
      if (value) {
        url.searchParams.set('geo', value);
      } else {
        url.searchParams.delete('geo');
      }
      break;
    case 'status_rk':
      if (value) {
        url.searchParams.set('status_rk', value);
      } else {
        url.searchParams.delete('status_rk');
      }
      break;
    case 'year_created':
      if (value !== null && value !== undefined) {
        if (typeof value === 'object' && value.from !== undefined && value.to !== undefined) {
          if (value.from) {
            url.searchParams.set('year_created_from', String(value.from));
          } else {
            url.searchParams.delete('year_created_from');
          }
          if (value.to) {
            url.searchParams.set('year_created_to', String(value.to));
          } else {
            url.searchParams.delete('year_created_to');
          }
        }
      }
      break;
    case 'limit_rk':
      if (value !== null && value !== undefined) {
        if (typeof value === 'object' && value.from !== undefined && value.to !== undefined) {
          if (value.from) {
            url.searchParams.set('limit_rk_from', String(value.from));
          } else {
            url.searchParams.delete('limit_rk_from');
          }
          if (value.to) {
            url.searchParams.set('limit_rk_to', String(value.to));
          } else {
            url.searchParams.delete('limit_rk_to');
          }
        }
      }
      break;
    default:
      if (typeof logger !== 'undefined') {
        logger.warn('Unknown filter:', filterName);
      }
      return;
  }
  
  // Сбрасываем на первую страницу при изменении фильтра
  url.searchParams.set('page', '1');
  
  // Обновляем URL без перезагрузки
  history.replaceState(null, '', url.toString());
  
  // Сбрасываем выбор при изменении фильтров
  if (typeof window.DashboardSelection !== 'undefined') {
    window.DashboardSelection.clearSelection();
  } else if (typeof selectedAllFiltered !== 'undefined' && typeof selectedIds !== 'undefined') {
    selectedAllFiltered = false;
    selectedIds.clear();
    if (typeof updateSelectedCount === 'function') {
      updateSelectedCount();
    }
  }
  
  // Обновляем данные через AJAX
  updateFiltersDebounced();
}

// Инициализация слайдера pharma
function initializePharmaSlider() {
  const slider = getElementById('pharmaSlider');
  if (!slider || typeof noUiSlider === 'undefined') return;
  
  const min = parseInt(slider.getAttribute('data-min') || '0', 10);
  const max = parseInt(slider.getAttribute('data-max') || '50', 10);
  const fromInit = slider.getAttribute('data-from');
  const toInit = slider.getAttribute('data-to');
  const from = (fromInit !== null && fromInit !== '') ? parseInt(fromInit, 10) : min;
  const to = (toInit !== null && toInit !== '') ? parseInt(toInit, 10) : max;
  const fromInput = getElementById('pharma_from');
  const toInput = getElementById('pharma_to');
  const fromDisp = getElementById('pharmaFromDisplay');
  const toDisp = getElementById('pharmaToDisplay');

  // Защита от повторной инициализации: если слайдер уже создан — пересоздаём
  if (slider.noUiSlider) slider.noUiSlider.destroy();
  noUiSlider.create(slider, {
    start: [Math.max(min, from), Math.min(max, to)],
    connect: true,
    range: { min, max },
    step: 1,
    behaviour: 'tap-drag',
    tooltips: false,
    format: {
      to: (v) => Math.round(v),
      from: (v) => Number(v)
    }
  });

  slider.noUiSlider.on('update', (values) => {
    const [vFrom, vTo] = values.map(Number);
    if (fromDisp) fromDisp.textContent = String(vFrom);
    if (toDisp) toDisp.textContent = String(vTo);
    if (fromInput) fromInput.value = String(vFrom);
    if (toInput) toInput.value = String(vTo);
  });
  
  // Дебаунсинг для слайдеров (500ms)
  const sliderChangeHandler = (typeof debounce !== 'undefined' && typeof debounce === 'function')
    ? debounce(() => {
        const values = slider.noUiSlider.get();
        const vFrom = Math.round(Number(values[0]));
        const vTo = Math.round(Number(values[1]));
        updateFilterURL('pharma', { from: vFrom, to: vTo }, min, max);
      }, 500)
    : () => {
        const values = slider.noUiSlider.get();
        const vFrom = Math.round(Number(values[0]));
        const vTo = Math.round(Number(values[1]));
        updateFilterURL('pharma', { from: vFrom, to: vTo }, min, max);
      };
  
  slider.noUiSlider.on('change', sliderChangeHandler);
}

// Инициализация слайдера friends
function initializeFriendsSlider() {
  const slider = getElementById('friendsSlider');
  if (!slider || typeof noUiSlider === 'undefined') return;
  
  const min = parseInt(slider.getAttribute('data-min') || '0', 10);
  const max = parseInt(slider.getAttribute('data-max') || '1000', 10);
  const fromInit = slider.getAttribute('data-from');
  const toInit = slider.getAttribute('data-to');
  const from = (fromInit !== null && fromInit !== '') ? parseInt(fromInit, 10) : min;
  const to = (toInit !== null && toInit !== '') ? parseInt(toInit, 10) : max;
  const fromInput = getElementById('friends_from');
  const toInput = getElementById('friends_to');
  const fromDisp = getElementById('friendsFromDisplay');
  const toDisp = getElementById('friendsToDisplay');

  // Защита от повторной инициализации: если слайдер уже создан — пересоздаём
  if (slider.noUiSlider) slider.noUiSlider.destroy();
  noUiSlider.create(slider, {
    start: [Math.max(min, from), Math.min(max, to)],
    connect: true,
    range: { min, max },
    step: 1,
    behaviour: 'tap-drag',
    tooltips: false,
    format: {
      to: (v) => Math.round(v),
      from: (v) => Number(v)
    }
  });

  slider.noUiSlider.on('update', (values) => {
    const [vFrom, vTo] = values.map(Number);
    if (fromDisp) fromDisp.textContent = String(vFrom);
    if (toDisp) toDisp.textContent = String(vTo);
    if (fromInput) fromInput.value = String(vFrom);
    if (toInput) toInput.value = String(vTo);
  });
  
  // Дебаунсинг для слайдеров (500ms)
  const sliderChangeHandler = (typeof debounce !== 'undefined' && typeof debounce === 'function')
    ? debounce(() => {
        const values = slider.noUiSlider.get();
        const vFrom = Math.round(Number(values[0]));
        const vTo = Math.round(Number(values[1]));
        updateFilterURL('friends', { from: vFrom, to: vTo }, min, max);
      }, 500)
    : () => {
        const values = slider.noUiSlider.get();
        const vFrom = Math.round(Number(values[0]));
        const vTo = Math.round(Number(values[1]));
        updateFilterURL('friends', { from: vFrom, to: vTo }, min, max);
      };
  
  slider.noUiSlider.on('change', sliderChangeHandler);
}

// Обработка изменений input полей с дебаунсингом
function setupFilterInputs() {
  // Поиск (q)
  const searchInput = getElementById('q');
  if (searchInput) {
    const searchHandler = (typeof debounce !== 'undefined' && typeof debounce === 'function')
      ? debounce((e) => {
          updateFilterURL('q', e.target.value);
        }, 300)
      : (e) => {
          updateFilterURL('q', e.target.value);
        };
    searchInput.addEventListener('input', searchHandler);
  }
  
  // Другие input поля можно добавить аналогично
  // Например, year_created_from, year_created_to, limit_rk_from, limit_rk_to
  const yearCreatedFrom = getElementById('year_created_from');
  const yearCreatedTo = getElementById('year_created_to');
  if (yearCreatedFrom || yearCreatedTo) {
    const yearHandler = (typeof debounce !== 'undefined' && typeof debounce === 'function')
      ? debounce(() => {
          const from = yearCreatedFrom ? yearCreatedFrom.value : null;
          const to = yearCreatedTo ? yearCreatedTo.value : null;
          updateFilterURL('year_created', { from, to });
        }, 300)
      : () => {
          const from = yearCreatedFrom ? yearCreatedFrom.value : null;
          const to = yearCreatedTo ? yearCreatedTo.value : null;
          updateFilterURL('year_created', { from, to });
        };
    if (yearCreatedFrom) yearCreatedFrom.addEventListener('input', yearHandler);
    if (yearCreatedTo) yearCreatedTo.addEventListener('input', yearHandler);
  }
  
  const limitRkFrom = getElementById('limit_rk_from');
  const limitRkTo = getElementById('limit_rk_to');
  if (limitRkFrom || limitRkTo) {
    const limitHandler = (typeof debounce !== 'undefined' && typeof debounce === 'function')
      ? debounce(() => {
          const from = limitRkFrom ? limitRkFrom.value : null;
          const to = limitRkTo ? limitRkTo.value : null;
          updateFilterURL('limit_rk', { from, to });
        }, 300)
      : () => {
          const from = limitRkFrom ? limitRkFrom.value : null;
          const to = limitRkTo ? limitRkTo.value : null;
          updateFilterURL('limit_rk', { from, to });
        };
  if (limitRkFrom) limitRkFrom.addEventListener('input', limitHandler);
  if (limitRkTo) limitRkTo.addEventListener('input', limitHandler);

  // Количество БМ (bm_from / bm_to)
  const bmFromInput = getElementById('bm_from');
  const bmToInput = getElementById('bm_to');
  if (bmFromInput || bmToInput) {
    const bmHandler = (typeof debounce !== 'undefined' && typeof debounce === 'function')
      ? debounce(() => {
          const from = bmFromInput ? bmFromInput.value : null;
          const to = bmToInput ? bmToInput.value : null;
          updateFilterURL('bm', { from, to });
        }, 300)
      : () => {
          const from = bmFromInput ? bmFromInput.value : null;
          const to = bmToInput ? bmToInput.value : null;
          updateFilterURL('bm', { from, to });
        };
    if (bmFromInput) bmFromInput.addEventListener('input', bmHandler);
    if (bmToInput) bmToInput.addEventListener('input', bmHandler);
  }
}
}

// Обработка изменений select полей
function setupFilterSelects() {
  // status_marketplace
  const statusMarketplace = getElementById('status_marketplace');
  if (statusMarketplace) {
    statusMarketplace.addEventListener('change', (e) => {
      updateFilterURL('status_marketplace', e.target.value);
    });
  }
  
  // currency
  const currency = getElementById('currency');
  if (currency) {
    currency.addEventListener('change', (e) => {
      updateFilterURL('currency', e.target.value);
    });
  }
  
  // geo
  const geo = getElementById('geo');
  if (geo) {
    geo.addEventListener('change', (e) => {
      updateFilterURL('geo', e.target.value);
    });
  }
  
  // status_rk
  const statusRk = getElementById('status_rk');
  if (statusRk) {
    statusRk.addEventListener('change', (e) => {
      updateFilterURL('status_rk', e.target.value);
    });
  }
}

// Инициализация модуля фильтров
function initFiltersModule() {
  // Инициализация слайдеров
  initializePharmaSlider();
  initializeFriendsSlider();
  
  // Настройка обработчиков input полей
  setupFilterInputs();
  
  // Настройка обработчиков select полей
  setupFilterSelects();
  
  if (typeof logger !== 'undefined') {
    logger.debug('✅ Модуль фильтров инициализирован');
  }
}

// Экспорт функций для глобального использования
window.DashboardFilters = {
  init: initFiltersModule,
  updateFilterURL: updateFilterURL,
  initializePharmaSlider: initializePharmaSlider,
  initializeFriendsSlider: initializeFriendsSlider,
  setupFilterInputs: setupFilterInputs,
  setupFilterSelects: setupFilterSelects
};

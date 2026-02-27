/**
 * JavaScript для современных фильтров
 * Обработка interactions, animations, ripple effects
 */

// Экранирование для безопасного вывода в HTML
function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ========================================
// УПРАВЛЕНИЕ CHIPS (Активные фильтры)
// ========================================

/**
 * Построить HTML чипов активных фильтров по текущему URL.
 * Вызывается после refreshDashboardData(), чтобы блок «Активные фильтры» соответствовал URL.
 */
function renderActiveFiltersFromUrl() {
    const listEl = document.getElementById('activeFiltersList');
    const sectionEl = document.getElementById('activeFiltersSection');
    if (!listEl) return;

    const params = new URLSearchParams(window.location.search);
    const chips = [];

    // Поиск
    const q = params.get('q');
    if (q !== null && q !== '') {
        const short = q.length > 20 ? q.substring(0, 20) + '...' : q;
        chips.push('<div class="filter-chip" data-filter="q"><i class="fas fa-search filter-chip-icon"></i><span>Поиск: "' + escapeHtml(short) + '"</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }

    // Пустой статус
    if (params.get('empty_status') === '1') {
        chips.push('<div class="filter-chip" data-filter="status" data-status-value="__empty__"><i class="fas fa-exclamation-triangle filter-chip-icon"></i><span>Пустой статус</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }

    // Статусы
    const statuses = params.getAll('status[]');
    statuses.forEach(function (st) {
        if (st !== '' && st !== '__empty__') {
            chips.push('<div class="filter-chip" data-filter="status" data-status-value="' + escapeHtml(st) + '"><i class="fas fa-tag filter-chip-icon"></i><span>' + escapeHtml(st) + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
        }
    });

    // Булевы и одиночные фильтры (название, иконка, подпись)
    const simpleFilters = [
        ['has_email', 'fa-envelope', 'Есть Email'],
        ['has_two_fa', 'fa-shield-alt', 'Есть 2FA'],
        ['has_token', 'fa-key', 'Есть Token'],
        ['has_fan_page', 'fa-flag', 'Есть Fan Page'],
        ['has_avatar', 'fa-image', 'Есть Аватар'],
        ['has_password', 'fa-lock', 'Есть Пароль'],
        ['has_cover', 'fa-image', 'Есть Обложка'],
        ['has_bm', 'fa-briefcase', 'Есть БМ'],
        ['full_filled', 'fa-check-circle', 'Полностью заполненные'],
        ['favorites_only', 'fa-star', 'Только избранные']
    ];
    simpleFilters.forEach(function (item) {
        const name = item[0], icon = item[1], label = item[2];
        const val = params.get(name);
        if (val !== null && val !== '') {
            chips.push('<div class="filter-chip" data-filter="' + name + '"><i class="fas ' + icon + ' filter-chip-icon"></i><span>' + escapeHtml(label) + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
        }
    });

    // Диапазоны
    const pharmaFrom = params.get('pharma_from') || '';
    const pharmaTo = params.get('pharma_to') || '';
    if (pharmaFrom !== '' || pharmaTo !== '') {
        chips.push('<div class="filter-chip" data-filter="pharma"><i class="fas fa-pills filter-chip-icon"></i><span>Pharma: ' + escapeHtml(pharmaFrom || '0') + '-' + escapeHtml(pharmaTo || '∞') + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const friendsFrom = params.get('friends_from') || '';
    const friendsTo = params.get('friends_to') || '';
    if (friendsFrom !== '' || friendsTo !== '') {
        chips.push('<div class="filter-chip" data-filter="friends"><i class="fas fa-users filter-chip-icon"></i><span>Друзья: ' + escapeHtml(friendsFrom || '0') + '-' + escapeHtml(friendsTo || '∞') + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const yearFrom = params.get('year_created_from') || '';
    const yearTo = params.get('year_created_to') || '';
    if (yearFrom !== '' || yearTo !== '') {
        chips.push('<div class="filter-chip" data-filter="year_created"><i class="fas fa-calendar filter-chip-icon"></i><span>Год: ' + escapeHtml(yearFrom || '∞') + '-' + escapeHtml(yearTo || '∞') + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const limitRkFrom = params.get('limit_rk_from') || '';
    const limitRkTo = params.get('limit_rk_to') || '';
    if (limitRkFrom !== '' || limitRkTo !== '') {
        chips.push('<div class="filter-chip" data-filter="limit_rk"><i class="fas fa-chart-line filter-chip-icon"></i><span>Limit RK: ' + escapeHtml(limitRkFrom || '0') + '-' + escapeHtml(limitRkTo || '∞') + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const bmFrom = params.get('bm_from') || '';
    const bmTo = params.get('bm_to') || '';
    if (bmFrom !== '' || bmTo !== '') {
        chips.push('<div class="filter-chip" data-filter="bm_range"><i class="fas fa-briefcase filter-chip-icon"></i><span>БМ: ' + escapeHtml(bmFrom || '0') + '—' + escapeHtml(bmTo || '∞') + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }

    // Одиночные с подписью из значения
    const statusMarketplace = params.get('status_marketplace');
    if (statusMarketplace !== null && statusMarketplace !== '') {
        chips.push('<div class="filter-chip" data-filter="status_marketplace"><i class="fas fa-store filter-chip-icon"></i><span>Marketplace: ' + escapeHtml(statusMarketplace) + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const currency = params.get('currency');
    if (currency !== null && currency !== '') {
        chips.push('<div class="filter-chip" data-filter="currency"><i class="fas fa-coins filter-chip-icon"></i><span>Currency: ' + escapeHtml(currency) + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const geo = params.get('geo');
    if (geo !== null && geo !== '') {
        const geoLabel = geo === '__empty__' ? 'Не указано' : geo;
        chips.push('<div class="filter-chip" data-filter="geo"><i class="fas fa-globe filter-chip-icon"></i><span>Geo: ' + escapeHtml(geoLabel) + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }
    const statusRk = params.get('status_rk');
    if (statusRk !== null && statusRk !== '') {
        const rkLabel = statusRk === '__empty__' ? 'Не указано' : statusRk;
        chips.push('<div class="filter-chip" data-filter="status_rk"><i class="fas fa-tag filter-chip-icon"></i><span>Status RK: ' + escapeHtml(rkLabel) + '</span><button class="filter-chip-remove" title="Удалить">&times;</button></div>');
    }

    listEl.innerHTML = chips.join('');

    if (sectionEl) {
        if (chips.length > 0) {
            sectionEl.classList.add('has-filters');
        } else {
            sectionEl.classList.remove('has-filters');
        }
    }

    // Показываем / скрываем кнопку "Сбросить все" вместе с секцией чипов
    var resetBtn = document.getElementById('resetAllFiltersBtn');
    if (resetBtn) {
        resetBtn.style.display = chips.length > 0 ? '' : 'none';
    }

    const badgeEl = document.querySelector('.filters-modern-badge');
    if (badgeEl) {
        badgeEl.textContent = String(chips.length);
        badgeEl.style.display = chips.length > 0 ? '' : 'none';
    }

    // Анимация появления (как при первой загрузке)
    listEl.querySelectorAll('.filter-chip').forEach(function (chip, index) {
        chip.style.animationDelay = (index * 50) + 'ms';
    });
}

window.renderActiveFiltersFromUrl = renderActiveFiltersFromUrl;

/**
 * Удаление фильтра через chip
 */
function removeFilterChip(filterName) {
    // Делаем функцию глобально доступной
    if (!window.removeFilterChip) {
        window.removeFilterChip = removeFilterChip;
    }
    const url = new URL(window.location);
    
    // Удаляем параметр из URL
    switch (filterName) {
        case 'q':
            url.searchParams.delete('q');
            break;
        case 'has_email':
            url.searchParams.delete('has_email');
            break;
        case 'has_two_fa':
            url.searchParams.delete('has_two_fa');
            break;
        case 'has_token':
            url.searchParams.delete('has_token');
            break;
        case 'has_fan_page':
            url.searchParams.delete('has_fan_page');
            break;
        case 'has_avatar':
            url.searchParams.delete('has_avatar');
            break;
        case 'has_password':
            url.searchParams.delete('has_password');
            break;
        case 'has_cover':
            url.searchParams.delete('has_cover');
            break;
        case 'has_bm':
            url.searchParams.delete('has_bm');
            break;
        case 'bm_range':
            url.searchParams.delete('bm_from');
            url.searchParams.delete('bm_to');
            break;
        case 'full_filled':
            url.searchParams.delete('full_filled');
            break;
        case 'favorites_only':
            url.searchParams.delete('favorites_only');
            break;
        case 'pharma':
            url.searchParams.delete('pharma_from');
            url.searchParams.delete('pharma_to');
            break;
        case 'friends':
            url.searchParams.delete('friends_from');
            url.searchParams.delete('friends_to');
            break;
        case 'year_created':
            url.searchParams.delete('year_created_from');
            url.searchParams.delete('year_created_to');
            break;
        case 'limit_rk':
            url.searchParams.delete('limit_rk_from');
            url.searchParams.delete('limit_rk_to');
            break;
        case 'status_marketplace':
            url.searchParams.delete('status_marketplace');
            break;
        case 'currency':
            url.searchParams.delete('currency');
            break;
        case 'geo':
            url.searchParams.delete('geo');
            break;
        case 'status_rk':
            url.searchParams.delete('status_rk');
            break;
        default:
            (typeof logger !== 'undefined' ? logger.warn : console.warn)('Unknown filter:', filterName);
            return;
    }
    
    url.searchParams.set('page', '1');
    applyFiltersWithoutReload(url);
}

/**
 * Синхронизировать все элементы формы фильтров по текущему URL.
 * Вызывается после любого изменения URL, чтобы DOM всегда соответствовал параметрам.
 * Решает баги: удаление chip не снимало чекбокс; применение сохранённого фильтра не обновляло форму.
 */
function syncFormFromUrl() {
    var form = document.getElementById('filtersForm');
    if (!form) return;
    var params = new URLSearchParams(window.location.search);

    // Статусы
    var urlStatuses = params.getAll('status[]');
    form.querySelectorAll('input.status-checkbox[name="status[]"]').forEach(function(cb) {
        cb.checked = urlStatuses.indexOf(cb.value) !== -1;
    });
    var emptyCb = form.querySelector('input.status-checkbox[name="empty_status"]');
    if (emptyCb) {
        emptyCb.checked = params.get('empty_status') === '1';
    }
    // Метка dropdown статусов
    var statusLabel = document.getElementById('statusDropdownLabel');
    if (statusLabel) {
        var cnt = urlStatuses.length + (params.get('empty_status') === '1' ? 1 : 0);
        statusLabel.textContent = cnt === 0 ? 'Все статусы' : 'Выбрано: ' + cnt;
    }

    // Быстрые фильтры (toggle-switch)
    QUICK_FILTER_PARAMS.forEach(function(name) {
        var cb = form.querySelector('input[type="checkbox"][name="' + name + '"]');
        if (!cb) return;
        var isActive = params.has(name) && params.get(name) !== '';
        cb.checked = isActive;
        var wrapper = cb.closest('.toggle-switch-wrapper');
        if (wrapper) {
            if (isActive) wrapper.classList.add('active');
            else wrapper.classList.remove('active');
        }
    });

    // Hidden-поля dropdown-фильтров + метки
    var dropdowns = [
        {name: 'status_marketplace', labelId: 'statusMarketplaceDropdownLabel', itemClass: 'status-marketplace-item', allText: 'Все статусы'},
        {name: 'currency', labelId: 'currencyDropdownLabel', itemClass: 'currency-item', allText: 'Все валюты'},
        {name: 'geo', labelId: 'geoDropdownLabel', itemClass: 'geo-item', allText: 'Все geo'},
        {name: 'status_rk', labelId: 'statusRkDropdownLabel', itemClass: 'status-rk-item', allText: 'Все статусы RK'}
    ];
    dropdowns.forEach(function(d) {
        var input = form.querySelector('input[name="' + d.name + '"]');
        var val = params.get(d.name) || '';
        if (input) input.value = val;
        var label = document.getElementById(d.labelId);
        if (label) {
            if (val === '') label.textContent = d.allText;
            else if (val === '__empty__') label.textContent = 'Не указано';
            else label.textContent = val;
        }
        document.querySelectorAll('.' + d.itemClass).forEach(function(item) {
            var itemVal = item.getAttribute('data-value');
            if (itemVal === val) item.classList.add('active');
            else item.classList.remove('active');
        });
    });

    // Поиск
    var searchInput = form.querySelector('input[name="q"]');
    if (searchInput) searchInput.value = params.get('q') || '';

    // Диапазоны
    ['pharma_from','pharma_to','friends_from','friends_to','bm_from','bm_to','year_created_from','year_created_to','limit_rk_from','limit_rk_to'].forEach(function(name) {
        var input = form.querySelector('input[name="' + name + '"]');
        if (input) input.value = params.get(name) || '';
    });

    // Per page
    var perPage = form.querySelector('select[name="per_page"]');
    if (perPage && params.get('per_page')) perPage.value = params.get('per_page');
}
window.syncFormFromUrl = syncFormFromUrl;

/**
 * Применить фильтры без перезагрузки страницы: обновить URL и подгрузить данные через AJAX.
 * После replaceState синхронизирует DOM формы, чтобы чекбоксы/инпуты соответствовали URL.
 * @param {URL} url - новый URL с параметрами фильтров
 */
function applyFiltersWithoutReload(url) {
    if (!url || !(url instanceof URL)) return;
    history.replaceState(null, '', url.toString());
    syncFormFromUrl();
    if (typeof window.DashboardSelection !== 'undefined' && window.DashboardSelection.clearSelection) {
        window.DashboardSelection.clearSelection();
    }
    if (typeof refreshDashboardData === 'function') {
        refreshDashboardData();
    } else {
        window.location.href = url.toString();
    }
}

/**
 * Удаление конкретного статуса через chip
 */
function removeStatusChip(statusValue) {
    if (!statusValue) {
        (typeof logger !== 'undefined' ? logger.error : console.error)('removeStatusChip: statusValue is required');
        return;
    }
    
    const url = new URL(window.location);
    
    if (statusValue === '__empty__') {
        // Удаляем empty_status
        url.searchParams.delete('empty_status');
    } else {
        // Получаем все текущие статусы (проверяем оба варианта: status[] и status)
        let currentStatuses = url.searchParams.getAll('status[]');
        
        // Если status[] пустой, пробуем получить из status
        if (currentStatuses.length === 0) {
            const statusParam = url.searchParams.get('status');
            if (statusParam) {
                currentStatuses = statusParam.split(',').map(s => s.trim()).filter(s => s);
            }
        }
        
        // Удаляем все статусы из URL
        url.searchParams.delete('status[]');
        url.searchParams.delete('status');
        
        // Добавляем обратно всё кроме удаляемого (строгое сравнение)
        currentStatuses.forEach(st => {
            if (String(st) !== String(statusValue)) {
                url.searchParams.append('status[]', st);
            }
        });
    }
    
    url.searchParams.set('page', '1');
    applyFiltersWithoutReload(url);
}

// Делаем функции глобально доступными
window.removeStatusChip = removeStatusChip;
window.applyFiltersWithoutReload = applyFiltersWithoutReload;

// ========================================
// ПОЛЕ ПОИСКА
// ========================================

/**
 * Очистка поля поиска.
 * Использует AJAX-обновление вместо form.submit() (который вызывал полную перезагрузку страницы).
 */
function clearSearch() {
    var input = document.getElementById('modernSearchInput');
    if (!input) return;
    input.value = '';
    input.focus();

    var url = new URL(window.location);
    url.searchParams.delete('q');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    if (typeof window.syncFormFromUrl === 'function') window.syncFormFromUrl();
    if (typeof refreshDashboardData === 'function') refreshDashboardData();
}

// Обработчик input на поле поиска НЕ дублируется здесь —
// единственный живой обработчик находится в dashboard-inline.js (applyLiveSearch, debounce 300ms).

// ========================================
// СБРОС ВСЕХ ФИЛЬТРОВ
// ========================================

/**
 * Список всех параметров фильтров, которые сбрасываются кнопкой «Сбросить все».
 * per_page не сбрасываем — это настройка отображения, не фильтр.
 */
var ALL_FILTER_PARAMS = [
    'q',
    'status[]', 'status', 'empty_status',
    'has_email', 'has_two_fa', 'has_token', 'has_fan_page',
    'has_avatar', 'has_password', 'has_cover', 'has_bm', 'full_filled', 'favorites_only',
    'pharma_from', 'pharma_to',
    'friends_from', 'friends_to',
    'bm_from', 'bm_to',
    'year_created_from', 'year_created_to',
    'limit_rk_from', 'limit_rk_to',
    'status_marketplace', 'currency', 'geo', 'status_rk'
];

/**
 * Сбросить все активные фильтры и обновить таблицу через AJAX.
 */
function resetAllFilters() {
    var url = new URL(window.location);
    ALL_FILTER_PARAMS.forEach(function(key) {
        url.searchParams.delete(key);
    });
    url.searchParams.set('page', '1');
    applyFiltersWithoutReload(url);
}
window.resetAllFilters = resetAllFilters;

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+F для фокуса на поиск
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        const searchInput = document.getElementById('modernSearchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
    
    // Escape для очистки поиска
    if (e.key === 'Escape') {
        const searchInput = document.getElementById('modernSearchInput');
        if (searchInput && searchInput === document.activeElement) {
            clearSearch();
        }
    }
});

// ========================================
// DROPDOWN СТАТУСОВ (стандартный функционал уже есть)
// ========================================

// ========================================
// БЫСТРЫЕ ФИЛЬТРЫ (TOGGLE SWITCHES)
// ========================================

/**
 * Обработка быстрых фильтров (toggle switches).
 * Клик на обёртке .toggle-switch-wrapper: если кликнули вне label/checkbox — программно кликаем checkbox.
 * Реальное применение фильтра — через единый change listener ниже (в DOMContentLoaded).
 */
function initQuickFilterWrappers() {
    document.querySelectorAll('.toggle-switch-wrapper').forEach(function(wrapper) {
        wrapper.addEventListener('click', function(e) {
            if (e.target.closest('input[type="checkbox"]') || e.target.closest('label.toggle-switch')) return;
            var cb = wrapper.querySelector('input[type="checkbox"]');
            if (cb) cb.click();
        });
    });
}


// ========================================
// АВТОМАТИЧЕСКОЕ ПРИМЕНЕНИЕ ФИЛЬТРОВ
// ========================================

/** Список параметров быстрых фильтров (чекбоксы). При снятии галочки поле не попадает в FormData — параметр нужно явно удалить из URL. */
var QUICK_FILTER_PARAMS = ['has_email', 'has_two_fa', 'has_token', 'has_fan_page', 'has_avatar', 'has_password', 'has_cover', 'has_bm', 'full_filled', 'favorites_only'];

/**
 * Собрать URL по текущему состоянию формы фильтров (без перезагрузки).
 * @param {HTMLFormElement} form - форма #filtersForm
 * @returns {URL}
 */
function getFormFiltersUrl(form) {
    const url = new URL(window.location);
    QUICK_FILTER_PARAMS.forEach(function (key) { url.searchParams.delete(key); });
    const fd = new FormData(form);
    for (const [key, value] of fd) {
        if (key === 'status[]' || key === 'empty_status') continue;
        if (value !== '' && value != null) {
            url.searchParams.set(key, value);
        } else {
            url.searchParams.delete(key);
        }
    }
    // Статусы и empty_status берём по текущему состоянию чекбоксов в DOM,
    // а не из FormData — иначе при быстром снятии одного и выборе другого может применяться старый набор
    for (const key of ['status[]', 'status', 'empty_status']) {
        while (url.searchParams.has(key)) url.searchParams.delete(key);
    }
    var emptyCb = form.querySelector('input.status-checkbox[name="empty_status"]');
    if (emptyCb && emptyCb.checked) {
        url.searchParams.set('empty_status', '1');
    }
    form.querySelectorAll('input.status-checkbox[name="status[]"]').forEach(function (cb) {
        if (cb.checked) {
            url.searchParams.append('status[]', cb.value);
        }
    });
    url.searchParams.set('page', '1');
    return url;
}

/**
 * Применить текущие значения формы фильтров без перезагрузки страницы.
 * @param {HTMLFormElement} form - форма #filtersForm
 */
function applyFormFiltersWithoutReload(form) {
    if (!form) return;
    const url = getFormFiltersUrl(form);
    applyFiltersWithoutReload(url);
}

window.applyFormFiltersWithoutReload = applyFormFiltersWithoutReload;

// Отслеживаем изменения в полях формы — применяем без перезагрузки (только таблица и статистика)
document.addEventListener('DOMContentLoaded', function() {
    var filtersForm = document.getElementById('filtersForm');
    if (!filtersForm) return;

    // Инициализация обёрток быстрых фильтров (клик вне label → toggle checkbox)
    initQuickFilterWrappers();

    // Единый change-обработчик: статусы, быстрые фильтры, select
    filtersForm.addEventListener('change', function(e) {
        var target = e.target;

        // Чекбоксы статусов
        if (target.classList.contains('status-checkbox')) {
            setTimeout(function() {
                if (filtersForm.parentNode) applyFormFiltersWithoutReload(filtersForm);
            }, 0);
            return;
        }

        // Быстрые фильтры (toggle switches) — единый обработчик, onclick из HTML убран
        if (target.type === 'checkbox' && QUICK_FILTER_PARAMS.indexOf(target.name) !== -1) {
            applyFormFiltersWithoutReload(filtersForm);
            return;
        }

        // Select (per_page)
        if (target.tagName === 'SELECT') {
            applyFormFiltersWithoutReload(filtersForm);
            return;
        }
    });

    // Диапазонные поля при потере фокуса
    filtersForm.addEventListener('blur', function(e) {
        var target = e.target;
        if (target.classList.contains('range-input-modern') && target.dataset.initialValue !== target.value) {
            setTimeout(function() {
                if (filtersForm.parentNode) applyFormFiltersWithoutReload(filtersForm);
            }, 100);
        }
    }, true);

    filtersForm.addEventListener('focus', function(e) {
        var target = e.target;
        if (target.classList.contains('range-input-modern')) target.dataset.initialValue = target.value;
    }, true);

    filtersForm.addEventListener('keypress', function(e) {
        var target = e.target;
        if (target.classList.contains('range-input-modern') && e.key === 'Enter') {
            e.preventDefault();
            if (filtersForm.parentNode) applyFormFiltersWithoutReload(filtersForm);
        }
    });

    // Кнопка «Обновить»: применить фильтры без перезагрузки
    filtersForm.addEventListener('submit', function(e) {
        e.preventDefault();
        applyFormFiltersWithoutReload(filtersForm);
    });
});

// ========================================
// УПРАВЛЕНИЕ АКТИВНЫМИ CHIPS
// ========================================

/**
 * Обновление видимости секции активных фильтров
 */
function updateActiveFiltersVisibility() {
    const section = document.getElementById('activeFiltersSection');
    if (!section) return;
    
    const chips = section.querySelectorAll('.filter-chip');
    const hasChips = chips.length > 0;
    
    if (hasChips) {
        section.classList.add('has-filters');
    } else {
        section.classList.remove('has-filters');
    }

    // Синхронизируем кнопку "Сбросить все" при первичной загрузке страницы
    var resetBtn = document.getElementById('resetAllFiltersBtn');
    if (resetBtn) {
        resetBtn.style.display = hasChips ? '' : 'none';
    }
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    // Проверяем видимость активных фильтров и кнопки "Сбросить все"
    updateActiveFiltersVisibility();

    // Обработчик кнопки "Сбросить все фильтры"
    var resetBtn = document.getElementById('resetAllFiltersBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            resetAllFilters();
        });
    }
    
    // Добавляем плавные анимации для chips
    document.querySelectorAll('.filter-chip').forEach((chip, index) => {
        chip.style.animationDelay = (index * 50) + 'ms';
    });
    
    // Делегирование событий для удаления filter-chip (более надежно чем inline onclick)
    document.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.filter-chip-remove');
        if (!removeBtn) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const chip = removeBtn.closest('.filter-chip');
        if (!chip) return;
        
        const filterType = chip.getAttribute('data-filter');
        const statusValue = chip.getAttribute('data-status-value');
        
        // Обработка удаления статуса
        if (filterType === 'status') {
            // Проверяем наличие значения статуса
            if (statusValue !== null && statusValue !== '') {
                // Вызываем removeStatusChip с правильным значением
                if (typeof removeStatusChip === 'function') {
                    removeStatusChip(statusValue);
                } else {
                    (typeof logger !== 'undefined' ? logger.error : console.error)('removeStatusChip function not found');
                }
            } else {
                (typeof logger !== 'undefined' ? logger.warn : console.warn)('Status chip missing data-status-value attribute');
            }
            return;
        }
        
        // Обработка других фильтров
        if (filterType && filterType !== 'status') {
            if (typeof removeFilterChip === 'function') {
                removeFilterChip(filterType);
            } else {
                (typeof logger !== 'undefined' ? logger.error : console.error)('removeFilterChip function not found');
            }
        }
    });
    
    // Индикация загрузки при отправке формы
    const filtersForm = document.getElementById('filtersForm');
    if (filtersForm) {
        filtersForm.addEventListener('submit', function(e) {
            // Показываем индикатор загрузки на кнопке
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loader loader-sm loader-white" style="display:inline-block;vertical-align:middle;width:14px;height:14px;border-top-width:2px;border-right-width:2px;margin-right:6px;"></span>Применение...';
            }
        });
    }
    
    if (typeof logger !== 'undefined') logger.debug('✓ Modern Filters initialized (auto-apply mode)');
});

// ========================================
// ACCESSIBILITY
// ========================================

// Tab navigation для toggle switches
document.querySelectorAll('.toggle-switch-wrapper').forEach(wrapper => {
    wrapper.setAttribute('tabindex', '0');
    wrapper.setAttribute('role', 'switch');
    
    const checkbox = wrapper.querySelector('input[type="checkbox"]');
    wrapper.setAttribute('aria-checked', checkbox.checked);
    
    // Поддержка клавиатуры
    wrapper.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            wrapper.click();
        }
    });
});

// Tab navigation для кнопок статусов
document.querySelectorAll('.status-btn-modern').forEach(btn => {
    btn.setAttribute('tabindex', '0');
    btn.setAttribute('role', 'button');
    
    btn.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            btn.click();
        }
    });
});


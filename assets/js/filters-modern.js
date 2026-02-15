/**
 * JavaScript для современных фильтров
 * Обработка interactions, animations, ripple effects
 */

// ========================================
// УПРАВЛЕНИЕ CHIPS (Активные фильтры)
// ========================================

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
 * Применить фильтры без перезагрузки страницы: обновить URL и подгрузить данные через AJAX.
 * @param {URL} url - новый URL с параметрами фильтров
 */
function applyFiltersWithoutReload(url) {
    if (!url || !(url instanceof URL)) return;
    history.replaceState(null, '', url.toString());
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

let searchTimeout = null;

/**
 * Очистка поля поиска
 */
function clearSearch() {
    const input = document.getElementById('modernSearchInput');
    if (input) {
        input.value = '';
        input.focus();
        
        // Если есть форма, отправляем
        const form = input.closest('form');
        if (form) {
            form.submit();
        }
    }
}

/**
 * Автоматическое применение поиска с задержкой (debounce)
 */
function handleSearchInput() {
    const input = document.getElementById('modernSearchInput');
    if (!input) return;
    
    // Очищаем предыдущий таймер
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    
    // Устанавливаем новый таймер (800ms задержка)
    searchTimeout = setTimeout(() => {
        const form = input.closest('form');
        if (form) {
            form.submit();
        }
    }, 800);
}

// Инициализация автоматического поиска
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('modernSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', handleSearchInput);
    }
});

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
 * Toggle быстрого фильтра с автоматическим применением
 */
function toggleQuickFilter(filterName, wrapper) {
    const checkbox = wrapper.querySelector('input[type="checkbox"]');
    if (!checkbox) return;
    
    // Toggle checkbox
    checkbox.checked = !checkbox.checked;
    
    // Toggle wrapper класс
    if (checkbox.checked) {
        wrapper.classList.add('active');
    } else {
        wrapper.classList.remove('active');
    }
    
    // Применяем фильтры без перезагрузки
    const form = checkbox.closest('form');
    if (form) {
        applyFormFiltersWithoutReload(form);
    }
}


// ========================================
// АВТОМАТИЧЕСКОЕ ПРИМЕНЕНИЕ ФИЛЬТРОВ
// ========================================

/**
 * Собрать URL по текущему состоянию формы фильтров (без перезагрузки).
 * @param {HTMLFormElement} form - форма #filtersForm
 * @returns {URL}
 */
function getFormFiltersUrl(form) {
    const url = new URL(window.location);
    const fd = new FormData(form);
    // Сначала выставляем все одиночные параметры
    for (const [key, value] of fd) {
        if (key === 'status[]' || key === 'empty_status') continue;
        if (value !== '' && value != null) {
            url.searchParams.set(key, value);
        } else {
            url.searchParams.delete(key);
        }
    }
    // status[] и empty_status — удаляем все и выставляем заново
    for (const key of ['status[]', 'status', 'empty_status']) {
        while (url.searchParams.has(key)) url.searchParams.delete(key);
    }
    for (const [key, value] of fd) {
        if (key === 'status[]') url.searchParams.append('status[]', value);
        if (key === 'empty_status' && value) url.searchParams.set('empty_status', '1');
    }
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
    const filtersForm = document.getElementById('filtersForm');
    if (!filtersForm) return;

    // Чекбоксы статусов: обновляем только таблицу и URL
    filtersForm.addEventListener('change', function(e) {
        if (e.target.classList.contains('status-checkbox')) {
            setTimeout(() => {
                if (filtersForm.parentNode) applyFormFiltersWithoutReload(filtersForm);
            }, 100);
        }
    });

    // Select (status_marketplace, currency и т.п.)
    filtersForm.addEventListener('change', function(e) {
        const target = e.target;
        if (target.tagName === 'SELECT' && (target.name === 'status_marketplace' || target.name === 'currency' || target.name === 'geo' || target.name === 'status_rk')) {
            setTimeout(() => {
                if (filtersForm.parentNode) applyFormFiltersWithoutReload(filtersForm);
            }, 100);
        }
    });

    // Диапазонные поля при потере фокуса
    filtersForm.addEventListener('blur', function(e) {
        const target = e.target;
        if (target.classList.contains('range-input-modern') && target.dataset.initialValue !== target.value) {
            setTimeout(() => {
                if (filtersForm.parentNode) applyFormFiltersWithoutReload(filtersForm);
            }, 100);
        }
    }, true);

    filtersForm.addEventListener('focus', function(e) {
        const target = e.target;
        if (target.classList.contains('range-input-modern')) target.dataset.initialValue = target.value;
    }, true);

    filtersForm.addEventListener('keypress', function(e) {
        const target = e.target;
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
    
    if (chips.length > 0) {
        section.classList.add('has-filters');
    } else {
        section.classList.remove('has-filters');
    }
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    // Проверяем видимость активных фильтров
    updateActiveFiltersVisibility();
    
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


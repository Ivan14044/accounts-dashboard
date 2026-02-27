/**
 * Управление сохранёнными фильтрами (Presets)
 * Сохранение, загрузка и применение фильтров
 */
class SavedFiltersManager {
    constructor() {
        this.filters = [];
        this.loaded = false;
        
        this.init();
    }
    
    async init() {
        // Ждём загрузки DOM
        if (document.readyState === 'loading') {
            await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve));
        }
        
        // Небольшая задержка для гарантии, что все элементы загружены
        await new Promise(resolve => setTimeout(resolve, 100));
        
        await this.loadFilters();
        this.renderFiltersDropdown();
        this.bindEvents();
    }
    
    /**
     * Загрузка списка сохранённых фильтров
     */
    async loadFilters() {
        try {
            const response = await fetch('api_saved_filters.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to load filters');
            }
            
            const data = await response.json();
            if (data.success && Array.isArray(data.filters)) {
                this.filters = data.filters;
                this.loaded = true;
                this.renderFiltersDropdown();
            }
        } catch (error) {
            (typeof logger !== 'undefined' ? logger.error : console.error)('Error loading saved filters:', error);
        }
    }
    
    /**
     * Отрисовка выпадающего списка фильтров
     */
    renderFiltersDropdown() {
        let dropdown = document.getElementById('savedFiltersDropdown');
        
        if (!dropdown) {
            // Создаём dropdown, если его нет
            // Пробуем найти контейнер или секцию действий фильтров
            let filtersSection = document.getElementById('savedFiltersContainer');
            if (!filtersSection) {
                filtersSection = document.querySelector('.filters-modern-actions');
            }
            if (!filtersSection) {
                filtersSection = document.querySelector('.filters-modern-header-actions');
            }
            if (!filtersSection) {
                (typeof logger !== 'undefined' ? logger.warn : console.warn)('SavedFilters: Cannot find container for dropdown');
                // Пробуем создать контейнер, если его нет
                const header = document.querySelector('.filters-modern-header');
                if (header) {
                    const actionsDiv = document.createElement('div');
                    actionsDiv.className = 'filters-modern-actions';
                    actionsDiv.id = 'savedFiltersContainer';
                    header.appendChild(actionsDiv);
                    filtersSection = actionsDiv;
                } else {
                    return;
                }
            }
            
            dropdown = document.createElement('div');
            dropdown.id = 'savedFiltersDropdown';
            dropdown.className = 'dropdown';
            dropdown.style.marginRight = '8px';
            dropdown.innerHTML = `
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bookmark me-1"></i>
                    Сохранённые фильтры
                </button>
                <ul class="dropdown-menu" id="savedFiltersList">
                    <li><a class="dropdown-item" href="#" id="saveCurrentFilter">
                        <i class="fas fa-save me-2"></i>Сохранить текущий фильтр
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li id="savedFiltersItems"></li>
                </ul>
            `;
            filtersSection.insertBefore(dropdown, filtersSection.firstChild);
        }
        
        const itemsContainer = document.getElementById('savedFiltersItems');
        if (!itemsContainer) return;
        
        if (this.filters.length === 0) {
            itemsContainer.innerHTML = '<li><span class="dropdown-item-text text-muted">Нет сохранённых фильтров</span></li>';
            return;
        }
        
        let html = '';
        this.filters.forEach(filter => {
            html += `
                <li>
                    <a class="dropdown-item" href="#" data-filter-id="${filter.id}">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>${this.escapeHtml(filter.name)}</span>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-sm btn-outline-primary apply-filter-btn" data-filter-id="${filter.id}" title="Применить">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger delete-filter-btn" data-filter-id="${filter.id}" title="Удалить">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </a>
                </li>
            `;
        });
        
        itemsContainer.innerHTML = html;
    }
    
    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        document.addEventListener('click', (e) => {
            // Сохранение текущего фильтра
            if (e.target.closest('#saveCurrentFilter')) {
                e.preventDefault();
                this.showSaveDialog();
            }
            
            // Применение фильтра
            const applyBtn = e.target.closest('.apply-filter-btn');
            if (applyBtn) {
                e.preventDefault();
                const filterId = parseInt(applyBtn.dataset.filterId, 10);
                this.applyFilter(filterId);
            }
            
            // Удаление фильтра
            const deleteBtn = e.target.closest('.delete-filter-btn');
            if (deleteBtn) {
                e.preventDefault();
                const filterId = parseInt(deleteBtn.dataset.filterId, 10);
                this.deleteFilter(filterId);
            }
        });
    }
    
    /**
     * Показ диалога сохранения фильтра
     */
    showSaveDialog() {
        const name = prompt('Введите название для сохранения фильтра:');
        if (!name || name.trim() === '') {
            return;
        }
        
        // Получаем текущие параметры фильтров из URL
        // Multi-value параметры (например status[]=A&status[]=B) собираем в массивы
        const params = new URLSearchParams(window.location.search);
        const filters = {};
        
        params.forEach((value, key) => {
            if (key === 'page') return; // страницу не сохраняем
            if (Object.prototype.hasOwnProperty.call(filters, key)) {
                // уже есть значение для этого ключа — конвертируем в массив
                if (Array.isArray(filters[key])) {
                    filters[key].push(value);
                } else {
                    filters[key] = [filters[key], value];
                }
            } else {
                filters[key] = value;
            }
        });
        
        // Проверяем, что есть хоть один активный фильтр для сохранения
        if (Object.keys(filters).length === 0) {
            if (typeof window.showToast === 'function') {
                window.showToast('Нет активных фильтров для сохранения', 'warning');
            } else {
                alert('Нет активных фильтров для сохранения');
            }
            return;
        }
        
        this.saveFilter(name.trim(), filters);
    }
    
    /**
     * Сохранение фильтра
     */
    async saveFilter(name, filters) {
        try {
            // CSRF-токен обязателен для POST/PUT/DELETE — без него API возвращает 403
            const csrfToken = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
            const response = await fetch('api_saved_filters.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    name: name,
                    filters: filters,
                    csrf: csrfToken
                })
            });
            
            if (!response.ok) {
                throw new Error('Failed to save filter');
            }
            
            const data = await response.json();
            if (data.success) {
                if (typeof window.showToast === 'function') {
                    window.showToast('Фильтр сохранён', 'success');
                }
                await this.loadFilters();
            } else {
                throw new Error(data.error || 'Ошибка сохранения');
            }
        } catch (error) {
            (typeof logger !== 'undefined' ? logger.error : console.error)('Error saving filter:', error);
            if (typeof window.showToast === 'function') {
                window.showToast('Ошибка при сохранении фильтра: ' + error.message, 'error');
            }
        }
    }
    
    /**
     * Применение сохранённого фильтра
     */
    applyFilter(filterId) {
        const filter = this.filters.find(f => f.id === filterId);
        if (!filter || !filter.filters) {
            return;
        }
        
        // Строим URL с параметрами фильтра (поддержка массивов, например status[])
        const params = new URLSearchParams();
        Object.entries(filter.filters || {}).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach(v => params.append(key, v));
            } else if (value !== null && value !== undefined && value !== '') {
                params.set(key, value);
            }
        });
        params.set('page', '1'); // Сбрасываем страницу
        
        // Обновляем URL без перезагрузки страницы
        // Используем текущий pathname вместо hardcoded 'index.php?' — на случай кастомных путей
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Синхронизируем DOM формы фильтров по новому URL
        if (typeof window.syncFormFromUrl === 'function') {
            window.syncFormFromUrl();
        }
        
        // Обновляем данные через AJAX
        if (typeof refreshDashboardData === 'function') {
            if (typeof selectedAllFiltered !== 'undefined') {
                selectedAllFiltered = false;
            }
            if (typeof selectedIds !== 'undefined' && selectedIds instanceof Set) {
                selectedIds.clear();
            }
            if (typeof updateSelectedCount === 'function') {
                updateSelectedCount();
            }
            refreshDashboardData();
        } else {
            window.location.href = newUrl;
        }
    }
    
    /**
     * Удаление сохранённого фильтра
     */
    async deleteFilter(filterId) {
        if (!confirm('Удалить этот сохранённый фильтр?')) {
            return;
        }
        
        try {
            // CSRF-токен обязателен для DELETE — без него API возвращает 403
            const csrfToken = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
            const response = await fetch('api_saved_filters.php', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    id: filterId,
                    csrf: csrfToken
                })
            });
            
            if (!response.ok) {
                throw new Error('Failed to delete filter');
            }
            
            const data = await response.json();
            if (data.success) {
                if (typeof window.showToast === 'function') {
                    window.showToast('Фильтр удалён', 'success');
                }
                await this.loadFilters();
            } else {
                throw new Error(data.error || 'Ошибка удаления');
            }
        } catch (error) {
            (typeof logger !== 'undefined' ? logger.error : console.error)('Error deleting filter:', error);
            if (typeof window.showToast === 'function') {
                window.showToast('Ошибка при удалении фильтра: ' + error.message, 'error');
            }
        }
    }
    
    /**
     * Экранирование HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    window.savedFiltersManager = new SavedFiltersManager();
});


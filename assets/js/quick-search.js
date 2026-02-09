/**
 * Быстрый поиск по ID (Jump to)
 * Горячая клавиша Ctrl+K или / для мгновенного поиска
 */
class QuickSearch {
    constructor() {
        this.modal = null;
        this.input = null;
        this.results = [];
        this.selectedIndex = -1;
        this.isOpen = false;
        this.keydownHandler = null; // Сохраняем ссылку на обработчик для удаления
        
        this.init();
    }
    
    init() {
        // Создаем модальное окно для быстрого поиска
        this.createModal();
        
        // Обработчики горячих клавиш (сохраняем ссылку для удаления)
        this.keydownHandler = (e) => {
            // Ctrl+K или / для открытия поиска
            if ((e.ctrlKey && e.key === 'k') || (e.key === '/' && !this.isInputFocused(e.target))) {
                e.preventDefault();
                this.open();
            }
            
            // Escape для закрытия
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
            
            // Стрелки для навигации по результатам
            if (this.isOpen) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.navigateResults(1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.navigateResults(-1);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    this.selectResult();
                }
            }
        };
        
        document.addEventListener('keydown', this.keydownHandler);
        
        // Дебаунс для поиска
        this.debouncedSearch = this.debounce(this.performSearch.bind(this), 300);
    }
    
    /**
     * Проверка, находится ли фокус в поле ввода
     */
    isInputFocused(element) {
        const tagName = element.tagName.toLowerCase();
        return tagName === 'input' || tagName === 'textarea' || element.isContentEditable;
    }
    
    /**
     * Создание модального окна для быстрого поиска
     */
    createModal() {
        const modal = document.createElement('div');
        modal.id = 'quickSearchModal';
        modal.className = 'quick-search-modal';
        modal.innerHTML = `
            <div class="quick-search-backdrop"></div>
            <div class="quick-search-container">
                <div class="quick-search-header">
                    <i class="fas fa-search me-2"></i>
                    <input 
                        type="text" 
                        id="quickSearchInput" 
                        class="quick-search-input" 
                        placeholder="Поиск по ID, логину, email... (Ctrl+K для открытия)"
                        autocomplete="off"
                    />
                    <span class="quick-search-hint">ESC для закрытия</span>
                </div>
                <div class="quick-search-results" id="quickSearchResults"></div>
            </div>
        `;
        
        document.body.appendChild(modal);
        this.modal = modal;
        this.input = modal.querySelector('#quickSearchInput');
        this.resultsContainer = modal.querySelector('#quickSearchResults');
        
        // Обработчик ввода
        this.input.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            if (query.length >= 2) {
                this.debouncedSearch(query);
            } else {
                this.clearResults();
            }
        });
        
        // Закрытие при клике на backdrop
        modal.querySelector('.quick-search-backdrop').addEventListener('click', () => {
            this.close();
        });
        
        // Добавляем стили
        this.addStyles();
    }
    
    /**
     * Добавление стилей для модального окна
     */
    addStyles() {
        if (document.getElementById('quickSearchStyles')) {
            return; // Стили уже добавлены
        }
        
        const style = document.createElement('style');
        style.id = 'quickSearchStyles';
        style.textContent = `
            .quick-search-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 10000;
                display: none;
            }
            
            .quick-search-modal.show {
                display: flex;
                align-items: flex-start;
                justify-content: center;
                padding-top: 100px;
            }
            
            .quick-search-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(4px);
            }
            
            .quick-search-container {
                position: relative;
                width: 600px;
                max-width: 90vw;
                background: white;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                z-index: 1;
                animation: quickSearchSlideDown 0.2s ease-out;
            }
            
            @keyframes quickSearchSlideDown {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .quick-search-header {
                padding: 20px;
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .quick-search-input {
                flex: 1;
                border: none;
                outline: none;
                font-size: 16px;
                padding: 8px 0;
            }
            
            .quick-search-hint {
                font-size: 12px;
                color: #6b7280;
                white-space: nowrap;
            }
            
            .quick-search-results {
                max-height: 400px;
                overflow-y: auto;
            }
            
            .quick-search-result {
                padding: 12px 20px;
                cursor: pointer;
                border-bottom: 1px solid #f3f4f6;
                display: flex;
                align-items: center;
                gap: 12px;
                transition: background 0.15s;
            }
            
            .quick-search-result:hover,
            .quick-search-result.selected {
                background: #f3f4f6;
            }
            
            .quick-search-result-icon {
                width: 40px;
                height: 40px;
                border-radius: 8px;
                background: #e5e7eb;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #6b7280;
                font-weight: 600;
            }
            
            .quick-search-result-content {
                flex: 1;
            }
            
            .quick-search-result-title {
                font-weight: 600;
                color: #111827;
                margin-bottom: 4px;
            }
            
            .quick-search-result-subtitle {
                font-size: 13px;
                color: #6b7280;
            }
            
            .quick-search-result-type {
                font-size: 11px;
                padding: 4px 8px;
                background: #e5e7eb;
                border-radius: 4px;
                color: #6b7280;
            }
            
            .quick-search-empty {
                padding: 40px 20px;
                text-align: center;
                color: #6b7280;
            }
            
            .quick-search-loading {
                padding: 40px 20px;
                text-align: center;
                color: #6b7280;
            }
        `;
        document.head.appendChild(style);
    }
    
    /**
     * Открытие модального окна
     */
    open() {
        this.modal.classList.add('show');
        this.input.focus();
        this.input.select();
        this.isOpen = true;
        
        // Очищаем предыдущие результаты
        this.clearResults();
    }
    
    /**
     * Закрытие модального окна
     */
    close() {
        this.modal.classList.remove('show');
        this.input.value = '';
        this.clearResults();
        this.isOpen = false;
        this.selectedIndex = -1;
    }
    
    /**
     * Выполнение поиска
     */
    async performSearch(query) {
        if (!query || query.length < 2) {
            this.clearResults();
            return;
        }
        
        this.showLoading();
        
        try {
            // Определяем тип поиска
            const isNumeric = /^\d+$/.test(query);
            const params = new URLSearchParams({
                q: query,
                limit: 10
            });
            
            const response = await fetch(`api.php?${params.toString()}`, {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error('Ошибка поиска');
            }
            
            const data = await response.json();
            
            // Получаем полные данные аккаунтов для отображения
            if (data.success && data.count > 0) {
                // Запрашиваем список аккаунтов через refresh.php
                const refreshParams = new URLSearchParams({
                    q: query,
                    per_page: 10,
                    sort: 'id',
                    dir: 'desc'
                });
                
                const refreshResponse = await fetch(`refresh.php?${refreshParams.toString()}`, {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                if (refreshResponse.ok) {
                    const refreshData = await refreshResponse.json();
                    if (refreshData.success && refreshData.rows) {
                        this.displayResults(refreshData.rows, query);
                        return;
                    }
                }
            }
            
            this.showEmpty();
        } catch (error) {
            console.error('Quick search error:', error);
            this.showError('Ошибка при выполнении поиска');
        }
    }
    
    /**
     * Экранирование HTML для защиты от XSS
     */
    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
    
    /**
     * Безопасная подсветка найденного текста
     */
    highlight(text, search) {
        if (!text) return '';
        const escapedText = this.escapeHtml(text);
        const escapedSearch = this.escapeHtml(search);
        // Экранируем специальные символы regex
        const safeSearch = escapedSearch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${safeSearch})`, 'gi');
        return escapedText.replace(regex, '<mark>$1</mark>');
    }
    
    /**
     * Отображение результатов
     */
    displayResults(rows, query) {
        this.results = rows;
        this.selectedIndex = -1;
        
        if (rows.length === 0) {
            this.showEmpty();
            return;
        }
        
        let html = '';
        rows.forEach((row, index) => {
            const id = this.escapeHtml(String(row.id || ''));
            const login = row.login || '';
            const email = row.email || '';
            const status = row.status || '';
            
            html += `
                <div class="quick-search-result" data-index="${index}" data-id="${id}">
                    <div class="quick-search-result-icon">#${id}</div>
                    <div class="quick-search-result-content">
                        <div class="quick-search-result-title">
                            ${this.highlight(login || 'Без логина', query)}
                        </div>
                        <div class="quick-search-result-subtitle">
                            ${email ? this.highlight(email, query) : 'Email не указан'} ${status ? `• ${this.escapeHtml(status)}` : ''}
                        </div>
                    </div>
                    <div class="quick-search-result-type">Аккаунт</div>
                </div>
            `;
        });
        
        this.resultsContainer.innerHTML = html;
        
        // Обработчики клика
        this.resultsContainer.querySelectorAll('.quick-search-result').forEach((el, index) => {
            el.addEventListener('click', () => {
                this.selectedIndex = index;
                this.selectResult();
            });
        });
    }
    
    /**
     * Отображение состояния загрузки
     */
    showLoading() {
        this.resultsContainer.innerHTML = `
            <div class="quick-search-loading">
                <span class="loader loader-sm loader-primary" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;margin-right:8px;"></span>
                Поиск...
            </div>
        `;
    }
    
    /**
     * Отображение пустых результатов
     */
    showEmpty() {
        this.resultsContainer.innerHTML = `
            <div class="quick-search-empty">
                <i class="fas fa-search me-2"></i>
                Ничего не найдено
            </div>
        `;
    }
    
    /**
     * Отображение ошибки
     */
    showError(message) {
        const escapedMessage = this.escapeHtml(message);
        this.resultsContainer.innerHTML = `
            <div class="quick-search-empty" style="color: #dc2626;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ${escapedMessage}
            </div>
        `;
    }
    
    /**
     * Очистка результатов
     */
    clearResults() {
        this.resultsContainer.innerHTML = '';
        this.results = [];
        this.selectedIndex = -1;
    }
    
    /**
     * Навигация по результатам
     */
    navigateResults(direction) {
        if (this.results.length === 0) return;
        
        this.selectedIndex += direction;
        
        if (this.selectedIndex < 0) {
            this.selectedIndex = this.results.length - 1;
        } else if (this.selectedIndex >= this.results.length) {
            this.selectedIndex = 0;
        }
        
        // Обновляем выделение
        this.resultsContainer.querySelectorAll('.quick-search-result').forEach((el, index) => {
            if (index === this.selectedIndex) {
                el.classList.add('selected');
                el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                el.classList.remove('selected');
            }
        });
    }
    
    /**
     * Выбор результата
     */
    selectResult() {
        if (this.selectedIndex >= 0 && this.selectedIndex < this.results.length) {
            const result = this.results[this.selectedIndex];
            const id = result.id;
            
            if (id) {
                // Переход на страницу просмотра аккаунта
                window.location.href = `view.php?id=${id}`;
            }
        } else if (this.results.length > 0) {
            // Если ничего не выбрано, выбираем первый результат
            this.selectedIndex = 0;
            this.selectResult();
        }
    }
    
    /**
     * Дебаунс функция
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Очистка ресурсов и удаление обработчиков
     */
    cleanup() {
        // Удаляем обработчик keydown
        if (this.keydownHandler) {
            document.removeEventListener('keydown', this.keydownHandler);
            this.keydownHandler = null;
        }
        
        // Удаляем модальное окно
        if (this.modal && this.modal.parentNode) {
            this.modal.parentNode.removeChild(this.modal);
            this.modal = null;
        }
        
        // Очищаем результаты
        this.results = [];
        this.selectedIndex = -1;
        this.isOpen = false;
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    window.quickSearch = new QuickSearch();
});

// Очистка при закрытии страницы
window.addEventListener('beforeunload', () => {
    if (window.quickSearch && typeof window.quickSearch.cleanup === 'function') {
        window.quickSearch.cleanup();
    }
});



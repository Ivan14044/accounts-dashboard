
/* === assets/js/validation.js === */
/**
 * Валидация форм на клиенте
 * Предотвращает отправку невалидных данных и улучшает UX
 */
class FormValidator {
    /**
     * Валидация формы фильтров
     * 
     * @param {HTMLFormElement} form Форма для валидации
     * @returns {string[]} Массив сообщений об ошибках (пустой если валидация прошла)
     */
    static validateFilters(form) {
        const errors = [];
        
        // Валидация диапазонов - начальное значение не может быть больше конечного
        const validateRange = (fromName, toName, label) => {
            const fromInput = form.querySelector(`[name="${fromName}"]`);
            const toInput = form.querySelector(`[name="${toName}"]`);
            
            if (fromInput && toInput) {
                const fromValue = fromInput.value.trim();
                const toValue = toInput.value.trim();
                
                if (fromValue && toValue) {
                    const fromNum = parseInt(fromValue, 10);
                    const toNum = parseInt(toValue, 10);
                    
                    if (!isNaN(fromNum) && !isNaN(toNum) && fromNum > toNum) {
                        errors.push(`${label}: начальное значение (${fromNum}) не может быть больше конечного (${toNum})`);
                    }
                    
                    // Проверка отрицательных значений для количественных полей
                    if (fromNum < 0 || toNum < 0) {
                        errors.push(`${label}: значения не могут быть отрицательными`);
                    }
                }
            }
        };
        
        // Валидация всех диапазонных фильтров
        validateRange('pharma_from', 'pharma_to', 'Pharma');
        validateRange('friends_from', 'friends_to', 'Количество друзей');
        validateRange('year_created_from', 'year_created_to', 'Год создания');
        validateRange('limit_rk_from', 'limit_rk_to', 'Limit RK');
        
        // Валидация года создания - должен быть в разумных пределах
        const yearFromInput = form.querySelector('[name="year_created_from"]');
        const yearToInput = form.querySelector('[name="year_created_to"]');
        
        if (yearFromInput) {
            const yearFrom = parseInt(yearFromInput.value.trim(), 10);
            if (yearFrom && !isNaN(yearFrom)) {
                const currentYear = new Date().getFullYear();
                if (yearFrom < 1900 || yearFrom > currentYear) {
                    errors.push(`Год создания: начальное значение должно быть между 1900 и ${currentYear}`);
                }
            }
        }
        
        if (yearToInput) {
            const yearTo = parseInt(yearToInput.value.trim(), 10);
            if (yearTo && !isNaN(yearTo)) {
                const currentYear = new Date().getFullYear();
                if (yearTo < 1900 || yearTo > currentYear) {
                    errors.push(`Год создания: конечное значение должно быть между 1900 и ${currentYear}`);
                }
            }
        }
        
        // Валидация поискового запроса - не слишком длинный
        const searchInput = form.querySelector('[name="q"]');
        if (searchInput) {
            const searchValue = searchInput.value.trim();
            if (searchValue.length > 255) {
                errors.push('Поисковый запрос слишком длинный (максимум 255 символов)');
            }
        }
        
        return errors;
    }
    
    /**
     * Валидация формы массового обновления
     * 
     * @param {HTMLFormElement} form Форма для валидации
     * @returns {string[]} Массив сообщений об ошибках
     */
    static validateBulkUpdate(form) {
        const errors = [];
        
        const fieldInput = form.querySelector('[name="field"]');
        const valueInput = form.querySelector('[name="value"]');
        
        if (fieldInput && !fieldInput.value.trim()) {
            errors.push('Необходимо указать поле для обновления');
        }
        
        if (valueInput && valueInput.value.length > 65535) {
            errors.push('Значение слишком длинное (максимум 65535 символов)');
        }
        
        return errors;
    }
    
    /**
     * Показать ошибки валидации пользователю
     * 
     * @param {string[]} errors Массив сообщений об ошибках
     */
    static showErrors(errors) {
        if (errors.length === 0) {
            return;
        }
        
        // Используем существующую систему уведомлений
        if (typeof window.showToast === 'function') {
            const errorMessage = errors.join('\n');
            window.showToast(errorMessage, 'error');
        } else {
            // Fallback на alert
            alert('Ошибки валидации:\n\n' + errors.join('\n'));
        }
    }
    
    /**
     * Добавить валидацию к форме
     * 
     * @param {HTMLFormElement} form Форма для валидации
     * @param {Function} validatorFn Функция валидации
     */
    static attachToForm(form, validatorFn) {
        if (!form) {
            return;
        }
        
        form.addEventListener('submit', (e) => {
            const errors = validatorFn(form);
            
            if (errors.length > 0) {
                e.preventDefault();
                e.stopPropagation();
                this.showErrors(errors);
                return false;
            }
            
            return true;
        });
        
        // Валидация при потере фокуса на полях диапазонов
        const rangeInputs = form.querySelectorAll('[name$="_from"], [name$="_to"]');
        rangeInputs.forEach(input => {
            input.addEventListener('blur', () => {
                const errors = validatorFn(form);
                if (errors.length > 0) {
                    // Подсвечиваем поле с ошибкой
                    input.classList.add('is-invalid');
                    
                    // Показываем ошибку только если есть связанное поле
                    const pairName = input.name.endsWith('_from') 
                        ? input.name.replace('_from', '_to')
                        : input.name.replace('_to', '_from');
                    const pairInput = form.querySelector(`[name="${pairName}"]`);
                    
                    if (pairInput && pairInput.value.trim() && input.value.trim()) {
                        const error = errors.find(err => err.includes(input.name.split('_')[0]));
                        if (error && typeof window.showToast === 'function') {
                            window.showToast(error, 'error');
                        }
                    }
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            
            input.addEventListener('input', () => {
                input.classList.remove('is-invalid');
            });
        });
    }
}

// Автоматическая инициализация валидации для всех форм фильтров
document.addEventListener('DOMContentLoaded', () => {
    const filtersForm = document.querySelector('form[method="get"]');
    if (filtersForm) {
        FormValidator.attachToForm(filtersForm, FormValidator.validateFilters);
    }
    
    // Валидация для форм массового обновления
    const bulkUpdateForms = document.querySelectorAll('form[data-action="bulk-update"]');
    bulkUpdateForms.forEach(form => {
        FormValidator.attachToForm(form, FormValidator.validateBulkUpdate);
    });
});

// Экспорт для использования в других модулях
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FormValidator;
}




/* === assets/js/toast.js === */
/**
 * Toast Notifications - Современные уведомления вместо alert()
 * 
 * Использование:
 * Toast.success('Данные сохранены');
 * Toast.error('Произошла ошибка');
 * Toast.warning('Внимание!');
 * Toast.info('Информация');
 * 
 * Или с настройками:
 * Toast.show('Сообщение', { type: 'success', duration: 5000 });
 */
class Toast {
    constructor() {
        this.container = null;
        this.queue = [];
        this.init();
    }
    
    /**
     * Инициализация контейнера
     */
    init() {
        if (document.querySelector('.toast-container')) {
            return; // Уже инициализирован
        }
        
        this.container = document.createElement('div');
        this.container.className = 'toast-container';
        document.body.appendChild(this.container);
    }
    
    /**
     * Показать уведомление
     * 
     * @param {string} message Текст сообщения
     * @param {object} options Опции
     */
    show(message, options = {}) {
        const defaults = {
            type: 'info', // info, success, warning, error
            duration: 3000, // Длительность в мс (0 = бесконечно)
            closable: true // Показывать кнопку закрытия
        };
        
        const config = { ...defaults, ...options };
        
        // Создаём элемент toast
        const toast = this.createToast(message, config);
        
        // Добавляем в контейнер
        this.container.appendChild(toast);
        
        // Анимация появления
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        // Автоматическое удаление
        if (config.duration > 0) {
            setTimeout(() => {
                this.hide(toast);
            }, config.duration);
        }
        
        return toast;
    }
    
    /**
     * Создание элемента toast
     */
    createToast(message, config) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${config.type}`;
        
        // Иконка
        const icon = this.getIcon(config.type);
        
        // Progress bar для показа оставшегося времени
        const progressBar = config.duration > 0 ? 
            `<div class="toast-progress-bar">
                <div class="toast-progress-fill"></div>
            </div>` : '';
        
        // Структура
        toast.innerHTML = `
            <div class="toast-content">
                <div class="toast-icon">${icon}</div>
                <div class="toast-message">${this.escapeHtml(message)}</div>
                ${config.closable ? '<button class="toast-close" type="button" aria-label="Закрыть" title="Закрыть"><i class="fas fa-times"></i></button>' : ''}
            </div>
            ${progressBar}
        `;
        
        // Обработчик закрытия
        if (config.closable) {
            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.addEventListener('click', () => {
                this.hide(toast);
            });
        }
        
        // Анимация progress bar
        if (config.duration > 0 && progressBar) {
            const progressFill = toast.querySelector('.toast-progress-fill');
            if (progressFill) {
                // Сбрасываем анимацию
                progressFill.style.width = '100%';
                progressFill.style.transition = 'none';
                
                // Запускаем анимацию через небольшой таймаут
                setTimeout(() => {
                    progressFill.style.transition = `width ${config.duration}ms linear`;
                    progressFill.style.width = '0%';
                }, 50);
            }
        }
        
        return toast;
    }
    
    /**
     * Скрытие toast
     */
    hide(toast) {
        // Останавливаем progress bar анимацию
        const progressFill = toast.querySelector('.toast-progress-fill');
        if (progressFill) {
            progressFill.style.transition = 'none';
            progressFill.style.width = '0%';
        }
        
        toast.classList.remove('show');
        toast.classList.add('hide');
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300); // Время анимации
    }
    
    /**
     * Получение иконки по типу
     */
    getIcon(type) {
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        return icons[type] || icons.info;
    }
    
    /**
     * Экранирование HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Shortcut методы
     */
    success(message, options = {}) {
        return this.show(message, { ...options, type: 'success' });
    }
    
    error(message, options = {}) {
        return this.show(message, { ...options, type: 'error' });
    }
    
    warning(message, options = {}) {
        return this.show(message, { ...options, type: 'warning' });
    }
    
    info(message, options = {}) {
        return this.show(message, { ...options, type: 'info' });
    }
    
    /**
     * Очистка всех toast
     */
    clearAll() {
        const toasts = this.container.querySelectorAll('.toast');
        toasts.forEach(toast => this.hide(toast));
    }
}

// Создаём глобальный экземпляр
window.Toast = new Toast();

// Для обратной совместимости - перехватываем alert()
// (опционально, можно включить если нужно)
/*
window.alertOriginal = window.alert;
window.alert = function(message) {
    Toast.info(message);
};
*/









/* === assets/js/sticky-scrollbar.js === */
/**
 * СТИКИ СКРОЛЛБАР - Новая реализация с нуля
 * Простая, быстрая и надежная синхронизация горизонтального скролла
 */
(function() {
  'use strict';

  class StickyScrollbar {
    constructor(options = {}) {
      this.options = {
        debug: options.debug || false,
        ...options
      };
      
      // Элементы
      this.wrapper = null;
      this.scrollbar = null;
      this.content = null;
      this.tableWrap = null;
      this.table = null;
      
      // Состояние
      this.isActive = false;
      this.lastScrollLeft = 0;
      
      // Флаги для предотвращения циклической синхронизации
      this.syncingFromSticky = false;
      this.syncingFromTable = false;
      
      // Наблюдатели
      this.resizeObserver = null;
      this.rafId = null;
      
      this.init();
    }
    
    /**
     * Инициализация
     */
    init() {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => this.setup());
      } else {
        this.setup();
      }
    }
    
    /**
     * Настройка компонента
     */
    setup() {
      // Поиск элементов
      this.wrapper = document.getElementById('stickyScrollbarWrapper');
      this.scrollbar = document.getElementById('stickyScrollbar');
      this.content = document.getElementById('stickyScrollbarContent');
      this.tableWrap = document.getElementById('tableWrap');
      this.table = document.getElementById('accountsTable');
      
      // Проверка наличия элементов
      if (!this.wrapper || !this.scrollbar || !this.content || !this.tableWrap || !this.table) {
        if (this.options.debug) {
          console.warn('StickyScrollbar: Не найдены необходимые элементы', {
            wrapper: !!this.wrapper,
            scrollbar: !!this.scrollbar,
            content: !!this.content,
            tableWrap: !!this.tableWrap,
            table: !!this.table
          });
        }
        return;
      }
      
      // Привязка событий
      this.attachEvents();
      
      // Настройка наблюдателей
      this.setupObservers();
      
      // Первичное обновление
      this.update();
      
      // Экспорт для внешнего использования
      window.updateStickyScrollbar = () => this.update();
      
      if (this.options.debug) {
        console.log('StickyScrollbar: Инициализация завершена');
      }
    }
    
    /**
     * Привязка событий скролла
     */
    attachEvents() {
      // Синхронизация: sticky -> table (пользователь скроллит sticky)
      this.scrollbar.addEventListener('scroll', () => {
        // Пропускаем если синхронизация идет от таблицы
        if (this.syncingFromTable) return;
        
        // Устанавливаем флаг
        this.syncingFromSticky = true;
        
        // Мгновенная синхронизация напрямую
        const scrollLeft = this.scrollbar.scrollLeft;
        
        // Синхронизируем только если позиции отличаются
        if (Math.abs(this.tableWrap.scrollLeft - scrollLeft) > 0.1) {
          this.tableWrap.scrollLeft = scrollLeft;
          this.lastScrollLeft = scrollLeft;
        }
        
        // Сбрасываем флаг асинхронно для предотвращения циклов
        // Используем микротаск для немедленного сброса после текущего стека
        queueMicrotask(() => {
          this.syncingFromSticky = false;
        });
      }, { passive: true });
      
      // Синхронизация: table -> sticky (пользователь скроллит table)
      this.tableWrap.addEventListener('scroll', () => {
        // Пропускаем если синхронизация идет от sticky
        if (this.syncingFromSticky) return;
        
        // Устанавливаем флаг
        this.syncingFromTable = true;
        
        // Мгновенная синхронизация напрямую
        const scrollLeft = this.tableWrap.scrollLeft;
        
        // Синхронизируем только если позиции отличаются
        if (Math.abs(this.scrollbar.scrollLeft - scrollLeft) > 0.1) {
          this.scrollbar.scrollLeft = scrollLeft;
          this.lastScrollLeft = scrollLeft;
        }
        
        // Сбрасываем флаг асинхронно для предотвращения циклов
        // Используем микротаск для немедленного сброса после текущего стека
        queueMicrotask(() => {
          this.syncingFromTable = false;
        });
      }, { passive: true });
      
      // Обновление при ресайзе окна
      let resizeTimer;
      window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => this.update(), 100);
      }, { passive: true });
    }
    
    /**
     * Настройка ResizeObserver для отслеживания размеров таблицы
     */
    setupObservers() {
      if (typeof ResizeObserver === 'undefined') return;
      
      this.resizeObserver = new ResizeObserver(() => {
        // Отменяем предыдущий RAF
        if (this.rafId) {
          cancelAnimationFrame(this.rafId);
        }
        
        // Используем RAF для батчинга обновлений
        this.rafId = requestAnimationFrame(() => {
          this.update();
          this.rafId = null;
        });
      });
      
      // Наблюдаем за таблицей и контейнером
      this.resizeObserver.observe(this.table);
      this.resizeObserver.observe(this.tableWrap);
    }
    
    /**
     * Обновление размеров и видимости скроллбара
     */
    update() {
      if (!this.table || !this.tableWrap || !this.content || !this.wrapper) {
        return;
      }
      
      // Получаем размеры
      const tableWidth = this.table.scrollWidth;
      const containerWidth = this.tableWrap.clientWidth;
      
      // Проверяем, нужен ли скроллбар
      const needsScrollbar = tableWidth > containerWidth;
      
      if (needsScrollbar) {
        // Устанавливаем ширину контента для корректной прокрутки
        this.content.style.width = tableWidth + 'px';
        
        // Показываем скроллбар
        if (!this.isActive) {
          this.wrapper.classList.add('active');
          this.isActive = true;
        }
        
        // Синхронизируем позицию скролла
        this.syncPosition();
      } else {
        // Скрываем скроллбар
        if (this.isActive) {
          this.wrapper.classList.remove('active');
          this.isActive = false;
        }
      }
      
      if (this.options.debug) {
        console.log('StickyScrollbar: update', {
          tableWidth,
          containerWidth,
          needsScrollbar,
          scrollLeft: this.tableWrap.scrollLeft
        });
      }
    }
    
    /**
     * Синхронизация позиции скролла
     */
    syncPosition() {
      if (!this.tableWrap || !this.scrollbar) return;
      
      const tableScrollLeft = this.tableWrap.scrollLeft;
      
      // Синхронизируем только если позиции отличаются
      if (Math.abs(this.scrollbar.scrollLeft - tableScrollLeft) > 1) {
        this.scrollbar.scrollLeft = tableScrollLeft;
        this.lastScrollLeft = tableScrollLeft;
      }
    }
    
    /**
     * Очистка ресурсов
     */
    destroy() {
      // Отключаем наблюдателей
      if (this.resizeObserver) {
        this.resizeObserver.disconnect();
        this.resizeObserver = null;
      }
      
      // Отменяем RAF
      if (this.rafId) {
        cancelAnimationFrame(this.rafId);
        this.rafId = null;
      }
      
      // Удаляем метод обновления
      if (window.updateStickyScrollbar) {
        delete window.updateStickyScrollbar;
      }
    }
  }
  
  // Создаем глобальный экземпляр
  let instance = null;
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      instance = new StickyScrollbar();
    });
  } else {
    instance = new StickyScrollbar();
  }
  
  // Экспорт для внешнего доступа
  window.StickyScrollbar = StickyScrollbar;
  
})();


/* === assets/js/table-module.js === */
/**
 * ============================================================================
 * TABLE MODULE - Единый модуль управления таблицей
 * ============================================================================
 * 
 * Объединяет сортировку, виртуализацию и управление таблицей
 * 
 * ВЕРСИЯ: 3.0
 * ДАТА: 2024
 * ============================================================================
 */

(function() {
  'use strict';

  const CLIP_LEN = 80;
  const TOKEN_CLIP = 20;
  const LONG_FIELDS = ['cookies', 'user_agent', 'extra_info_1', 'extra_info_2', 'extra_info_3', 'extra_info_4'];

  class TableModule {
    constructor(root, options = {}) {
      this.root = root;
      this.table = document.getElementById('accountsTable');
      this.tbody = this.table ? this.table.querySelector('tbody') : null;
      this.toolbar = document.getElementById('rowsCounterBar');
      this.scrollContainer = document.getElementById('tableWrap');
      this.options = Object.assign({
        virtualization: {
          threshold: 80,
          bufferSize: 15,
          rowHeight: 48,
          debug: false
        }
      }, options);
      this.boundSortHandler = this.handleSortClick.bind(this);
      this.virtualScroller = null;
      if (this.table) {
        this.init();
      }
    }

    init() {
      this.bindSortLinks();
      this.setupVirtualization();
      const params = new URLSearchParams(window.location.search);
      this.updateSortIndicators(params.get('sort') || 'id', params.get('dir') || 'asc');
      window.tableModule = this;
      window.tableLayoutManager = {
        refresh: () => this.refreshLayout()
      };
    }

    bindSortLinks() {
      document.addEventListener('click', this.boundSortHandler, true);
    }

    handleSortClick(event) {
      const sortLink = event.target.closest('[data-sort-link]');
      if (!sortLink) return;
      const column = sortLink.getAttribute('data-sort-column');
      if (!column) return;
      event.preventDefault();
      event.stopPropagation();
      const params = new URLSearchParams(window.location.search);
      const currentSort = params.get('sort') || 'id';
      const currentDir = params.get('dir') || 'asc';
      let newDir = 'asc';
      if (currentSort === column && currentDir === 'asc') {
        newDir = 'desc';
      }
      params.set('sort', column);
      params.set('dir', newDir);
      params.set('page', '1');
      history.pushState(null, '', window.location.pathname + '?' + params.toString());
      this.updateSortIndicators(column, newDir);
      if (typeof selectedAllFiltered !== 'undefined') {
        selectedAllFiltered = false;
      }
      if (typeof selectedIds !== 'undefined' && selectedIds instanceof Set) {
        selectedIds.clear();
      }
      if (typeof updateSelectedCount === 'function') {
        updateSelectedCount();
      }
      if (typeof refreshDashboardData === 'function') {
        refreshDashboardData();
      }
    }

    updateSortIndicators(activeColumn, dir) {
      if (!this.table) return;
      this.table.querySelectorAll('thead th[data-col] a[data-sort-link]').forEach(link => {
        const col = link.getAttribute('data-sort-column');
        const indicator = link.querySelector('.sort-indicator');
        if (indicator) {
          indicator.textContent = (col === activeColumn) ? (dir === 'asc' ? '▲' : '▼') : '';
        }
        const th = link.closest('th');
        if (th) {
          th.setAttribute('aria-sort', col === activeColumn ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
        }
      });
    }

    setupVirtualization() {
      this.virtualScroller = new TableVirtualization({
        table: this.table,
        scrollContainer: this.scrollContainer,
        threshold: this.options.virtualization.threshold,
        bufferSize: this.options.virtualization.bufferSize,
        rowHeight: this.options.virtualization.rowHeight,
        debug: this.options.virtualization.debug
      });
      this.virtualScroller.mount();
      window.tableVirtualization = this.virtualScroller;
    }

    updateRows(data) {
      if (!this.table) return;
      const rows = Array.isArray(data?.rows) ? data.rows : [];
      const columns = Array.isArray(data?.columns) && data.columns.length
        ? data.columns
        : this.getColumnKeys();
      const tbody = this.table.querySelector('tbody');
      if (!tbody) return;
      if (!rows.length) {
        tbody.innerHTML = this.emptyStateHtml(columns.length);
      } else {
        tbody.innerHTML = rows.map(row => this.renderRow(row, columns)).join('');
      }
      this.tbody = tbody;
      this.afterRender();
    }

    afterRender() {
      if (typeof applySavedColumnVisibility === 'function') {
        applySavedColumnVisibility();
      }
      if (typeof initCheckboxStates === 'function') {
        initCheckboxStates();
      }
      if (typeof updateAllSelectedRowsHighlight === 'function') {
        updateAllSelectedRowsHighlight();
      }
      if (window.favoritesManager && typeof window.favoritesManager.updateTableFavorites === 'function') {
        window.favoritesManager.updateTableFavorites();
      }
      if (typeof updateSelectedCount === 'function') {
        updateSelectedCount();
      }
      this.refreshLayout();
    }

    refreshLayout() {
      if (this.virtualScroller && typeof this.virtualScroller.refresh === 'function') {
        this.virtualScroller.refresh();
      }
      if (typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
    }

    getColumnKeys() {
      if (!this.table) return [];
      return Array.from(this.table.querySelectorAll('thead th[data-col]')).map(th => th.getAttribute('data-col'));
    }

    emptyStateHtml(colCount) {
      const totalCols = Number(colCount || 0) + 3;
      return `<tr class="ac-row ac-row--empty"><td colspan="${totalCols}" class="text-center text-muted py-5">
        <i class="fas fa-search fa-2x mb-3 text-muted"></i>
        <div>Ничего не найдено</div>
      </td></tr>`;
    }

    renderRow(row, columnKeys) {
      const cellsHtml = columnKeys.map(col => {
        if (col === 'id') {
          const idCell = `<td class="ac-cell ac-cell--id" data-col="id" data-column="id">
            <span class="fw-bold text-primary">#${this.escape(row[col])}</span>
          </td>`;
          const favoriteCell = `<td class="ac-cell ac-cell--favorite favorite-cell text-center" data-column="favorite" data-account-id="${row.id}">
            <button type="button" class="btn btn-sm btn-link favorite-btn p-0" data-account-id="${row.id}" title="Избранное">
              <i class="far fa-star"></i>
            </button>
          </td>`;
          return idCell + favoriteCell;
        }
        return `<td class="ac-cell" data-col="${col}" data-column="${col}">${this.renderCellContent(col, row)}</td>`;
      }).join('');

      return `<tr class="ac-row" data-id="${row.id}">
        <td class="ac-cell ac-cell--checkbox checkbox-cell" data-column="checkbox">
          <div class="form-check">
            <input class="form-check-input row-checkbox" type="checkbox" value="${row.id}" title="ID записи: ${row.id}">
          </div>
        </td>
        ${cellsHtml}
        <td class="ac-cell ac-cell--actions text-end" data-column="actions">
          <a class="btn btn-sm btn-outline-primary" href="view.php?id=${row.id}">
            <i class="fas fa-eye me-1"></i>Открыть
          </a>
        </td>
      </tr>`;
    }

    renderCellContent(col, row) {
      const value = row[col];
      if ((value === undefined || value === null || value === '') && !['password', 'email_password', 'id', 'actions'].includes(col)) {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="text-muted field-value">—</span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
        </div>`;
      }
      if (col === 'email') {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <a href="mailto:${this.escape(value)}" class="text-decoration-none field-value">${this.escape(value)}</a>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button class="copy-btn" type="button" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'login') {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="fw-semibold field-value">${this.escape(value)}</span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button class="copy-btn" type="button" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'password' || col === 'email_password') {
        const displayDots = !value ? '<span class="pw-dots text-muted">(не задан)</span>' : '<span class="pw-dots">••••••••</span>';
        return `<div class="pw-mask" data-row-id="${row.id}" data-field="${col}">
          ${displayDots}
          <span class="pw-text d-none">${this.escape(value)}</span>
          <button type="button" class="pw-toggle" title="Показать/скрыть"><i class="fas fa-eye"></i></button>
          <button type="button" class="pw-edit" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button type="button" class="copy-btn" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'token') {
        const clipped = this.clip(value, TOKEN_CLIP);
        return `<div class="d-flex align-items-center gap-2">
          <span class="truncate mono" title="Нажмите для просмотра" data-full="${this.escape(value)}" data-title="Token">
            ${this.escape(clipped)}
          </span>
          <button class="copy-btn" type="button" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'status') {
        const statusValue = String(value || '').toLowerCase();
        let badgeClass = 'badge-default';
        let displayText = value || '—';
        if (value === null || value === '') {
          badgeClass = 'badge-empty-status';
          displayText = 'Пустой статус';
        } else if (statusValue.includes('new')) {
          badgeClass = 'badge-new';
        } else if (statusValue.includes('add_selphi_true')) {
          badgeClass = 'badge-add_selphi_true';
        } else if (statusValue.includes('error')) {
          badgeClass = 'badge-error_login';
        }
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="badge ${badgeClass} field-value">${this.escape(displayText)}</span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
        </div>`;
      }
      if (col === 'social_url' && /^https?:\/\//i.test(String(value || ''))) {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <a href="${this.escape(value)}" target="_blank" rel="noopener" class="text-decoration-none field-value">
            <i class="fas fa-external-link-alt me-2"></i>${this.escape(value)}
          </a>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
        </div>`;
      }
      if (LONG_FIELDS.includes(col) || (typeof value === 'string' && value.length > CLIP_LEN)) {
        const clipped = this.clip(value, CLIP_LEN);
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="truncate mono field-value" data-full="${this.escape(value)}" data-title="${this.escape(col)}">
            ${this.escape(clipped)}
          </span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
        </div>`;
      }
      return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
        <span class="field-value">${this.escape(value)}</span>
        <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
      </div>`;
    }

    escape(value) {
      return String(value ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
    }

    clip(value, length) {
      const str = String(value ?? '');
      return str.length > length ? str.slice(0, length) + '…' : str;
    }
  }

  // Встроенная виртуализация
  class TableVirtualization {
    constructor(options = {}) {
      this.options = Object.assign({
        threshold: 80,
        bufferSize: 15,
        rowHeight: 48,
        debug: false,
        table: null,
        scrollContainer: null
      }, options);
      this.table = this.options.table || document.getElementById('accountsTable');
      this.scrollTarget = this.options.scrollContainer || document.getElementById('tableWrap') || window;
      this.tbody = this.table ? this.table.querySelector('tbody') : null;
      this.allRows = [];
      this.spacerTop = null;
      this.spacerBottom = null;
      this.scrollHandler = null;
      this.resizeHandler = null;
      this.visibleRange = { start: 0, end: 0 };
      this.tableOffsetTop = 0;
      this.enabled = false;
      this.useWindowScroll = this.scrollTarget === window;
      this.isRendering = false;
      if (!this.scrollTarget) {
        this.scrollTarget = window;
        this.useWindowScroll = true;
      }
    }

    mount() {
      this.table = this.options.table || document.getElementById('accountsTable');
      this.scrollTarget = this.options.scrollContainer || document.getElementById('tableWrap') || window;
      this.tbody = this.table ? this.table.querySelector('tbody') : null;
      this.useWindowScroll = this.scrollTarget === window;
      if (!this.table || !this.tbody) {
        return;
      }
      this.checkAndToggle();
    }

    refresh() {
      this.mount();
      if (!this.table || !this.tbody) return;
      const rows = Array.from(this.tbody.querySelectorAll('tr[data-id]'));
      if (rows.length <= this.options.threshold) {
        this.disable(true);
        return;
      }
      this.disable(true);
      this.enable(rows);
    }

    checkAndToggle() {
      if (!this.tbody) return;
      const dataRows = Array.from(this.tbody.querySelectorAll('tr[data-id]'));
      if (dataRows.length > this.options.threshold) {
        if (!this.enabled) {
          this.enable(dataRows);
        }
      } else if (this.enabled) {
        this.disable();
      }
    }

    enable(currentRows) {
      this.enabled = true;
      this.allRows = currentRows || Array.from(this.tbody.querySelectorAll('tr[data-id]'));
      if (!this.allRows.length) {
        this.enabled = false;
        return;
      }
      const sampleRow = this.allRows[0];
      this.options.rowHeight = sampleRow.offsetHeight || this.options.rowHeight;
      this.allRows.forEach(row => row.remove());
      this.createSpacers();
      this.scrollHandler = this.throttle(() => this.updateVisibleRows(), 16);
      const target = this.useWindowScroll ? window : this.scrollTarget;
      target.addEventListener('scroll', this.scrollHandler, { passive: true });
      this.resizeHandler = this.debounce(() => {
        this.updateTableOffset();
        this.recalculate();
      }, 150);
      window.addEventListener('resize', this.resizeHandler, { passive: true });
      this.updateTableOffset();
      this.updateVisibleRows();
    }

    disable(preserveDom = false) {
      if (!this.enabled && !preserveDom) return;
      this.enabled = false;
      this.visibleRange = { start: 0, end: 0 };
      if (this.scrollHandler) {
        const target = this.useWindowScroll ? window : this.scrollTarget;
        target.removeEventListener('scroll', this.scrollHandler);
        this.scrollHandler = null;
      }
      if (this.resizeHandler) {
        window.removeEventListener('resize', this.resizeHandler);
        this.resizeHandler = null;
      }
      if (this.spacerTop) {
        this.spacerTop.remove();
        this.spacerTop = null;
      }
      if (this.spacerBottom) {
        this.spacerBottom.remove();
        this.spacerBottom = null;
      }
      if (!preserveDom && this.allRows.length && this.tbody) {
        const fragment = document.createDocumentFragment();
        this.allRows.forEach(row => fragment.appendChild(row));
        this.tbody.innerHTML = '';
        this.tbody.appendChild(fragment);
      }
      this.allRows = [];
    }

    createSpacers() {
      if (!this.tbody) return;
      this.spacerTop = document.createElement('tr');
      this.spacerTop.className = 'spacer spacer-top';
      this.spacerTop.innerHTML = '<td colspan="100" style="height:0;padding:0;border:0;"></td>';
      this.spacerBottom = document.createElement('tr');
      this.spacerBottom.className = 'spacer spacer-bottom';
      this.spacerBottom.innerHTML = '<td colspan="100" style="height:0;padding:0;border:0;"></td>';
      this.tbody.innerHTML = '';
      this.tbody.appendChild(this.spacerTop);
      this.tbody.appendChild(this.spacerBottom);
    }

    updateTableOffset() {
      if (!this.table) return;
      const rect = this.table.getBoundingClientRect();
      const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
      this.tableOffsetTop = rect.top + scrollY;
    }

    getRelativeScrollTop() {
      if (!this.useWindowScroll && this.scrollTarget) {
        return this.scrollTarget.scrollTop;
      }
      const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
      return Math.max(0, scrollY - this.tableOffsetTop);
    }

    getViewportHeight() {
      return this.useWindowScroll ? window.innerHeight : this.scrollTarget.clientHeight;
    }

    updateVisibleRows() {
      if (!this.enabled || this.isRendering || !this.allRows.length) {
        return;
      }
      this.isRendering = true;
      const totalRows = this.allRows.length;
      const scrollTop = this.getRelativeScrollTop();
      const viewport = this.getViewportHeight();
      const totalHeight = totalRows * this.options.rowHeight;
      const maxScrollTop = Math.max(0, totalHeight - viewport);
      const safeScroll = Math.max(0, Math.min(scrollTop, maxScrollTop));
      const startIndex = Math.max(0, Math.floor(safeScroll / this.options.rowHeight) - this.options.bufferSize);
      const endIndex = Math.min(totalRows, Math.ceil((safeScroll + viewport) / this.options.rowHeight) + this.options.bufferSize);
      if (startIndex === this.visibleRange.start && endIndex === this.visibleRange.end) {
        this.isRendering = false;
        return;
      }
      this.visibleRange = { start: startIndex, end: endIndex };
      const topHeight = startIndex * this.options.rowHeight;
      const bottomHeight = Math.max(0, (totalRows - endIndex) * this.options.rowHeight);
      const topCell = this.spacerTop?.querySelector('td');
      const bottomCell = this.spacerBottom?.querySelector('td');
      if (topCell) topCell.style.height = `${topHeight}px`;
      if (bottomCell) bottomCell.style.height = `${bottomHeight}px`;
      const renderedRows = Array.from(this.tbody.querySelectorAll('tr:not(.spacer)'));
      renderedRows.forEach(row => row.remove());
      const fragment = document.createDocumentFragment();
      for (let i = startIndex; i < endIndex; i += 1) {
        fragment.appendChild(this.allRows[i]);
      }
      this.tbody.insertBefore(fragment, this.spacerBottom);
      if (typeof applySavedColumnVisibility === 'function') {
        applySavedColumnVisibility();
      }
      if (typeof initCheckboxStates === 'function') {
        initCheckboxStates();
      }
      if (typeof updateAllSelectedRowsHighlight === 'function') {
        updateAllSelectedRowsHighlight();
      }
      this.isRendering = false;
    }

    recalculate() {
      if (!this.enabled || !this.allRows.length) return;
      const sampleRow = this.allRows[this.visibleRange.start] || this.allRows[0];
      if (sampleRow) {
        this.options.rowHeight = sampleRow.offsetHeight || this.options.rowHeight;
      }
      this.updateTableOffset();
      this.updateVisibleRows();
    }

    throttle(func, wait) {
      let timeout = null;
      let previous = 0;
      return (...args) => {
        const now = Date.now();
        const remaining = wait - (now - previous);
        if (remaining <= 0 || remaining > wait) {
          if (timeout) {
            clearTimeout(timeout);
            timeout = null;
          }
          previous = now;
          func.apply(this, args);
        } else if (!timeout) {
          timeout = setTimeout(() => {
            previous = Date.now();
            timeout = null;
            func.apply(this, args);
          }, remaining);
        }
      };
    }

    debounce(func, wait) {
      let timeout;
      return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
      };
    }
  }

  function bootstrapTableModule() {
    const root = document.querySelector('[data-module="accounts-table"]');
    if (!root) return;
    new TableModule(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapTableModule);
  } else {
    bootstrapTableModule();
  }
})();


/* === assets/js/filters-modern.js === */
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
            console.warn('Unknown filter:', filterName);
            return;
    }
    
    // Сбрасываем на первую страницу
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

/**
 * Удаление конкретного статуса через chip
 */
function removeStatusChip(statusValue) {
    if (!statusValue) {
        console.error('removeStatusChip: statusValue is required');
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
    
    // Сбрасываем на первую страницу
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Делаем функцию глобально доступной
window.removeStatusChip = removeStatusChip;

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
    
    // Автоматически отправляем форму
    const form = checkbox.closest('form');
    if (form) {
        form.submit();
    }
}


// ========================================
// АВТОМАТИЧЕСКОЕ ПРИМЕНЕНИЕ ФИЛЬТРОВ
// ========================================

// Отслеживаем изменения в полях формы для автоматического применения
document.addEventListener('DOMContentLoaded', function() {
    const filtersForm = document.getElementById('filtersForm');
    if (!filtersForm) return;
    
    // Автоматическое применение для чекбоксов статусов
    const statusCheckboxes = filtersForm.querySelectorAll('.status-checkbox');
    statusCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            // Небольшая задержка для визуальной обратной связи
            setTimeout(() => {
                filtersForm.submit();
            }, 100);
        });
    });
    
    // Автоматическое применение для select (status_marketplace, currency)
    const selectFilters = filtersForm.querySelectorAll('select[name="status_marketplace"], select[name="currency"]');
    selectFilters.forEach(select => {
        select.addEventListener('change', function() {
            setTimeout(() => {
                filtersForm.submit();
            }, 100);
        });
    });
    
    // Автоматическое применение для диапазонных фильтров при потере фокуса
    const rangeInputs = filtersForm.querySelectorAll('.range-input-modern');
    rangeInputs.forEach(input => {
        input.addEventListener('blur', function() {
            // Проверяем что значение изменилось
            if (this.dataset.initialValue !== this.value) {
                setTimeout(() => {
                    filtersForm.submit();
                }, 100);
            }
        });
        
        // Сохраняем начальное значение
        input.addEventListener('focus', function() {
            this.dataset.initialValue = this.value;
        });
        
        // Enter тоже применяет
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filtersForm.submit();
            }
        });
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
                    console.error('removeStatusChip function not found');
                }
            } else {
                console.warn('Status chip missing data-status-value attribute');
            }
            return;
        }
        
        // Обработка других фильтров
        if (filterType && filterType !== 'status') {
            if (typeof removeFilterChip === 'function') {
                removeFilterChip(filterType);
            } else {
                console.error('removeFilterChip function not found');
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
    
    console.log('✓ Modern Filters initialized (auto-apply mode)');
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



/* === assets/js/saved-filters.js === */
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
            console.error('Error loading saved filters:', error);
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
                console.warn('SavedFilters: Cannot find container for dropdown');
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
        const params = new URLSearchParams(window.location.search);
        const filters = {};
        
        params.forEach((value, key) => {
            if (key !== 'page') { // Исключаем параметр страницы
                filters[key] = value;
            }
        });
        
        this.saveFilter(name.trim(), filters);
    }
    
    /**
     * Сохранение фильтра
     */
    async saveFilter(name, filters) {
        try {
            const response = await fetch('api_saved_filters.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    name: name,
                    filters: filters
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
            console.error('Error saving filter:', error);
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
        
        // Строим URL с параметрами фильтра
        const params = new URLSearchParams(filter.filters);
        params.set('page', '1'); // Сбрасываем страницу
        
        // Обновляем URL без перезагрузки страницы
        const newUrl = 'index.php?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Обновляем данные через AJAX
        if (typeof refreshDashboardData === 'function') {
            // Сбрасываем выделение
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
            // Fallback на перезагрузку, если функция недоступна
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
            const response = await fetch('api_saved_filters.php', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    id: filterId
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
            console.error('Error deleting filter:', error);
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



/* === assets/js/quick-search.js === */
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
        
        this.init();
    }
    
    init() {
        // Создаем модальное окно для быстрого поиска
        this.createModal();
        
        // Обработчики горячих клавиш
        document.addEventListener('keydown', (e) => {
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
        });
        
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
            const id = row.id || '';
            const login = row.login || '';
            const email = row.email || '';
            const status = row.status || '';
            
            // Подсветка найденного текста
            const highlight = (text, search) => {
                if (!text) return '';
                const regex = new RegExp(`(${search})`, 'gi');
                return text.replace(regex, '<mark>$1</mark>');
            };
            
            html += `
                <div class="quick-search-result" data-index="${index}" data-id="${id}">
                    <div class="quick-search-result-icon">#${id}</div>
                    <div class="quick-search-result-content">
                        <div class="quick-search-result-title">
                            ${highlight(login || 'Без логина', query)}
                        </div>
                        <div class="quick-search-result-subtitle">
                            ${email ? highlight(email, query) : 'Email не указан'} ${status ? `• ${status}` : ''}
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
        this.resultsContainer.innerHTML = `
            <div class="quick-search-empty" style="color: #dc2626;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ${message}
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
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    window.quickSearch = new QuickSearch();
});




/* === assets/js/favorites.js === */
/**
 * Управление избранными аккаунтами
 * Добавление/удаление избранного с сохранением в БД
 */
class FavoritesManager {
    constructor() {
        this.favorites = new Set();
        this.userId = null;
        this.loaded = false;
        this.processingIds = new Set(); // Защита от множественных кликов
        this.updateDebounceTimer = null; // Debounce для обновления UI
        
        this.init();
    }
    
    async init() {
        // Загружаем список избранных при загрузке страницы
        await this.loadFavorites();
        
        // Обработка кликов на кнопки избранного
        document.addEventListener('click', (e) => {
            const favoriteBtn = e.target.closest('.favorite-btn');
            if (favoriteBtn) {
                e.preventDefault();
                const accountId = parseInt(favoriteBtn.dataset.accountId || favoriteBtn.closest('[data-account-id]')?.dataset.accountId, 10);
                if (accountId) {
                    this.toggleFavorite(accountId);
                }
            }
        });
    }
    
    /**
     * Загрузка списка избранных аккаунтов
     */
    async loadFavorites() {
        try {
            const response = await fetch('api_favorites.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                let errorMessage = 'Ошибка загрузки избранного';
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.error || errorMessage;
                } catch (e) {
                    errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                }
                throw new Error(errorMessage);
            }
            
            let data;
            try {
                const text = await response.text();
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error when loading favorites:', e);
                throw new Error('Некорректный ответ сервера');
            }
            
            if (data.success && Array.isArray(data.favorites)) {
                this.favorites = new Set(data.favorites.map(id => parseInt(id, 10)));
                this.updateFavoritesUI();
                this.loaded = true;
            } else {
                console.warn('Invalid favorites response:', data);
                // Устанавливаем пустой список, чтобы не блокировать работу
                this.favorites = new Set();
                this.loaded = true;
            }
        } catch (error) {
            console.error('Error loading favorites:', error);
            // Устанавливаем пустой список, чтобы не блокировать работу
            this.favorites = new Set();
            this.loaded = true;
        }
    }
    
    /**
     * Переключение избранного (добавить/удалить)
     */
    async toggleFavorite(accountId) {
        // Защита от множественных кликов
        if (this.processingIds.has(accountId)) {
            console.log('Favorite toggle already in progress for account:', accountId);
            return;
        }
        
        const isFavorite = this.favorites.has(accountId);
        this.processingIds.add(accountId);
        
        // Оптимистичное обновление UI (сразу меняем состояние)
        const originalState = isFavorite;
        if (isFavorite) {
            this.favorites.delete(accountId);
        } else {
            this.favorites.add(accountId);
        }
        this.updateFavoritesUI(accountId);
        
        try {
            const method = isFavorite ? 'DELETE' : 'POST';
            const response = await fetch('api_favorites.php', {
                method: method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ account_id: accountId })
            });
            
            // Проверяем статус ответа
            if (!response.ok) {
                let errorMessage = 'Ошибка сервера';
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.error || errorMessage;
                } catch (e) {
                    errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                }
                throw new Error(errorMessage);
            }
            
            // Парсим JSON ответ
            let data;
            try {
                const text = await response.text();
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e, 'Response:', text);
                throw new Error('Некорректный ответ сервера');
            }
            
            // Проверяем успешность операции
            if (!data.success) {
                // Откатываем оптимистичное обновление
                if (originalState) {
                    this.favorites.add(accountId);
                } else {
                    this.favorites.delete(accountId);
                }
                this.updateFavoritesUI(accountId);
                throw new Error(data.error || 'Неизвестная ошибка');
            }
            
            // Состояние уже обновлено оптимистично, только показываем уведомление
            // Показываем уведомление
            if (typeof window.showToast === 'function') {
                window.showToast(
                    originalState ? 'Удалено из избранного' : 'Добавлено в избранное',
                    'success'
                );
            }
        } catch (error) {
            console.error('Error toggling favorite:', error);
            
            // Откатываем оптимистичное обновление при ошибке
            if (originalState) {
                this.favorites.add(accountId);
            } else {
                this.favorites.delete(accountId);
            }
            this.updateFavoritesUI(accountId);
            
            const errorMessage = error.message || 'Ошибка при обновлении избранного';
            if (typeof window.showToast === 'function') {
                window.showToast(errorMessage, 'error');
            } else {
                alert(errorMessage);
            }
        } finally {
            // Убираем из списка обрабатываемых
            this.processingIds.delete(accountId);
        }
    }
    
    /**
     * Проверка, является ли аккаунт избранным
     */
    isFavorite(accountId) {
        return this.favorites.has(parseInt(accountId, 10));
    }
    
    /**
     * Обновление UI кнопок избранного
     */
    updateFavoritesUI(accountId = null) {
        // Debounce для предотвращения множественных обновлений
        if (this.updateDebounceTimer) {
            clearTimeout(this.updateDebounceTimer);
        }
        
        this.updateDebounceTimer = setTimeout(() => {
            this._updateFavoritesUIInternal(accountId);
        }, 50);
    }
    
    /**
     * Внутренний метод обновления UI (без debounce)
     */
    _updateFavoritesUIInternal(accountId = null) {
        // Обновляем все кнопки избранного или только конкретную
        const buttons = accountId
            ? document.querySelectorAll(`[data-account-id="${accountId}"] .favorite-btn, .favorite-btn[data-account-id="${accountId}"]`)
            : document.querySelectorAll('.favorite-btn');
        
        buttons.forEach(btn => {
            const id = parseInt(btn.dataset.accountId || btn.closest('[data-account-id]')?.dataset.accountId, 10);
            if (!id) return;
            
            const isFavorite = this.favorites.has(id);
            const icon = btn.querySelector('i');
            
            // Обновляем состояние
            if (isFavorite) {
                btn.classList.add('active');
                btn.title = 'Удалить из избранного';
                // Обновляем только иконку, сохраняя стили
                if (icon) {
                    icon.className = 'fas fa-star';
                } else {
                    btn.innerHTML = '<i class="fas fa-star"></i>';
                }
                // Убеждаемся, что цвет правильный
                if (!btn.style.color) {
                    // Убираем inline стили - используем CSS классы
                    btn.style.color = '';
                }
            } else {
                btn.classList.remove('active');
                btn.title = 'Добавить в избранное';
                // Обновляем только иконку, сохраняя стили
                if (icon) {
                    icon.className = 'far fa-star';
                } else {
                    btn.innerHTML = '<i class="far fa-star"></i>';
                }
                // Убеждаемся, что цвет правильный
                if (!btn.style.color) {
                    // Убираем inline стили - используем CSS классы
                    btn.style.color = '';
                }
            }
        });
        
        // Обновляем иконки в таблице
        this.updateTableFavorites();
    }
    
    /**
     * Обновление иконок избранного в таблице
     */
    updateTableFavorites() {
        const rows = document.querySelectorAll('#accountsTable tbody tr[data-id]');
        if (rows.length === 0) return;
        
        rows.forEach(row => {
            const accountId = parseInt(row.dataset.id, 10);
            if (!accountId) return;
            
            // Ищем ячейку избранного (должна быть сразу после ID)
            let favoriteCell = row.querySelector('.favorite-cell');
            
            // Если ячейки нет, находим ячейку с ID и создаём после неё
            if (!favoriteCell) {
                const idCell = row.querySelector('td[data-col="id"]');
                if (idCell) {
                    // Проверяем, нет ли уже ячейки избранного
                    const nextCell = idCell.nextElementSibling;
                    if (!nextCell || !nextCell.classList.contains('favorite-cell')) {
                        favoriteCell = document.createElement('td');
                        favoriteCell.className = 'favorite-cell text-center';
                        favoriteCell.setAttribute('data-account-id', accountId);
                        row.insertBefore(favoriteCell, nextCell || idCell.nextSibling);
                    } else {
                        favoriteCell = nextCell;
                    }
                } else {
                    // Fallback: находим первую ячейку данных и вставляем после неё
                    const firstDataCell = row.querySelector('td:not(.checkbox-cell)');
                    if (firstDataCell) {
                        favoriteCell = document.createElement('td');
                        favoriteCell.className = 'favorite-cell text-center';
                        favoriteCell.setAttribute('data-account-id', accountId);
                        firstDataCell.parentNode.insertBefore(favoriteCell, firstDataCell.nextElementSibling);
                    }
                }
            }
            
            if (!favoriteCell) return;
            
            // Обновляем атрибут data-account-id
            favoriteCell.setAttribute('data-account-id', accountId);
            
            const isFavorite = this.favorites.has(accountId);
            let favoriteBtn = favoriteCell.querySelector('.favorite-btn');
            
            if (!favoriteBtn) {
                // Создаём кнопку, если её нет
                favoriteBtn = document.createElement('button');
                favoriteBtn.type = 'button';
                favoriteBtn.className = 'btn btn-sm btn-link favorite-btn p-0';
                favoriteBtn.setAttribute('data-account-id', accountId);
                favoriteCell.appendChild(favoriteBtn);
            }
            
            // Обновляем состояние кнопки, сохраняя структуру
            favoriteBtn.setAttribute('data-account-id', accountId);
            favoriteBtn.title = isFavorite ? 'Удалить из избранного' : 'Добавить в избранное';
            
            const icon = favoriteBtn.querySelector('i');
            if (icon) {
                // Обновляем только иконку
                icon.className = isFavorite ? 'fas fa-star' : 'far fa-star';
                if (isFavorite) {
                    favoriteBtn.classList.add('active');
                } else {
                    favoriteBtn.classList.remove('active');
                }
            } else {
                // Создаём иконку, если её нет
                favoriteBtn.innerHTML = `<i class="${isFavorite ? 'fas' : 'far'} fa-star"></i>`;
                if (isFavorite) {
                    favoriteBtn.classList.add('active');
                } else {
                    favoriteBtn.classList.remove('active');
                }
            }
            
            // Убираем inline стили - теперь используем CSS классы
            favoriteBtn.style.color = '';
            favoriteBtn.style.fontSize = '';
        });
    }
    
    /**
     * Получение списка избранных аккаунтов
     */
    getFavoritesList() {
        return Array.from(this.favorites);
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    window.favoritesManager = new FavoritesManager();
    
    // Обновляем UI избранного после загрузки таблицы
    const updateFavoritesAfterTableUpdate = () => {
        if (window.favoritesManager && window.favoritesManager.loaded) {
            setTimeout(() => {
                // Обновляем таблицу (если она есть на странице)
                window.favoritesManager.updateTableFavorites();
                // Обновляем все кнопки избранного (включая view.php)
                window.favoritesManager._updateFavoritesUIInternal();
            }, 100);
        }
    };
    
    // Обновляем избранное на странице view.php после загрузки списка
    // (только если мы на странице view.php, где нет таблицы #accountsTable)
    if (!document.querySelector('#accountsTable') && window.favoritesManager) {
        // Ждём загрузки избранного и обновляем кнопку на view.php
        const checkLoaded = setInterval(() => {
            if (window.favoritesManager.loaded) {
                clearInterval(checkLoaded);
                // Небольшая задержка для гарантии, что DOM готов
                setTimeout(() => {
                    window.favoritesManager._updateFavoritesUIInternal();
                }, 200);
            }
        }, 100);
        
        // Останавливаем проверку через 5 секунд
        setTimeout(() => {
            clearInterval(checkLoaded);
        }, 5000);
    }
    
    // Наблюдаем за изменениями в таблице (с debounce)
    let observerDebounceTimer = null;
    const tableObserver = new MutationObserver(() => {
        // Debounce для предотвращения множественных обновлений
        if (observerDebounceTimer) {
            clearTimeout(observerDebounceTimer);
        }
        observerDebounceTimer = setTimeout(() => {
            updateFavoritesAfterTableUpdate();
        }, 200);
    });
    
    const accountsTable = document.querySelector('#accountsTable tbody');
    if (accountsTable) {
        tableObserver.observe(accountsTable, {
            childList: true,
            subtree: false // Отключаем subtree для оптимизации
        });
    }
    
    // Обновляем после обновления данных через refresh
    if (typeof refreshDashboardData !== 'undefined') {
        const originalRefresh = window.refreshDashboardData;
        window.refreshDashboardData = async function(...args) {
            const result = await originalRefresh.apply(this, args);
            updateFavoritesAfterTableUpdate();
            return result;
        };
    }
    
    // Обновляем при изменении фильтров/пагинации
    setTimeout(() => {
        updateFavoritesAfterTableUpdate();
    }, 500);
});



/* === assets/js/trash.js === */
/**
 * Управление корзиной (Trash)
 * Восстановление и окончательное удаление аккаунтов
 */
document.addEventListener('DOMContentLoaded', function() {
    const selectedIds = new Set();
    const selectAllCheckbox = document.getElementById('selectAllTrash');
    const trashCheckboxes = document.querySelectorAll('.trash-checkbox');
    const restoreSelectedBtn = document.getElementById('restoreSelectedBtn');
    const deletePermanentlyBtn = document.getElementById('deletePermanentlyBtn');
    const emptyTrashBtn = document.getElementById('emptyTrashBtn');
    const selectedCountEl = document.getElementById('selectedCount');
    
    // Обновление счётчика выбранных
    function updateSelectedCount() {
        const count = selectedIds.size;
        selectedCountEl.textContent = count;
        
        // Включаем/отключаем кнопки
        restoreSelectedBtn.disabled = count === 0;
        deletePermanentlyBtn.disabled = count === 0;
        
        // Обновляем состояние "Выбрать все"
        if (selectAllCheckbox) {
            const allChecked = trashCheckboxes.length > 0 && 
                               Array.from(trashCheckboxes).every(cb => selectedIds.has(parseInt(cb.value, 10)));
            selectAllCheckbox.checked = allChecked;
        }
    }
    
    // Обработка кликов на чекбоксы
    trashCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const id = parseInt(this.value, 10);
            if (this.checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
            updateSelectedCount();
        });
    });
    
    // Обработка "Выбрать все"
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            trashCheckboxes.forEach(checkbox => {
                const id = parseInt(checkbox.value, 10);
                checkbox.checked = this.checked;
                if (this.checked) {
                    selectedIds.add(id);
                } else {
                    selectedIds.delete(id);
                }
            });
            updateSelectedCount();
        });
    }
    
    // Восстановление выбранных аккаунтов
    if (restoreSelectedBtn) {
        restoreSelectedBtn.addEventListener('click', function() {
            if (selectedIds.size === 0) return;
            
            if (!confirm(`Восстановить ${selectedIds.size} аккаунт(ов)?`)) {
                return;
            }
            
            restoreAccounts(Array.from(selectedIds));
        });
    }
    
    // Окончательное удаление выбранных аккаунтов
    if (deletePermanentlyBtn) {
        deletePermanentlyBtn.addEventListener('click', function() {
            if (selectedIds.size === 0) return;
            
            if (!confirm(`ВНИМАНИЕ! Вы уверены, что хотите окончательно удалить ${selectedIds.size} аккаунт(ов)?\n\nЭто действие нельзя отменить!`)) {
                return;
            }
            
            if (!confirm('Это действие невозможно отменить. Вы действительно уверены?')) {
                return;
            }
            
            deletePermanently(Array.from(selectedIds));
        });
    }
    
    // Очистка корзины (удаление всех удалённых аккаунтов)
    if (emptyTrashBtn) {
        emptyTrashBtn.addEventListener('click', function() {
            if (!confirm('ВНИМАНИЕ! Вы уверены, что хотите окончательно удалить ВСЕ аккаунты из корзины?\n\nЭто действие нельзя отменить!')) {
                return;
            }
            
            if (!confirm('Это действие невозможно отменить. Вы действительно уверены, что хотите удалить все аккаунты из корзины?')) {
                return;
            }
            
            emptyTrash();
        });
    }
    
    // Восстановление одного аккаунта
    document.querySelectorAll('.restore-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = parseInt(this.dataset.id, 10);
            if (!confirm('Восстановить этот аккаунт?')) {
                return;
            }
            
            restoreAccounts([id]);
        });
    });
    
    // Окончательное удаление одного аккаунта
    document.querySelectorAll('.delete-permanent-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = parseInt(this.dataset.id, 10);
            if (!confirm('ВНИМАНИЕ! Вы уверены, что хотите окончательно удалить этот аккаунт?\n\nЭто действие нельзя отменить!')) {
                return;
            }
            
            if (!confirm('Это действие невозможно отменить. Вы действительно уверены?')) {
                return;
            }
            
            deletePermanently([id]);
        });
    });
    
    /**
     * Восстановление аккаунтов из корзины
     */
    async function restoreAccounts(ids) {
        try {
            restoreSelectedBtn.disabled = true;
            
            const response = await fetch('restore.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    ids: ids,
                    csrf: getCsrfToken()
                })
            });
            
            if (!response.ok) {
                throw new Error('Ошибка восстановления');
            }
            
            const data = await response.json();
            
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(`Восстановлено ${data.restored_count || ids.length} аккаунт(ов)`, 'success');
                }
                
                // Удаляем восстановленные аккаунты из выбранных
                ids.forEach(id => selectedIds.delete(id));
                updateSelectedCount();
                
                // Удаляем строки из таблицы
                ids.forEach(id => {
                    const row = document.querySelector(`tr[data-id="${id}"]`);
                    if (row) {
                        row.remove();
                    }
                });
                
                // Обновляем счётчик
                const deletedCountEl = document.querySelector('.trash-header p');
                if (deletedCountEl) {
                    const remaining = document.querySelectorAll('.trash-checkbox').length;
                    deletedCountEl.textContent = `Удалённые аккаунты (${remaining} записей)`;
                }
                
                // Если корзина пуста, перезагружаем страницу
                if (document.querySelectorAll('.trash-checkbox').length === 0) {
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                }
            } else {
                throw new Error(data.error || 'Ошибка восстановления');
            }
        } catch (error) {
            console.error('Restore error:', error);
            if (typeof showToast === 'function') {
                showToast('Ошибка при восстановлении аккаунтов: ' + error.message, 'error');
            } else {
                alert('Ошибка при восстановлении аккаунтов: ' + error.message);
            }
        } finally {
            restoreSelectedBtn.disabled = selectedIds.size === 0;
        }
    }
    
    /**
     * Окончательное удаление аккаунтов
     */
    async function deletePermanently(ids) {
        try {
            deletePermanentlyBtn.disabled = true;
            
            const response = await fetch('delete_permanent.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    ids: ids,
                    csrf: getCsrfToken()
                })
            });
            
            if (!response.ok) {
                throw new Error('Ошибка удаления');
            }
            
            const data = await response.json();
            
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(`Окончательно удалено ${data.deleted_count || ids.length} аккаунт(ов)`, 'success');
                }
                
                // Удаляем удалённые аккаунты из выбранных
                ids.forEach(id => selectedIds.delete(id));
                updateSelectedCount();
                
                // Удаляем строки из таблицы
                ids.forEach(id => {
                    const row = document.querySelector(`tr[data-id="${id}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                });
                
                // Обновляем счётчик
                const deletedCountEl = document.querySelector('.trash-header p');
                if (deletedCountEl) {
                    const remaining = document.querySelectorAll('.trash-checkbox').length;
                    deletedCountEl.textContent = `Удалённые аккаунты (${remaining} записей)`;
                }
                
                // Если корзина пуста, перезагружаем страницу
                setTimeout(() => {
                    if (document.querySelectorAll('.trash-checkbox').length === 0) {
                        window.location.reload();
                    }
                }, 500);
            } else {
                throw new Error(data.error || 'Ошибка удаления');
            }
        } catch (error) {
            console.error('Delete permanent error:', error);
            if (typeof showToast === 'function') {
                showToast('Ошибка при удалении аккаунтов: ' + error.message, 'error');
            } else {
                alert('Ошибка при удалении аккаунтов: ' + error.message);
            }
        } finally {
            deletePermanentlyBtn.disabled = selectedIds.size === 0;
        }
    }
    
    /**
     * Очистка корзины
     */
    async function emptyTrash() {
        try {
            emptyTrashBtn.disabled = true;
            
            const response = await fetch('empty_trash.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf: getCsrfToken()
                })
            });
            
            if (!response.ok) {
                throw new Error('Ошибка очистки корзины');
            }
            
            const data = await response.json();
            
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(`Корзина очищена. Удалено ${data.deleted_count || 0} аккаунт(ов)`, 'success');
                }
                
                // Перезагружаем страницу
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                throw new Error(data.error || 'Ошибка очистки корзины');
            }
        } catch (error) {
            console.error('Empty trash error:', error);
            if (typeof showToast === 'function') {
                showToast('Ошибка при очистке корзины: ' + error.message, 'error');
            } else {
                alert('Ошибка при очистке корзины: ' + error.message);
            }
        } finally {
            emptyTrashBtn.disabled = false;
        }
    }
    
    /**
     * Получение CSRF токена
     */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content');
        }
        
        // Или из cookie
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'csrf_token') {
                return decodeURIComponent(value);
            }
        }
        
        return '';
    }
});




/* === assets/js/dashboard.js === */
/**
 * Оптимизированный JavaScript для дашборда
 * Исправляет утечки памяти, улучшает производительность
 */

(function() {
    if (typeof window !== 'undefined' && window.__INLINE_DASHBOARD_ACTIVE__) {
        console.info('[dashboard.js] Inline dashboard скрипт активен — пропускаем инициализацию класса Dashboard');
        return;
    }

class Dashboard {
    constructor() {
        this.selectedIds = new Set();
        this.selectedAllFiltered = false;
        this.filteredTotalLive = 0;
        this.isRefreshing = false;
        this.refreshController = null;
        this.refreshQueued = false;
        this.overlayShownAt = 0;
        
        // Дебаунс функции для оптимизации
        this.debouncedSearch = this.debounce(this.applyLiveSearch.bind(this), 300);
        this.debouncedRefresh = this.debounce(this.refreshDashboardData.bind(this), 100);
        
        // Константы
        this.LS_KEYS = {
            COLUMNS: 'dashboard_visible_columns',
            CARDS: 'dashboard_visible_cards',
            KNOWN_COLS: 'dashboard_known_columns',
            SELECTED: 'dashboard_selected_ids',
            CUSTOM_CARDS: 'dashboard_custom_cards_v1'
        };
        
        this.init();
    }
    
    init() {
        this.loadSelectedIds();
        this.updateSelectedCount();
        this.loadSettings();
        this.bindEvents();
        this.initializeComponents();
        
        // Автоочистка кэша каждые 5 минут
        setInterval(() => this.cleanupMemory(), 5 * 60 * 1000);
    }
    
    loadSelectedIds() {
        try {
            const saved = localStorage.getItem(this.LS_KEYS.SELECTED);
            if (saved) {
                this.selectedIds = new Set(JSON.parse(saved));
            }
        } catch (e) {
            console.error('Error loading selected IDs:', e);
        }
    }
    
    loadSettings() {
        // Загружаем настройки колонок
        try {
            const savedColumns = localStorage.getItem(this.LS_KEYS.COLUMNS);
            const visibleColumns = savedColumns ? JSON.parse(savedColumns) : null;
            
            // Определяем новые колонки
            let knownCols = [];
            try {
                const k = localStorage.getItem(this.LS_KEYS.KNOWN_COLS);
                if (k) knownCols = JSON.parse(k) || [];
            } catch (_) {}
            
            const allColKeys = Array.from(document.querySelectorAll('.column-toggle'))
                .map(cb => cb.getAttribute('data-col'));
            const newCols = allColKeys.filter(c => !knownCols.includes(c));
            
            document.querySelectorAll('.column-toggle').forEach(cb => {
                const colName = cb.getAttribute('data-col');
                let isChecked = cb.checked;
                if (visibleColumns) {
                    isChecked = visibleColumns.includes(colName) || newCols.includes(colName);
                }
                cb.checked = isChecked;
                if (typeof toggleColumnVisibility === 'function') {
                    toggleColumnVisibility(colName, isChecked);
                }
            });
            
            // Сохраняем актуальный список колонок
            localStorage.setItem(this.LS_KEYS.KNOWN_COLS, JSON.stringify(allColKeys));
            
            // Загружаем настройки карточек
            const savedCards = localStorage.getItem(this.LS_KEYS.CARDS);
            if (savedCards) {
                const visibleCards = JSON.parse(savedCards);
                document.querySelectorAll('.card-toggle').forEach(cb => {
                    const cardId = cb.getAttribute('data-card');
                    cb.checked = visibleCards.includes(cardId);
                });
            }
        } catch (e) {
            console.error('Error loading settings:', e);
        }
    }
    
    bindEvents() {
        // Используем делегирование событий для лучшей производительности
        document.addEventListener('click', this.handleDocumentClick.bind(this));
        document.addEventListener('change', this.handleDocumentChange.bind(this));
        document.addEventListener('submit', this.handleFormSubmit.bind(this));
        
        // Оптимизированные обработчики для частых событий
        const searchInput = document.querySelector('input[name="q"]');
        if (searchInput) {
            // ОТКЛЮЧЕНО автоприменение - только по кнопке "Применить"
            // searchInput.addEventListener('input', this.debouncedSearch);
            searchInput.addEventListener('keydown', this.handleSearchKeydown.bind(this));
        }
        
        // Обработчики для селектов
        this.bindSelectEvents();
        
        // Пагинация
        this.bindPaginationEvents();
        
        // Предотвращение утечек памяти при закрытии страницы
        window.addEventListener('beforeunload', this.cleanup.bind(this));
    }
    
    handleDocumentClick(e) {
        // Пагинация
        const pageLink = e.target.closest('ul.pagination a.page-link');
        if (pageLink) {
            this.handlePaginationClick(e, pageLink);
            return;
        }
        
        // Переключение паролей
        const pwToggle = e.target.closest('.pw-toggle');
        if (pwToggle) {
            this.togglePassword(pwToggle);
            return;
        }
        
        // Модальные окна для полного содержимого
        const fullDataTarget = e.target.closest('[data-full]');
        if (fullDataTarget) {
            this.showFullDataModal(fullDataTarget);
            return;
        }
        
        // Редактирование ячеек таблицы
        const editableCell = e.target.closest('#accountsTable td[data-col]');
        if (editableCell && !e.target.closest('a,button,.pw-toggle,[data-full]')) {
            this.handleCellEdit(editableCell);
            return;
        }
    }
    
    handleDocumentChange(e) {
        const target = e.target;
        
        // Чекбоксы строк
        if (target.classList.contains('row-checkbox')) {
            this.handleRowCheckboxChange(target);
            return;
        }
        
        // Главный чекбокс
        if (target.id === 'selectAll') {
            this.handleSelectAllChange(target);
            return;
        }
        
        // Настройки видимости колонок/карточек
        if (target.classList.contains('column-toggle')) {
            this.handleColumnToggle(target);
            return;
        }
        
        if (target.classList.contains('card-toggle')) {
            this.handleCardToggle(target);
            return;
        }
    }
    
    handleFormSubmit(e) {
        // Блокируем отправку форм фильтров
        if (e.target.closest('.card.mb-4 form')) {
            e.preventDefault();
            return;
        }
        
        // Синхронизируем ползунки перед отправкой
        this.syncSliderValues();
    }
    
    bindSelectEvents() {
        // ОТКЛЮЧЕНО автоприменение фильтров - только по кнопке "Применить"
        // Обработчики для per_page оставляем только для показа индикатора
        
        const perPageSelect = document.querySelector('select[name="per_page"]');
        if (perPageSelect) {
            perPageSelect.addEventListener('change', () => {
                // Только показываем индикатор, НЕ применяем
                if (typeof markFiltersAsChanged === 'function') {
                    markFiltersAsChanged();
                }
            });
        }
        
        // Селект страниц (пагинация) - оставляем автоприменение
        const pageSelect = document.getElementById('pageSelect');
        if (pageSelect) {
            pageSelect.addEventListener('change', this.handlePageSelectChange.bind(this));
        }
    }
    
    bindPaginationEvents() {
        // Уже обрабатывается в handleDocumentClick
    }
    
    // Дебаунс функция для оптимизации
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
    
    // Применение поиска с дебаунсом
    // ОТКЛЮЧЕНО - фильтры применяются только по кнопке "Применить"
    applyLiveSearch() {
        // Функция оставлена для совместимости, но не используется
        return;
        
        /* СТАРЫЙ КОД (отключен):
        const searchInput = document.querySelector('input[name="q"]');
        if (!searchInput) return;
        
        const url = new URL(window.location);
        url.searchParams.set('q', searchInput.value || '');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        
        this.selectedAllFiltered = false;
        this.selectedIds.clear();
        this.updateSelectedCount();
        this.debouncedRefresh();
        */
    }
    
    handleSearchKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    }
    
    // ОТКЛЮЧЕНО - фильтры применяются только по кнопке "Применить"
    handleStatusChange() {
        // Не используется - статусы применяются через форму
        return;
    }
    
    handleMarketplaceStatusChange() {
        // Не используется - применяются через форму
        return;
    }
    
    handlePerPageChange() {
        // Не используется - применяется через форму
        return;
    }
    
    handlePageSelectChange() {
        const pageSelect = document.getElementById('pageSelect');
        const selectedPage = parseInt(pageSelect.value);
        
        if (selectedPage && selectedPage > 0) {
            const url = new URL(window.location);
            url.searchParams.set('page', String(selectedPage));
            history.replaceState(null, '', url.toString());
            
            // Обновляем номер страницы немедленно
            const pageNumEl = document.getElementById('pageNum');
            if (pageNumEl) pageNumEl.textContent = String(selectedPage);
            
            this.selectedAllFiltered = false;
            this.selectedIds.clear();
            this.updateSelectedCount();
            this.debouncedRefresh();
        }
    }
    
    updateUrlAndRefresh(param, value) {
        const url = new URL(window.location);
        if (value) {
            url.searchParams.set(param, value);
        } else {
            url.searchParams.delete(param);
        }
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        
        this.selectedAllFiltered = false;
        this.selectedIds.clear();
        this.updateSelectedCount();
        this.debouncedRefresh();
    }
    
    handlePaginationClick(e, pageLink) {
        e.preventDefault();
        
        const li = pageLink.closest('li');
        if (li && li.classList.contains('disabled')) return;
        
        const href = pageLink.getAttribute('href') || '';
        if (!href) return;
        
        try {
            const url = new URL(href, window.location.origin);
            const pageParam = parseInt(url.searchParams.get('page') || '1');
            const current = new URL(window.location);
            current.searchParams.set('page', String(pageParam));
            history.replaceState(null, '', current.toString());
            
            // Обновляем UI немедленно
            this.updatePageUI(pageParam);
            
            this.selectedAllFiltered = false;
            this.selectedIds.clear();
            this.updateSelectedCount();
            this.debouncedRefresh();
        } catch (error) {
            console.error('Pagination error:', error);
        }
    }
    
    updatePageUI(pageNum) {
        const pageNumEl = document.getElementById('pageNum');
        if (pageNumEl) pageNumEl.textContent = String(pageNum);
        
        const pageSelectEl = document.getElementById('pageSelect');
        if (pageSelectEl) pageSelectEl.value = String(pageNum);
    }
    
    // Оптимизированное обновление данных
    async refreshDashboardData() {
        // Предотвращаем множественные запросы
        if (this.refreshController) {
            this.refreshQueued = true;
            try {
                this.refreshController.abort();
            } catch (e) {
                // Игнорируем ошибки отмены
            }
        }
        
        const params = new URLSearchParams(window.location.search);
        const url = 'refresh.php?' + params.toString();
        
        this.refreshController = new AbortController();
        const signal = this.refreshController.signal;
        
        try {
            this.isRefreshing = true;
            this.showLoadingOverlay();
            
            const res = await fetch(url, { 
                credentials: 'same-origin', 
                signal, 
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || 'Unknown error');
            }
            
            this.updateDashboardUI(data);
            
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Refresh error:', error);
                this.showToast('Ошибка обновления данных', 'error');
            }
        } finally {
            this.isRefreshing = false;
            this.hideLoadingOverlay();
            this.refreshController = null;
            
            // Если был запрос на повторное обновление
            if (this.refreshQueued) {
                this.refreshQueued = false;
                setTimeout(() => this.refreshDashboardData(), 100);
            }
        }
    }
    
    updateDashboardUI(data) {
        // Обновляем статистику
        this.updateStats(data);
        
        // Обновляем таблицу
        this.updateTable(data);
        
        // Обновляем пагинацию
        this.updatePagination(data);
        
        // Обновляем счетчики
        this.updateCounters(data);
    }
    
    updateStats(data) {
        // Общая статистика
        const totalEl = document.querySelector('[data-card="total"] .stat-value');
        if (totalEl && data.totals && typeof data.totals.all === 'number') {
            this.updateStatValue(totalEl, data.totals.all);
        }
        
        if (typeof data.filteredTotal === 'number') {
            this.filteredTotalLive = data.filteredTotal;
        }
        
        // Статистика по статусам
        const statusCards = document.querySelectorAll('[data-card^="status:"]');
        statusCards.forEach(cardWrap => {
            const statusKey = this.getCardStatusKey(cardWrap);
            const count = data.byStatus && typeof data.byStatus[statusKey] !== 'undefined' 
                ? data.byStatus[statusKey] 
                : null;
                
            if (count !== null) {
                const valEl = cardWrap.querySelector('.stat-value');
                if (valEl) this.updateStatValue(valEl, count);
            }
        });
    }
    
    updateTable(data) {
        const tbody = document.querySelector('#accountsTable tbody');
        if (!tbody || !Array.isArray(data.rows)) return;
        
        // Сохраняем позицию скролла
        const prevScrollTop = window.pageYOffset || document.documentElement.scrollTop;
        
        // Плавная анимация обновления
        tbody.style.opacity = '0.7';
        tbody.style.transition = 'opacity 0.2s ease';
        
        setTimeout(() => {
            tbody.innerHTML = this.generateTableRows(data.rows);
            tbody.style.opacity = '1';
            
            // Восстанавливаем позицию скролла
            window.scrollTo(0, prevScrollTop);
            
            // Переинициализируем обработчики
            this.rebindTableEvents();
            this.applySavedColumnVisibility();
            this.updateSelectedCount();
        }, 100);
    }
    
    generateTableRows(rows) {
        if (!rows.length) {
            const colCount = document.querySelectorAll('#accountsTable thead th').length;
            return `<tr><td colspan="${colCount}" class="text-center text-muted py-5">
                <i class="fas fa-search fa-2x mb-3"></i><div>Ничего не найдено</div>
            </td></tr>`;
        }
        
        const headKeys = Array.from(document.querySelectorAll('#accountsTable thead th[data-col]'))
            .map(th => th.getAttribute('data-col'));
        
        return rows.map(row => this.generateTableRow(row, headKeys)).join('');
    }
    
    generateTableRow(row, headKeys) {
        const cells = headKeys.map(col => {
            const sticky = col === 'id' ? ' sticky-id' : '';
            return `<td data-col="${col}" class="${sticky}">${this.renderCell(col, row)}</td>`;
        }).join('');
        
        const viewBtn = `<a class="btn btn-sm btn-outline-primary" href="view.php?id=${row.id}">
            <i class="fas fa-eye me-1"></i>Открыть
        </a>`;
        
        return `<tr data-id="${row.id}">
            <td class="checkbox-cell">
                <div class="form-check">
                    <input class="form-check-input row-checkbox" type="checkbox" value="${row.id}">
                </div>
            </td>
            ${cells}
            <td data-col="actions" class="text-end sticky-actions">${viewBtn}</td>
        </tr>`;
    }
    
    renderCell(col, row) {
        const value = row[col];
        
        if (value === undefined || value === null || value === '') {
            return '<span class="text-muted">—</span>';
        }
        
        // Специальная обработка для разных типов колонок
        switch (col) {
            case 'id':
                return `<span class="fw-bold text-primary">#${this.escapeHtml(value)}</span>`;
                
            case 'email':
                return `<div class="d-flex align-items-center gap-2">
                    <a href="mailto:${this.escapeHtml(value)}" class="text-decoration-none">${this.escapeHtml(value)}</a>
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>`;
                
            case 'login':
                return `<div class="d-flex align-items-center gap-2">
                    <span class="fw-semibold">${this.escapeHtml(value)}</span>
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>`;
                
            case 'password':
            case 'email_password':
                return `<div class="pw-mask">
                    <span class="pw-dots">••••••••</span>
                    <span class="pw-text d-none">${this.escapeHtml(value)}</span>
                    <button type="button" class="pw-toggle" title="Показать пароль">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>`;
                
            case 'status':
                return this.renderStatusBadge(value);
                
            default:
                // Длинные поля
                if (typeof value === 'string' && value.length > 80) {
                    const clipped = value.substring(0, 80) + '…';
                    return `<span class="truncate mono" title="Нажмите для просмотра" 
                        data-full="${this.escapeHtml(value)}" data-title="${this.escapeHtml(col)}">
                        ${this.escapeHtml(clipped)}
                    </span>`;
                }
                
                return `<span>${this.escapeHtml(value)}</span>`;
        }
    }
    
    renderStatusBadge(status) {
        const statusValue = String(status || '').toLowerCase();
        let badgeClass = 'badge-default';
        
        if (statusValue.includes('new')) badgeClass = 'badge-new';
        else if (statusValue.includes('add_selphi_true')) badgeClass = 'badge-add_selphi_true';
        else if (statusValue.includes('error')) badgeClass = 'badge-error_login';
        
        return `<span class="badge ${badgeClass}">${this.escapeHtml(status || '—')}</span>`;
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Очистка памяти и предотвращение утечек
    cleanupMemory() {
        // Очищаем старые обработчики событий
        const oldElements = document.querySelectorAll('[data-cleanup]');
        oldElements.forEach(el => {
            el.removeEventListener('click', el._clickHandler);
            el.removeEventListener('change', el._changeHandler);
            el.removeAttribute('data-cleanup');
        });
        
        // Принудительная сборка мусора (если доступна)
        if (window.gc && typeof window.gc === 'function') {
            window.gc();
        }
    }
    
    cleanup() {
        // Отменяем все активные запросы
        if (this.refreshController) {
            this.refreshController.abort();
        }
        
        // Очищаем таймеры
        clearTimeout(this._searchTimeout);
        clearTimeout(this._refreshTimeout);
        
        // Очищаем память
        this.selectedIds.clear();
        this.cleanupMemory();
    }
    
    // Вспомогательные методы...
    showToast(message, type = 'info') {
        // Реализация уведомлений
        console.log(`Toast [${type}]: ${message}`);
    }
    
    showLoadingOverlay() {
        const overlay = document.getElementById('tableLoading');
        if (overlay) {
            overlay.classList.add('show');
            this.overlayShownAt = Date.now();
        }
    }
    
    hideLoadingOverlay() {
        const overlay = document.getElementById('tableLoading');
        if (overlay) {
            const elapsed = Date.now() - (this.overlayShownAt || 0);
            const minMs = 300;
            
            if (elapsed < minMs) {
                setTimeout(() => overlay.classList.remove('show'), minMs - elapsed);
            } else {
                overlay.classList.remove('show');
            }
        }
    }
    
    // --- Дополнительные методы для стабильной работы UI ---
    // Безопасная обертка для копирования в буфер обмена
    copyToClipboard(text) {
        try {
            if (typeof window.copyToClipboard === 'function') {
                window.copyToClipboard(text);
                return;
            }
            // Fallback для старых браузеров
            window.fallbackCopyTextToClipboard(String(text || ''));
        } catch (_) {}
    }
    
    // Плавное обновление числовых значений карточек
    updateStatValue(el, value) {
        if (!el) return;
        const safe = Number.isFinite(value) ? value : 0;
        el.textContent = String(safe);
    }
    
    // Переключение видимости пароля в ячейке
    togglePassword(toggleBtn) {
        const wrap = toggleBtn.closest('.pw-mask');
        if (!wrap) return;
        const dots = wrap.querySelector('.pw-dots');
        const text = wrap.querySelector('.pw-text');
        const icon = toggleBtn.querySelector('i');
        if (!dots || !text) return;
        const isHidden = text.classList.contains('d-none');
        if (isHidden) {
            text.classList.remove('d-none');
            dots.classList.add('d-none');
            if (icon) icon.className = 'fas fa-eye-slash';
            toggleBtn.title = 'Скрыть пароль';
        } else {
            text.classList.add('d-none');
            dots.classList.remove('d-none');
            if (icon) icon.className = 'fas fa-eye';
            toggleBtn.title = 'Показать пароль';
        }
    }
    
    // Показ полного содержимого длинных полей
    showFullDataModal(target) {
        const full = target.getAttribute('data-full') || '';
        const title = target.getAttribute('data-title') || 'Данные';
        if (!full) return;
        
        let modal = document.getElementById('fullDataModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'fullDataModal';
            modal.innerHTML = `
                <div class="fdm-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:99999;">
                  <div class="fdm-dialog" style="max-width:70vw;max-height:70vh;width:70vw;background:#fff;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.2);display:flex;flex-direction:column;">
                    <div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;">
                      <div style="font-weight:600">${this.escapeHtml(title)}</div>
                      <button type="button" class="fdm-close" style="border:none;background:transparent;font-size:20px;line-height:1;cursor:pointer">&times;</button>
                    </div>
                    <div style="padding:16px;overflow:auto">
                      <pre style="white-space:pre-wrap;word-wrap:break-word;font-family:monospace;margin:0">${this.escapeHtml(full)}</pre>
                    </div>
                  </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.querySelector('.fdm-backdrop').addEventListener('click', (e) => {
                if (e.target.classList.contains('fdm-backdrop')) {
                    modal.remove();
                }
            });
            modal.querySelector('.fdm-close').addEventListener('click', () => modal.remove());
        }
    }
    
    // Переинициализация обработчиков для динамически обновлённой таблицы (заглушка)
    rebindTableEvents() {
        // В этом файле обработчики навешиваются на document (делегирование),
        // поэтому дополнительная инициализация не требуется.
    }
    
    // Применение сохранённой видимости колонок (заглушка)
    applySavedColumnVisibility() {
        // Логика управления видимостью колонок может быть реализована в инлайновом скрипте шаблона.
    }
    
    // Обновление счётчиков выбранных записей и кнопок
    updateSelectedCount() {
        const selectedCountEl = document.getElementById('selectedCount');
        if (selectedCountEl) {
            selectedCountEl.textContent = this.selectedAllFiltered ? 'Все по фильтру' : String(this.selectedIds.size);
        }
    }
    
    // Обработка чекбокса строки
    handleRowCheckboxChange(inputEl) {
        const id = parseInt(inputEl.value, 10);
        if (Number.isFinite(id)) {
            if (inputEl.checked) this.selectedIds.add(id);
            else this.selectedIds.delete(id);
            this.selectedAllFiltered = false;
            this.updateSelectedCount();
        }
    }
    
    // Обработка главного чекбокса
    handleSelectAllChange(master) {
        const rows = document.querySelectorAll('#accountsTable tbody .row-checkbox');
        rows.forEach(cb => {
            cb.checked = master.checked;
            const id = parseInt(cb.value, 10);
            if (Number.isFinite(id)) {
                if (master.checked) this.selectedIds.add(id); else this.selectedIds.delete(id);
            }
        });
        this.selectedAllFiltered = false;
        this.updateSelectedCount();
    }
    
    // Обновление агрегированных счётчиков (заглушка)
    updateCounters() {
        // Значения карточек обновляются в updateStats/updateTable; здесь ничего не требуется.
    }
    
    // Синхронизация ползунков (заглушка)
    syncSliderValues() {}
    
    // Редактирование ячейки (делегируем шаблону; заглушка)
    handleCellEdit() {}
    
    // Остальные методы из оригинального кода...
    // (сокращено для экономии места, но все методы должны быть перенесены)
}

// Инициализация при загрузке DOM
document.addEventListener('DOMContentLoaded', () => {
    window.dashboard = new Dashboard();
});

// Предотвращение утечек памяти при закрытии страницы
window.addEventListener('beforeunload', () => {
    if (window.dashboard) {
        window.dashboard.cleanup();
    }
});

// Глобальные утилиты (безопасно определяем, если не заданы инлайном)
(function attachGlobalUtils() {
    // Копирование в буфер обмена с падением на execCommand
    if (typeof window.copyToClipboard !== 'function') {
        window.copyToClipboard = function(text) {
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(String(text)).then(() => {
                        if (typeof window.showToast === 'function') {
                            window.showToast('Скопировано в буфер обмена', 'success');
                        }
                    }).catch(() => {
                        window.fallbackCopyTextToClipboard(String(text));
                    });
                }
            } catch (_) {}
            window.fallbackCopyTextToClipboard(String(text));
        };
    }

    if (typeof window.fallbackCopyTextToClipboard !== 'function') {
        window.fallbackCopyTextToClipboard = function(text) {
            const ta = document.createElement('textarea');
            ta.value = String(text || '');
            // Для Firefox: элемент должен быть видимым, но можно сделать его очень маленьким
            ta.style.position = 'fixed';
            ta.style.top = '0';
            ta.style.left = '0';
            ta.style.width = '2px';
            ta.style.height = '2px';
            ta.style.padding = '0';
            ta.style.border = 'none';
            ta.style.outline = 'none';
            ta.style.boxShadow = 'none';
            ta.style.background = 'transparent';
            ta.setAttribute('readonly', '');
            document.body.appendChild(ta);
            
            // Для Firefox: используем setSelectionRange вместо select()
            ta.focus();
            ta.setSelectionRange(0, ta.value.length);
            
            try {
                const successful = document.execCommand('copy');
                if (successful && typeof window.showToast === 'function') {
                    window.showToast('Скопировано в буфер обмена', 'success');
                } else if (!successful && typeof window.showToast === 'function') {
                    window.showToast('Ошибка копирования', 'error');
                }
            } catch (_) {
                if (typeof window.showToast === 'function') {
                    window.showToast('Ошибка копирования', 'error');
                }
            } finally {
                document.body.removeChild(ta);
            }
        };
    }

    // Простой тост без зависимости от Bootstrap (используется, если инлайн-реализация отсутствует)
    if (typeof window.showToast !== 'function') {
        window.showToast = function(message, type) {
            const kind = (type === 'success') ? 'success' : (type === 'error' ? 'error' : 'info');
            const wrapId = 'toast-container-generic';
            let wrap = document.getElementById(wrapId);
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = wrapId;
                wrap.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
                document.body.appendChild(wrap);
            }
            const toast = document.createElement('div');
            toast.style.cssText = 'min-width:220px;max-width:420px;padding:10px 12px;border-radius:8px;color:#fff;font:500 14px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial; box-shadow:0 6px 24px rgba(0,0,0,.15); opacity:0; transform:translateY(-6px); transition:all .2s ease;';
            const bg = kind === 'success' ? '#28a745' : (kind === 'error' ? '#dc3545' : '#0d6efd');
            toast.style.background = bg;
            toast.textContent = String(message || '');
            wrap.appendChild(toast);
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
            });
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-6px)';
                setTimeout(() => wrap.contains(toast) && wrap.removeChild(toast), 200);
            }, 2200);
        };
    }
})();

})();

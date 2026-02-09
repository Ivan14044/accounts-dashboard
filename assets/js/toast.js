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








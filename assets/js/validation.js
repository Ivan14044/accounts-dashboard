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



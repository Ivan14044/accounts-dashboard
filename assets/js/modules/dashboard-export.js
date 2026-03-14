/**
 * Модуль управления экспортом данных с расширенными настройками
 */

(function() {
    // Вспомогательная функция для безопасного получения элемента
    function getElementById(id) {
        return document.getElementById(id);
    }

    // Инициализация модуля экспорта
    function initExportModule() {
        const modal = getElementById('exportSettingsModal');
        if (!modal) return;

        const confirmBtn = getElementById('confirmExportBtn');
        const customLimitInput = getElementById('exportCustomLimit');
        const customLimitWrapper = getElementById('customLimitWrapper');
        
        // Обработка переключения опций (radio buttons)
        document.querySelectorAll('input[name="export_scope"]').forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'custom') {
                    customLimitWrapper.style.display = 'flex';
                    customLimitInput.focus();
                } else {
                    customLimitWrapper.style.display = 'none';
                }
            });
        });

        // Также позволяем кликать по контейнерам опций
        document.querySelectorAll('.custom-option-check').forEach(container => {
            container.addEventListener('click', function(e) {
                if (e.target.tagName === 'INPUT') return;
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change'));
                }
            });
        });

        // Клик по основной кнопке "Скачать" в модалке
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                submitExport();
            });
        }
    }

    // Открытие модалки с предустановками
    function openExportModal(format) {
        const modalEl = getElementById('exportSettingsModal');
        if (!modalEl) return;

        const formatInput = getElementById('exportFormat');
        if (formatInput) formatInput.value = format;

        const DS = window.DashboardSelection;
        const selectedCount = DS ? DS.getSelectedIds().size : 0;
        const filteredTotal = DS ? DS.getFilteredTotalLive() : 0;
        const isAllFiltered = DS ? DS.getSelectedAllFiltered() : false;

        // Обновляем счетчики в модалке
        const filteredTotalEl = getElementById('exportFilteredTotal');
        if (filteredTotalEl) filteredTotalEl.textContent = filteredTotal.toLocaleString('ru-RU');

        const selectedCountEl = getElementById('exportSelectedModalCount');
        if (selectedCountEl) selectedCountEl.textContent = selectedCount.toLocaleString('ru-RU');

        // Управляем доступностью опции "Выбранные"
        const selectedRadio = getElementById('exportScopeSelected');
        const selectedContainer = getElementById('exportOptionSelectedContainer');
        
        if (selectedCount === 0) {
            selectedRadio.disabled = true;
            selectedContainer.classList.add('opacity-50');
            selectedContainer.style.pointerEvents = 'none';
            // Если было выбрано "выбранные", переключаем на "все"
            if (selectedRadio.checked) {
                getElementById('exportScopeAll').checked = true;
                getElementById('customLimitWrapper').style.display = 'none';
            }
        } else {
            selectedRadio.disabled = false;
            selectedContainer.classList.remove('opacity-50');
            selectedContainer.style.pointerEvents = 'auto';
            // Если есть выделение, по умолчанию предлагаем "Выбранные"
            selectedRadio.checked = true;
            getElementById('customLimitWrapper').style.display = 'none';
        }
        
        // Если выбран режим "Все по фильтру" в основном интерфейсе, 
        // то и в модалке ставим его по умолчанию
        if (isAllFiltered) {
            getElementById('exportScopeAll').checked = true;
            getElementById('customLimitWrapper').style.display = 'none';
        }

        const bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
    }

    // Сбор параметров и отправка запроса
    function submitExport() {
        const format = getElementById('exportFormat').value;
        const scope = document.querySelector('input[name="export_scope"]:checked').value;
        const limit = getElementById('exportCustomLimit').value;

        const DS = window.DashboardSelection;
        const currentSort = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.sort) || '';
        const currentDir = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.dir) || '';

        // Создаем скрытую форму
        const form = document.createElement('form');
        form.method = 'GET';
        form.action = 'export.php';

        const params = new URLSearchParams(window.location.search);
        params.set('format', format);
        params.set('sort', currentSort);
        params.set('dir', currentDir);

        if (scope === 'all') {
            params.set('select', 'all');
        } else if (scope === 'selected') {
            const ids = Array.from(DS.getSelectedIds()).join(',');
            params.set('ids', ids);
            params.delete('select');
        } else if (scope === 'custom') {
            params.set('select', 'all'); // Для кастомного лимита ведем себя как "все по фильтру" + limit
            params.set('limit', limit);
        }

        // Если TXT, добавляем видимые колонки
        if (format === 'txt') {
            let visibleCols = [];
            try { 
                const saved = localStorage.getItem('dashboard_visible_columns'); 
                if (saved) visibleCols = JSON.parse(saved); 
            } catch (_) { }
            
            if (!Array.isArray(visibleCols) || visibleCols.length === 0) {
                visibleCols = Array.from(document.querySelectorAll('#accountsTable thead th[data-col]')).map(th => th.getAttribute('data-col'));
            }
            
            const ALL_COL_KEYS = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.allColumnKeys) || [];
            visibleCols = (visibleCols || []).filter(c => ALL_COL_KEYS.includes(c));
            visibleCols = visibleCols.filter(c => c !== 'id');
            params.set('cols', visibleCols.join(','));
        }

        // Добавляем все параметры в форму
        params.forEach((value, key) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        // Закрываем модалку
        const modalEl = getElementById('exportSettingsModal');
        if (modalEl) {
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            if (bsModal) bsModal.hide();
        }
    }

    // Экспортируем функции в глобальную область
    window.DashboardExport = {
        init: initExportModule,
        openModal: openExportModal
    };

    // Авто-инициализация при загрузке DOM
    document.addEventListener('DOMContentLoaded', initExportModule);
})();

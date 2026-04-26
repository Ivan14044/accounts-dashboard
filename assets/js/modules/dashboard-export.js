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

        const params = new URLSearchParams(window.location.search);
        params.set('format', format);
        params.set('sort', currentSort);
        params.set('dir', currentDir);

        // Определяем IDs для экспорта выбранных
        let selectedIds = '';
        if (scope === 'all') {
            params.set('select', 'all');
        } else if (scope === 'selected') {
            selectedIds = Array.from(DS.getSelectedIds()).join(',');
            params.delete('select');
            // IDs добавим в форму отдельно (не в URL params, чтобы не превысить лимит GET)
        } else if (scope === 'custom') {
            params.set('select', 'all');
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

        // Всегда используем POST — export.php требует POST + CSRF.
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.getTableAwareUrl('export.php');

        // Добавляем URL-параметры как скрытые поля
        params.forEach((value, key) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        });

        // Добавляем IDs отдельным полем (может быть очень большим)
        if (selectedIds) {
            const idsInput = document.createElement('input');
            idsInput.type = 'hidden';
            idsInput.name = 'ids';
            idsInput.value = selectedIds;
            form.appendChild(idsInput);
        }

        // CSRF-токен
        const csrfToken = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);

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

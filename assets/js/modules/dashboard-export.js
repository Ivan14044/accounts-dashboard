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

        function closeModal() {
            const modalEl = getElementById('exportSettingsModal');
            if (modalEl) {
                const bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            }
        }

        // TXT качаем частями (chunked) — большой объём не упирается в таймаут сервера.
        // Колонки и формат не меняем: качаются ровно выбранные колонки.
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
            visibleCols = (visibleCols || []).filter(c => ALL_COL_KEYS.includes(c)).filter(c => c !== 'id');

            closeModal();
            downloadTxtChunked({
                scope: scope,
                ids: scope === 'selected' ? Array.from(DS.getSelectedIds()) : null,
                cols: visibleCols,
                sort: currentSort,
                dir: currentDir,
                limit: scope === 'custom' ? (parseInt(limit, 10) || 0) : 0,
                filterSearch: window.location.search
            });
            return;
        }

        // CSV (и прочие форматы) — нативная отправка формы (скачивание одним запросом).
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

        closeModal();
    }

    // ── Chunked TXT download ──────────────────────────────────────────────
    // Собирает один .txt из множества мелких быстрых запросов к export_chunk.php,
    // чтобы тысячи строк не упирались в ~120s таймаут веб-сервера на шаринге.

    function nowStamp() {
        const d = new Date();
        const p = n => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
            '_' + p(d.getHours()) + '-' + p(d.getMinutes()) + '-' + p(d.getSeconds());
    }

    function notify(msg, type) {
        if (typeof window.showToast === 'function') { window.showToast(msg, type || 'info'); }
        else if (type === 'error') { alert(msg); }
    }

    function getProgress() {
        let el = getElementById('exportChunkProgress');
        if (!el) {
            el = document.createElement('div');
            el.id = 'exportChunkProgress';
            el.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99999;' +
                'background:#1f2937;color:#fff;padding:10px 14px;border-radius:8px;' +
                'font:13px/1.4 system-ui,-apple-system,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.35);min-width:190px';
            document.body.appendChild(el);
        }
        el.style.display = 'block';
        return {
            set(text) { el.textContent = text; },
            hide() { el.style.display = 'none'; }
        };
    }

    async function postJson(url, body, csrf) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        });
        if (!res.ok) {
            let msg = 'HTTP ' + res.status;
            try { const j = await res.json(); if (j && j.error) msg = j.error + (j.detail ? (': ' + j.detail) : ''); } catch (_) { }
            throw new Error(msg);
        }
        return res.json();
    }

    async function downloadTxtChunked(opts) {
        const csrf = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
        const url = window.getTableAwareUrl('export_chunk.php');
        const CHUNK = 1000;
        const prog = getProgress();
        try {
            let ids;
            if (opts.scope === 'selected') {
                ids = (opts.ids || []).slice();
            } else {
                // all / custom: берём упорядоченный список id по текущему фильтру одним лёгким запросом.
                prog.set('Готовлю список аккаунтов…');
                const body = new URLSearchParams(opts.filterSearch || '');
                body.set('mode', 'idlist');
                body.set('sort', opts.sort || 'id');
                body.set('dir', opts.dir || 'ASC');
                const j = await postJson(url, body, csrf);
                if (!j.ok) throw new Error(j.error || 'idlist');
                ids = j.ids || [];
            }
            if (opts.scope === 'custom' && opts.limit > 0) ids = ids.slice(0, opts.limit);

            const total = ids.length;
            if (total === 0) { notify('Нет записей для экспорта', 'error'); prog.hide(); return; }

            let out = '';
            let written = 0;
            for (let i = 0; i < ids.length; i += CHUNK) {
                const slice = ids.slice(i, i + CHUNK);
                const body = new URLSearchParams();
                body.set('mode', 'rows');
                body.set('ids', slice.join(','));
                body.set('cols', (opts.cols || []).join(','));
                body.set('sort', opts.sort || 'id');
                body.set('dir', opts.dir || 'ASC');
                const j = await postJson(url, body, csrf);
                if (!j.ok) throw new Error(j.error || 'rows');
                out += (j.text || '');
                written += (j.count || 0);
                const done = Math.min(i + CHUNK, total);
                prog.set('Скачивание: ' + done.toLocaleString('ru-RU') + ' / ' + total.toLocaleString('ru-RU'));
            }

            // Собираем один файл с BOM (как в export.php) и отдаём на скачивание.
            const blob = new Blob(['﻿' + out], { type: 'text/plain;charset=utf-8' });
            const a = document.createElement('a');
            const objUrl = URL.createObjectURL(blob);
            a.href = objUrl;
            a.download = 'accounts_' + written + '_' + nowStamp() + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(objUrl), 2000);

            prog.hide();
            notify('Скачано аккаунтов: ' + written.toLocaleString('ru-RU'), 'success');
        } catch (e) {
            prog.hide();
            notify('Ошибка экспорта: ' + (e && e.message ? e.message : e), 'error');
        }
    }

    // Экспортируем функции в глобальную область
    window.DashboardExport = {
        init: initExportModule,
        openModal: openExportModal,
        downloadTxtChunked: downloadTxtChunked
    };

    // Авто-инициализация при загрузке DOM
    document.addEventListener('DOMContentLoaded', initExportModule);
})();

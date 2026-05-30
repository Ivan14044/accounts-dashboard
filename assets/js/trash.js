/**
 * Управление корзиной (Trash)
 * Восстановление, окончательное удаление, retention/автоочистка,
 * массовые действия по фильтру, история изменений (аудит).
 */
document.addEventListener('DOMContentLoaded', function() {
    const selectedIds = new Set();
    const selectAllCheckbox = document.getElementById('selectAllTrash');
    const trashCheckboxes = document.querySelectorAll('.trash-checkbox');
    const restoreSelectedBtn = document.getElementById('restoreSelectedBtn');
    const deletePermanentlyBtn = document.getElementById('deletePermanentlyBtn');
    const emptyTrashBtn = document.getElementById('emptyTrashBtn');
    const selectedCountEl = document.getElementById('selectedCount');

    const cfg = window.TrashConfig || { filterParams: {}, filteredTotal: 0, pageRows: 0, retention: {} };

    // Режим "выбраны все N по фильтру" (через все страницы), не только видимые.
    let filterMode = false;

    const BATCH = 1000;

    function log(level, ...args) {
        if (typeof logger !== 'undefined' && logger[level]) logger[level](...args);
        else if (level === 'error') console.error(...args);
    }

    function notify(message, type) {
        if (typeof showToast === 'function') showToast(message, type);
        else if (type === 'error') alert(message);
    }

    /**
     * Получение CSRF токена
     */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'csrf_token') return decodeURIComponent(value);
        }
        return (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Выбор строк
    // ─────────────────────────────────────────────────────────────────────────

    function updateSelectedCount() {
        const count = filterMode ? cfg.filteredTotal : selectedIds.size;
        if (selectedCountEl) selectedCountEl.textContent = count;

        const hasSelection = count > 0;
        if (restoreSelectedBtn) restoreSelectedBtn.disabled = !hasSelection;
        if (deletePermanentlyBtn) deletePermanentlyBtn.disabled = !hasSelection;

        if (selectAllCheckbox && !filterMode) {
            const allChecked = trashCheckboxes.length > 0 &&
                Array.from(trashCheckboxes).every(cb => selectedIds.has(parseInt(cb.value, 10)));
            selectAllCheckbox.checked = allChecked;
        }
        updateSelectAllBanner();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Баннер "выбрать все по фильтру"
    // ─────────────────────────────────────────────────────────────────────────
    const banner = document.getElementById('selectAllBanner');
    const bannerText = document.getElementById('selectAllBannerText');
    const bannerBtn = document.getElementById('selectAllFilterBtn');

    function updateSelectAllBanner() {
        if (!banner) return;

        // Баннер появляется, когда на странице выбраны ВСЕ строки, а всего записей больше,
        // чем на одной странице.
        const allPageSelected = trashCheckboxes.length > 0 && selectedIds.size === trashCheckboxes.length;
        const moreThanPage = cfg.filteredTotal > cfg.pageRows;

        if (filterMode) {
            banner.classList.add('is-visible', 'is-active');
            bannerText.textContent = 'Выбраны все ' + cfg.filteredTotal + ' записей по текущему фильтру.';
            bannerBtn.textContent = 'Снять выделение';
        } else if (allPageSelected && moreThanPage) {
            banner.classList.add('is-visible');
            banner.classList.remove('is-active');
            bannerText.textContent = 'Выбрано ' + selectedIds.size + ' на этой странице.';
            bannerBtn.textContent = 'Выбрать все ' + cfg.filteredTotal + ' по фильтру';
        } else {
            banner.classList.remove('is-visible', 'is-active');
        }
    }

    if (bannerBtn) {
        bannerBtn.addEventListener('click', function() {
            if (filterMode) {
                // Снять режим "по фильтру"
                filterMode = false;
                selectedIds.clear();
                trashCheckboxes.forEach(cb => { cb.checked = false; });
                if (selectAllCheckbox) selectAllCheckbox.checked = false;
            } else {
                // Включить режим "по фильтру"
                filterMode = true;
            }
            updateSelectedCount();
        });
    }

    trashCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            // Любое ручное изменение чекбокса выходит из режима "по фильтру"
            filterMode = false;
            const id = parseInt(this.value, 10);
            if (this.checked) selectedIds.add(id);
            else selectedIds.delete(id);
            updateSelectedCount();
        });
    });

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            filterMode = false;
            trashCheckboxes.forEach(checkbox => {
                const id = parseInt(checkbox.value, 10);
                checkbox.checked = this.checked;
                if (this.checked) selectedIds.add(id);
                else selectedIds.delete(id);
            });
            updateSelectedCount();
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Восстановление
    // ─────────────────────────────────────────────────────────────────────────

    if (restoreSelectedBtn) {
        restoreSelectedBtn.addEventListener('click', function() {
            if (restoreSelectedBtn.disabled) return;

            if (filterMode) {
                if (!confirm('Восстановить все ' + cfg.filteredTotal + ' аккаунт(ов) по текущему фильтру?')) return;
                restoreSelectedBtn.disabled = true;
                restoreByFilter();
                return;
            }

            if (selectedIds.size === 0) return;
            if (!confirm('Восстановить ' + selectedIds.size + ' аккаунт(ов)?')) return;
            restoreSelectedBtn.disabled = true;
            restoreAccounts(Array.from(selectedIds));
        });
    }

    document.querySelectorAll('.restore-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = parseInt(this.dataset.id, 10);
            if (!confirm('Восстановить этот аккаунт?')) return;
            restoreAccounts([id]);
        });
    });

    async function restoreAccounts(ids) {
        try {
            if (restoreSelectedBtn) restoreSelectedBtn.disabled = true;
            let totalRestored = 0;

            for (let i = 0; i < ids.length; i += BATCH) {
                const batch = ids.slice(i, i + BATCH);
                const data = await postJson(window.getTableAwareUrl('restore.php'), {
                    ids: batch, csrf: getCsrfToken()
                });
                if (data.success) totalRestored += (data.restored_count || batch.length);
                else throw new Error(data.error || 'Ошибка восстановления');
            }

            notify('Восстановлено ' + totalRestored + ' аккаунт(ов)', 'success');
            ids.forEach(id => selectedIds.delete(id));
            removeRows(ids);
            updateSelectedCount();
            reloadIfEmpty(1000);
        } catch (error) {
            log('error', 'Restore error:', error.message);
            notify('Ошибка при восстановлении: ' + error.message, 'error');
        } finally {
            if (restoreSelectedBtn) restoreSelectedBtn.disabled = (filterMode ? false : selectedIds.size === 0);
        }
    }

    async function restoreByFilter() {
        try {
            const data = await postJson(window.getTableAwareUrl('restore.php'), {
                scope: 'filter', filter: cfg.filterParams, csrf: getCsrfToken()
            });
            if (!data.success) throw new Error(data.error || 'Ошибка восстановления');
            notify('Восстановлено ' + (data.restored_count || 0) + ' аккаунт(ов)', 'success');
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            log('error', 'Restore by filter error:', error.message);
            notify('Ошибка при восстановлении: ' + error.message, 'error');
            if (restoreSelectedBtn) restoreSelectedBtn.disabled = false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Окончательное удаление
    // ─────────────────────────────────────────────────────────────────────────

    if (deletePermanentlyBtn) {
        deletePermanentlyBtn.addEventListener('click', function() {
            if (deletePermanentlyBtn.disabled) return;

            if (filterMode) {
                // Необратимо + массово → typed-confirm (ввести число).
                openTypedConfirm(
                    cfg.filteredTotal,
                    'Вы собираетесь НАВСЕГДА удалить все ' + cfg.filteredTotal + ' аккаунт(ов) по текущему фильтру. Это действие необратимо.',
                    function() { deletePermanentlyByFilter(); }
                );
                return;
            }

            if (selectedIds.size === 0) return;
            if (!confirm('ВНИМАНИЕ! Вы уверены, что хотите окончательно удалить ' + selectedIds.size + ' аккаунт(ов)?\n\nЭто действие нельзя отменить!')) return;
            if (!confirm('Это действие невозможно отменить. Вы действительно уверены?')) return;
            deletePermanentlyBtn.disabled = true;
            deletePermanently(Array.from(selectedIds));
        });
    }

    document.querySelectorAll('.delete-permanent-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = parseInt(this.dataset.id, 10);
            if (!confirm('ВНИМАНИЕ! Вы уверены, что хотите окончательно удалить этот аккаунт?\n\nЭто действие нельзя отменить!')) return;
            if (!confirm('Это действие невозможно отменить. Вы действительно уверены?')) return;
            deletePermanently([id]);
        });
    });

    async function deletePermanently(ids) {
        try {
            if (deletePermanentlyBtn) deletePermanentlyBtn.disabled = true;
            let totalDeleted = 0;

            for (let i = 0; i < ids.length; i += BATCH) {
                const batch = ids.slice(i, i + BATCH);
                const data = await postJson('delete_permanent.php', {
                    ids: batch, csrf: getCsrfToken()
                });
                if (data.success) totalDeleted += (data.deleted_count || batch.length);
                else throw new Error(data.error || 'Ошибка удаления');
            }

            notify('Окончательно удалено ' + totalDeleted + ' аккаунт(ов)', 'success');
            ids.forEach(id => selectedIds.delete(id));
            removeRows(ids, true);
            updateSelectedCount();
            reloadIfEmpty(500);
        } catch (error) {
            log('error', 'Delete permanent error:', error.message);
            notify('Ошибка при удалении: ' + error.message, 'error');
        } finally {
            if (deletePermanentlyBtn) deletePermanentlyBtn.disabled = (filterMode ? false : selectedIds.size === 0);
        }
    }

    /**
     * Удаление по фильтру — сервер режет чанками с кэпом за вызов, клиент повторяет,
     * пока remaining > 0 (как chunked-export).
     */
    async function deletePermanentlyByFilter() {
        let totalDeleted = 0;
        let guard = 0;
        try {
            while (true) {
                guard++;
                if (guard > 1000) throw new Error('Слишком много итераций удаления');
                const data = await postJson('delete_permanent.php', {
                    scope: 'filter', filter: cfg.filterParams, csrf: getCsrfToken()
                });
                if (!data.success) throw new Error(data.error || 'Ошибка удаления');
                totalDeleted += (data.deleted_count || 0);
                if ((data.remaining || 0) <= 0 || (data.deleted_count || 0) === 0) break;
            }
            notify('Окончательно удалено ' + totalDeleted + ' аккаунт(ов)', 'success');
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            log('error', 'Delete by filter error:', error.message);
            notify('Ошибка при удалении: ' + error.message, 'error');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Полная очистка корзины
    // ─────────────────────────────────────────────────────────────────────────

    if (emptyTrashBtn) {
        emptyTrashBtn.addEventListener('click', function() {
            if (emptyTrashBtn.disabled) return;
            if (!confirm('ВНИМАНИЕ! Вы уверены, что хотите окончательно удалить ВСЕ аккаунты из корзины?\n\nЭто действие нельзя отменить!')) return;
            if (!confirm('Это действие невозможно отменить. Вы действительно уверены, что хотите удалить все аккаунты из корзины?')) return;
            emptyTrashBtn.disabled = true;
            emptyTrash();
        });
    }

    async function emptyTrash() {
        try {
            emptyTrashBtn.disabled = true;
            if (restoreSelectedBtn) restoreSelectedBtn.disabled = true;
            if (deletePermanentlyBtn) deletePermanentlyBtn.disabled = true;

            const data = await postJson(window.getTableAwareUrl('empty_trash.php'), { csrf: getCsrfToken() });
            if (data.success) {
                notify('Корзина очищена. Удалено ' + (data.deleted_count || 0) + ' аккаунт(ов)', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                throw new Error(data.error || 'Ошибка очистки корзины');
            }
        } catch (error) {
            log('error', 'Empty trash error:', error.message);
            notify('Ошибка при очистке корзины: ' + (error.message || 'Неизвестная ошибка'), 'error');
        } finally {
            emptyTrashBtn.disabled = false;
            if (restoreSelectedBtn) restoreSelectedBtn.disabled = selectedIds.size === 0;
            if (deletePermanentlyBtn) deletePermanentlyBtn.disabled = selectedIds.size === 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Retention: сохранение настроек + ручная очистка старых
    // ─────────────────────────────────────────────────────────────────────────
    const retentionEnabled = document.getElementById('retentionEnabled');
    const retentionDays = document.getElementById('retentionDays');
    const saveRetentionBtn = document.getElementById('saveRetentionBtn');
    const purgeOldBtn = document.getElementById('purgeOldBtn');

    if (saveRetentionBtn) {
        saveRetentionBtn.addEventListener('click', async function() {
            const days = parseInt(retentionDays.value, 10);
            if (isNaN(days) || days < 1) { notify('Укажите корректное число дней (>= 1)', 'error'); return; }
            saveRetentionBtn.disabled = true;
            try {
                const data = await postJson('trash_settings.php', {
                    enabled: !!retentionEnabled.checked,
                    days: days,
                    csrf: getCsrfToken()
                });
                if (!data.success) throw new Error(data.error || 'Ошибка сохранения');
                notify('Настройки автоочистки сохранены', 'success');
                cfg.retention = { enabled: data.enabled, days: data.days };
            } catch (error) {
                log('error', 'Save retention error:', error.message);
                notify('Ошибка сохранения: ' + error.message, 'error');
            } finally {
                saveRetentionBtn.disabled = false;
            }
        });
    }

    if (purgeOldBtn) {
        purgeOldBtn.addEventListener('click', async function() {
            const days = parseInt(retentionDays.value, 10);
            if (isNaN(days) || days < 1) { notify('Укажите корректное число дней (>= 1)', 'error'); return; }
            if (!confirm('Окончательно удалить ВСЕ записи корзины старше ' + days + ' дн.?\n\nЭто действие необратимо.')) return;
            if (!confirm('Подтвердите: безвозвратное удаление записей старше ' + days + ' дн.')) return;

            purgeOldBtn.disabled = true;
            const original = purgeOldBtn.innerHTML;
            purgeOldBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Очистка…';
            let totalDeleted = 0, guard = 0;
            try {
                while (true) {
                    guard++;
                    if (guard > 1000) throw new Error('Слишком много итераций');
                    const data = await postJson('purge_old.php', { days: days, csrf: getCsrfToken() });
                    if (!data.success) throw new Error(data.error || 'Ошибка очистки');
                    totalDeleted += (data.deleted_count || 0);
                    if ((data.remaining || 0) <= 0 || (data.deleted_count || 0) === 0) break;
                }
                notify('Удалено ' + totalDeleted + ' аккаунт(ов) старше ' + days + ' дн.', 'success');
                setTimeout(() => window.location.reload(), 900);
            } catch (error) {
                log('error', 'Purge old error:', error.message);
                notify('Ошибка очистки: ' + error.message, 'error');
                purgeOldBtn.disabled = false;
                purgeOldBtn.innerHTML = original;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // История изменений (аудит)
    // ─────────────────────────────────────────────────────────────────────────
    const historyModalEl = document.getElementById('historyModal');
    const historyBody = document.getElementById('historyBody');
    const historyAccountId = document.getElementById('historyAccountId');
    let historyModal = null;
    if (historyModalEl && window.bootstrap) historyModal = new bootstrap.Modal(historyModalEl);

    document.querySelectorAll('.history-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = parseInt(this.dataset.id, 10);
            openHistory(id);
        });
    });

    async function openHistory(id) {
        if (historyAccountId) historyAccountId.textContent = '#' + id;
        if (historyBody) historyBody.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Загрузка…</div>';
        if (historyModal) historyModal.show();

        try {
            const resp = await fetch('trash_history.php?id=' + encodeURIComponent(id), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Ошибка загрузки истории');
            renderHistory(data);
        } catch (error) {
            log('error', 'History error:', error.message);
            if (historyBody) historyBody.innerHTML = '<div class="text-danger py-3"><i class="fas fa-exclamation-circle me-2"></i>' + escapeHtml(error.message) + '</div>';
        }
    }

    function renderHistory(data) {
        if (!historyBody) return;
        const rows = data.history || [];

        let head = '';
        if (data.deleted_by || data.deleted_at) {
            head = '<div class="alert alert-light border mb-3 mb-0">' +
                '<i class="fas fa-user-slash me-2 text-danger"></i>Удалил: <strong>' +
                escapeHtml(data.deleted_by || 'неизвестно') + '</strong>' +
                (data.deleted_at ? ' <span class="text-muted">(' + escapeHtml(data.deleted_at) + ')</span>' : '') +
                '</div>';
        }

        if (rows.length === 0) {
            historyBody.innerHTML = head + '<div class="text-muted text-center py-3">Записи истории не найдены. Возможно, аккаунт удалён до включения аудита.</div>';
            return;
        }

        const items = rows.map(function(r) {
            const field = escapeHtml(r.field_name || '');
            const oldV = escapeHtml(r.old_value || '');
            const newV = escapeHtml(r.new_value || '');
            const by = escapeHtml(r.changed_by || 'system');
            const at = escapeHtml(r.changed_at || '');
            const ip = r.ip_address ? ' · ' + escapeHtml(r.ip_address) : '';

            let change;
            if (field === 'deleted_at') {
                change = newV ? 'Удалён' : 'Восстановлен';
            } else if (field === 'delete_note') {
                change = 'Причина: ' + (newV || '—');
            } else {
                change = '<span class="text-muted">' + (oldV || '∅') + '</span> → <span class="fw-medium">' + (newV || '∅') + '</span>';
            }

            return '<div class="history-item">' +
                '<div class="history-field">' + field + '</div>' +
                '<div class="history-change">' + change + '</div>' +
                '<div class="history-meta">' + at + ' · ' + by + ip + '</div>' +
                '</div>';
        }).join('');

        historyBody.innerHTML = head + items;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Typed-confirm модалка (необратимое массовое удаление по фильтру)
    // ─────────────────────────────────────────────────────────────────────────
    const confirmModalEl = document.getElementById('confirmDeleteModal');
    const confirmInput = document.getElementById('confirmDeleteInput');
    const confirmOk = document.getElementById('confirmDeleteOk');
    const confirmText = document.getElementById('confirmDeleteText');
    const confirmNumber = document.getElementById('confirmDeleteNumber');
    let confirmModal = null;
    let confirmExpected = 0;
    let confirmCallback = null;
    if (confirmModalEl && window.bootstrap) confirmModal = new bootstrap.Modal(confirmModalEl);

    function openTypedConfirm(expectedNumber, text, onConfirm) {
        confirmExpected = expectedNumber;
        confirmCallback = onConfirm;
        if (confirmText) confirmText.textContent = text;
        if (confirmNumber) confirmNumber.textContent = String(expectedNumber);
        if (confirmInput) confirmInput.value = '';
        if (confirmOk) confirmOk.disabled = true;
        if (confirmModal) confirmModal.show();
    }

    if (confirmInput) {
        confirmInput.addEventListener('input', function() {
            confirmOk.disabled = (parseInt(this.value, 10) !== confirmExpected);
        });
    }
    if (confirmOk) {
        confirmOk.addEventListener('click', function() {
            if (parseInt(confirmInput.value, 10) !== confirmExpected) return;
            if (confirmModal) confirmModal.hide();
            const cb = confirmCallback;
            confirmCallback = null;
            if (typeof cb === 'function') cb();
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Утилиты
    // ─────────────────────────────────────────────────────────────────────────

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            const text = await response.text().catch(() => '');
            throw new Error('Сервер вернул некорректный ответ (HTTP ' + response.status + '): ' + text.substring(0, 200));
        }
        const data = await response.json();
        if (!response.ok && !data.error) {
            throw new Error('HTTP ' + response.status);
        }
        return data;
    }

    function removeRows(ids, fade) {
        ids.forEach(function(id) {
            const row = document.querySelector('tr[data-id="' + id + '"]');
            if (!row) return;
            if (fade) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 300);
            } else {
                row.remove();
            }
        });
    }

    function reloadIfEmpty(delay) {
        setTimeout(function() {
            if (document.querySelectorAll('.trash-checkbox').length === 0) {
                window.location.reload();
            }
        }, delay);
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // init
    updateSelectedCount();
});

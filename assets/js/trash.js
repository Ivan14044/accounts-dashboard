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
            (typeof logger !== 'undefined' ? logger.error : console.error)('Restore error:', error);
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
            (typeof logger !== 'undefined' ? logger.error : console.error)('Delete permanent error:', error);
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
            if (typeof logger !== 'undefined') logger.debug('🗑️ [EMPTY TRASH] Начало очистки корзины...');
            emptyTrashBtn.disabled = true;
            
            const csrfToken = getCsrfToken();
            if (typeof logger !== 'undefined') logger.debug('🗑️ [EMPTY TRASH] CSRF токен получен:', csrfToken ? (csrfToken.substring(0, 20) + '...') : 'НЕ НАЙДЕН');
            
            const requestBody = {
                csrf: csrfToken
            };
            if (typeof logger !== 'undefined') logger.debug('🗑️ [EMPTY TRASH] Отправка запроса с телом:', requestBody);
            
            const response = await fetch('empty_trash.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(requestBody)
            });
            
            if (typeof logger !== 'undefined') logger.debug('🗑️ [EMPTY TRASH] Ответ получен:', {
                status: response.status,
                statusText: response.statusText,
                ok: response.ok,
                contentType: response.headers.get('content-type')
            });
            
            const contentType = response.headers.get('content-type') || '';
            const isJson = contentType.includes('application/json');
            
            if (!response.ok) {
                let errorMessage = `Ошибка ${response.status}: ${response.statusText}`;
                
                if (isJson) {
                    try {
                        const errorData = await response.json();
                        (typeof logger !== 'undefined' ? logger.error : console.error)('🗑️ [EMPTY TRASH] Ошибка (JSON):', errorData);
                        errorMessage = errorData.error || errorMessage;
                    } catch (e) {
                        (typeof logger !== 'undefined' ? logger.error : console.error)('🗑️ [EMPTY TRASH] Ошибка парсинга JSON ошибки:', e);
                    }
                } else {
                    const textResponse = await response.text().catch(() => '');
                    (typeof logger !== 'undefined' ? logger.error : console.error)('🗑️ [EMPTY TRASH] Ошибка (текст):', textResponse.substring(0, 500));
                    errorMessage = textResponse || errorMessage;
                }
                
                throw new Error(errorMessage);
            }
            
            if (isJson) {
                const data = await response.json();
                if (typeof logger !== 'undefined') logger.debug('🗑️ [EMPTY TRASH] Данные ответа:', data);
                
                if (data.success) {
                    if (typeof logger !== 'undefined') logger.debug('✅ [EMPTY TRASH] Корзина успешно очищена!', {
                        deleted_count: data.deleted_count || 0
                    });
                    
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
            } else {
                const textResponse = await response.text().catch(() => '');
                (typeof logger !== 'undefined' ? logger.error : console.error)('🗑️ [EMPTY TRASH] Ответ не JSON:', textResponse.substring(0, 500));
                throw new Error('Сервер вернул некорректный ответ');
            }
        } catch (error) {
            (typeof logger !== 'undefined' ? logger.error : console.error)('❌ [EMPTY TRASH] Критическая ошибка:', error);
            (typeof logger !== 'undefined' ? logger.error : console.error)('❌ [EMPTY TRASH] Детали ошибки:', {
                name: error.name,
                message: error.message,
                stack: error.stack
            });
            
            if (typeof showToast === 'function') {
                showToast('Ошибка при очистке корзины: ' + (error.message || 'Неизвестная ошибка'), 'error');
            } else {
                alert('Ошибка при очистке корзины: ' + (error.message || 'Неизвестная ошибка'));
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



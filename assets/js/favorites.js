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
        this.tableObserver = null; // MutationObserver для отслеживания изменений таблицы
        this.observerDebounceTimer = null; // Debounce для observer
        
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
                    // Создаём иконку безопасным методом
                    const newIcon = document.createElement('i');
                    newIcon.className = 'fas fa-star';
                    btn.innerHTML = '';
                    btn.appendChild(newIcon);
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
                    // Создаём иконку безопасным методом
                    const newIcon = document.createElement('i');
                    newIcon.className = 'far fa-star';
                    btn.innerHTML = '';
                    btn.appendChild(newIcon);
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
                // Создаём иконку, если её нет (безопасный метод)
                const icon = document.createElement('i');
                icon.className = isFavorite ? 'fas fa-star' : 'far fa-star';
                favoriteBtn.innerHTML = '';
                favoriteBtn.appendChild(icon);
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
    
    /**
     * Очистка ресурсов и отключение observers
     */
    cleanup() {
        // Отключаем MutationObserver
        if (this.tableObserver) {
            this.tableObserver.disconnect();
            this.tableObserver = null;
        }
        
        // Очищаем таймеры
        if (this.updateDebounceTimer) {
            clearTimeout(this.updateDebounceTimer);
            this.updateDebounceTimer = null;
        }
        
        if (this.observerDebounceTimer) {
            clearTimeout(this.observerDebounceTimer);
            this.observerDebounceTimer = null;
        }
        
        // Очищаем множества
        this.favorites.clear();
        this.processingIds.clear();
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
        let checkLoaded = null;
        const maxWait = setTimeout(() => {
            if (checkLoaded) {
                clearInterval(checkLoaded);
                checkLoaded = null;
            }
        }, 5000);
        
        checkLoaded = setInterval(() => {
            if (window.favoritesManager && window.favoritesManager.loaded) {
                clearInterval(checkLoaded);
                clearTimeout(maxWait);
                checkLoaded = null;
                // Небольшая задержка для гарантии, что DOM готов
                setTimeout(() => {
                    if (window.favoritesManager) {
                        window.favoritesManager._updateFavoritesUIInternal();
                    }
                }, 200);
            }
        }, 100);
    }
    
    // Наблюдаем за изменениями в таблице (с debounce)
    const accountsTable = document.querySelector('#accountsTable tbody');
    if (accountsTable && window.favoritesManager) {
        window.favoritesManager.tableObserver = new MutationObserver(() => {
            // Debounce для предотвращения множественных обновлений
            if (window.favoritesManager.observerDebounceTimer) {
                clearTimeout(window.favoritesManager.observerDebounceTimer);
            }
            window.favoritesManager.observerDebounceTimer = setTimeout(() => {
                updateFavoritesAfterTableUpdate();
            }, 200);
        });
        
        window.favoritesManager.tableObserver.observe(accountsTable, {
            childList: true,
            subtree: false // Отключаем subtree для оптимизации
        });
    }
    
    // Обновляем после обновления данных через refresh
    if (typeof refreshDashboardData !== 'undefined') {
        const originalRefresh = window.refreshDashboardData;
        window.refreshDashboardData = async function(...args) {
            try {
                const result = await originalRefresh.apply(this, args);
                updateFavoritesAfterTableUpdate();
                return result;
            } catch (error) {
                // Игнорируем AbortError - это нормальная отмена запроса
                if (error.name === 'AbortError' || error.message?.includes('aborted')) {
                    return;
                }
                // Пробрасываем другие ошибки
                throw error;
            }
        };
    }
    
    // Обновляем при изменении фильтров/пагинации
    setTimeout(() => {
        updateFavoritesAfterTableUpdate();
    }, 500);
    
    // Очистка при закрытии страницы
    window.addEventListener('beforeunload', () => {
        if (window.favoritesManager && typeof window.favoritesManager.cleanup === 'function') {
            window.favoritesManager.cleanup();
        }
    });
});


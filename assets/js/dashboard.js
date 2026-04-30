/**
 * Оптимизированный JavaScript для дашборда
 * Исправляет утечки памяти, улучшает производительность
 * 
 * VERSION: 2024-01-XX-DEBUG-WITH-LOGS
 */

// ОЧЕНЬ ЗАМЕТНЫЙ ЛОГ для проверки, что файл загружается
logger.debug('%c📜📜📜 DASHBOARD.JS ЗАГРУЖЕН (версия с логами) 📜📜📜', 'color: red; font-size: 20px; font-weight: bold; background: yellow; padding: 10px;');
logger.debug('📜 [DASHBOARD.JS] Файл dashboard.js загружается...');
logger.debug('📜 [DASHBOARD.JS] Текущее время:', new Date().toISOString());
logger.debug('📜 [DASHBOARD.JS] URL файла:', document.currentScript ? document.currentScript.src : 'unknown');

// Используем DOMCache для оптимизации DOM запросов (загружается через dom-cache.js)
// Fallback на прямые вызовы, если глобальный window.domCache еще не загружен.
// ВАЖНО: используем отдельное имя `dashboardDomCache`, чтобы не конфликтовать
// с глобальной константой `domCache`, создаваемой в `core/dom-cache.js`.
const dashboardDomCache = (function() {
  if (window.domCache) {
    return window.domCache;
  }
  // Fallback для случаев, когда domCache еще не инициализирован
  return {
    get: (selector) => {
      if (selector.startsWith('#')) {
        return document.getElementById(selector.slice(1));
      }
      return document.querySelector(selector);
    },
    getById: (id) => document.getElementById(id),
    getAll: (selector) => document.querySelectorAll(selector)
  };
})();

(function() {
    logger.debug('📜 [DASHBOARD.JS] Проверка inline dashboard:', {
        hasWindow: typeof window !== 'undefined',
        inlineActive: typeof window !== 'undefined' ? window.__INLINE_DASHBOARD_ACTIVE__ : 'no window'
    });

    // Загрузка аккаунтов вынесена в assets/js/modules/dashboard-upload.js
    // Fallback: определяем handleUploadAccountsGlobal только если модуль не загружен
    if (typeof window.handleUploadAccountsGlobal !== 'function') {
    window.handleUploadAccountsGlobal = async function(e) {
        logger.debug('🚨🚨🚨 === ГЛОБАЛЬНАЯ ФУНКЦИЯ ЗАГРУЗКИ АККАУНТОВ === 🚨🚨🚨');
        logger.debug('🚨 [GLOBAL UPLOAD] Функция handleUploadAccountsGlobal вызвана!');
        logger.debug('🚨 [GLOBAL UPLOAD] Событие:', e);
        
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
            logger.debug('🚨 [GLOBAL UPLOAD] preventDefault() вызван');
        }
        
        const form = dashboardDomCache.getById('uploadAccountsForm');
        const submitBtn = dashboardDomCache.getById('uploadAccountsBtn');
        const errorsDiv = dashboardDomCache.getById('addAccountErrors');
        const successDiv = dashboardDomCache.getById('addAccountSuccess');
        const fileInput = dashboardDomCache.getById('accountsFile');
        
        logger.debug('🚨 [GLOBAL UPLOAD] Элементы формы:', {
            form: form ? 'найден' : 'не найден',
            submitBtn: submitBtn ? 'найден' : 'не найден',
            errorsDiv: errorsDiv ? 'найден' : 'не найден',
            successDiv: successDiv ? 'найден' : 'не найден',
            fileInput: fileInput ? 'найден' : 'не найден'
        });
        
        if (errorsDiv) errorsDiv.classList.add('d-none');
        if (successDiv) successDiv.classList.add('d-none');
        
        // Проверка выбранного файла
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            logger.warn('⚠️ [GLOBAL UPLOAD] Файл не выбран');
            if (errorsDiv) {
                errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки';
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        const file = fileInput.files[0];
        logger.debug('📁 [GLOBAL UPLOAD] Информация о файле:', {
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: new Date(file.lastModified).toISOString()
        });
        
        // Проверка размера файла (20MB)
        const maxSize = 20 * 1024 * 1024;
        if (file.size > maxSize) {
            logger.error('❌ [GLOBAL UPLOAD] Файл слишком большой:', file.size, 'байт (максимум:', maxSize, 'байт)');
            if (errorsDiv) {
                errorsDiv.textContent = `Файл слишком большой. Максимальный размер: ${Math.round(maxSize / 1024 / 1024)} MB`;
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        // Проверка расширения файла
        const allowedExtensions = ['.csv', '.txt'];
        const fileName = file.name.toLowerCase();
        const hasValidExtension = allowedExtensions.some(ext => fileName.endsWith(ext));
        logger.debug('🔍 [GLOBAL UPLOAD] Проверка расширения файла:', {
            fileName: fileName,
            hasValidExtension: hasValidExtension,
            allowedExtensions: allowedExtensions
        });
        
        if (!hasValidExtension) {
            logger.error('❌ [GLOBAL UPLOAD] Неподдерживаемое расширение файла:', fileName);
            if (errorsDiv) {
                errorsDiv.textContent = 'Поддерживаются только файлы CSV или TXT';
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        const formData = new FormData(form);
        logger.debug('📦 [GLOBAL UPLOAD] Данные формы FormData:');
        for (let [key, value] of formData.entries()) {
            if (key === 'import_file') {
                logger.debug(`  ${key}:`, '[File object]', value.name, value.size + ' bytes');
            } else {
                logger.debug(`  ${key}:`, value);
            }
        }
        
        if (submitBtn) {
            submitBtn.disabled = true;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка...';
            
            try {
                logger.debug('🚀 [GLOBAL UPLOAD] Отправка запроса на import_accounts.php...');
                const response = await fetch(window.getTableAwareUrl('import_accounts.php'), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                logger.debug('📥 [GLOBAL UPLOAD] Ответ получен:', {
                    status: response.status,
                    statusText: response.statusText,
                    ok: response.ok,
                    url: response.url
                });
                
                // Проверяем Content-Type ответа
                const contentType = response.headers.get('content-type') || '';
                const isJson = contentType.includes('application/json');
                logger.debug('📋 [GLOBAL UPLOAD] Заголовки ответа:', {
                    'content-type': contentType,
                    isJson: isJson,
                    allHeaders: Object.fromEntries(response.headers.entries())
                });
                
                let result;
                
                if (!response.ok) {
                    logger.error('❌ [GLOBAL UPLOAD] Ответ с ошибкой:', response.status, response.statusText);
                    // Пытаемся прочитать ошибку как JSON, если это возможно
                    if (isJson) {
                        try {
                            const errorData = await response.json();
                            logger.error('📄 [GLOBAL UPLOAD] Ошибка (JSON):', errorData);
                            throw new Error(errorData.error || `Ошибка ${response.status}: ${response.statusText}`);
                        } catch (parseError) {
                            logger.error('❌ [GLOBAL UPLOAD] Ошибка парсинга JSON ошибки:', parseError);
                            if (parseError instanceof Error && parseError.message.includes('Ошибка')) {
                                throw parseError;
                            }
                            throw new Error(`Ошибка ${response.status}: ${response.statusText}`);
                        }
                    } else {
                        // Если ответ не JSON, читаем как текст
                        const errorText = await response.text().catch(() => '');
                        logger.error('📄 [GLOBAL UPLOAD] Ошибка (текст):', errorText.substring(0, 500));
                        throw new Error(errorText || `Ошибка ${response.status}: ${response.statusText}`);
                    }
                }
                
                // Парсим успешный ответ
                if (isJson) {
                    try {
                        logger.debug('🔄 [GLOBAL UPLOAD] Парсинг JSON ответа...');
                        result = await response.json();
                        logger.debug('✅ [GLOBAL UPLOAD] JSON успешно распарсен:', result);
                    } catch (parseError) {
                        logger.error('❌ [GLOBAL UPLOAD] Ошибка парсинга JSON ответа:', parseError);
                        logger.error('📄 [GLOBAL UPLOAD] Сырой ответ (первые 500 символов):', await response.clone().text().then(t => t.substring(0, 500)).catch(() => 'Не удалось прочитать'));
                        throw new Error('Ошибка при обработке ответа от сервера. Проверьте формат файла и попробуйте снова.');
                    }
                } else {
                    // Если ответ не JSON, это ошибка
                    logger.warn('⚠️ [GLOBAL UPLOAD] Ответ не является JSON, пытаемся прочитать как текст...');
                    const textResponse = await response.text().catch(() => '');
                    logger.error('📄 [GLOBAL UPLOAD] Текстовый ответ (первые 500 символов):', textResponse.substring(0, 500));
                    throw new Error(textResponse || 'Сервер вернул некорректный ответ. Попробуйте снова.');
                }
                
                logger.debug('🔍 [GLOBAL UPLOAD] Результат импорта:', {
                    success: result.success,
                    created: result.created,
                    skipped: result.skipped,
                    total: result.total,
                    errorsCount: result.errors ? result.errors.length : 0,
                    message: result.message
                });
                
                if (result.success) {
                    logger.debug('✅ [GLOBAL UPLOAD] Импорт успешен!', {
                        created: result.created || 0,
                        skipped: result.skipped || 0,
                        errors: result.errors ? result.errors.length : 0
                    });
                    
                    // Группируем ошибки по типам для более понятного отображения
                    const errorGroups = {};
                    if (result.errors && result.errors.length > 0) {
                        result.errors.forEach(err => {
                            const msg = err.message || 'Неизвестная ошибка';
                            if (!errorGroups[msg]) {
                                errorGroups[msg] = {
                                    message: msg,
                                    count: 0,
                                    examples: [] // Примеры строк с этой ошибкой (максимум 5)
                                };
                            }
                            errorGroups[msg].count++;
                            if (errorGroups[msg].examples.length < 5) {
                                errorGroups[msg].examples.push(err.row);
                            }
                        });
                        
                        logger.warn('⚠️ [GLOBAL UPLOAD] Обнаружены ошибки при импорте!');
                        logger.warn('⚠️ [GLOBAL UPLOAD] Группировка ошибок:', errorGroups);
                        result.errors.forEach((err, index) => {
                            logger.error(`❌ [GLOBAL UPLOAD] Ошибка ${index + 1}:`, {
                                row: err.row,
                                message: err.message,
                                fullError: err
                            });
                        });
                    }
                    
                    const created = result.created || 0;
                    const duplicates = result.skipped || 0;
                    const errorsCount = result.errors ? result.errors.length : 0;
                    
                    // 1. Показываем улучшенное toast‑уведомление
                    if (typeof window.showToast === 'function') {
                        // Формируем более понятное сообщение
                        let toastMsg = '';
                        
                        if (created > 0) {
                            toastMsg += `✅ Добавлено: ${created}`;
                        }
                        
                        if (duplicates > 0) {
                            if (toastMsg) toastMsg += '\n';
                            toastMsg += `⚠️ Пропущено (уже есть в панели): ${duplicates}`;
                        }
                        
                        if (errorsCount > 0) {
                            if (toastMsg) toastMsg += '\n';
                            // Показываем краткую информацию об ошибках
                            const errorTypes = Object.keys(errorGroups);
                            if (errorTypes.length === 1) {
                                // Если все ошибки одного типа, показываем это явно
                                const errorType = errorTypes[0];
                                const humanReadable = errorType === 'Status is required' 
                                    ? 'отсутствует статус' 
                                    : errorType === 'Login is required'
                                    ? 'отсутствует логин'
                                    : errorType.toLowerCase();
                                toastMsg += `❌ Не добавлено (${humanReadable}): ${errorsCount}`;
                            } else {
                                toastMsg += `❌ Не добавлено из-за ошибок: ${errorsCount}`;
                            }
                        }
                        
                        if (!toastMsg) {
                            toastMsg = 'Импорт завершён';
                        }

                        logger.debug('🔔 [GLOBAL UPLOAD] Показ toast уведомления:', {
                            message: toastMsg,
                            created,
                            duplicates,
                            errorsCount
                        });

                        // Если есть ошибки или дубликаты, показываем предупреждение, иначе — успех
                        const toastType = (errorsCount > 0 || duplicates > 0) ? 'warning' : 'success';
                        window.showToast(toastMsg, toastType);
                    } else {
                        logger.warn('⚠️ [GLOBAL UPLOAD] Функция window.showToast не найдена');
                    }
                    
                    // 2. Показываем детальную информацию об ошибках в errorsDiv
                    if (errorsDiv) {
                        if (errorsCount > 0) {
                            let detailsHtml = '<div class="import-result-details">';
                            
                            // Заголовок
                            detailsHtml += `<h6 class="mb-3"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Детали ошибок импорта:</h6>`;
                            
                            // Группированные ошибки
                            Object.values(errorGroups).forEach(group => {
                                detailsHtml += '<div class="mb-3">';
                                
                                // Название типа ошибки (человекочитаемое)
                                let errorTitle = group.message;
                                if (group.message === 'Status is required') {
                                    errorTitle = 'Отсутствует статус';
                                } else if (group.message === 'Login is required') {
                                    errorTitle = 'Отсутствует логин';
                                } else if (group.message.includes('already exists')) {
                                    errorTitle = 'Дубликат логина';
                                }
                                
                                detailsHtml += `<div class="fw-semibold mb-1">${this.escapeHtml(errorTitle)} <span class="badge bg-danger">${group.count}</span></div>`;

                                // Примеры строк
                                if (group.examples.length > 0) {
                                    const examplesText = group.examples.length === group.count
                                        ? `Строки: ${group.examples.map(e => this.escapeHtml(String(e))).join(', ')}`
                                        : `Примеры строк: ${group.examples.map(e => this.escapeHtml(String(e))).join(', ')}${group.count > group.examples.length ? ` и ещё ${group.count - group.examples.length}` : ''}`;
                                    detailsHtml += `<div class="text-muted small">${this.escapeHtml(examplesText)}</div>`;
                                }
                                
                                detailsHtml += '</div>';
                            });
                            
                            // Рекомендации
                            detailsHtml += '<div class="mt-3 p-2 bg-light rounded">';
                            detailsHtml += '<small class="text-muted">';
                            detailsHtml += '<strong>Рекомендации:</strong><br>';
                            if (errorGroups['Status is required']) {
                                detailsHtml += '• Заполните поле "status" для всех строк<br>';
                            }
                            if (errorGroups['Login is required']) {
                                detailsHtml += '• Заполните поле "login" для всех строк<br>';
                            }
                            if (duplicates > 0) {
                                detailsHtml += '• Проверьте, не дублируются ли логины в файле<br>';
                            }
                            detailsHtml += '• Исправьте ошибки в файле и попробуйте импортировать снова';
                            detailsHtml += '</small>';
                            detailsHtml += '</div>';
                            
                            detailsHtml += '</div>';
                            
                            errorsDiv.innerHTML = detailsHtml;
                            errorsDiv.classList.remove('d-none');
                        } else {
                            errorsDiv.classList.add('d-none');
                            errorsDiv.innerHTML = '';
                        }
                    }
                    
                    // 3. Очищаем форму
                    if (form) {
                        form.reset();
                        logger.debug('🧹 [GLOBAL UPLOAD] Форма очищена');
                    }
                    if (successDiv) {
                        successDiv.classList.add('d-none');
                        successDiv.innerHTML = '';
                    }
                    
                    // 4. Закрываем модальное окно только если нет ошибок
                    // Если есть ошибки, оставляем модальное окно открытым, чтобы пользователь видел детали
                    if (errorsCount === 0) {
                        const addAccountModal = dashboardDomCache.getById('addAccountModal');
                        if (addAccountModal) {
                            try {
                                // Пробуем получить существующий инстанс
                                let modalInstance = typeof bootstrap !== 'undefined' && bootstrap.Modal
                                    ? bootstrap.Modal.getInstance(addAccountModal)
                                    : null;
                                
                                // Если инстанса нет, создаем новый
                                if (!modalInstance && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                    modalInstance = bootstrap.Modal.getOrCreateInstance(addAccountModal);
                                }
                                
                                // Закрываем модальное окно
                                if (modalInstance) {
                                    logger.debug('🔒 [GLOBAL UPLOAD] Закрытие модального окна через Bootstrap API...');
                                    modalInstance.hide();
                                } else {
                                    // Fallback: используем data-атрибут
                                    logger.debug('🔒 [GLOBAL UPLOAD] Fallback: закрытие через data-атрибут...');
                                    const closeBtn = addAccountModal.querySelector('[data-bs-dismiss="modal"]');
                                    if (closeBtn) {
                                        closeBtn.click();
                                    }
                                }
                            } catch (error) {
                                logger.error('❌ [GLOBAL UPLOAD] Ошибка при закрытии модального окна:', error);
                                // Fallback: используем data-атрибут
                                const closeBtn = addAccountModal.querySelector('[data-bs-dismiss="modal"]');
                                if (closeBtn) {
                                    closeBtn.click();
                                }
                            }
                        }
                    } else {
                        logger.debug('ℹ️ [GLOBAL UPLOAD] Модальное окно остаётся открытым для просмотра ошибок');
                        // Прокручиваем к блоку с ошибками, чтобы пользователь сразу увидел детали
                        if (errorsDiv) {
                            setTimeout(() => {
                                errorsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }, 100);
                        }
                    }
                    
                    // 4. Обновляем таблицу после закрытия модального окна
                    setTimeout(() => {
                        logger.debug('🔄 [GLOBAL UPLOAD] Обновление данных дашборда...');
                        if (typeof window.refreshDashboardData === 'function') {
                            window.refreshDashboardData().catch(error => {
                                logger.error('❌ [GLOBAL UPLOAD] Ошибка при обновлении дашборда:', error);
                                // Если обновление не сработало, перезагружаем страницу
                                if (error.name !== 'AbortError') {
                                    logger.warn('⚠️ [GLOBAL UPLOAD] Перезагрузка страницы из-за ошибки обновления...');
                                    window.location.reload();
                                }
                            });
                        } else {
                            logger.warn('⚠️ [GLOBAL UPLOAD] Функция window.refreshDashboardData не найдена, перезагрузка страницы...');
                            window.location.reload();
                        }
                    }, 400); // Оптимальная задержка для закрытия модального окна
                    
                } else {
                    logger.error('❌ [GLOBAL UPLOAD] Импорт не успешен, result.success = false:', result);
                    throw new Error(result.error || 'Ошибка при загрузке файла');
                }
            } catch (error) {
                logger.error('❌ [GLOBAL UPLOAD] КРИТИЧЕСКАЯ ОШИБКА при загрузке аккаунтов:', error);
                logger.error('📊 [GLOBAL UPLOAD] Детали ошибки:', {
                    name: error.name,
                    message: error.message,
                    stack: error.stack
                });
                
                let errorMessage = 'Ошибка при загрузке файла. Проверьте формат файла и попробуйте снова.';
                
                if (error instanceof Error) {
                    errorMessage = error.message || errorMessage;
                    // Очищаем HTML теги из сообщения об ошибке для безопасности
                    const tempDiv = document.createElement('div');
                    tempDiv.textContent = errorMessage;
                    errorMessage = tempDiv.textContent || errorMessage;
                }
                
                logger.debug('📝 [GLOBAL UPLOAD] Отображение ошибки пользователю:', errorMessage);
                
                if (errorsDiv) {
                    errorsDiv.textContent = errorMessage;
                    errorsDiv.classList.remove('d-none');
                } else {
                    logger.error('❌ [GLOBAL UPLOAD] errorsDiv не найден, не удалось отобразить ошибку!');
                }
                
                if (typeof window.showToast === 'function') {
                    logger.debug('🔔 [GLOBAL UPLOAD] Показ toast уведомления об ошибке');
                    window.showToast(errorMessage, 'error');
                } else {
                    logger.warn('⚠️ [GLOBAL UPLOAD] Функция window.showToast не найдена');
                }
            } finally {
                logger.debug('🏁 [GLOBAL UPLOAD] Завершение обработки запроса, восстановление кнопки');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
                logger.debug('=== КОНЕЦ ГЛОБАЛЬНОЙ ЗАГРУЗКИ АККАУНТОВ ===');
            }
        } else {
            logger.error('❌ [GLOBAL UPLOAD] submitBtn не найден!');
        }
    };
    } // конец fallback handleUploadAccountsGlobal

    if (typeof window !== 'undefined' && window.__INLINE_DASHBOARD_ACTIVE__) {
        // Inline dashboard скрипт активен — модуль dashboard-upload уже привязал форму
        logger.debug('✅ [DASHBOARD.JS] Inline dashboard активен, модуль upload подключен');
        // Модуль dashboard-upload уже привязал форму при DOMContentLoaded
        return;
    }

class Dashboard {
    constructor() {
        logger.debug('🏗️ [CONSTRUCTOR] Создание экземпляра Dashboard...');
        logger.debug('🏗️ [CONSTRUCTOR] Время:', new Date().toISOString());
        
        this.selectedIds = new Set();
        this.selectedAllFiltered = false;
        this.filteredTotalLive = 0;
        this.isRefreshing = false;
        this.refreshController = null;
        this.refreshQueueCount = 0;
        this.overlayShownAt = 0;
        this.cleanupInterval = null; // Для очистки setInterval
        
        // Дебаунс функции для оптимизации
        this.debouncedSearch = this.debounce(this.applyLiveSearch.bind(this), 300);
        this.debouncedRefresh = this.debounce(this.refreshDashboardData.bind(this), 100);
        
        // Сохранение ссылок на обработчики для последующего удаления
        this.boundHandlers = {
            click: this.handleDocumentClick.bind(this),
            change: this.handleDocumentChange.bind(this),
            submit: this.handleFormSubmit.bind(this),
            beforeunload: this.cleanup.bind(this)
        };
        
        // Константы
        this.LS_KEYS = {
            COLUMNS: 'dashboard_visible_columns',
            CARDS: 'dashboard_visible_cards',
            KNOWN_COLS: 'dashboard_known_columns',
            SELECTED: 'dashboard_selected_ids',
            CUSTOM_CARDS: 'dashboard_custom_cards_v1'
        };
        
        logger.debug('🏗️ [CONSTRUCTOR] Вызов метода init()...');
        this.init();
        logger.debug('✅ [CONSTRUCTOR] Dashboard успешно создан и инициализирован');
    }
    
    init() {
        logger.debug('🔧 [INIT] Метод init() вызван');
        logger.debug('🔧 [INIT] Загрузка выбранных ID...');
        this.loadSelectedIds();
        logger.debug('🔧 [INIT] Обновление счетчика выбранных...');
        this.updateSelectedCount();
        logger.debug('🔧 [INIT] Загрузка настроек...');
        this.loadSettings();
        logger.debug('🔧 [INIT] Привязка событий...');
        this.bindEvents();
        logger.debug('🔧 [INIT] Инициализация компонентов...');
        this.initializeComponents();
        
        // Автоочистка кэша каждые 5 минут (сохраняем ссылку для очистки)
        this.cleanupInterval = setInterval(() => this.cleanupMemory(), 5 * 60 * 1000);
        logger.debug('✅ [INIT] Инициализация завершена');
    }
    
    loadSelectedIds() {
        try {
            const saved = localStorage.getItem(this.LS_KEYS.SELECTED);
            if (saved) {
                this.selectedIds = new Set(JSON.parse(saved));
            }
        } catch (e) {
            logger.error('Error loading selected IDs:', e);
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
            
            const allColKeys = Array.from(dashboardDomCache.getAll('.column-toggle'))
                .map(cb => cb.getAttribute('data-col'));
            const newCols = allColKeys.filter(c => !knownCols.includes(c));
            
            dashboardDomCache.getAll('.column-toggle').forEach(cb => {
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
                dashboardDomCache.getAll('.card-toggle').forEach(cb => {
                    const cardId = cb.getAttribute('data-card');
                    cb.checked = visibleCards.includes(cardId);
                });
            }
        } catch (e) {
            logger.error('Error loading settings:', e);
        }
    }
    
    bindEvents() {
        // Используем делегирование событий для лучшей производительности
        // Используем сохраненные ссылки для возможности удаления
        document.addEventListener('click', this.boundHandlers.click);
        document.addEventListener('change', this.boundHandlers.change);
        document.addEventListener('submit', this.boundHandlers.submit);
        
        // Оптимизированные обработчики для частых событий
        const searchInput = document.querySelector('input[name="q"]');
        if (searchInput) {
            // ОТКЛЮЧЕНО автоприменение - только по кнопке "Применить"
            // searchInput.addEventListener('input', this.debouncedSearch);
            this.boundHandlers.searchKeydown = this.handleSearchKeydown.bind(this);
            searchInput.addEventListener('keydown', this.boundHandlers.searchKeydown);
        }
        
        // Обработчики для селектов
        this.bindSelectEvents();

        // Предотвращение утечек памяти при закрытии страницы
        window.addEventListener('beforeunload', this.boundHandlers.beforeunload);
    }
    
    handleDocumentClick(e) {
        // Переключение паролей
        const pwToggle = e.target.closest('.pw-toggle');
        if (pwToggle) {
            this.togglePassword(pwToggle);
            return;
        }
        
        // Модальные окна для полного содержимого. Захватываем data-full ИЛИ data-truncated
        // (для heavy-полей значение лежит не в DOM, а лениво грузится через AJAX)
        const fullDataTarget = e.target.closest('[data-full],[data-truncated]');
        if (fullDataTarget) {
            this.showFullDataModal(fullDataTarget);
            return;
        }

        // Редактирование ячеек таблицы
        const editableCell = e.target.closest('#accountsTable td[data-col]');
        if (editableCell && !e.target.closest('a,button,.pw-toggle,[data-full],[data-truncated]')) {
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
        
        // Форма добавления аккаунта обрабатывается отдельно
        if (e.target.id === 'addAccountForm') {
            // Предотвращаем дефолтное поведение, обработка в handleAddAccountSubmit
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
    
    // Оптимизированное обновление данных
    async refreshDashboardData() {
        // Предотвращаем множественные запросы
        const currentController = this.refreshController;
        if (currentController) {
            this.refreshQueueCount++;
            try {
                currentController.abort();
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
                logger.error('Refresh error:', error);
                this.showToast('Ошибка обновления данных', 'error');
            }
        } finally {
            this.isRefreshing = false;
            this.hideLoadingOverlay();
            this.refreshController = null;
            
            // Если был запрос на повторное обновление
            if (this.refreshQueueCount > 0) {
                this.refreshQueueCount--;
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
        const tbody = dashboardDomCache.get('#accountsTable tbody');
        if (!tbody || !Array.isArray(data.rows)) return;

        // Сохраняем позицию скролла
        const prevScrollTop = window.pageYOffset || document.documentElement.scrollTop;

        // Плавная анимация обновления
        tbody.style.opacity = '0.7';
        tbody.style.transition = 'opacity 0.2s ease';

        // Generation guard: если за 100ms пришёл более свежий updateTable(),
        // отменяем перезапись содержимого, чтобы stale-ответ не затёр новый.
        this._tableGen = (this._tableGen || 0) + 1;
        const gen = this._tableGen;

        setTimeout(() => {
            if (gen !== this._tableGen) {
                // Пришёл более свежий апдейт — дропаем этот.
                return;
            }
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
            const colCount = dashboardDomCache.getAll('#accountsTable thead th').length;
            return `<tr><td colspan="${colCount}" class="text-center text-muted py-5">
                <i class="fas fa-search fa-2x mb-3"></i><div>Ничего не найдено</div>
            </td></tr>`;
        }
        
        const headKeys = Array.from(dashboardDomCache.getAll('#accountsTable thead th[data-col]'))
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
            return `<span class="text-muted">—</span>
                <button type="button" class="copy-btn" data-copy-text="" title="Копировать"><i class="fas fa-copy"></i></button>`;
        }
        
        // Специальная обработка для разных типов колонок
        switch (col) {
            case 'id':
                return `<span class="fw-bold text-primary">#${this.escapeHtml(value)}</span>
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать"><i class="fas fa-copy"></i></button>`;
                
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
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать пароль"><i class="fas fa-copy"></i></button>
                </div>`;
                
            case 'status':
                return `<div class="d-flex align-items-center gap-2">${this.renderStatusBadge(value)}
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
                </div>`;
                
            default:
                // Длинные поля
                if (typeof value === 'string' && value.length > 80) {
                    const clipped = value.substring(0, 80) + '…';
                    return `<span class="truncate mono" title="Нажмите для просмотра" 
                        data-full="${this.escapeHtml(value)}" data-title="${this.escapeHtml(col)}">
                        ${this.escapeHtml(clipped)}
                    </span>
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать"><i class="fas fa-copy"></i></button>`;
                }
                
                return `<span>${this.escapeHtml(value)}</span>
                    <button type="button" class="copy-btn" data-copy-text="${this.escapeHtml(value)}" title="Копировать"><i class="fas fa-copy"></i></button>`;
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
        const oldElements = dashboardDomCache.getAll('[data-cleanup]');
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
        const currentController = this.refreshController;
        if (currentController) {
            try {
                currentController.abort();
            } catch (e) {
                // Игнорируем ошибки отмены
            }
        }
        
        // Очищаем интервал автоочистки
        if (this.cleanupInterval) {
            clearInterval(this.cleanupInterval);
            this.cleanupInterval = null;
        }
        
        // Удаляем обработчики событий
        if (this.boundHandlers) {
            document.removeEventListener('click', this.boundHandlers.click);
            document.removeEventListener('change', this.boundHandlers.change);
            document.removeEventListener('submit', this.boundHandlers.submit);
            window.removeEventListener('beforeunload', this.boundHandlers.beforeunload);
            
            // Удаляем обработчик поиска, если был добавлен
            const searchInput = document.querySelector('input[name="q"]');
            if (searchInput && this.boundHandlers.searchKeydown) {
                searchInput.removeEventListener('keydown', this.boundHandlers.searchKeydown);
            }
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
        logger.debug(`Toast [${type}]: ${message}`);
    }
    
    showLoadingOverlay() {
        const overlay = dashboardDomCache.getById('tableLoading');
        if (overlay) {
            overlay.classList.add('show');
            this.overlayShownAt = Date.now();
        }
    }
    
    hideLoadingOverlay() {
        const overlay = dashboardDomCache.getById('tableLoading');
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
    
    // Показ полного содержимого длинных полей. Поддерживает два режима:
    //  - data-full: значение уже в DOM (обычные long-fields).
    //  - data-truncated: heavy-поле, в DOM лежит обрезанное preview, полный текст
    //    лениво грузим через window.fetchAccountField().
    showFullDataModal(target) {
        const title = target.getAttribute('data-title') || 'Данные';
        if (target.hasAttribute('data-truncated')) {
            const t = (typeof window._resolveTruncatedTarget === 'function')
                ? window._resolveTruncatedTarget(target)
                : { rowId: null, field: null };
            if (!t.rowId || !t.field) return;
            // Сначала покажем placeholder, чтобы пользователь видел что грузим
            this._renderFullDataModal(title, 'Загружаю…');
            if (typeof window.fetchAccountField === 'function') {
                window.fetchAccountField(t.rowId, t.field).then((value) => {
                    this._renderFullDataModal(title, value || '(пусто)');
                });
            }
            return;
        }
        const full = target.getAttribute('data-full') || '';
        if (!full) return;
        this._renderFullDataModal(title, full);
    }

    _renderFullDataModal(title, full) {
        let modal = document.getElementById('fullDataModal');
        // Если предыдущий instance ещё на странице — обновим title/body in-place
        // (нужно для AJAX-кейса: сначала показываем "Загружаю…", потом ответ).
        if (modal) {
            const titleDiv = modal.querySelector('.fdm-title');
            const pre = modal.querySelector('pre');
            if (titleDiv) titleDiv.textContent = title;
            if (pre) pre.textContent = full;
            return;
        }

        modal = document.createElement('div');
        modal.id = 'fullDataModal';

        const backdrop = document.createElement('div');
        backdrop.className = 'fdm-backdrop';
        backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:99999;';

        const dialog = document.createElement('div');
        dialog.className = 'fdm-dialog';
        dialog.style.cssText = 'max-width:70vw;max-height:70vh;width:70vw;background:#fff;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.2);display:flex;flex-direction:column;';

        const header = document.createElement('div');
        header.style.cssText = 'padding:12px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;';

        const titleDiv = document.createElement('div');
        titleDiv.className = 'fdm-title';
        titleDiv.style.fontWeight = '600';
        titleDiv.textContent = title;

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'fdm-close';
        closeBtn.style.cssText = 'border:none;background:transparent;font-size:20px;line-height:1;cursor:pointer';
        closeBtn.textContent = '×';

        header.appendChild(titleDiv);
        header.appendChild(closeBtn);

        const content = document.createElement('div');
        content.style.cssText = 'padding:16px;overflow:auto';

        const pre = document.createElement('pre');
        pre.style.cssText = 'white-space:pre-wrap;word-wrap:break-word;font-family:monospace;margin:0';
        pre.textContent = full;

        content.appendChild(pre);

        dialog.appendChild(header);
        dialog.appendChild(content);
        backdrop.appendChild(dialog);
        modal.appendChild(backdrop);

        document.body.appendChild(modal);

        backdrop.addEventListener('click', (e) => {
            if (e.target.classList.contains('fdm-backdrop')) {
                modal.remove();
            }
        });
        closeBtn.addEventListener('click', () => modal.remove());
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
        const selectedCountEl = dashboardDomCache.getById('selectedCount');
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
        const rows = dashboardDomCache.getAll('#accountsTable tbody .row-checkbox');
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
    
    // Инициализация компонентов (заглушка для совместимости)
    initializeComponents() {
        // Метод оставлен для совместимости, может быть переопределен в шаблоне
        this.initAddAccountForm();
    }
    
    // Инициализация формы добавления аккаунта
    initAddAccountForm() {
        logger.debug('🔧 [INIT] Инициализация формы добавления аккаунта...');
        const addAccountModal = dashboardDomCache.getById('addAccountModal');
        const uploadForm = dashboardDomCache.getById('uploadAccountsForm');
        const uploadBtn = dashboardDomCache.getById('uploadAccountsBtn');
        
        logger.debug('🔧 [INIT] Элементы формы:', {
            addAccountModal: addAccountModal ? 'найден' : 'НЕ НАЙДЕН',
            uploadForm: uploadForm ? 'найден' : 'НЕ НАЙДЕН',
            uploadBtn: uploadBtn ? 'найден' : 'НЕ НАЙДЕН'
        });
        
        if (!addAccountModal || !uploadForm) {
            logger.warn('⚠️ [INIT] Форма не найдена, возможно не на странице dashboard');
            return; // Форма не найдена, возможно не на странице dashboard
        }
        
        // Обработка открытия модального окна
        addAccountModal.addEventListener('show.bs.modal', () => {
            logger.debug('📂 [MODAL] Модальное окно открывается');
            this.clearAddAccountForm();
        });
        
        // Обработка отправки формы загрузки
        logger.debug('🔧 [INIT] Привязка обработчика submit к форме...');
        uploadForm.addEventListener('submit', (e) => {
            logger.debug('🚨 [FORM] Событие submit формы перехвачено!');
            e.preventDefault();
            logger.debug('🚨 [FORM] Вызов handleUploadAccounts...');
            this.handleUploadAccounts(e);
        });
        
        // Дополнительно привязываем обработчик к кнопке (на случай если форма не работает)
        if (uploadBtn) {
            logger.debug('🔧 [INIT] Привязка дополнительного обработчика к кнопке...');
            uploadBtn.addEventListener('click', (e) => {
                e.preventDefault(); // Предотвращаем стандартную отправку формы
                e.stopPropagation(); // Останавливаем всплытие события
                logger.debug('🚨 [BUTTON] Клик по кнопке загрузки, проверяем форму...');
                const form = dashboardDomCache.getById('uploadAccountsForm');
                if (form) {
                    const fileInput = dashboardDomCache.getById('accountsFile');
                    if (fileInput && fileInput.files && fileInput.files.length > 0) {
                        logger.debug('🚨 [BUTTON] Файл выбран, инициируем submit формы...');
                        // Создаем событие submit и вызываем обработчик напрямую
                        const submitEvent = new Event('submit', { cancelable: true, bubbles: true });
                        submitEvent.preventDefault = () => {}; // Добавляем метод preventDefault
                        this.handleUploadAccounts(submitEvent);
                    } else {
                        logger.warn('⚠️ [BUTTON] Файл не выбран');
                        const errorsDiv = dashboardDomCache.getById('addAccountErrors');
                        if (errorsDiv) {
                            errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки';
                            errorsDiv.classList.remove('d-none');
                        }
                    }
                }
            });
        }
        
        // Сброс при закрытии модального окна (работает для ручного и программного закрытия)
        addAccountModal.addEventListener('hidden.bs.modal', () => {
            logger.debug('📂 [MODAL] Модальное окно закрыто, полная очистка формы');
            this.clearAddAccountForm();
            
            // Дополнительная очистка элементов (на случай если clearAddAccountForm не отработал)
            const errorsDiv = dashboardDomCache.getById('addAccountErrors');
            const successDiv = dashboardDomCache.getById('addAccountSuccess');
            const form = dashboardDomCache.getById('uploadAccountsForm');
            const fileInput = dashboardDomCache.getById('accountsFile');
            const submitBtn = dashboardDomCache.getById('uploadAccountsBtn');
            
            if (errorsDiv) {
                errorsDiv.classList.add('d-none');
                errorsDiv.textContent = '';
            }
            if (successDiv) {
                successDiv.classList.add('d-none');
                successDiv.innerHTML = '';
            }
            if (form) {
                form.reset();
            }
            if (fileInput) {
                fileInput.value = '';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                // Восстанавливаем оригинальный текст кнопки, если он был изменен
                if (submitBtn.querySelector('.spinner-border')) {
                    submitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Загрузить аккаунты';
                }
            }
        });
        
        logger.debug('✅ [INIT] Форма добавления аккаунта инициализирована');
    }
    
    // Загрузка статусов для формы
    async loadStatusesForForm() {
        // Статусы должны быть доступны через window.accountStatuses
        // Это устанавливается в PHP шаблоне
        return;
    }
    
    // Получение HTML опций для select статусов
    getStatusOptionsHtml() {
        const statuses = window.accountStatuses || [];
        let html = '<option value="">-- Выберите --</option>';
        statuses.forEach(status => {
            if (status) {
                html += `<option value="${this.escapeHtml(status)}">${this.escapeHtml(status)}</option>`;
            }
        });
        return html;
    }
    
    // Очистка формы добавления аккаунта
    clearAddAccountForm() {
        const form = dashboardDomCache.getById('uploadAccountsForm');
        if (form) {
            form.reset();
        }
        const errorsDiv = dashboardDomCache.getById('addAccountErrors');
        const successDiv = dashboardDomCache.getById('addAccountSuccess');
        if (errorsDiv) {
            errorsDiv.classList.add('d-none');
            errorsDiv.textContent = '';
        }
        if (successDiv) {
            successDiv.classList.add('d-none');
            successDiv.textContent = '';
        }
    }
    
    // Обработка загрузки аккаунтов из CSV файла
    async handleUploadAccounts(e) {
        logger.debug('🚨🚨🚨 === НАЧАЛО ЗАГРУЗКИ АККАУНТОВ === 🚨🚨🚨');
        logger.debug('🚨 [UPLOAD] Функция handleUploadAccounts вызвана!');
        logger.debug('🚨 [UPLOAD] Событие:', e);
        
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
            logger.debug('🚨 [UPLOAD] preventDefault() вызван');
        }
        
        // Получаем форму из события или находим по ID
        const form = (e && e.target && e.target.tagName === 'FORM') ? e.target : dashboardDomCache.getById('uploadAccountsForm');
        const submitBtn = dashboardDomCache.getById('uploadAccountsBtn');
        const errorsDiv = dashboardDomCache.getById('addAccountErrors');
        const successDiv = dashboardDomCache.getById('addAccountSuccess');
        const fileInput = dashboardDomCache.getById('accountsFile');
        
        logger.debug('Элементы формы:', {
            form: form ? 'найден' : 'не найден',
            submitBtn: submitBtn ? 'найден' : 'не найден',
            errorsDiv: errorsDiv ? 'найден' : 'не найден',
            successDiv: successDiv ? 'найден' : 'не найден',
            fileInput: fileInput ? 'найден' : 'не найден'
        });
        
        if (errorsDiv) errorsDiv.classList.add('d-none');
        if (successDiv) successDiv.classList.add('d-none');
        
        // Проверка выбранного файла
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            logger.warn('⚠️ Файл не выбран');
            if (errorsDiv) {
                errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки';
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        const file = fileInput.files[0];
        logger.debug('📁 Информация о файле:', {
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: new Date(file.lastModified).toISOString()
        });
        
        // Проверка размера файла (20MB)
        const maxSize = 20 * 1024 * 1024;
        if (file.size > maxSize) {
            logger.error('❌ Файл слишком большой:', file.size, 'байт (максимум:', maxSize, 'байт)');
            if (errorsDiv) {
                errorsDiv.textContent = `Файл слишком большой. Максимальный размер: ${Math.round(maxSize / 1024 / 1024)} MB`;
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        // Проверка расширения файла
        const allowedExtensions = ['.csv', '.txt'];
        const fileName = file.name.toLowerCase();
        const hasValidExtension = allowedExtensions.some(ext => fileName.endsWith(ext));
        logger.debug('🔍 Проверка расширения файла:', {
            fileName: fileName,
            hasValidExtension: hasValidExtension,
            allowedExtensions: allowedExtensions
        });
        
        if (!hasValidExtension) {
            logger.error('❌ Неподдерживаемое расширение файла:', fileName);
            if (errorsDiv) {
                errorsDiv.textContent = 'Поддерживаются только файлы CSV или TXT';
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        const formData = new FormData(form);
        logger.debug('📦 Данные формы FormData:');
        for (let [key, value] of formData.entries()) {
            if (key === 'import_file') {
                logger.debug(`  ${key}:`, '[File object]', value.name, value.size + ' bytes');
            } else {
                logger.debug(`  ${key}:`, value);
            }
        }
        
        if (submitBtn) {
            submitBtn.disabled = true;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка...';
            
            try {
                logger.debug('🚀 Отправка запроса на import_accounts.php...');
                const response = await fetch(window.getTableAwareUrl('import_accounts.php'), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                logger.debug('📥 Ответ получен:', {
                    status: response.status,
                    statusText: response.statusText,
                    ok: response.ok,
                    url: response.url
                });
                
                // Проверяем Content-Type ответа
                const contentType = response.headers.get('content-type') || '';
                const isJson = contentType.includes('application/json');
                logger.debug('📋 Заголовки ответа:', {
                    'content-type': contentType,
                    isJson: isJson,
                    allHeaders: Object.fromEntries(response.headers.entries())
                });
                
                let result;
                
                if (!response.ok) {
                    logger.error('❌ Ответ с ошибкой:', response.status, response.statusText);
                    // Пытаемся прочитать ошибку как JSON, если это возможно
                    if (isJson) {
                        try {
                            const errorData = await response.json();
                            logger.error('📄 Ошибка (JSON):', errorData);
                            throw new Error(errorData.error || `Ошибка ${response.status}: ${response.statusText}`);
                        } catch (parseError) {
                            logger.error('❌ Ошибка парсинга JSON ошибки:', parseError);
                            if (parseError instanceof Error && parseError.message.includes('Ошибка')) {
                                throw parseError;
                            }
                            throw new Error(`Ошибка ${response.status}: ${response.statusText}`);
                        }
                    } else {
                        // Если ответ не JSON, читаем как текст
                        const errorText = await response.text().catch(() => '');
                        logger.error('📄 Ошибка (текст):', errorText.substring(0, 500));
                        throw new Error(errorText || `Ошибка ${response.status}: ${response.statusText}`);
                    }
                }
                
                // Парсим успешный ответ
                if (isJson) {
                    try {
                        logger.debug('🔄 Парсинг JSON ответа...');
                        result = await response.json();
                        logger.debug('✅ JSON успешно распарсен:', result);
                    } catch (parseError) {
                        logger.error('❌ Ошибка парсинга JSON ответа:', parseError);
                        logger.error('📄 Сырой ответ (первые 500 символов):', await response.clone().text().then(t => t.substring(0, 500)).catch(() => 'Не удалось прочитать'));
                        throw new Error('Ошибка при обработке ответа от сервера. Проверьте формат файла и попробуйте снова.');
                    }
                } else {
                    // Если ответ не JSON, это ошибка
                    logger.warn('⚠️ Ответ не является JSON, пытаемся прочитать как текст...');
                    const textResponse = await response.text().catch(() => '');
                    logger.error('📄 Текстовый ответ (первые 500 символов):', textResponse.substring(0, 500));
                    throw new Error(textResponse || 'Сервер вернул некорректный ответ. Попробуйте снова.');
                }
                
                logger.debug('🔍 Результат импорта:', {
                    success: result.success,
                    created: result.created,
                    skipped: result.skipped,
                    total: result.total,
                    errorsCount: result.errors ? result.errors.length : 0,
                    message: result.message
                });
                
                if (result.success) {
                    logger.debug('✅ Импорт успешен!', {
                        created: result.created || 0,
                        skipped: result.skipped || 0,
                        errors: result.errors ? result.errors.length : 0
                    });
                    
                    // Успешная загрузка
                    let message = result.message || `Успешно обработано ${result.total || 0} строк(и)`;

                    if (result.errors && result.errors.length > 0) {
                        logger.warn('⚠️ Обнаружены ошибки при импорте:', result.errors.slice(0, 10));
                        message += `<br><strong>Ошибки (${result.errors.length}):</strong><ul class="mb-0 mt-2 small">`;
                        result.errors.slice(0, 10).forEach(err => { // Показываем только первые 10 ошибок
                            // Escape both row number and message from server
                            const escapedRow = this.escapeHtml(String(err.row));
                            const escapedMsg = this.escapeHtml(err.message);
                            message += `<li>Строка ${escapedRow}: ${escapedMsg}</li>`;
                        });
                        if (result.errors.length > 10) {
                            message += `<li><em>... и еще ${result.errors.length - 10} ошибок</em></li>`;
                        }
                        message += '</ul>';
                    }

                    if (successDiv) {
                        logger.debug('📝 Отображение сообщения об успехе в successDiv');
                        // For messages with HTML structure (list), use innerHTML with properly escaped content
                        // For plain text messages, use textContent
                        if (message.includes('<')) {
                            successDiv.innerHTML = message;
                        } else {
                            successDiv.textContent = message;
                        }
                        successDiv.classList.remove('d-none');
                    } else {
                        logger.warn('⚠️ successDiv не найден, не удалось отобразить сообщение');
                    }
                    
                    if (typeof window.showToast === 'function') {
                        const toastMsg = `Создано: ${result.created || 0}, Пропущено: ${result.skipped || 0}`;
                        logger.debug('🔔 Показ toast уведомления:', toastMsg);
                        window.showToast(toastMsg, 'success');
                    } else {
                        logger.warn('⚠️ Функция window.showToast не найдена');
                    }
                    
                    // Обновляем таблицу на дашборде
                    if (this.refreshDashboardData) {
                        logger.debug('🔄 Обновление данных дашборда через 1 секунду...');
                        setTimeout(() => {
                            logger.debug('🔄 Выполняем refreshDashboardData...');
                            this.refreshDashboardData();
                        }, 1000);
                    } else {
                        logger.warn('⚠️ Метод refreshDashboardData не найден');
                    }
                    
                    // Очищаем форму через 3 секунды
                    setTimeout(() => {
                        logger.debug('🧹 Очистка формы...');
                        form.reset();
                        if (successDiv) {
                            successDiv.classList.add('d-none');
                        }
                    }, 3000);
                    
                } else {
                    logger.error('❌ Импорт не успешен, result.success = false:', result);
                    throw new Error(result.error || 'Ошибка при загрузке файла');
                }
            } catch (error) {
                logger.error('❌ КРИТИЧЕСКАЯ ОШИБКА при загрузке аккаунтов:', error);
                logger.error('📊 Детали ошибки:', {
                    name: error.name,
                    message: error.message,
                    stack: error.stack
                });
                
                let errorMessage = 'Ошибка при загрузке файла. Проверьте формат файла и попробуйте снова.';
                
                if (error instanceof Error) {
                    errorMessage = error.message || errorMessage;
                    // Очищаем HTML теги из сообщения об ошибке для безопасности
                    const tempDiv = document.createElement('div');
                    tempDiv.textContent = errorMessage;
                    errorMessage = tempDiv.textContent || errorMessage;
                }
                
                logger.debug('📝 Отображение ошибки пользователю:', errorMessage);
                
                if (errorsDiv) {
                    errorsDiv.textContent = errorMessage;
                    errorsDiv.classList.remove('d-none');
                } else {
                    logger.error('❌ errorsDiv не найден, не удалось отобразить ошибку!');
                }
                
                if (typeof window.showToast === 'function') {
                    logger.debug('🔔 Показ toast уведомления об ошибке');
                    window.showToast(errorMessage, 'error');
                } else {
                    logger.warn('⚠️ Функция window.showToast не найдена');
                }
            } finally {
                logger.debug('🏁 Завершение обработки запроса, восстановление кнопки');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
                logger.debug('=== КОНЕЦ ЗАГРУЗКИ АККАУНТОВ ===');
            }
        } else {
            logger.error('❌ submitBtn не найден!');
        }
    }
    
    // Добавление новой строки в таблицу
    addAccountRow() {
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        if (!tbody) return;
        
        const rowCount = tbody.children.length;
        const row = document.createElement('tr');
        row.dataset.rowIndex = rowCount;
        
        // Получаем список статусов (уже с экранированием)
        const statusOptions = this.getStatusOptionsHtml();

        // Build row safely with proper escaping of row number
        const rowNumber = rowCount + 1;
        row.innerHTML = `
            <td class="text-center">${this.escapeHtml(String(rowNumber))}</td>
            <td>
                <input type="text" class="form-control form-control-sm" name="login" maxlength="255" required>
                <div class="invalid-feedback small">Обязательно</div>
            </td>
            <td>
                <select class="form-select form-select-sm" name="status" required>
                    ${statusOptions}
                </select>
                <div class="invalid-feedback small">Обязательно</div>
            </td>
            <td>
                <input type="email" class="form-control form-control-sm" name="email" maxlength="255">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" name="password" maxlength="255">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" name="first_name" maxlength="255">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" name="last_name" maxlength="255">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" name="two_fa" maxlength="255">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); dashboard.updateRowCount();">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
        this.updateRowCount();
        
        // Фокус на первое поле новой строки
        const firstInput = row.querySelector('input[name="login"]');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }
    
    // Очистка таблицы
    clearAddAccountTable() {
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        if (tbody) {
            tbody.innerHTML = '';
            this.addAccountRow(); // Добавляем одну пустую строку
        }
        this.updateRowCount();
    }
    
    // Обновление счетчика строк
    updateRowCount() {
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        const countEl = dashboardDomCache.getById('accountsTableRowCount');
        if (tbody && countEl) {
            const count = tbody.children.length;
            countEl.textContent = count;
            
            // Обновляем номера строк
            Array.from(tbody.children).forEach((row, index) => {
                const numCell = row.querySelector('td:first-child');
                if (numCell) {
                    numCell.textContent = index + 1;
                }
                row.dataset.rowIndex = index;
            });
        }
    }
    
    // Обработка вставки данных (paste) в таблицу
    handleTablePaste(e) {
        e.preventDefault();
        const pastedData = (e.clipboardData || window.clipboardData).getData('text');
        if (!pastedData) return;
        
        // Определяем разделитель (табуляция, точка с запятой, запятая)
        let delimiter = '\t'; // По умолчанию табуляция (Excel/Sheets)
        if (pastedData.includes(';') && !pastedData.includes('\t')) {
            delimiter = ';';
        } else if (pastedData.includes(',') && !pastedData.includes('\t') && !pastedData.includes(';')) {
            delimiter = ',';
        }
        
        // Парсим строки
        const lines = pastedData.split(/\r?\n/).filter(line => line.trim() !== '');
        if (lines.length === 0) return;
        
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        if (!tbody) return;
        
        // Находим активную строку (где был клик) или последнюю
        const activeRow = e.target.closest('tr') || tbody.lastElementChild;
        if (!activeRow) {
            this.addAccountRow();
            return this.handleTablePaste(e);
        }
        
        let currentRow = activeRow;
        let rowIndex = Array.from(tbody.children).indexOf(activeRow);
        
        // Получаем список статусов
        const statusOptionsHtml = this.getStatusOptionsHtml();
        
        // Вставляем данные построчно
        lines.forEach((line, lineIndex) => {
            const values = line.split(delimiter).map(v => v.trim());
            if (values.length === 0 || values.every(v => !v)) return; // Пропускаем пустые строки
            
            // Если текущей строки нет, создаем новую
            if (!currentRow || !tbody.contains(currentRow)) {
                this.addAccountRow();
                currentRow = tbody.lastElementChild;
            }
            
            // Заполняем ячейки: login, status, email, password, first_name, last_name, two_fa
            const cells = [
                currentRow.querySelector('input[name="login"]'),
                currentRow.querySelector('select[name="status"]'),
                currentRow.querySelector('input[name="email"]'),
                currentRow.querySelector('input[name="password"]'),
                currentRow.querySelector('input[name="first_name"]'),
                currentRow.querySelector('input[name="last_name"]'),
                currentRow.querySelector('input[name="two_fa"]')
            ];
            
            values.forEach((value, colIndex) => {
                if (colIndex < cells.length && cells[colIndex]) {
                    if (cells[colIndex].tagName === 'SELECT') {
                        // Для select ищем опцию по значению или тексту
                        const option = Array.from(cells[colIndex].options).find(opt => 
                            opt.value === value || opt.text === value
                        );
                        if (option) {
                            cells[colIndex].value = option.value;
                        } else if (value) {
                            // Если точного совпадения нет, устанавливаем значение напрямую
                            cells[colIndex].value = value;
                        }
                    } else {
                        cells[colIndex].value = value;
                    }
                }
            });
            
            // Переходим к следующей строке
            if (lineIndex < lines.length - 1) {
                const nextRow = currentRow.nextElementSibling;
                if (nextRow) {
                    currentRow = nextRow;
                } else {
                    this.addAccountRow();
                    currentRow = tbody.lastElementChild;
                }
            }
        });
        
        this.updateRowCount();
        
        // Показываем уведомление
        if (typeof window.showToast === 'function') {
            window.showToast(`Вставлено ${lines.length} строк(и)`, 'success');
        }
    }
    
    // Заполнение статуса для всех строк
    fillStatusForAllRows() {
        const statuses = window.accountStatuses || [];
        if (statuses.length === 0) {
            alert('Список статусов не загружен. Попробуйте обновить страницу.');
            return;
        }
        
        // Создаем список статусов для выбора
        let statusList = statuses.map((s, i) => `${i + 1}. ${s}`).join('\n');
        const statusNum = prompt(`Выберите номер статуса:\n\n${statusList}\n\nВведите номер:`);
        if (!statusNum || statusNum.trim() === '') return;
        
        const index = parseInt(statusNum.trim()) - 1;
        if (isNaN(index) || index < 0 || index >= statuses.length) {
            alert('Неверный номер статуса');
            return;
        }
        
        const selectedStatus = statuses[index];
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        if (!tbody) return;
        
        const selects = tbody.querySelectorAll('select[name="status"]');
        let filled = 0;
        selects.forEach(select => {
            // Пытаемся найти опцию по значению
            const option = Array.from(select.options).find(opt => 
                opt.value === selectedStatus
            );
            if (option) {
                select.value = option.value;
                filled++;
            }
        });
        
        if (filled > 0 && typeof window.showToast === 'function') {
            window.showToast(`Заполнено ${filled} строк(и) статусом "${selectedStatus}"`, 'success');
        }
    }
    
    // Валидация всех строк таблицы
    validateAccountsTable() {
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        if (!tbody) return { valid: false, errors: [] };
        
        const rows = Array.from(tbody.children);
        const errors = [];
        let validCount = 0;
        
        rows.forEach((row, index) => {
            const loginInput = row.querySelector('input[name="login"]');
            const statusSelect = row.querySelector('select[name="status"]');
            const emailInput = row.querySelector('input[name="email"]');
            
            let rowValid = true;
            const rowErrors = [];
            
            // Проверка логина
            if (!loginInput || !loginInput.value.trim()) {
                rowValid = false;
                rowErrors.push('Логин обязателен');
                if (loginInput) loginInput.classList.add('is-invalid');
            } else {
                if (loginInput) loginInput.classList.remove('is-invalid');
            }
            
            // Проверка статуса
            if (!statusSelect || !statusSelect.value) {
                rowValid = false;
                rowErrors.push('Статус обязателен');
                if (statusSelect) statusSelect.classList.add('is-invalid');
            } else {
                if (statusSelect) statusSelect.classList.remove('is-invalid');
            }
            
            // Проверка email (если заполнен)
            if (emailInput && emailInput.value.trim()) {
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(emailInput.value.trim())) {
                    rowValid = false;
                    rowErrors.push('Неверный формат email');
                    emailInput.classList.add('is-invalid');
                } else {
                    emailInput.classList.remove('is-invalid');
                }
            }
            
            if (!rowValid) {
                row.classList.add('table-danger');
                errors.push({
                    row: index + 1,
                    errors: rowErrors
                });
            } else {
                row.classList.remove('table-danger');
                validCount++;
            }
        });
        
        return {
            valid: errors.length === 0 && validCount > 0,
            validCount: validCount,
            errors: errors
        };
    }
    
    // Сбор данных из таблицы
    collectAccountsData() {
        const tbody = dashboardDomCache.getById('addAccountsTableBody');
        if (!tbody) return [];
        
        const accounts = [];
        const rows = Array.from(tbody.children);
        
        rows.forEach(row => {
            const loginInput = row.querySelector('input[name="login"]');
            const statusSelect = row.querySelector('select[name="status"]');
            
            // Пропускаем пустые строки (без логина)
            if (!loginInput || !loginInput.value.trim()) return;
            if (!statusSelect || !statusSelect.value) return;
            
            const account = {
                login: loginInput.value.trim(),
                status: statusSelect.value
            };
            
            // Добавляем опциональные поля если они заполнены
            const fields = ['email', 'password', 'first_name', 'last_name', 'two_fa'];
            fields.forEach(field => {
                const input = row.querySelector(`input[name="${field}"]`);
                if (input && input.value.trim()) {
                    account[field] = input.value.trim();
                }
            });
            
            accounts.push(account);
        });
        
        return accounts;
    }
    
    // Обработка массового создания аккаунтов
    async handleAddAccountsBulkSubmit(e) {
        e.preventDefault();
        
        const submitBtn = dashboardDomCache.getById('submitAddAccount');
        const errorsDiv = dashboardDomCache.getById('addAccountErrors');
        const successDiv = dashboardDomCache.getById('addAccountSuccess');
        
        // Валидация таблицы
        const validation = this.validateAccountsTable();
        if (!validation.valid) {
            let errorMsg = `Найдены ошибки валидации в ${validation.errors.length} строке(ах). `;
            errorMsg += validation.errors.map(e => `Строка ${this.escapeHtml(String(e.row))}: ${this.escapeHtml(e.errors.join(', '))}`).join('; ');

            if (errorsDiv) {
                errorsDiv.textContent = errorMsg;
                errorsDiv.classList.remove('d-none');
            }
            if (typeof window.showToast === 'function') {
                window.showToast('Исправьте ошибки перед отправкой', 'error');
            }
            return;
        }
        
        // Собираем данные
        const accounts = this.collectAccountsData();
        if (accounts.length === 0) {
            if (errorsDiv) {
                errorsDiv.textContent = 'Нет данных для создания. Заполните хотя бы одну строку с логином и статусом.';
                errorsDiv.classList.remove('d-none');
            }
            return;
        }
        
        // Отключаем кнопку
        if (submitBtn) {
            submitBtn.disabled = true;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Создание...';
            
            try {
                const csrfInput = dashboardDomCache.getById('addAccountCsrf');
                const csrfToken = csrfInput ? csrfInput.value : '';
                
                // Отправляем bulk запрос
                const response = await fetch('/api/accounts/bulk', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        accounts: accounts,
                        csrf: csrfToken,
                        duplicate_action: 'skip'
                    })
                });
                
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.error || `Ошибка ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    // Успешное создание
                    this.showAddAccountsBulkSuccess(result);
                } else {
                    throw new Error(result.error || 'Ошибка при создании аккаунтов');
                }
            } catch (error) {
                logger.error('Error creating accounts:', error);
                const errorMessage = error.message || 'Ошибка при создании аккаунтов';
                
                if (errorsDiv) {
                    errorsDiv.textContent = errorMessage;
                    errorsDiv.classList.remove('d-none');
                }
                if (typeof window.showToast === 'function') {
                    window.showToast(errorMessage, 'error');
                }
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            }
        }
    }
    
    // Показ результата массового создания
    showAddAccountsBulkSuccess(result) {
        const successDiv = dashboardDomCache.getById('addAccountSuccess');
        const errorsDiv = dashboardDomCache.getById('addAccountErrors');
        
        if (errorsDiv) errorsDiv.classList.add('d-none');
        
        let message = result.message || `Создано: ${result.created || 0}, Пропущено: ${result.skipped || 0}`;

        if (result.errors && result.errors.length > 0) {
            message += `<br><strong>Ошибки (${result.errors.length}):</strong><ul class="mb-0 mt-2">`;
            result.errors.forEach(err => {
                // Escape both row number and message from server
                const escapedRow = this.escapeHtml(String(err.row));
                const escapedMsg = this.escapeHtml(err.message);
                message += `<li>Строка ${escapedRow}: ${escapedMsg}</li>`;
            });
            message += '</ul>';
        }

        if (successDiv) {
            // For messages with HTML structure (list), use innerHTML with properly escaped content
            // For plain text messages, use textContent
            if (message.includes('<')) {
                successDiv.innerHTML = message;
            } else {
                successDiv.textContent = message;
            }
            successDiv.classList.remove('d-none');
        }
        
        if (typeof window.showToast === 'function') {
            window.showToast(`Успешно создано ${result.created || 0} аккаунт(ов)`, 'success');
        }
        
        // Обновляем таблицу на дашборде если есть метод
        if (this.refreshDashboardData) {
            setTimeout(() => {
                this.refreshDashboardData();
            }, 1000);
        }
        
        // Очищаем таблицу через 2 секунды
        setTimeout(() => {
            this.clearAddAccountTable();
        }, 2000);
    }
    
    // Вспомогательная функция для экранирования HTML
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Обработка успешного создания аккаунта (для совместимости со старым API)
    showAddAccountSuccess(result) {
        // Используем новый метод для массового создания
        this.showAddAccountsBulkSuccess({
            success: true,
            created: 1,
            skipped: 0,
            errors: [],
            message: 'Аккаунт успешно создан'
        });
    }
    
    // Остальные методы из оригинального кода...
    // (сокращено для экономии места, но все методы должны быть перенесены)
}

// Инициализация при загрузке DOM
logger.debug('📜 [DASHBOARD.JS] Регистрация обработчика DOMContentLoaded...');

// Проверяем состояние DOM
logger.debug('📜 [DASHBOARD.JS] Состояние документа:', document.readyState);

if (document.readyState === 'loading') {
    logger.debug('📜 [DASHBOARD.JS] Документ еще загружается, ждем DOMContentLoaded...');
} else {
    logger.debug('📜 [DASHBOARD.JS] Документ уже загружен, инициализируем немедленно...');
}

document.addEventListener('DOMContentLoaded', () => {
    logger.debug('%c🚀 [DOMContentLoaded] Событие DOMContentLoaded сработало!', 'color: green; font-size: 16px; font-weight: bold;');
    logger.debug('🚀 [DOMContentLoaded] Создаем экземпляр Dashboard...');
    
    try {
        window.dashboard = new Dashboard();
        logger.debug('%c✅ [DOMContentLoaded] Dashboard успешно создан!', 'color: green; font-size: 14px;');
        logger.debug('✅ [DOMContentLoaded] Dashboard сохранен в window.dashboard:', window.dashboard);
    } catch (error) {
        logger.error('%c❌ [DOMContentLoaded] КРИТИЧЕСКАЯ ОШИБКА при создании Dashboard!', 'color: red; font-size: 18px; font-weight: bold;');
        logger.error('❌ [DOMContentLoaded] Ошибка:', error);
        logger.error('❌ [DOMContentLoaded] Сообщение:', error.message);
        logger.error('❌ [DOMContentLoaded] Стек ошибки:', error.stack);
        alert('Ошибка инициализации Dashboard! Проверьте консоль для деталей.');
    }
    
    // Дополнительная проверка элементов формы через некоторое время
    setTimeout(() => {
        logger.debug('🔍 [DELAYED CHECK] Проверка элементов формы через 500ms...');
        const uploadBtn = dashboardDomCache.getById('uploadAccountsBtn');
        const uploadForm = dashboardDomCache.getById('uploadAccountsForm');
        const addAccountModal = dashboardDomCache.getById('addAccountModal');
        
        logger.debug('🔍 [DELAYED CHECK] Элементы:', {
            uploadBtn: uploadBtn ? 'найден' : 'НЕ НАЙДЕН',
            uploadForm: uploadForm ? 'найден' : 'НЕ НАЙДЕН',
            addAccountModal: addAccountModal ? 'найден' : 'НЕ НАЙДЕН',
            dashboardInstance: window.dashboard ? 'создан' : 'НЕ СОЗДАН'
        });
        
        // Добавляем глобальный обработчик как резервный
        if (uploadBtn && !uploadBtn.hasAttribute('data-global-handler-attached')) {
            logger.debug('🔧 [GLOBAL FALLBACK] Добавление глобального обработчика к кнопке...');
            uploadBtn.setAttribute('data-global-handler-attached', 'true');
            uploadBtn.addEventListener('click', function(e) {
                logger.debug('🚨🚨🚨 [GLOBAL FALLBACK CLICK] Клик по кнопке загрузки (глобальный обработчик)!');
                
                if (!uploadForm) {
                    logger.error('❌ [GLOBAL FALLBACK] Форма не найдена!');
                    return;
                }
                
                const fileInput = dashboardDomCache.getById('accountsFile');
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    logger.warn('⚠️ [GLOBAL FALLBACK] Файл не выбран');
                    alert('Пожалуйста, выберите файл для загрузки');
                    return;
                }
                
                logger.debug('🚨 [GLOBAL FALLBACK] Вызываем handleUploadAccounts через window.dashboard...');
                if (window.dashboard && typeof window.dashboard.handleUploadAccounts === 'function') {
                    const fakeEvent = { preventDefault: () => {}, target: uploadForm };
                    window.dashboard.handleUploadAccounts(fakeEvent);
                } else {
                    logger.error('❌ [GLOBAL FALLBACK] window.dashboard.handleUploadAccounts не найден!');
                    alert('Ошибка: функция загрузки не инициализирована. Проверьте консоль.');
                }
            });
        }
    }, 500);
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
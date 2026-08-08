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
    // ─────────────────────────────────────────────────────────────────────────
    // Здесь заканчивается живой код файла.
    //
    // Ниже до 2026-08-08 лежали 1 760 строк: класс Dashboard со своим рендерером
    // строк таблицы, собственными escapeHtml/copyToClipboard/showToast и
    // регистрацией DOMContentLoaded. Всё это было НЕДОСТИЖИМО, и вот почему:
    //
    //   templates/dashboard.php подключает init-script.php (строка 1226), то есть
    //   dashboard-init.js, а сам dashboard.js — только строкой 1249. К моменту
    //   выполнения этого файла dashboard-init.js уже выставил
    //   window.__INLINE_DASHBOARD_ACTIVE__ = true, поэтому охранник выше делал
    //   return, и объявления ниже никогда не вычислялись.
    //
    //   dashboard.js подключает ровно одна страница — templates/dashboard.php,
    //   и она всегда грузит init-скрипт первым. То есть недостижимо везде.
    //
    // Проверено вживую: в области страницы binding Dashboard отсутствует,
    // window.dashboard содержит DashboardMain, а строки таблицы после AJAX
    // рисует table-module.js (50 вызовов renderRow на 50 строк).
    //
    // Удалено, а не оставлено «на всякий случай», по трём причинам: это был
    // третий рендерер таблицы, из-за которого любая правка разметки делалась в
    // трёх местах; он объявлял window.showToast и window.copyToClipboard, и при
    // случайном изменении охранника перезаписал бы настоящие реализации из
    // dashboard-init.js; и он создавал ложное впечатление, что здесь есть логика.
    // Восстанавливается из истории git: git log --all -- assets/js/dashboard.js
    // ─────────────────────────────────────────────────────────────────────────
})();

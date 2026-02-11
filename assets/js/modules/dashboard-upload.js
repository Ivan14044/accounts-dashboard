/**
 * Модуль загрузки аккаунтов (импорт CSV/TXT)
 * Включает клиентскую валидацию CSV перед отправкой на сервер
 * Экспортирует handleUploadAccountsGlobal и привязывает обработчики к форме
 */
(function() {
  const cache = (typeof domCache !== 'undefined' && domCache.getById)
    ? domCache
    : {
        getById: (id) => document.getElementById(id),
        get: (sel) => document.querySelector(sel)
      };

  const log = (typeof logger !== 'undefined' && logger) ? logger : { 
    debug: function() { console.log('[DEBUG]', ...arguments); }, 
    warn: function() { console.warn('[WARN]', ...arguments); }, 
    error: function() { console.error('[ERROR]', ...arguments); },
    info: function() { console.info('[INFO]', ...arguments); }
  };
  
  // Конфигурация (загружается с сервера или используется fallback)
  let config = {
    MAX_IMPORT_FILE_SIZE: 20 * 1024 * 1024,
    MAX_IMPORT_ROWS: 10000
  };
  
  /**
   * Загружает конфигурацию с сервера
   */
  async function loadConfig() {
    try {
      const response = await fetch('api/config.php');
      if (response.ok) {
        const data = await response.json();
        if (data.success && data.config) {
          config = { ...config, ...data.config };
          log.debug('[CONFIG] Конфигурация загружена с сервера:', config);
        }
      }
    } catch (err) {
      log.warn('[CONFIG] Не удалось загрузить конфигурацию, используется fallback:', err);
    }
  }
  
  // Загружаем конфигурацию при инициализации модуля
  loadConfig();
  
  /**
   * Валидация CSV файла на клиенте перед отправкой
   * Проверяет заголовки, обязательные поля и первые строки
   * 
   * @param {File} file - CSV файл для валидации
   * @returns {Promise<{valid: boolean, errors: string[], warnings: string[], preview: object}>}
   */
  async function validateCsvFile(file) {
    return new Promise((resolve, reject) => {
      // Проверка размера файла перед чтением
      const maxValidationSize = 10 * 1024 * 1024; // 10 MB для валидации (увеличено с 5 MB)
      const fileSize = file.size;
      
      if (fileSize > maxValidationSize) {
        log.warn('[CSV VALIDATION] Файл слишком большой для полной валидации, проверяем только начало');
        // Для больших файлов читаем только первые 5 МБ
        const blob = file.slice(0, maxValidationSize);
        const reader = new FileReader();
        
        reader.onload = (e) => {
          try {
            const text = e.target.result;
            const result = parseAndValidate(text, true, fileSize);
            resolve(result);
          } catch (parseError) {
            log.error('[CSV VALIDATION] Ошибка парсинга:', parseError);
            reject(new Error('Ошибка чтения файла: ' + parseError.message));
          }
        };
        
        reader.onerror = () => {
          reject(new Error('Ошибка чтения файла'));
        };
        
        reader.readAsText(blob, 'UTF-8');
        return;
      }
      
      // Для обычных файлов читаем полностью
      const reader = new FileReader();
      
      reader.onload = (e) => {
        try {
          const text = e.target.result;
          const result = parseAndValidate(text, false, fileSize);
          resolve(result);
        } catch (parseError) {
          log.error('[CSV VALIDATION] Ошибка парсинга:', parseError);
          reject(new Error('Ошибка чтения файла: ' + parseError.message));
        }
      };
      
      reader.onerror = () => {
        reject(new Error('Ошибка чтения файла'));
      };
      
      reader.readAsText(file, 'UTF-8');
    });
  }
  
  /**
   * Внутренняя функция для парсинга и валидации CSV текста
   * 
   * @param {string} text - Текст CSV файла
   * @param {boolean} isPartial - Является ли текст частью большого файла
   * @param {number} fileSize - Размер оригинального файла
   * @returns {{valid: boolean, errors: string[], warnings: string[], preview: object}}
   */
  function parseAndValidate(text, isPartial, fileSize) {
    const errors = [];
    const warnings = [];
    let validDataLines = [];
    
    log.debug('[parseAndValidate] Начало парсинга:', {
      textLength: text.length,
      isPartial,
      fileSize,
      fileSizeMB: Math.round(fileSize / 1024 / 1024 * 100) / 100
    });
    
    // Если файл был частично прочитан, добавляем предупреждение
    if (isPartial) {
      warnings.push(`Файл очень большой (${Math.round(fileSize / 1024 / 1024)} МБ). Валидация выполнена только для первых 10 МБ. Полная валидация будет выполнена на сервере.`);
    }
    
    const lines = text.split('\n');
          
          // Фильтруем пустые строки и комментарии
          const nonEmptyLines = lines.filter(line => {
            const trimmed = line.trim();
            return trimmed !== '' && !trimmed.startsWith('#');
          });
          
          if (nonEmptyLines.length < 2) {
            errors.push('Файл пустой или содержит только заголовки');
            resolve({ valid: false, errors, warnings, preview: null });
            return;
          }
          
          // Определяем разделитель
          const delimiter = text.includes(';') ? ';' : ',';
          
          // Парсим заголовки
          const headerLine = nonEmptyLines[0];
          log.debug('[CSV VALIDATION] Исходная строка заголовков:', {
            length: headerLine.length,
            preview: headerLine.substring(0, 200)
          });
          
          const headers = headerLine.split(delimiter).map(h => {
            // Убираем звёздочки, BOM, непечатаемые символы и приводим к нижнему регистру
            return h.trim()
                    .replace(/\uFEFF/g, '') // BOM
                    .replace(/\*/g, '') // Звёздочки (все)
                    .replace(/[^\x20-\x7E\u0400-\u04FF]/g, '') // Непечатаемые символы
                    .toLowerCase();
          });
          
          log.debug('[CSV VALIDATION] Заголовки после нормализации:', headers);
          log.debug('[CSV VALIDATION] Проверяем наличие обязательных полей:', {
            hasLogin: headers.includes('login'),
            hasStatus: headers.includes('status'),
            loginIndex: headers.indexOf('login'),
            statusIndex: headers.indexOf('status')
          });
          
          // Проверяем обязательные поля
          const requiredFields = ['login', 'status'];
          const missingFields = requiredFields.filter(field => !headers.includes(field));
          
          if (missingFields.length > 0) {
            errors.push(`В файле отсутствуют обязательные поля: ${missingFields.join(', ')}`);
            log.warn('[CSV VALIDATION] Отсутствующие поля:', missingFields, 'Найденные заголовки:', headers);
          }
          
          // Находим индексы обязательных полей
          const loginIdx = headers.indexOf('login');
          const statusIdx = headers.indexOf('status');
          
          // Проверяем первые 10 строк данных (или все, если меньше)
          const maxLinesToCheck = Math.min(11, nonEmptyLines.length);
          const rowErrors = [];
          
          for (let i = 1; i < maxLinesToCheck; i++) {
            const line = nonEmptyLines[i];
            const values = line.split(delimiter);
            
            const rowNum = i + 1;
            const rowData = {
              rowNum,
              login: values[loginIdx]?.trim() || '',
              status: values[statusIdx]?.trim() || '',
              valid: true,
              errors: []
            };
            
            // Проверка login
            if (!rowData.login) {
              rowData.valid = false;
              rowData.errors.push('отсутствует login');
              rowErrors.push(`Строка ${rowNum}: отсутствует login`);
            }
            
            // Проверка status
            if (!rowData.status) {
              rowData.valid = false;
              rowData.errors.push('отсутствует status');
              rowErrors.push(`Строка ${rowNum}: отсутствует status`);
            }
            // Любое непустое значение status принимается без валидации
            
            // Проверка количества колонок
            if (values.length !== headers.length) {
              rowData.valid = false;
              rowData.errors.push(`неверное количество колонок (${values.length} вместо ${headers.length})`);
              warnings.push(`Строка ${rowNum}: количество колонок не совпадает`);
            }
            
            validDataLines.push(rowData);
          }
          
          // Добавляем ошибки строк в общий список
          if (rowErrors.length > 0) {
            errors.push(...rowErrors.slice(0, 5)); // Показываем только первые 5 ошибок
            if (rowErrors.length > 5) {
              warnings.push(`... и ещё ${rowErrors.length - 5} ошибок в других строках`);
            }
          }
          
          // Предупреждения
          const totalDataLines = nonEmptyLines.length - 1;
          const maxRows = config.MAX_IMPORT_ROWS || 10000;
          
          if (totalDataLines > 1000) {
            warnings.push(`Файл содержит ${totalDataLines} строк. Импорт может занять несколько минут.`);
          }
          
          if (totalDataLines > maxRows) {
            errors.push(`Файл содержит ${totalDataLines} строк, что превышает максимум (${maxRows}). Файл будет обрезан.`);
          }
          
          const result = {
            valid: errors.length === 0,
            errors,
            warnings,
            preview: {
              headers,
              rows: validDataLines,
              totalRows: isPartial ? '~' + totalDataLines + '+' : totalDataLines,
              delimiter,
              isPartial
            }
          };
          
          log.debug('[CSV VALIDATION] Результат валидации:', result);
          return result;
  }
  
  /**
   * Показывает предпросмотр CSV в модальном окне
   * 
   * @param {object} preview - Данные предпросмотра из validateCsvFile
   */
  function showCsvPreview(preview) {
    const previewContainer = cache.getById('csvPreviewContainer');
    if (!previewContainer) {
      log.warn('[CSV PREVIEW] Контейнер предпросмотра не найден');
      return;
    }
    
    const { headers, rows, totalRows } = preview;
    
    // Создаём таблицу предпросмотра
    let html = '<div class="csv-preview">';
    html += '<h6 class="mb-3"><i class="fas fa-eye me-2"></i>Предпросмотр (первые 10 строк из ' + totalRows + '):</h6>';
    html += '<div class="table-responsive">';
    html += '<table class="table table-sm table-bordered">';
    
    // Заголовки
    html += '<thead class="table-light"><tr>';
    headers.forEach(h => {
      const isRequired = h === 'login' || h === 'status';
      html += '<th>' + h + (isRequired ? '<span class="text-danger">*</span>' : '') + '</th>';
    });
    html += '</tr></thead>';
    
    // Данные
    html += '<tbody>';
    rows.forEach(row => {
      const rowClass = row.valid ? '' : 'table-danger';
      const title = row.errors.length > 0 ? 'title="' + row.errors.join(', ') + '"' : '';
      html += '<tr class="' + rowClass + '" ' + title + '>';
      html += '<td>' + (row.login || '<em class="text-muted">пусто</em>') + '</td>';
      html += '<td>' + (row.status || '<em class="text-muted">пусто</em>') + '</td>';
      // Остальные колонки (если есть)
      for (let i = 2; i < headers.length; i++) {
        html += '<td></td>'; // Упрощённо, без всех данных
      }
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    
    // Легенда
    html += '<div class="mt-2 small text-muted">';
    html += '<i class="fas fa-info-circle me-1"></i>';
    html += 'Строки с ошибками подсвечены красным. Наведите курсор для деталей.';
    html += '</div>';
    html += '</div>';
    
    previewContainer.innerHTML = html;
    previewContainer.classList.remove('d-none');
  }

  async function handleUpload(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();

    const form = cache.getById('uploadAccountsForm');
    const submitBtn = cache.getById('uploadAccountsBtn');
    const errorsDiv = cache.getById('addAccountErrors');
    const successDiv = cache.getById('addAccountSuccess');
    const fileInput = cache.getById('accountsFile');
    const previewContainer = cache.getById('csvPreviewContainer');

    if (errorsDiv) errorsDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');
    if (previewContainer) previewContainer.classList.add('d-none');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      if (errorsDiv) { errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки'; errorsDiv.classList.remove('d-none'); }
      return;
    }

    const file = fileInput.files[0];
    const maxSize = config.MAX_IMPORT_FILE_SIZE || (20 * 1024 * 1024);
    if (file.size > maxSize) {
      if (errorsDiv) { errorsDiv.textContent = `Файл слишком большой. Максимальный размер: ${Math.round(maxSize / 1024 / 1024)} MB`; errorsDiv.classList.remove('d-none'); }
      return;
    }

    const allowedExtensions = ['.csv', '.txt'];
    const hasValidExt = allowedExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
    if (!hasValidExt) {
      if (errorsDiv) { errorsDiv.textContent = 'Поддерживаются только файлы CSV или TXT'; errorsDiv.classList.remove('d-none'); }
      return;
    }
    
    // НОВОЕ: Клиентская валидация CSV перед отправкой
    log.debug('[UPLOAD] Начало валидации CSV файла...');
    
    try {
      const validation = await validateCsvFile(file);
      
      // Показываем предпросмотр
      if (validation.preview) {
        showCsvPreview(validation.preview);
      }
      
      // Показываем предупреждения (не блокируют загрузку)
      if (validation.warnings.length > 0) {
        const warningMsg = '<div class="mb-2"><strong>⚠️ Предупреждения:</strong></div>' + 
                          validation.warnings.map(w => '• ' + w).join('<br>');
        if (errorsDiv) {
          errorsDiv.innerHTML = warningMsg;
          errorsDiv.classList.remove('d-none', 'alert-danger');
          errorsDiv.classList.add('alert-warning');
        }
      }
      
      // Показываем ошибки (блокируют загрузку)
      if (!validation.valid) {
        const errorMsg = '<div class="mb-2"><strong>❌ Ошибки валидации:</strong></div>' + 
                        validation.errors.map(e => '• ' + e).join('<br>') +
                        '<div class="mt-3"><small>Исправьте ошибки в CSV файле и попробуйте снова.</small></div>';
        if (errorsDiv) {
          errorsDiv.innerHTML = errorMsg;
          errorsDiv.classList.remove('d-none', 'alert-warning');
          errorsDiv.classList.add('alert-danger');
        }
        log.warn('[UPLOAD] Валидация не прошла:', validation.errors);
        return; // Останавливаем загрузку
      }
      
      log.debug('[UPLOAD] Валидация успешна');
      
    } catch (validationError) {
      log.error('[UPLOAD] Ошибка валидации:', validationError);
      if (errorsDiv) {
        errorsDiv.textContent = 'Ошибка при проверке файла: ' + validationError.message;
        errorsDiv.classList.remove('d-none');
      }
      return;
    }

    const formData = new FormData(form);
    if (submitBtn) {
      submitBtn.disabled = true;
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка...';

      try {
        const response = await fetch('import_accounts.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        });

        const ct = response.headers.get('content-type') || '';
        const isJson = ct.includes('application/json');

        if (!response.ok) {
          if (isJson) {
            const errData = await response.json();
            throw new Error(errData.error || `Ошибка ${response.status}: ${response.statusText}`);
          }
          const errText = await response.text().catch(() => '');
          throw new Error(errText || `Ошибка ${response.status}: ${response.statusText}`);
        }

        const result = isJson ? await response.json() : null;
        if (!result || !result.success) throw new Error(result?.error || 'Ошибка при загрузке файла');

        const created = result.created || 0;
        const updated = result.updated || 0;
        const duplicates = result.skipped || 0;
        const errorsCount = result.errors ? result.errors.length : 0;
        const errorGroups = {};
        if (result.errors && result.errors.length > 0) {
          result.errors.forEach(err => {
            const msg = err.message || 'Неизвестная ошибка';
            if (!errorGroups[msg]) errorGroups[msg] = { message: msg, count: 0, examples: [] };
            errorGroups[msg].count++;
            if (errorGroups[msg].examples.length < 5) errorGroups[msg].examples.push(err.row);
          });
        }

        if (typeof window.showToast === 'function') {
          let toastMsg = '';
          if (created > 0) toastMsg += `✅ Добавлено: ${created}`;
          if (updated > 0) toastMsg += (toastMsg ? '\n' : '') + `🔄 Обновлено: ${updated}`;
          if (duplicates > 0) toastMsg += (toastMsg ? '\n' : '') + `⚠️ Пропущено (дубликаты): ${duplicates}`;
          if (errorsCount > 0) {
            const keys = Object.keys(errorGroups);
            const hr = keys[0] === 'Status is required' ? 'отсутствует статус' : keys[0] === 'Login is required' ? 'отсутствует логин' : keys[0].toLowerCase();
            toastMsg += (toastMsg ? '\n' : '') + `❌ Не добавлено (${hr}): ${errorsCount}`;
          }
          if (!toastMsg) toastMsg = 'Импорт завершён';
          window.showToast(toastMsg, (errorsCount > 0 || duplicates > 0) ? 'warning' : 'success');
        }

        if (errorsDiv) {
          if (errorsCount > 0) {
            let html = '<div class="import-result-details"><h6 class="mb-3"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Детали ошибок импорта:</h6>';
            Object.values(errorGroups).forEach(g => {
              let title = g.message;
              if (g.message === 'Status is required') title = 'Отсутствует статус';
              else if (g.message === 'Login is required') title = 'Отсутствует логин';
              else if (g.message.includes('already exists')) title = 'Дубликат логина';
              html += `<div class="mb-3"><div class="fw-semibold mb-1">${title} <span class="badge bg-danger">${g.count}</span></div>`;
              if (g.examples.length > 0) html += `<div class="text-muted small">Примеры строк: ${g.examples.join(', ')}</div>`;
              html += '</div>';
            });
            html += '<div class="mt-3 p-2 bg-light rounded"><small class="text-muted"><strong>Рекомендации:</strong><br>';
            if (errorGroups['Status is required']) html += '• Заполните поле "status" для всех строк<br>';
            if (errorGroups['Login is required']) html += '• Заполните поле "login" для всех строк<br>';
            if (duplicates > 0) html += '• Проверьте, не дублируются ли логины в файле<br>';
            html += '• Исправьте ошибки в файле и попробуйте снова</small></div></div>';
            errorsDiv.innerHTML = html;
            errorsDiv.classList.remove('d-none');
          } else { errorsDiv.classList.add('d-none'); errorsDiv.innerHTML = ''; }
        }

        if (form) form.reset();
        if (successDiv) { successDiv.classList.add('d-none'); successDiv.innerHTML = ''; }

        if (errorsCount === 0) {
          const modal = cache.getById('addAccountModal');
          if (modal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const inst = bootstrap.Modal.getInstance(modal) || bootstrap.Modal.getOrCreateInstance(modal);
            if (inst) inst.hide();
            else { const btn = modal.querySelector('[data-bs-dismiss="modal"]'); if (btn) btn.click(); }
          }
        } else if (errorsDiv) {
          setTimeout(() => errorsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);
        }

        setTimeout(() => {
          if (typeof window.refreshDashboardData === 'function') {
            window.refreshDashboardData().catch(err => { if (err.name !== 'AbortError') window.location.reload(); });
          } else window.location.reload();
        }, 400);
      } catch (err) {
        const msg = (err instanceof Error ? err.message : String(err)) || 'Ошибка при загрузке файла';
        const safe = document.createElement('div');
        safe.textContent = msg;
        const safeMsg = safe.textContent || msg;
        if (errorsDiv) { errorsDiv.textContent = safeMsg; errorsDiv.classList.remove('d-none'); }
        if (typeof window.showToast === 'function') window.showToast(safeMsg, 'error');
      } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
      }
    }
  }

  window.handleUploadAccountsGlobal = handleUpload;
  window.DashboardUpload = { 
    init: () => {}, 
    handleUpload,
    validateCsvFile,
    showCsvPreview
  };

  function bindForm() {
    const uploadForm = cache.getById('uploadAccountsForm');
    const uploadBtn = cache.getById('uploadAccountsBtn');
    const fileInput = cache.getById('accountsFile');
    
    if (uploadForm) uploadForm.addEventListener('submit', e => { e.preventDefault(); handleUpload(e); });
    
    if (uploadBtn) {
      uploadBtn.addEventListener('click', function(e) {
        log.debug('[UPLOAD BTN] Клик по кнопке загрузки');
        
        // Проверяем, не заблокирована ли кнопка
        if (uploadBtn.disabled || uploadBtn.classList.contains('disabled')) {
          log.warn('[UPLOAD BTN] Кнопка заблокирована, игнорируем клик');
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        const form = cache.getById('uploadAccountsForm');
        const fileInput = cache.getById('accountsFile');
        
        if (form && fileInput && fileInput.files && fileInput.files.length > 0) {
          log.debug('[UPLOAD BTN] Файл выбран, начинаем загрузку');
          handleUpload({ preventDefault: () => {} });
        } else {
          log.warn('[UPLOAD BTN] Файл не выбран');
          const errorsDiv = cache.getById('addAccountErrors');
          if (errorsDiv) { 
            errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки'; 
            errorsDiv.classList.remove('d-none'); 
          }
        }
      });
    }
    
    // НОВОЕ: Автоматическая валидация при выборе файла
    if (fileInput) {
      fileInput.addEventListener('change', async function(e) {
        const errorsDiv = cache.getById('addAccountErrors');
        const previewContainer = cache.getById('csvPreviewContainer');
        
        if (errorsDiv) errorsDiv.classList.add('d-none');
        if (previewContainer) previewContainer.classList.add('d-none');
        
        // Разблокируем кнопку по умолчанию
        if (uploadBtn) {
          uploadBtn.disabled = false;
          uploadBtn.classList.remove('disabled');
        }
        
        if (!e.target.files || e.target.files.length === 0) {
          return;
        }
        
        const file = e.target.files[0];
        
        log.debug('[FILE CHANGE] Начало автоматической валидации...', {
          fileName: file.name,
          fileSize: file.size,
          fileType: file.type
        });
        
        // Показываем индикатор загрузки
        if (uploadBtn) {
          const originalText = uploadBtn.innerHTML;
          uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Проверка файла...';
          uploadBtn.disabled = true;
          uploadBtn.dataset.originalText = originalText;
        }
        
        try {
          const validation = await validateCsvFile(file);
          
          // Показываем предпросмотр
          if (validation.preview) {
            showCsvPreview(validation.preview);
          }
          
          // Показываем предупреждения (НЕ блокируют кнопку)
          if (validation.warnings.length > 0) {
            const warningMsg = '<div class="mb-2"><strong>⚠️ Предупреждения:</strong></div>' + 
                              validation.warnings.map(w => '• ' + w).join('<br>');
            if (errorsDiv) {
              errorsDiv.innerHTML = warningMsg;
              errorsDiv.classList.remove('d-none', 'alert-danger');
              errorsDiv.classList.add('alert-warning');
            }
          }
          
          // Показываем ошибки и БЛОКИРУЕМ кнопку (только для маленьких файлов)
          if (!validation.valid) {
            const errorMsg = '<div class="mb-2"><strong>❌ Ошибки валидации:</strong></div>' + 
                            validation.errors.map(e => '• ' + e).join('<br>') +
                            '<div class="mt-3"><small>' + 
                            (validation.preview && validation.preview.isPartial 
                              ? 'Файл очень большой. Полная валидация будет выполнена на сервере. Вы можете попробовать загрузить файл.' 
                              : 'Исправьте ошибки в CSV файле и выберите файл заново.') +
                            '</small></div>';
            if (errorsDiv) {
              errorsDiv.innerHTML = errorMsg;
              errorsDiv.classList.remove('d-none', 'alert-warning');
              errorsDiv.classList.add('alert-danger');
            }
            
            // БЛОКИРУЕМ кнопку ТОЛЬКО для маленьких файлов (где валидация полная)
            // Для больших файлов позволяем загрузку на сервер для полной валидации
            if (uploadBtn) {
              if (validation.preview && validation.preview.isPartial) {
                // Большой файл - разблокируем, пусть сервер валидирует
                uploadBtn.disabled = false;
                uploadBtn.classList.remove('disabled');
                log.debug('[FILE CHANGE] Большой файл - кнопка разблокирована, серверная валидация');
              } else {
                // Маленький файл - блокируем при ошибках
                uploadBtn.disabled = true;
                uploadBtn.classList.add('disabled');
                log.debug('[FILE CHANGE] Кнопка заблокирована из-за ошибок валидации');
              }
            }
          } else {
            // Если нет ошибок - разблокируем кнопку
            if (uploadBtn) {
              uploadBtn.disabled = false;
              uploadBtn.classList.remove('disabled');
              log.debug('[FILE CHANGE] Кнопка разблокирована - валидация успешна');
            }
          }
          
        } catch (validationError) {
          log.error('[FILE CHANGE] Ошибка валидации:', validationError);
          // При ошибке валидации - разблокируем кнопку (пусть попробует отправить)
          if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.classList.remove('disabled');
          }
        } finally {
          // Восстанавливаем текст кнопки
          if (uploadBtn && uploadBtn.dataset.originalText) {
            uploadBtn.innerHTML = uploadBtn.dataset.originalText;
            delete uploadBtn.dataset.originalText;
          }
        }
      });
    }
  }

  // Инициализация с обработкой ошибок
  try {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        try {
          bindForm();
        } catch (err) {
          console.error('[DASHBOARD-UPLOAD] Ошибка при инициализации после DOMContentLoaded:', err);
        }
      });
    } else {
      bindForm();
    }
  } catch (err) {
    console.error('[DASHBOARD-UPLOAD] Критическая ошибка при загрузке модуля:', err);
  }
})();

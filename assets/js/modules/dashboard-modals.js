/**
 * Модуль управления модальными окнами дашборда
 * Отвечает за инициализацию модальных окон, обработчики открытия/закрытия, валидацию форм
 */

// Вспомогательная функция для безопасного получения элемента через dom-cache
function getElementById(id) {
  if (typeof domCache !== 'undefined' && domCache.getById) {
    return domCache.getById(id);
  }
  return document.getElementById(id);
}

// Инициализация модального окна изменения статуса
function initStatusModal() {
  const changeStatusSelected = getElementById('changeStatusSelected');
  const applyStatusBtn = getElementById('applyStatusBtn');
  const statusModalEl = getElementById('statusModal');
  
  if (!changeStatusSelected || !applyStatusBtn || !statusModalEl) return;
  
  // Открытие модального окна смены статуса
  changeStatusSelected.addEventListener('click', function() {
    const DS = window.DashboardSelection;
    if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
    const modal = new bootstrap.Modal(statusModalEl);
    modal.show();
  });
  
  // Применение нового статуса
  applyStatusBtn.addEventListener('click', async function() {
    const statusSelect = getElementById('statusSelect');
    const statusNewInput = getElementById('statusNewInput');
    const newStatus = (statusNewInput?.value || '').trim() || statusSelect?.value;
    
    if (!newStatus) {
      if (typeof showToast === 'function') {
        showToast('Укажите статус', 'error');
      }
      return;
    }
    
    try {
      const DS = window.DashboardSelection;
      let body;
      
      const csrfToken = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
      
      if (DS && DS.getSelectedAllFiltered()) {
        const params = new URLSearchParams(window.location.search);
        body = { ids: [], status: newStatus, select: 'all', query: params.toString(), csrf: csrfToken };
        if (typeof logger !== 'undefined') {
          logger.group('📝 Изменение статуса (все по фильтру)');
        }
      } else {
        const ids = Array.from(DS ? DS.getSelectedIds() : []);
        body = { ids, status: newStatus, csrf: csrfToken };
        if (typeof logger !== 'undefined') {
          logger.group('📝 Изменение статуса (выбранные)');
          logger.debug('ID для изменения:', ids);
          logger.debug('Количество:', ids.length);
        }
      }
      
      if (typeof logger !== 'undefined') {
        logger.debug('Новый статус:', newStatus);
        logger.debug('Тело запроса:', body);
        logger.groupEnd();
      }
      
      const res = await fetch('status_update.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(body)
      });
      
      if (typeof logger !== 'undefined') {
        logger.debug('📡 Статус ответа:', res.status, res.statusText);
      }
      
      if (!res.ok) {
        const text = await res.text();
        if (typeof logger !== 'undefined') {
          logger.error('❌ Ошибка HTTP при смене статуса:', res.status, text);
        }
        
        let errorMessage = `HTTP ${res.status}: ${res.statusText}`;
        try {
          const errorJson = JSON.parse(text);
          if (errorJson.error) {
            errorMessage = errorJson.error;
            if (errorMessage.includes('invalid characters') || errorMessage.includes('Status contains')) {
              errorMessage = 'Статус содержит недопустимые символы. Разрешены только буквы (включая кириллицу), цифры, подчеркивания, дефисы и пробелы.';
            }
          }
        } catch (e) {
          // Игнорируем ошибку парсинга и используем стандартное сообщение
        }
        throw new Error(errorMessage);
      }
      
      const json = await res.json();
      if (typeof logger !== 'undefined') {
        logger.debug('📥 Ответ сервера при смене статуса:', json);
      }
      
      if (!json.success) {
        let errorMessage = json.error || 'Update failed';
        if (errorMessage.includes('invalid characters') || errorMessage.includes('Status contains')) {
          errorMessage = 'Статус содержит недопустимые символы. Разрешены только буквы (включая кириллицу), цифры, подчеркивания, дефисы и пробелы.';
        }
        throw new Error(errorMessage);
      }
      
      if (typeof showToast === 'function') {
        showToast(`Статус обновлён для ${json.affected || 0} записей`, 'success');
      }
      
      if (typeof logger !== 'undefined') {
        logger.debug('🔄 Обновляем статистику после изменения статуса...');
      }
      
      if (typeof refreshDashboardData === 'function') {
        await refreshDashboardData();
      }

      // После смены статуса сбрасываем выбор: аккаунт мог уйти из текущего фильтра,
      // показ «Выбраны 1» при отсутствии выбранных на странице вводит в заблуждение
      if (window.DashboardSelection && typeof window.DashboardSelection.clearSelection === 'function') {
        window.DashboardSelection.clearSelection();
      }
      
      const modalInstance = bootstrap.Modal.getInstance(statusModalEl);
      if (modalInstance) {
        modalInstance.hide();
      }
    } catch (error) {
      if (typeof logger !== 'undefined') {
        logger.error('Ошибка изменения статуса:', error);
      }
      if (typeof showToast === 'function') {
        showToast('Ошибка обновления статуса: ' + error.message, 'error');
      }
    }
  });
}

// Инициализация модального окна массового редактирования
function initBulkEditModal() {
  const bulkEditBtn = getElementById('bulkEditFieldBtn');
  const bulkEditModalEl = getElementById('bulkFieldModal');
  const bulkFieldSelect = getElementById('bulkFieldSelect');
  const bulkGlobalWarning = getElementById('bulkGlobalWarning');
  const bulkGlobalFieldLabel = bulkGlobalWarning ? bulkGlobalWarning.querySelector('.bulk-global-field') : null;
  const bulkGlobalCountLabel = bulkGlobalWarning ? bulkGlobalWarning.querySelector('.bulk-global-count') : null;
  const bulkGlobalConfirm = getElementById('bulkGlobalConfirm');
  const applyBulkFieldBtn = getElementById('applyBulkFieldBtn');
  
  if (!bulkEditBtn || !bulkEditModalEl) return;
  
  const DS = window.DashboardSelection;
  
  function shouldWarnGlobalBulk() {
    return DS && DS.getSelectedAllFiltered() && typeof ACTIVE_FILTERS_COUNT !== 'undefined' && ACTIVE_FILTERS_COUNT === 0;
  }
  
  function updateBulkWarningState() {
    if (!bulkGlobalWarning) return;
    const needWarning = shouldWarnGlobalBulk();
    if (!needWarning) {
      bulkGlobalWarning.style.display = 'none';
      if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
      if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = false;
      return;
    }
    bulkGlobalWarning.style.display = '';
    if (bulkGlobalFieldLabel && bulkFieldSelect) {
      const optionText = bulkFieldSelect.options[bulkFieldSelect.selectedIndex]?.textContent?.trim() || 'поле';
      bulkGlobalFieldLabel.textContent = optionText;
    }
    if (bulkGlobalCountLabel && DS && typeof DS.getFilteredTotalLive === 'function') {
      bulkGlobalCountLabel.textContent = DS.getFilteredTotalLive().toLocaleString('ru-RU');
    }
    if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
    if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = true;
  }
  
  // Открытие модального окна
  bulkEditBtn.addEventListener('click', function() {
    if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
    const modal = bootstrap.Modal.getOrCreateInstance(bulkEditModalEl);
    const input = getElementById('bulkFieldValue');
    if (input) input.value = '';
    updateBulkWarningState();
    modal.show();
  });
  
  // Переключение чекбокса подтверждения глобального изменения
  if (bulkGlobalConfirm && applyBulkFieldBtn) {
    bulkGlobalConfirm.addEventListener('change', () => {
      if (!shouldWarnGlobalBulk()) {
        applyBulkFieldBtn.disabled = false;
        return;
      }
      applyBulkFieldBtn.disabled = !bulkGlobalConfirm.checked;
    });
  }
  
  // Сброс предупреждения при закрытии модалки
  if (bulkEditModalEl && bulkGlobalConfirm && applyBulkFieldBtn) {
    bulkEditModalEl.addEventListener('hidden.bs.modal', () => {
      bulkGlobalConfirm.checked = false;
      applyBulkFieldBtn.disabled = false;
    });
  }
  
  // Обновление текста предупреждения при смене поля
  if (bulkFieldSelect) {
    bulkFieldSelect.addEventListener('change', () => {
      if (shouldWarnGlobalBulk()) {
        updateBulkWarningState();
      }
    });
  }
  
  // Применение массового изменения поля
  if (applyBulkFieldBtn) {
    applyBulkFieldBtn.addEventListener('click', async function() {
      const field = (getElementById('bulkFieldSelect')?.value || '').trim();
      const value = (getElementById('bulkFieldValue')?.value || '').trim();
      if (!field) {
        if (typeof showToast === 'function') {
          showToast('Выберите поле', 'error');
        }
        return;
      }
      if (!DS) return;
      
      const scope = DS.getSelectedAllFiltered()
        ? (typeof ACTIVE_FILTERS_COUNT !== 'undefined' && ACTIVE_FILTERS_COUNT === 0 ? 'all' : 'filtered')
        : 'selected';
      
      if (scope === 'all' && bulkGlobalConfirm && !bulkGlobalConfirm.checked) {
        if (typeof showToast === 'function') {
          showToast('Подтвердите глобальное изменение всех записей', 'error');
        }
        return;
      }
      
      try {
        const csrfToken = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
        let body;
        if (DS.getSelectedAllFiltered()) {
          const params = new URLSearchParams(window.location.search);
          body = { field, value, ids: [], select: 'all', query: params.toString(), csrf: csrfToken, scope };
        } else {
          body = { field, value, ids: Array.from(DS.getSelectedIds()), csrf: csrfToken, scope };
        }
        
        const res = await fetch('bulk_update_field.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(body)
        });
        
        if (!res.ok) {
          const text = await res.text();
          throw new Error(text || 'HTTP error');
        }
        
        const json = await res.json();
        if (!json.success) {
          throw new Error(json.error || 'Ошибка массового обновления поля');
        }
        
        if (typeof showToast === 'function') {
          showToast(`Поле "${field}" обновлено для ${json.affected || 0} записей`, 'success');
        }
        
        if (typeof refreshDashboardData === 'function') {
          await refreshDashboardData();
        }

        // После массового редактирования сбрасываем выбор по той же логике, что и при смене статуса
        if (window.DashboardSelection && typeof window.DashboardSelection.clearSelection === 'function') {
          window.DashboardSelection.clearSelection();
        }
        
        const modalInstance = bootstrap.Modal.getInstance(bulkEditModalEl);
        if (modalInstance) {
          modalInstance.hide();
        }
      } catch (error) {
        if (typeof logger !== 'undefined') {
          logger.error('Ошибка массового изменения поля:', error);
        }
        if (typeof showToast === 'function') {
          showToast('Ошибка массового изменения поля: ' + error.message, 'error');
        }
      }
    });
  }
}

// Инициализация модального окна настроек
function initSettingsModal() {
  const settingsModalEl = getElementById('settingsModal');
  if (!settingsModalEl) return;
  
  // Обработчики для настроек можно добавить здесь
  // Например, сохранение настроек видимости колонок, карточек и т.д.
}

// Инициализация модального окна массового переноса аккаунтов
function initTransferModal() {
  const transferBtn = getElementById('transferAccountsBtn');
  const applyTransferBtn = getElementById('applyTransferBtn');
  const transferModalEl = getElementById('transferAccountsModal');
  
  if (!transferBtn || !applyTransferBtn || !transferModalEl) return;
  
  // Открытие модального окна
  transferBtn.addEventListener('click', function() {
    const modal = new bootstrap.Modal(transferModalEl);
    modal.show();
  });
  
  // Применение переноса
  applyTransferBtn.addEventListener('click', async function() {
    const text = (getElementById('transferText')?.value || '').trim();
    const statusSelect = (getElementById('transferStatusSelect')?.value || '').trim();
    const statusCustom = (getElementById('transferStatusCustom')?.value || '').trim();
    const status = statusCustom || statusSelect;
    const enableLike = getElementById('transferEnableLike')?.checked ?? false;
    
    if (!text) {
      if (typeof showToast === 'function') {
        showToast('Вставьте текст с ID аккаунтов', 'error');
      }
      return;
    }
    
    if (!status) {
      if (typeof showToast === 'function') {
        showToast('Укажите новый статус', 'error');
      }
      return;
    }
    
    const lines = text.split('\n').filter(l => l.trim() !== '');
    const sizeInBytes = new Blob([text]).size;
    const maxSize = 20 * 1024 * 1024; // 20MB
    const maxLines = 50000;
    const recommendedLines = 2000;
    
    if (sizeInBytes > maxSize) {
      if (typeof showToast === 'function') {
        showToast(`⚠️ Слишком большой текст (${(sizeInBytes/1024/1024).toFixed(1)}MB). Максимум 20MB`, 'error');
      }
      return;
    }
    
    if (lines.length > maxLines) {
      if (typeof showToast === 'function') {
        showToast(`⚠️ Слишком много строк (${lines.length.toLocaleString()}). Максимум ${maxLines.toLocaleString()}`, 'error');
      }
      return;
    }
    
    if (lines.length > recommendedLines) {
      const confirmMsg = `⚠️ Вы вставили ${lines.length.toLocaleString()} строк.\n\n` +
        `Рекомендуется обрабатывать не более ${recommendedLines.toLocaleString()} строк за раз.\n` +
        `При большом объёме обработка может занять 30-60 секунд.\n\n` +
        `Продолжить?`;
      
      if (!confirm(confirmMsg)) {
        return;
      }
    }
    
    // timerInterval объявлен вне try/catch — const внутри try недоступен в catch (block scope)
    let timerInterval = null;

    try {
      if (typeof showPageLoader === 'function') {
        showPageLoader();
      }
      
      const loadingInfoEl = document.createElement('div');
      loadingInfoEl.id = 'massTransferLoadingInfo';
      loadingInfoEl.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10001;background:#fff;padding:30px;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);text-align:center;min-width:350px;';
      loadingInfoEl.innerHTML = `
        <div style="font-size:48px;margin-bottom:15px;">⏳</div>
        <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:10px;">Обработка массового переноса</div>
        <div style="font-size:14px;color:#666;margin-bottom:15px;">Обрабатывается ${lines.length.toLocaleString()} строк...</div>
        <div style="font-size:12px;color:#999;">Пожалуйста, подождите. Это может занять некоторое время.</div>
        <div id="transferTimer" style="font-size:13px;color:#0d6efd;margin-top:15px;font-weight:500;">Прошло: 0 сек</div>
      `;
      document.body.appendChild(loadingInfoEl);
      
      let secondsPassed = 0;
      const timerEl = document.getElementById('transferTimer');
      timerInterval = setInterval(() => {
        secondsPassed++;
        if (timerEl) {
          timerEl.textContent = `Прошло: ${secondsPassed} сек`;
        }
      }, 1000);
      
      const csrfToken = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
      
      const body = {
        text,
        status,
        enable_like: enableLike ? 1 : 0,
        csrf: csrfToken
      };
      
      const res = await fetch('mass_transfer.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(body)
      });
      
      if (!res.ok) {
        const textResp = await res.text();
        throw new Error(textResp || `HTTP ${res.status}`);
      }
      
      const json = await res.json();
      if (!json.success) {
        throw new Error(json.error || 'Ошибка массового переноса');
      }
      
      if (typeof showToast === 'function') {
        showToast(`Перенос завершён. Обновлено записей: ${json.affected ?? 0}`, 'success');
      }
      
      if (typeof refreshDashboardData === 'function') {
        await refreshDashboardData();
      }
      
      const modalInstance = bootstrap.Modal.getInstance(transferModalEl);
      if (modalInstance) {
        modalInstance.hide();
      }
      
      if (typeof hidePageLoader === 'function') {
        hidePageLoader();
      }
      
      const loadingInfo = document.getElementById('massTransferLoadingInfo');
      if (loadingInfo) loadingInfo.remove();
      clearInterval(timerInterval);
    } catch (error) {
      // Очищаем таймер — без этого он продолжит тикать после ошибки (memory leak)
      clearInterval(timerInterval);
      const loadingInfo = document.getElementById('massTransferLoadingInfo');
      if (loadingInfo) loadingInfo.remove();
      if (typeof logger !== 'undefined') {
        logger.error('Ошибка массового переноса аккаунтов:', error);
      }
      if (typeof showToast === 'function') {
        showToast('Ошибка массового переноса: ' + error.message, 'error');
      }
      if (typeof hidePageLoader === 'function') {
        hidePageLoader();
      }
    }
  });
}

// Валидация формы в модальном окне
function validateModalForm(formElement) {
  if (!formElement) return false;
  
  const requiredFields = formElement.querySelectorAll('[required]');
  let isValid = true;
  
  requiredFields.forEach(field => {
    if (!field.value || !field.value.trim()) {
      isValid = false;
      field.classList.add('is-invalid');
    } else {
      field.classList.remove('is-invalid');
    }
  });
  
  return isValid;
}

// Инициализация всех модальных окон
function initModalsModule() {
  initStatusModal();
  initBulkEditModal();
  initSettingsModal();
  initTransferModal();
  
  // Общая валидация для всех форм в модальных окнах
  document.addEventListener('submit', function(e) {
    const form = e.target.closest('form');
    if (form && form.closest('.modal')) {
      if (!validateModalForm(form)) {
        e.preventDefault();
        if (typeof showToast === 'function') {
          showToast('Заполните все обязательные поля', 'error');
        }
      }
    }
  });
  
  if (typeof logger !== 'undefined') {
    logger.debug('✅ Модуль модальных окон инициализирован');
  }
}

// Экспорт функций для глобального использования
window.DashboardModals = {
  init: initModalsModule,
  initStatusModal: initStatusModal,
  initBulkEditModal: initBulkEditModal,
  initSettingsModal: initSettingsModal,
  initTransferModal: initTransferModal,
  validateModalForm: validateModalForm
};

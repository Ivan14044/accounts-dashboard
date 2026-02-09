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
  const changeStatusBtn = getElementById('changeStatusSelected');
  const statusModalEl = getElementById('statusModal');
  const applyStatusBtn = getElementById('applyStatusBtn');
  
  if (!changeStatusBtn || !statusModalEl || !applyStatusBtn) return;
  
  // Обработчик открытия модального окна
  changeStatusBtn.addEventListener('click', function() {
    // Проверяем, есть ли выбранные строки
    let hasSelection = false;
    if (typeof window.DashboardSelection !== 'undefined') {
      const selectedIds = window.DashboardSelection.getSelectedIds();
      const selectedAllFiltered = window.DashboardSelection.getSelectedAllFiltered();
      hasSelection = selectedAllFiltered || selectedIds.size > 0;
    } else if (typeof selectedAllFiltered !== 'undefined' && typeof selectedIds !== 'undefined') {
      hasSelection = selectedAllFiltered || selectedIds.size > 0;
    }
    
    if (!hasSelection) return;
    
    const modal = new bootstrap.Modal(statusModalEl);
    modal.show();
  });
  
  // Обработчик применения статуса
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
      let body;
      let selectedAllFiltered = false;
      let selectedIds = [];
      
      if (typeof window.DashboardSelection !== 'undefined') {
        selectedAllFiltered = window.DashboardSelection.getSelectedAllFiltered();
        selectedIds = Array.from(window.DashboardSelection.getSelectedIds());
      } else if (typeof window.selectedAllFiltered !== 'undefined' && typeof window.selectedIds !== 'undefined') {
        selectedAllFiltered = window.selectedAllFiltered;
        selectedIds = Array.from(window.selectedIds);
      }
      
      if (selectedAllFiltered) {
        const params = new URLSearchParams(window.location.search);
        body = {
          ids: [],
          status: newStatus,
          select: 'all',
          query: params.toString(),
          csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        };
        if (typeof logger !== 'undefined') {
          logger.group('📝 Изменение статуса (все по фильтру)');
        }
      } else {
        body = {
          ids: selectedIds,
          status: newStatus,
          csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        };
        if (typeof logger !== 'undefined') {
          logger.group('📝 Изменение статуса (выбранные)');
          logger.debug('ID для изменения:', selectedIds);
          logger.debug('Количество:', selectedIds.length);
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
      
      const data = await res.json();
      
      if (data.success) {
        if (typeof showToast === 'function') {
          showToast('Статус успешно обновлен', 'success');
        }
        
        // Закрываем модальное окно
        const modal = bootstrap.Modal.getInstance(statusModalEl);
        if (modal) {
          modal.hide();
        }
        
        // Обновляем данные
        if (typeof refreshDashboardData === 'function') {
          refreshDashboardData();
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || 'Ошибка обновления статуса', 'error');
        }
      }
    } catch (error) {
      if (typeof logger !== 'undefined') {
        logger.error('Error updating status:', error);
      }
      if (typeof showToast === 'function') {
        showToast('Ошибка обновления статуса', 'error');
      }
    }
  });
}

// Инициализация модального окна массового редактирования
function initBulkEditModal() {
  const bulkEditBtn = getElementById('bulkEditFieldBtn');
  const bulkEditModalEl = getElementById('bulkEditModal');
  
  if (!bulkEditBtn || !bulkEditModalEl) return;
  
  bulkEditBtn.addEventListener('click', function() {
    // Проверяем, есть ли выбранные строки
    let hasSelection = false;
    if (typeof window.DashboardSelection !== 'undefined') {
      const selectedIds = window.DashboardSelection.getSelectedIds();
      const selectedAllFiltered = window.DashboardSelection.getSelectedAllFiltered();
      hasSelection = selectedAllFiltered || selectedIds.size > 0;
    } else if (typeof selectedAllFiltered !== 'undefined' && typeof selectedIds !== 'undefined') {
      hasSelection = selectedAllFiltered || selectedIds.size > 0;
    }
    
    if (!hasSelection) return;
    
    const modal = new bootstrap.Modal(bulkEditModalEl);
    modal.show();
  });
}

// Инициализация модального окна настроек
function initSettingsModal() {
  const settingsModalEl = getElementById('settingsModal');
  if (!settingsModalEl) return;
  
  // Обработчики для настроек можно добавить здесь
  // Например, сохранение настроек видимости колонок, карточек и т.д.
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
  validateModalForm: validateModalForm
};

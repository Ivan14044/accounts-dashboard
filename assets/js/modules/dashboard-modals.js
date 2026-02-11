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
  // Логика изменения статуса (открытие модалки и отправка запроса на status_update.php)
  // перенесена в inline-скрипт `init-script.php` (блок "Change status (bulk)").
  //
  // Здесь оставляем пустую реализацию, чтобы не дублировать обработчики кликов
  // и не отправлять второй запрос с некорректным CSRF-токеном, который ранее
  // приводил к 400 и ложному сообщению "Ошибка обновления статуса" при успешном обновлении.
  return;
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

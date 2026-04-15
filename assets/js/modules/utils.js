/**
 * Утилиты для дашборда
 * Общие функции, используемые в разных модулях
 */

// Копирование в буфер обмена
export function copyToClipboard(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => {
      if (typeof window.showToast === 'function') {
        window.showToast('Скопировано в буфер обмена', 'success');
      }
    }).catch(() => {
      fallbackCopyTextToClipboard(text);
    });
  } else {
    fallbackCopyTextToClipboard(text);
  }
}

// Fallback для копирования (для старых браузеров)
export function fallbackCopyTextToClipboard(text) {
  const textArea = document.createElement('textarea');
  textArea.value = String(text || '');
  textArea.style.position = 'fixed';
  textArea.style.top = '0';
  textArea.style.left = '0';
  textArea.style.width = '2px';
  textArea.style.height = '2px';
  textArea.style.padding = '0';
  textArea.style.border = 'none';
  textArea.style.outline = 'none';
  textArea.style.boxShadow = 'none';
  textArea.style.background = 'transparent';
  textArea.setAttribute('readonly', '');
  document.body.appendChild(textArea);
  
  textArea.focus();
  textArea.setSelectionRange(0, textArea.value.length);
  
  try {
    const successful = document.execCommand('copy');
    if (successful && typeof window.showToast === 'function') {
      window.showToast('Скопировано в буфер обмена', 'success');
    } else if (!successful && typeof window.showToast === 'function') {
      window.showToast('Ошибка копирования', 'error');
    }
  } catch (err) {
    if (typeof window.showToast === 'function') {
      window.showToast('Ошибка копирования', 'error');
    }
  } finally {
    document.body.removeChild(textArea);
  }
}

// Helper function to escape HTML
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  const div = document.createElement('div');
  div.textContent = String(str);
  return div.innerHTML;
}

// Обертка для showToast (использует класс Toast из toast.js)
export function showToast(message, type = 'info', duration = 3000) {
  // Используем улучшенный класс Toast с progress bar
  if (typeof window.Toast !== 'undefined' && window.Toast.show) {
    const normalizedType = type === 'danger' || type === 'error' ? 'error' :
                          type === 'warning' ? 'warning' :
                          type === 'success' ? 'success' : 'info';

    return window.Toast.show(message, {
      type: normalizedType,
      duration: duration,
      closable: true
    });
  }

  // Fallback для старых версий
  const toast = document.createElement('div');
  const bgColor = type === 'success' ? 'success' : (type === 'error' ? 'danger' : 'info');
  toast.className = `toast align-items-center text-white bg-${bgColor} border-0 position-fixed`;
  toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';

  // Build toast content safely using DOM methods instead of innerHTML
  const flexDiv = document.createElement('div');
  flexDiv.className = 'd-flex';

  const bodyDiv = document.createElement('div');
  bodyDiv.className = 'toast-body';

  const icon = document.createElement('i');
  icon.className = `fas fa-${type === 'success' ? 'check' : (type === 'error' ? 'exclamation-triangle' : 'info-circle')} me-2`;

  const messageSpan = document.createElement('span');
  messageSpan.textContent = message;

  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'toast-close';
  closeBtn.setAttribute('aria-label', 'Закрыть');
  closeBtn.title = 'Закрыть';
  const closeIcon = document.createElement('i');
  closeIcon.className = 'fas fa-times';
  closeBtn.appendChild(closeIcon);

  bodyDiv.appendChild(icon);
  bodyDiv.appendChild(messageSpan);
  flexDiv.appendChild(bodyDiv);
  flexDiv.appendChild(closeBtn);
  toast.appendChild(flexDiv);

  document.body.appendChild(toast);

  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      toast.style.opacity = '0';
      setTimeout(() => {
        if (toast.parentNode) {
          document.body.removeChild(toast);
        }
      }, 300);
    });
  }
  setTimeout(() => {
    toast.style.opacity = '1';
  }, 10);
  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => {
      if (toast.parentNode) {
        document.body.removeChild(toast);
      }
    }, 300);
  }, duration);
}

// Для обратной совместимости - экспортируем в window
if (typeof window !== 'undefined') {
  if (typeof window.copyToClipboard !== 'function') {
    window.copyToClipboard = copyToClipboard;
  }
  if (typeof window.fallbackCopyTextToClipboard !== 'function') {
    window.fallbackCopyTextToClipboard = fallbackCopyTextToClipboard;
  }
  if (typeof window.showToast !== 'function') {
    window.showToast = showToast;
  }
}

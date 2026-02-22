/**
 * Fallback для copyToClipboard и showToast (если не загружены из dashboard.js).
 * Подключается до dashboard-init.
 */
(function () {
  'use strict';
  if (typeof window.copyToClipboard === 'function') return;
  window.copyToClipboard = function (text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        if (typeof window.showToast === 'function') window.showToast('Скопировано в буфер обмена', 'success');
      }).catch(function () { window.fallbackCopyTextToClipboard(text); });
    } else {
      window.fallbackCopyTextToClipboard(text);
    }
  };
  if (typeof window.fallbackCopyTextToClipboard === 'function') return;
  window.fallbackCopyTextToClipboard = function (text) {
    var textArea = document.createElement('textarea');
    textArea.value = String(text || '');
    textArea.style.cssText = 'position:fixed;top:0;left:0;width:2px;height:2px;padding:0;border:none;outline:none;box-shadow:none;background:transparent';
    textArea.setAttribute('readonly', '');
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.setSelectionRange(0, textArea.value.length);
    try {
      var ok = document.execCommand('copy');
      if (typeof window.showToast === 'function') window.showToast(ok ? 'Скопировано в буфер обмена' : 'Ошибка копирования', ok ? 'success' : 'error');
    } catch (err) {
      if (typeof window.showToast === 'function') window.showToast('Ошибка копирования', 'error');
    } finally {
      document.body.removeChild(textArea);
    }
  };
  if (typeof window.showToast === 'function') return;
  window.showToast = function (message, type, duration) {
    type = type || 'info';
    duration = duration || 3000;
    if (typeof window.Toast !== 'undefined' && window.Toast.show) {
      var t = type === 'danger' || type === 'error' ? 'error' : type === 'warning' ? 'warning' : type === 'success' ? 'success' : 'info';
      return window.Toast.show(message, { type: t, duration: duration, closable: true });
    }
    var toast = document.createElement('div');
    var bg = type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info';
    toast.className = 'toast align-items-center text-white bg-' + bg + ' border-0 position-fixed';
    toast.style.cssText = 'top:20px;right:20px;z-index:9999';
    toast.innerHTML = '<div class="d-flex"><div class="toast-body">' + message + '</div><button type="button" class="toast-close" aria-label="Закрыть"><i class="fas fa-times"></i></button></div>';
    document.body.appendChild(toast);
    var btn = toast.querySelector('.toast-close');
    if (btn) btn.addEventListener('click', function () { toast.remove(); });
    setTimeout(function () { toast.remove(); }, duration);
  };
})();

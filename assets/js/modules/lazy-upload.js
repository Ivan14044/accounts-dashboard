/** Keep import parsing and validation off the initial dashboard path. */
(function () {
  const modal = document.getElementById('addAccountModal');
  if (!modal) return;
  let ready = false;
  let loading = false;

  modal.addEventListener('show.bs.modal', function (event) {
    if (ready) return;
    event.preventDefault();
    if (loading) return;
    loading = true;
    const trigger = event.relatedTarget;
    const button = trigger && trigger.setAttribute ? trigger : null;
    const label = button ? button.innerHTML : '';
    if (button) {
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
      button.textContent = 'Загрузка…';
    }
    const restoreButton = function () {
      if (!button) return;
      button.removeAttribute('aria-busy');
      button.disabled = false;
      button.innerHTML = label;
    };
    const script = document.createElement('script');
    script.src = modal.dataset.uploadScript;
    const failed = function () {
      loading = false;
      restoreButton();
      script.remove();
      if (typeof window.showToast === 'function') {
        window.showToast('Не удалось загрузить импорт. Нажмите «Добавить» ещё раз, чтобы повторить.', 'error');
      }
    };
    script.onerror = failed;
    script.onload = function () {
      try {
        if (!window.DashboardUpload) throw new Error('Import module unavailable');
        window.DashboardUpload.init();
        ready = true;
        loading = false;
      } catch (error) {
        failed();
        return;
      }
      restoreButton();
      bootstrap.Modal.getOrCreateInstance(modal).show(trigger);
    };
    document.head.appendChild(script);
  });
})();

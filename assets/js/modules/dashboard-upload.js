/**
 * Модуль загрузки аккаунтов (импорт CSV/TXT)
 * Экспортирует handleUploadAccountsGlobal и привязывает обработчики к форме
 */
(function() {
  const cache = (typeof domCache !== 'undefined' && domCache.getById)
    ? domCache
    : {
        getById: (id) => document.getElementById(id),
        get: (sel) => document.querySelector(sel)
      };

  const log = (typeof logger !== 'undefined') ? logger : { debug: () => {}, warn: () => {}, error: () => {} };

  async function handleUpload(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();

    const form = cache.getById('uploadAccountsForm');
    const submitBtn = cache.getById('uploadAccountsBtn');
    const errorsDiv = cache.getById('addAccountErrors');
    const successDiv = cache.getById('addAccountSuccess');
    const fileInput = cache.getById('accountsFile');

    if (errorsDiv) errorsDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      if (errorsDiv) { errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки'; errorsDiv.classList.remove('d-none'); }
      return;
    }

    const file = fileInput.files[0];
    const maxSize = 20 * 1024 * 1024;
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
          if (duplicates > 0) toastMsg += (toastMsg ? '\n' : '') + `⚠️ Пропущено (уже есть в панели): ${duplicates}`;
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
  window.DashboardUpload = { init: () => {}, handleUpload };

  function bindForm() {
    const uploadForm = cache.getById('uploadAccountsForm');
    const uploadBtn = cache.getById('uploadAccountsBtn');
    if (uploadForm) uploadForm.addEventListener('submit', e => { e.preventDefault(); handleUpload(e); });
    if (uploadBtn) {
      uploadBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const form = cache.getById('uploadAccountsForm');
        const fileInput = cache.getById('accountsFile');
        if (form && fileInput && fileInput.files && fileInput.files.length > 0) {
          handleUpload({ preventDefault: () => {} });
        } else {
          const errorsDiv = cache.getById('addAccountErrors');
          if (errorsDiv) { errorsDiv.textContent = 'Пожалуйста, выберите файл для загрузки'; errorsDiv.classList.remove('d-none'); }
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindForm);
  } else {
    bindForm();
  }
})();

/**
 * Inline-редактирование значения ячейки таблицы.
 *
 * Вынесено из dashboard-init.js 2026-08-08. Раньше эти 220 строк жили внутри
 * общего делегированного обработчика кликов handleDocumentClick, между веткой
 * копирования и веткой клика по строке — читать такой обработчик невозможно.
 *
 * Почему модуль отдаёт функцию, а не вешает СВОЙ обработчик на document:
 * порядок веток в handleDocumentClick значим (ветка редактирования перехватывает
 * клик и не даёт сработать клику по строке), а отдельный слушатель этот порядок
 * сломал бы. Поэтому dashboard-init.js по-прежнему решает, когда звать модуль.
 *
 * Зависимости берутся из глобальной области в момент клика, а не при загрузке:
 * getElementById, showToast, logger, DashboardConfig, CellValues.
 */
(function () {
  'use strict';

  /**
   * Открывает редактор для поля, к которому относится нажатая кнопка.
   *
   * @param {HTMLElement} fieldEditBtn Кнопка .field-edit-btn из hover-панели ячейки
   * @returns {void}
   */
  function handleEditClick(fieldEditBtn) {
    var wrap = fieldEditBtn.closest('.editable-field-wrap');
    if (!wrap) return;
    var rowId = parseInt(wrap.getAttribute('data-row-id'), 10);
    var field = wrap.getAttribute('data-field');
    var fieldType = wrap.getAttribute('data-field-type');
    var fieldValue = wrap.querySelector('.field-value');
    // Тонкая разметка: у обрезанных полей полного значения нет в DOM —
    // подгружаем (CellValues), кладём во временный data-full и повторяем клик.
    if (fieldValue && fieldValue.hasAttribute('data-clipped') && !fieldValue.hasAttribute('data-full') && window.CellValues) {
      window.CellValues.getFor(fieldValue).then(function (v) {
        fieldValue.setAttribute('data-full', v === null ? fieldValue.textContent.trim() : v);
        fieldEditBtn.click();
      });
      return;
    }
    var oldVal = '';
    if (fieldType === 'numeric') {
      var textContent = fieldValue.textContent.trim();
      oldVal = (textContent === '—' || textContent === '') ? '' : textContent.replace(/[^\d.-]/g, '');
    } else {
      oldVal = fieldValue.textContent.trim();
      if (oldVal === '—') oldVal = '';
    }
    var fullValue = fieldValue.getAttribute('data-full');
    if (fullValue !== null) oldVal = fullValue;
    if (fieldValue.tagName === 'A') {
      if (field === 'email') oldVal = fieldValue.href.replace('mailto:', '');
      else if (field === 'social_url') {
        oldVal = fieldValue.href;
        if (oldVal.startsWith(window.location.origin)) oldVal = oldVal.substring(window.location.origin.length);
        if (!oldVal.match(/^https?:\/\//)) oldVal = fieldValue.textContent.replace(/^\s*\S+\s*/, '').trim() || fieldValue.textContent.trim();
      } else oldVal = fieldValue.textContent.trim();
    }
    var longFields = ['token', 'cookies', 'first_cookie', 'user_agent', 'extra_info_1', 'extra_info_2', 'extra_info_3', 'extra_info_4'];
    var isLongField = longFields.indexOf(field) !== -1;
    var input = document.createElement(isLongField ? 'textarea' : 'input');
    if (!isLongField) {
      input.type = (fieldType === 'numeric') ? 'number' : 'text';
      if (fieldType === 'numeric') input.step = 'any';
    } else {
      input.rows = 4;
      input.style.resize = 'vertical';
      input.style.minWidth = '300px';
    }
    input.className = 'form-control form-control-sm';
    input.value = oldVal || '';
    var tableModule = window.tableModule;
    var virtualization = tableModule && tableModule.virtualScroller;
    var virtualizationWasEnabled = false;
    if (virtualization && virtualization.enabled) {
      virtualizationWasEnabled = true;
      virtualization.disable(true);
    }
    var saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-sm btn-success ms-1';
    saveBtn.innerHTML = '<i class="fas fa-check"></i>';
    saveBtn.title = 'Сохранить';
    var cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
    cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
    cancelBtn.title = 'Отмена';
    var originalContent = wrap.innerHTML;
    var originalValue = oldVal;
    wrap.setAttribute('data-editing', 'true');
    var row = wrap.closest('tr[data-id]');
    if (row) row.setAttribute('data-editing', 'true');
    var cell = wrap.closest('td');
    if (cell) cell.setAttribute('data-editing', 'true');
    wrap.innerHTML = '';
    wrap.appendChild(input);
    wrap.appendChild(saveBtn);
    wrap.appendChild(cancelBtn);
    input.style.display = 'block';
    input.style.visibility = 'visible';
    input.style.opacity = '1';
    input.style.width = 'auto';
    input.style.minWidth = '120px';
    input.style.flex = '1';
    setTimeout(function() {
      input.focus();
      // input[type=number] не поддерживает выделение: и select(), и
      // setSelectionRange() бросают InvalidStateError. Уронить редактирование
      // это не успевает (ошибка вылетает после focus), но в консоли висел
      // необработанный exception при каждой правке числового поля.
      var supportsSelection = input.type !== 'number' && input.type !== 'email';
      if (!supportsSelection) return;
      if (oldVal && oldVal !== '') input.select();
      else if (input.setSelectionRange) input.setSelectionRange(0, 0);
    }, 0);
    var scrollContainer = getElementById('tableWrap');
    var scrollBlocked = false;
    var savedScrollTop = 0;
    if (scrollContainer) {
      scrollBlocked = true;
      savedScrollTop = scrollContainer.scrollTop;
      scrollContainer.style.overflow = 'hidden';
    }
    var unlockScroll = function() {
      if (scrollBlocked && scrollContainer) {
        scrollContainer.style.overflow = '';
        scrollContainer.scrollTop = savedScrollTop;
        scrollBlocked = false;
      }
      wrap.removeAttribute('data-editing');
      if (row) row.removeAttribute('data-editing');
      var cell2 = wrap.closest('td');
      if (cell2) cell2.removeAttribute('data-editing');
      if (virtualizationWasEnabled && virtualization && tableModule) {
        setTimeout(function() {
          var stillEditing = tableModule.tbody && tableModule.tbody.querySelector('tr[data-id][data-editing="true"]');
          if (!stillEditing && tableModule.tbody) {
            var rows = Array.from(tableModule.tbody.querySelectorAll('tr[data-id]'));
            if (rows.length > (virtualization.options.threshold || 80)) virtualization.enable(rows);
          }
        }, 100);
      }
    };
    var restoreOriginal = function() {
      unlockScroll();
      wrap.innerHTML = originalContent;
      var restoredFieldValue = wrap.querySelector('.field-value');
      if (restoredFieldValue && originalValue !== oldVal) {
        if (originalValue === '') { restoredFieldValue.textContent = '—'; restoredFieldValue.classList.add('text-muted'); }
        else restoredFieldValue.textContent = originalValue;
      }
    };
    var save = async function() {
      var newVal = isLongField ? input.value : input.value.trim();
      var fieldTypeAttr = wrap.getAttribute('data-field-type');
      if (fieldTypeAttr === 'numeric' && newVal !== '' && newVal !== null) {
        var trimmed = newVal.trim();
        if (trimmed !== '' && (isNaN(trimmed) || trimmed === '')) {
          showToast('Поле должно содержать число', 'error');
          input.focus();
          input.select();
          return;
        }
        newVal = trimmed;
      }
      try {
        var res = await fetch(window.getTableAwareUrl('update_field.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ id: rowId, field: field, value: newVal, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
        });
        var text = await res.text();
        var json = text ? JSON.parse(text) : { success: false, error: 'Empty response' };
        if (!res.ok) throw new Error(json.error || 'HTTP error! status: ' + res.status);
        if (!json.success) throw new Error(json.error || 'update failed');
        wrap.innerHTML = originalContent;
        var updatedFieldValue = wrap.querySelector('.field-value');
        if (newVal === '' || newVal === null) {
          updatedFieldValue.textContent = '—';
          updatedFieldValue.classList.add('text-muted');
        } else if (field === 'email') {
          updatedFieldValue.href = 'mailto:' + newVal;
          updatedFieldValue.textContent = newVal;
        } else if (field === 'social_url') {
          if (/^https?:\/\//i.test(newVal)) {
            updatedFieldValue.href = newVal;
            updatedFieldValue.target = '_blank';
            updatedFieldValue.rel = 'noopener';
            updatedFieldValue.className = 'text-decoration-none field-value';
            updatedFieldValue.textContent = '';
            var icon1 = document.createElement('i');
            icon1.className = 'fas fa-external-link-alt me-2';
            updatedFieldValue.appendChild(icon1);
            updatedFieldValue.appendChild(document.createTextNode(newVal));
          } else if (newVal !== '' && newVal !== null) {
            var urlWithProtocol = 'http://' + newVal;
            updatedFieldValue.href = urlWithProtocol;
            updatedFieldValue.target = '_blank';
            updatedFieldValue.rel = 'noopener';
            updatedFieldValue.className = 'text-decoration-none field-value';
            updatedFieldValue.textContent = '';
            var icon2 = document.createElement('i');
            icon2.className = 'fas fa-external-link-alt me-2';
            updatedFieldValue.appendChild(icon2);
            updatedFieldValue.appendChild(document.createTextNode(urlWithProtocol));
          } else {
            updatedFieldValue.textContent = '—';
            updatedFieldValue.classList.add('text-muted');
          }
        } else if (isLongField) {
          updatedFieldValue.setAttribute('data-full', newVal);
          updatedFieldValue.textContent = newVal.substring(0, 100) + (newVal.length > 100 ? '…' : '');
        } else if (field === 'status') {
          var statusClass = 'badge-default';
          var statusDisplay = newVal;
          var statusValue = String(newVal).toLowerCase();
          if (newVal === null || newVal === '' || newVal === undefined) {
            statusClass = 'badge-empty-status';
            statusDisplay = 'Пустой статус';
          } else if (statusValue.indexOf('new') !== -1) statusClass = 'badge-new';
          else if (statusValue.indexOf('add_selphi_true') !== -1) statusClass = 'badge-add_selphi_true';
          else if (statusValue.indexOf('error') !== -1) statusClass = 'badge-error_login';
          updatedFieldValue.className = 'badge ' + statusClass + ' field-value';
          updatedFieldValue.textContent = statusDisplay;
        } else updatedFieldValue.textContent = newVal;
        unlockScroll();
        showToast('Поле успешно обновлено', 'success');
      } catch (err) {
        restoreOriginal();
        var errorMessage = err instanceof TypeError && err.message.indexOf('fetch') !== -1 ? 'Ошибка сети. Проверьте подключение к интернету.' : ('Ошибка сохранения: ' + (err.message || ''));
        showToast(errorMessage, 'error');
        logger.error('Field update error:', err);
      }
    };
    var cancel = function() { unlockScroll(); wrap.innerHTML = originalContent; };
    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', cancel);
    input.addEventListener('keydown', function(ev) {
      if (ev.key === 'Enter') {
        if (isLongField) { if (ev.ctrlKey) { ev.preventDefault(); save(); } }
        else if (!ev.shiftKey) { ev.preventDefault(); save(); }
      } else if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
    });
    return;
  }

  window.DashboardInlineEdit = { handleEditClick: handleEditClick };
})();

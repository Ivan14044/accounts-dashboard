/**
 * Undo — просмотр и отмена последнего действия пользователя.
 *
 * Кнопка «↩ Отменить…» в тулбаре показывает последнее отменяемое действие
 * текущего пользователя (undo.php GET) и откатывает его (undo.php POST).
 * После AJAX-обновления данных (refreshDashboardData) состояние кнопки
 * обновляется; если появилось новое действие — предлагаем отмену в тосте.
 *
 * Отменяются: правки полей/статусов (в т.ч. массовые), удаление в корзину,
 * массовый перенос. Строки, изменённые после действия, при откате не трогаются.
 */
(function () {
  'use strict';

  let lastKnownActionId = null; // null = ещё не загружали
  let undoInProgress = false;

  function getCsrf() {
    return (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
  }

  function undoUrl() {
    return typeof window.getTableAwareUrl === 'function'
      ? window.getTableAwareUrl('undo.php')
      : 'undo.php';
  }

  async function fetchLastAction() {
    const res = await fetch(undoUrl(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) return null;
    const json = await res.json().catch(function () { return null; });
    return json && json.success ? json.action : null;
  }

  function updateButton(action) {
    const btn = document.getElementById('undoLastActionBtn');
    if (!btn) return;
    if (!action) {
      btn.style.display = 'none';
      btn.disabled = true;
      btn.removeAttribute('data-action-id');
      return;
    }
    btn.style.display = '';
    btn.disabled = undoInProgress;
    btn.setAttribute('data-action-id', String(action.id));
    btn.title = 'Отменить: ' + action.description + ' — ' + action.created_at;
  }

  /**
   * Обновить состояние кнопки. offerToast=true — если с прошлой проверки
   * появилось новое действие, предложить отмену в тосте.
   */
  async function refresh(opts) {
    opts = opts || {};
    let action = null;
    try {
      action = await fetchLastAction();
    } catch (e) {
      return; // сеть/сервер недоступны — кнопку не трогаем
    }
    const prevId = lastKnownActionId;
    lastKnownActionId = action ? action.id : null;
    updateButton(action);

    if (opts.offerToast && action && prevId !== null && action.id !== prevId && window.Toast) {
      Toast.info('Можно отменить: ' + action.description, {
        duration: 8000,
        action: { label: 'Отменить', onClick: performUndo }
      });
    }
  }

  function formatReport(json) {
    let msg = 'Отменено изменений: ' + json.reverted;
    const parts = [];
    if (json.skipped_conflict) parts.push('пропущено ' + json.skipped_conflict + ' (изменены позже)');
    if (json.skipped_sensitive) parts.push('пропущено ' + json.skipped_sensitive + ' (скрытые поля)');
    if (json.unsupported) parts.push(json.unsupported + ' отменить невозможно');
    if (parts.length) msg += '. ' + parts.join(', ');
    return { msg: msg, hasSkips: parts.length > 0 };
  }

  async function performUndo() {
    if (undoInProgress) return;

    let action = null;
    try {
      action = await fetchLastAction(); // всегда свежее состояние
    } catch (e) { /* обработается ниже */ }
    if (!action) {
      if (window.Toast) Toast.info('Нечего отменять');
      await refresh();
      return;
    }

    const confirmMsg = 'Отменить последнее действие?\n\n' +
      action.description + '\nЗатронуто записей: ' + action.affected_count +
      (action.table_name ? '\nТаблица: ' + action.table_name : '');
    if (!confirm(confirmMsg)) return;

    undoInProgress = true;
    const btn = document.getElementById('undoLastActionBtn');
    if (btn) btn.disabled = true;

    try {
      const res = await fetch(undoUrl(), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ action_id: action.id, csrf: getCsrf() })
      });
      const json = await res.json().catch(function () { return null; });
      if (!res.ok || !json || !json.success) {
        throw new Error((json && json.error) || ('HTTP ' + res.status));
      }

      const report = formatReport(json);
      if (window.Toast) {
        Toast[report.hasSkips ? 'warning' : 'success'](report.msg, { duration: 6000 });
      }

      // Обновляем таблицу и карточки; если AJAX-обновления нет — полная перезагрузка
      if (typeof window.refreshDashboardData === 'function') {
        await window.refreshDashboardData().catch(function () { window.location.reload(); });
      } else {
        window.location.reload();
        return;
      }
    } catch (e) {
      if (window.Toast) Toast.error('Ошибка отмены: ' + e.message);
    } finally {
      undoInProgress = false;
      await refresh();
    }
  }

  function init() {
    const btn = document.getElementById('undoLastActionBtn');
    if (!btn) return; // не дашборд — модуль неактивен

    btn.addEventListener('click', performUndo);

    // После каждого AJAX-обновления данных проверяем, не появилось ли новое действие
    const origRefresh = window.refreshDashboardData;
    if (typeof origRefresh === 'function') {
      window.refreshDashboardData = function () {
        const p = origRefresh.apply(this, arguments);
        Promise.resolve(p).then(function () {
          refresh({ offerToast: true });
        }).catch(function () {});
        return p;
      };
    }

    refresh();
  }

  window.UndoManager = { refresh: refresh, performUndo: performUndo };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

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
 *
 * Тосты зовутся строго как `window.Toast.*`, а не `Toast.*`. Голое имя `Toast`
 * резолвится в КЛАСС из assets/js/toast.js (top-level `class Toast` — лексическая
 * привязка, она приоритетнее свойства window), а у класса нет методов
 * success/error/warning/info. Из-за этого после успешной отмены модуль падал на
 * `Toast.success(...)`, улетал в catch, падал там ещё раз на `Toast.error(...)`
 * и не доходил до refreshDashboardData(): в БД откат происходил, а пользователь
 * не видел ни тоста, ни обновлённой таблицы. Стережёт
 * tests/test_toast_global_shadowing.php. (Найдено и починено 2026-08-09.)
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
      window.Toast.info('Можно отменить: ' + action.description, {
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
      if (window.Toast) window.Toast.info('Нечего отменять');
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
        window.Toast[report.hasSkips ? 'warning' : 'success'](report.msg, { duration: 6000 });
      }

      // Обновляем таблицу и карточки; если AJAX-обновления нет — полная перезагрузка
      if (typeof window.refreshDashboardData === 'function') {
        await window.refreshDashboardData().catch(function () { window.location.reload(); });
      } else {
        window.location.reload();
        return;
      }
    } catch (e) {
      if (window.Toast) window.Toast.error('Ошибка отмены: ' + e.message);
    } finally {
      undoInProgress = false;
      await refresh();
    }
  }

  function init() {
    const btn = document.getElementById('undoLastActionBtn');
    if (!btn) return; // не дашборд — модуль неактивен

    btn.addEventListener('click', performUndo);

    // После каждого AJAX-обновления данных проверяем, не появилось ли новое действие.
    // Подписка, а не обёртка над window.refreshDashboardData: обёртки от разных
    // модулей выстраивались в цепочку, зависящую от порядка загрузки скриптов.
    if (window.DashboardRefresh && typeof window.DashboardRefresh.onAfterRefresh === 'function') {
      window.DashboardRefresh.onAfterRefresh(function () {
        refresh({ offerToast: true });
      });
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

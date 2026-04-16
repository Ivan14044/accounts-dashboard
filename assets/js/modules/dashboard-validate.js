/**
 * dashboard-validate.js
 * Модуль проверки аккаунтов на валидность (check.fb.tools)
 *
 * ВАЖНО: параллельные запросы к нашему API блокируются nginx rate limiter'ом (429).
 * Поэтому отправляем СТРОГО ОДИН запрос за раз (CONCURRENCY=1),
 * а параллельность обеспечивает бэкенд: AccountValidationService раскидывает
 * суб-батчи к check.fb.tools через curl_multi.
 *
 * BATCH_SIZE=500 (= VALIDATE_CHECK_MAX_ITEMS) → бэкенд делит их на 10 суб-батчей
 * по FB_CHECK_BATCH_SIZE=50 и обращается к check.fb.tools одновременно.
 * UI обновляется после каждого большого батча. Ошибки не крашат процесс.
 */
(function () {
  'use strict';

  // ─── Константы ─────────────────────────────────────────
  var BATCH_SIZE  = 500;   // элементов за один /check запрос (= VALIDATE_CHECK_MAX_ITEMS)
  var CONCURRENCY = 1;     // СТРОГО 1 — иначе nginx отдаёт 429!
  var PREP_LIMIT  = 2000;  // лимит prepare за одну страницу
  var ACTION_BATCH = 1000; // лимит ID за один запрос действия

  // ─── DOM ───────────────────────────────────────────────
  var $ = function (id) { return document.getElementById(id); };

  // ─── Состояние ─────────────────────────────────────────
  var state = {
    cancelled  : false,
    running    : false,
    abortCtrl  : null,
    items      : [],
    skipped    : [],
    valid      : [],
    invalid    : [],
    errors     : [],
    total      : 0,
    totalAll   : 0,
    checked    : 0,
    startTime  : 0
  };

  // ─── Анимация: плавные счётчики ────────────────────────
  var anim = {
    _tweens: [],

    /** Плавно меняет число внутри элемента от текущего к target за duration ms */
    countTo: function (id, target, duration) {
      var el = $(id);
      if (!el) return;
      duration = duration || 400;
      target = Math.round(target);
      var start = parseInt(el.textContent, 10) || 0;
      if (start === target) return;
      var diff = target - start;
      var t0 = performance.now();

      // Отменяем предыдущий tween на этот элемент
      this._tweens = this._tweens.filter(function (tw) { return tw.id !== id; });
      var tween = { id: id, raf: 0 };
      this._tweens.push(tween);

      function tick(now) {
        var elapsed = now - t0;
        var progress = Math.min(elapsed / duration, 1);
        // easeOutCubic
        var ease = 1 - Math.pow(1 - progress, 3);
        var current = Math.round(start + diff * ease);
        el.textContent = current;
        if (progress < 1) {
          tween.raf = requestAnimationFrame(tick);
        }
      }
      tween.raf = requestAnimationFrame(tick);
    },

    /** Сброс — отменяем все активные анимации */
    cancelAll: function () {
      this._tweens.forEach(function (tw) {
        if (tw.raf) cancelAnimationFrame(tw.raf);
      });
      this._tweens = [];
    }
  };

  function resetState() {
    if (state.abortCtrl) { state.abortCtrl.abort(); state.abortCtrl = null; }
    anim.cancelAll();
    state.cancelled  = false;
    state.running    = false;
    state.abortCtrl  = null;
    state.items      = [];
    state.skipped    = [];
    state.valid      = [];
    state.invalid    = [];
    state.errors     = [];
    state.total      = 0;
    state.totalAll   = 0;
    state.checked    = 0;
    state.startTime  = 0;
  }

  /** Полный сброс всех DOM-элементов */
  function resetDOM() {
    setText('vldProgressLabel', '');
    setWidth('vldProgressBar', '0%');
    setText('vldProgressBar', '');
    addClass('vldEta', 'd-none');
    addClass('vldRatioWrap', 'd-none');
    setWidth('vldRatioValid', '0%');
    setWidth('vldRatioInvalid', '0%');
    setHTML('vldRatioLabel', '');
    removeClass('vldProgressWrap', 'vld-indeterminate');

    setText('vldResValidNum', '0');
    setText('vldResInvalidNum', '0');
    setText('vldResSkippedNum', '0');
    setText('vldResValidPct', '');
    setText('vldResInvalidPct', '');
    setWidth('vldResRatioValid', '0%');
    setWidth('vldResRatioInvalid', '0%');
    addClass('vldResSkippedCard', 'd-none');
    addClass('vldActionsBlock', 'd-none');

    var startBtn = $('vldStartBtn');
    if (startBtn) { startBtn.disabled = false; startBtn.innerHTML = '<i class="fas fa-play me-2"></i>Запустить проверку'; }
  }

  // ─── Утилиты DOM ───────────────────────────────────────
  function setText(id, t)    { var el = $(id); if (el) el.textContent = t; }
  function setHTML(id, h)    { var el = $(id); if (el) el.innerHTML = h; }
  function setWidth(id, w)   { var el = $(id); if (el) el.style.width = w; }
  function addClass(id, c)   { var el = $(id); if (el) el.classList.add(c); }
  function removeClass(id, c){ var el = $(id); if (el) el.classList.remove(c); }
  function toggle(id, c, f)  { var el = $(id); if (el) el.classList.toggle(c, f); }

  // ─── Панели с fade-эффектом ────────────────────────────
  function showPane(id) {
    ['vldScopePane', 'vldProgressPane', 'vldResultPane'].forEach(function (p) {
      var el = $(p);
      if (!el) return;
      if (p === id) {
        el.classList.remove('d-none');
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        // Trigger reflow before transition
        void el.offsetWidth;
        el.style.transition = 'opacity .3s ease, transform .3s ease';
        el.style.opacity = '1';
        el.style.transform = 'translateY(0)';
      } else {
        el.classList.add('d-none');
        el.style.opacity = '';
        el.style.transform = '';
        el.style.transition = '';
      }
    });
  }

  // ─── CSRF ──────────────────────────────────────────────
  function csrf() {
    return (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
  }

  // ─── Scope ─────────────────────────────────────────────
  function getScopeData(scope) {
    var DS = window.DashboardSelection;

    if (scope === 'selected') {
      if (DS && DS.getSelectedAllFiltered && DS.getSelectedAllFiltered()) {
        return { scope: 'filter', ids: [], query: location.search.replace(/^\?/, '') };
      }
      return { scope: 'selected', ids: DS ? Array.from(DS.getSelectedIds()) : [], query: '' };
    }
    if (scope === 'page') {
      var ids = [];
      document.querySelectorAll('.row-checkbox').forEach(function (cb) {
        var v = parseInt(cb.value, 10);
        if (Number.isFinite(v)) ids.push(v);
      });
      return { scope: 'selected', ids: ids, query: '' };
    }
    return { scope: 'filter', ids: [], query: location.search.replace(/^\?/, '') };
  }

  // ─── API ───────────────────────────────────────────────
  function apiPrepare(scope, ids, query, offset) {
    var opts = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ scope: scope, ids: ids, query: query, limit: PREP_LIMIT, offset: offset || 0, csrf: csrf() })
    };
    if (state.abortCtrl) opts.signal = state.abortCtrl.signal;
    return fetch(window.getTableAwareUrl('/api/accounts/validate/prepare'), opts)
      .then(function (r) { return r.json(); });
  }

  function apiCheck(items) {
    var opts = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ items: items, csrf: csrf() })
    };
    if (state.abortCtrl) opts.signal = state.abortCtrl.signal;
    return fetch(window.getTableAwareUrl('/api/accounts/validate/check'), opts)
      .then(function (r) { return r.json(); });
  }

  // ─── ETA ───────────────────────────────────────────────
  function formatEta(sec) {
    if (sec < 1)  return '< 1 сек';
    if (sec < 60) return '≈ ' + Math.round(sec) + ' сек';
    var m = Math.floor(sec / 60);
    var s = Math.round(sec % 60);
    return s > 0 ? ('≈ ' + m + ' мин ' + s + ' сек') : ('≈ ' + m + ' мин');
  }

  // ─── Плавное обновление прогресса ─────────────────────
  function refreshProgress() {
    var done    = state.checked;
    var total   = state.total;
    var vCount  = state.valid.length;
    var iCount  = state.invalid.length;
    var solved  = vCount + iCount;
    var pct     = total > 0 ? Math.round((done / total) * 100) : 0;

    // Прогресс-бар (CSS transition делает анимацию плавной)
    setWidth('vldProgressBar', pct + '%');
    setText('vldProgressBar', pct + '%');
    var progressText = 'Проверено ' + done + ' из ' + total;
    if (state.skipped.length > 0) {
      progressText += ' (пропущено ' + state.skipped.length + ' — нет FB ID)';
    }
    setText('vldProgressLabel', progressText);

    // Spinner — скрываем когда пошла фактическая работа
    if (done > 0) addClass('vldSpinner', 'd-none');

    // Ratio-бар с плавными числами
    if (solved > 0) {
      var pv = Math.round((vCount / solved) * 100);
      var pi = 100 - pv;
      removeClass('vldRatioWrap', 'd-none');
      setWidth('vldRatioValid', pv + '%');
      setWidth('vldRatioInvalid', pi + '%');
      setHTML('vldRatioLabel',
        '<span style="color:#198754">✓ ' + vCount + ' (' + pv + '%)</span>' +
        ' · <span style="color:#dc3545">✗ ' + iCount + ' (' + pi + '%)</span>'
      );
    }

    // ETA — начинаем показывать после 3 проверенных
    if (done >= 3 && done < total) {
      var elapsed   = (Date.now() - state.startTime) / 1000;
      var rate      = done / elapsed;
      var remaining = (total - done) / rate;
      setText('vldEta', formatEta(remaining));
      removeClass('vldEta', 'd-none');
    } else if (done >= total) {
      addClass('vldEta', 'd-none');
    }
  }

  // ─── Worker Pool ──────────────────────────────────────
  function workerPool(items, batchSize, concurrency, taskFn) {
    var cursor = 0;

    function next() {
      if (state.cancelled || cursor >= items.length) return Promise.resolve();
      var batch = items.slice(cursor, cursor + batchSize);
      cursor += batch.length;
      return taskFn(batch).then(next);
    }

    var workers = [];
    var n = Math.min(concurrency, Math.ceil(items.length / batchSize));
    for (var i = 0; i < n; i++) workers.push(next());
    return Promise.all(workers);
  }

  // ─── Главный процесс ──────────────────────────────────
  function run() {
    var scopeEl = $('vldScopeSelect');
    var scope   = scopeEl ? scopeEl.value : 'selected';
    var data    = getScopeData(scope);

    resetState();
    resetDOM();
    state.running = true;
    state.abortCtrl = new AbortController();

    var startBtn = $('vldStartBtn');
    if (startBtn) { startBtn.disabled = true; startBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Подготовка…'; }

    showPane('vldProgressPane');
    setPreparing(true);

    // ── Шаг 1: Prepare (может быть многостраничным) ──
    function fetchAll(offset) {
      if (state.cancelled) return Promise.resolve();
      return apiPrepare(data.scope, data.ids, data.query, offset).then(function (res) {
        if (!res.success) throw new Error(res.error || 'Ошибка подготовки');
        (res.items || []).forEach(function (it) { state.items.push(it); });
        (res.skipped || []).forEach(function (s) { state.skipped.push(s); });
        state.totalAll = res.total || (state.items.length + state.skipped.length);
        state.total = res.total || state.items.length;
        var prepLabel = 'Подготовка… загружено ' + state.items.length + ' из ' + state.totalAll;
        if (state.skipped.length > 0) {
          prepLabel += ' (пропущено ' + state.skipped.length + ' — нет FB ID)';
        }
        setText('vldProgressLabel', prepLabel);
        if (res.has_more && res.next_offset != null) {
          return fetchAll(res.next_offset);
        }
      });
    }

    fetchAll(0)
      .then(function () {
        if (state.cancelled) { finish(); return; }

        if (state.items.length === 0) {
          toast('Нет записей с FB ID для проверки' + (state.skipped.length > 0 ? ' (все ' + state.skipped.length + ' без FB ID)' : ''), 'info');
          finish(); return;
        }

        setPreparing(false);
        state.total     = state.items.length;
        state.startTime = Date.now();
        refreshProgress();

        // ── Шаг 2: Check через worker pool ──
        return workerPool(state.items, BATCH_SIZE, CONCURRENCY, function (batch) {
          if (state.cancelled) return Promise.resolve();

          return apiCheck(batch)
            .then(function (res) {
              if (!res.success) {
                console.warn('Validate batch error:', res.error);
                batch.forEach(function (it) { state.errors.push(it); });
                state.checked += batch.length;
                refreshProgress();
                return;
              }

              (res.valid   || []).forEach(function (r) { state.valid.push(r); });
              (res.invalid || []).forEach(function (r) { state.invalid.push(r); });
              (res.skipped || []).forEach(function (r) { state.skipped.push(r); });

              state.checked += batch.length;
              refreshProgress();
            })
            .catch(function (err) {
              if (err.name === 'AbortError') return;
              console.warn('Validate batch fetch error:', err.message);
              batch.forEach(function (it) { state.errors.push(it); });
              state.checked += batch.length;
              refreshProgress();
            });
        });
      })
      .then(function () {
        if (state.running) showResult();
      })
      .catch(function (err) {
        toast(err.message || 'Ошибка', 'error');
        finish();
      });
  }

  // ─── Preparing UI ──────────────────────────────────────
  function setPreparing(on) {
    toggle('vldProgressWrap', 'vld-indeterminate', on);
    toggle('vldSpinner', 'd-none', !on);
    toggle('vldRatioWrap', 'd-none', true);
    addClass('vldEta', 'd-none');

    if (on) {
      setWidth('vldProgressBar', '100%');
      setText('vldProgressBar', '');
      setText('vldProgressLabel', 'Подготовка списка…');
    } else {
      setWidth('vldProgressBar', '0%');
      setText('vldProgressBar', '0%');
    }
  }

  // ─── Результат с анимированными счётчиками ─────────────
  function showResult() {
    state.running = false;
    showPane('vldResultPane');

    var v     = state.valid.length;
    var inv   = state.invalid.length;
    var skip  = state.skipped.length;
    var errs  = state.errors.length;
    var total = v + inv;
    var pv    = total ? Math.round((v / total) * 100) : 0;
    var pi    = total ? (100 - pv) : 0;

    // Сбрасываем числа в 0 перед анимацией
    setText('vldResValidNum', '0');
    setText('vldResInvalidNum', '0');
    setText('vldResSkippedNum', '0');

    // Запускаем countUp после небольшой задержки (пусть fade-in пройдёт)
    setTimeout(function () {
      anim.countTo('vldResValidNum', v, 600);
      anim.countTo('vldResInvalidNum', inv, 600);
      if (skip > 0 || errs > 0) {
        anim.countTo('vldResSkippedNum', skip + errs, 600);
      }
    }, 150);

    setText('vldResValidPct', pv + '%');
    setText('vldResInvalidPct', pi + '%');
    toggle('vldResSkippedCard', 'd-none', skip === 0 && errs === 0);

    // Ratio bar — задержка для синхронизации с fade-in
    setTimeout(function () {
      setWidth('vldResRatioValid', pv + '%');
      setWidth('vldResRatioInvalid', pi + '%');
    }, 100);

    toggle('vldActionsBlock', 'd-none', inv === 0);

    var skipLabel = $('vldResSkippedLabel');
    if (skipLabel) {
      if (errs > 0 && skip > 0) {
        skipLabel.textContent = 'Пропущено (нет FB ID) + ' + errs + ' не проверено (ошибки)';
      } else if (errs > 0) {
        skipLabel.textContent = errs + ' не проверено (ошибки сети)';
      } else if (skip > 0) {
        skipLabel.textContent = 'Пропущено (нет FB ID)';
      }
    }

    window.__validateInvalidIds = state.invalid.map(function (r) { return r.id; });
  }

  function finish() {
    state.running = false;
    showPane('vldScopePane');
    var btn = $('vldStartBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-play me-2"></i>Запустить проверку'; }
  }

  // ─── Действия ──────────────────────────────────────────
  function sendInBatches(url, ids, extraFields) {
    var totalAffected = 0;
    var errors = [];
    var i = 0;

    function nextBatch() {
      if (i >= ids.length) return Promise.resolve();
      var batch = ids.slice(i, i + ACTION_BATCH);
      i += batch.length;

      var body = Object.assign({}, extraFields, { ids: batch, csrf: csrf() });
      return fetch(window.getTableAwareUrl(url), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body)
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          totalAffected += (d.affected || d.deleted_count || batch.length);
        } else {
          errors.push(d.error || 'Ошибка');
        }
      })
      .catch(function (err) { errors.push(err.message || 'Ошибка запроса'); })
      .then(nextBatch);
    }

    return nextBatch().then(function () {
      return { totalAffected: totalAffected, errors: errors };
    });
  }

  function applyStatus() {
    var ids = window.__validateInvalidIds;
    if (!ids || !ids.length) { toast('Нет невалидных', 'warning'); return; }
    var sel = $('vldActionStatusSelect');
    var status = (sel && sel.value) ? sel.value.trim() : '';
    if (!status) { toast('Выберите статус', 'warning'); return; }

    var btn = $('vldActionSetStatusBtn');
    btnLoading(btn, true, '<i class="fas fa-spinner fa-spin me-1"></i>Применяю…');

    sendInBatches('status_update.php', ids, { status: status })
      .then(function (result) {
        if (result.errors.length) {
          toast('Ошибки: ' + result.errors.join('; '), 'error');
        } else {
          toast('Статус обновлён (' + result.totalAffected + ')', 'success');
        }
        if (result.totalAffected > 0) {
          if (typeof window.refreshDashboardData === 'function') window.refreshDashboardData();
          if (window.DashboardSelection && typeof window.DashboardSelection.clearSelection === 'function') {
            window.DashboardSelection.clearSelection();
          }
        }
      })
      .finally(function () { btnLoading(btn, false, '<i class="fas fa-tag me-1"></i>Применить'); });
  }

  function deleteInvalid() {
    var ids = window.__validateInvalidIds;
    if (!ids || !ids.length) { toast('Нет невалидных', 'warning'); return; }
    if (!confirm('Удалить в корзину ' + ids.length + ' аккаунтов?')) return;

    var btn = $('vldActionDeleteBtn');
    btnLoading(btn, true, '<i class="fas fa-spinner fa-spin me-1"></i>Удаляю…');

    sendInBatches('delete.php', ids, {})
      .then(function (result) {
        if (result.errors.length) {
          toast('Ошибки: ' + result.errors.join('; '), 'error');
        } else {
          toast('Удалено: ' + result.totalAffected, 'success');
        }
        if (result.totalAffected > 0) {
          if (typeof window.refreshDashboardData === 'function') window.refreshDashboardData();
          if (window.DashboardSelection && typeof window.DashboardSelection.clearSelection === 'function') {
            window.DashboardSelection.clearSelection();
          }
        }
      })
      .finally(function () { btnLoading(btn, false, '<i class="fas fa-trash-alt me-1"></i>Удалить в корзину'); });
  }

  function btnLoading(btn, on, html) {
    if (!btn) return;
    btn.disabled  = on;
    btn.innerHTML = html;
  }

  function toast(msg, type) {
    if (typeof window.showToast === 'function') window.showToast(msg, type);
  }

  // ─── Init ──────────────────────────────────────────────
  function init() {
    var modal = $('validateAccountsModal');
    if (!modal) return;

    var trigger = $('validateAccountsBtn');
    if (trigger) {
      trigger.addEventListener('click', function () {
        resetState(); resetDOM(); showPane('vldScopePane');
        if (window.bootstrap && bootstrap.Modal) new bootstrap.Modal(modal).show();
      });
    }

    on('vldStartBtn',           'click', run);
    on('vldCancelBtn',          'click', function () {
      state.cancelled = true;
      if (state.abortCtrl) { state.abortCtrl.abort(); }
    });
    on('vldActionSetStatusBtn', 'click', applyStatus);
    on('vldActionDeleteBtn',    'click', deleteInvalid);

    modal.addEventListener('hidden.bs.modal', function () {
      resetState(); resetDOM(); showPane('vldScopePane');
    });
  }

  function on(id, ev, fn) { var el = $(id); if (el) el.addEventListener(ev, fn); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/**
 * Карточка аккаунта (view.php): копирование, коды 2FA, сворачивание длинных
 * значений, показ пустых полей, дублирование и выгрузка JSON.
 *
 * Данные страница кладёт в <script type="application/json" id="accountViewData">:
 *   { id, csrf, row: {колонка: значение}, access: [[подпись, значение]], all: [...] }
 * Значения для копирования берутся из row по ключу (data-copy-key), а не из
 * атрибутов разметки: у аккаунта бывают куки по 30+ КБ, и дублировать их в
 * HTML дважды незачем.
 *
 * Код 2FA считается прямо в браузере по ключу из поля two_fa (TOTP, RFC 6238:
 * HMAC-SHA1, 30 с, 6 цифр) — без обращений к сторонним сервисам. Нужен
 * crypto.subtle, а он есть только на https и localhost; без него блок кода
 * просто не показывается.
 *
 * Чего здесь осознанно НЕТ: правки полей. Страница — для просмотра и
 * копирования; правят аккаунт в таблице дашборда.
 */
(function () {
  'use strict';

  var B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  var SHOW_EMPTY_KEY = 'account_view_show_empty_v1';

  /**
   * base32 → байты. Пробелы/дефисы/хвостовые '=' игнорируются.
   * @param {string} input
   * @returns {Uint8Array|null} null — если в строке есть чужие символы или она пустая.
   */
  function base32Decode(input) {
    var s = String(input || '').toUpperCase().replace(/[\s-]+/g, '').replace(/=+$/, '');
    if (!s || /[^A-Z2-7]/.test(s)) return null;
    var bits = 0, value = 0, out = [];
    for (var i = 0; i < s.length; i++) {
      value = (value << 5) | B32.indexOf(s[i]);
      bits += 5;
      if (bits >= 8) {
        out.push((value >>> (bits - 8)) & 0xff);
        bits -= 8;
      }
    }
    return new Uint8Array(out);
  }

  /**
   * Код TOTP на момент timeMs.
   * @param {Uint8Array} key   ключ (байты)
   * @param {number} timeMs    время, мс
   * @param {object} [subtle]  crypto.subtle (для тестов можно подставить свой)
   * @param {number} [digits]  6 по умолчанию (8 — для проверочных векторов RFC)
   * @returns {Promise<string>}
   */
  function totp(key, timeMs, subtle, digits) {
    digits = digits || 6;
    subtle = subtle || (globalThis.crypto && globalThis.crypto.subtle);
    var counter = Math.floor(timeMs / 1000 / 30);
    var msg = new Uint8Array(8);
    var c = counter;
    for (var i = 7; i >= 0; i--) {
      msg[i] = c & 0xff;
      c = Math.floor(c / 256);
    }
    return subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-1' }, false, ['sign'])
      .then(function (k) { return subtle.sign('HMAC', k, msg); })
      .then(function (sig) {
        var h = new Uint8Array(sig);
        var off = h[h.length - 1] & 0x0f;
        var bin = ((h[off] & 0x7f) << 24) | (h[off + 1] << 16) | (h[off + 2] << 8) | h[off + 3];
        var code = String(bin % Math.pow(10, digits));
        while (code.length < digits) code = '0' + code;
        return code;
      });
  }

  /** Сколько секунд текущий код ещё действует (1…30). */
  function secondsLeft(timeMs) {
    return 30 - (Math.floor(timeMs / 1000) % 30);
  }

  /** «123456» → «123 456», чтобы глазу было легче переписать. */
  function groupCode(code) {
    return code.length === 6 ? code.slice(0, 3) + ' ' + code.slice(3) : code;
  }

  // ───────────────────────── страница ─────────────────────────

  var payload = null;

  function readPayload() {
    var el = document.getElementById('accountViewData');
    if (!el) return null;
    try { return JSON.parse(el.textContent || '{}'); } catch (e) { return null; }
  }

  function announce(text) {
    var live = document.getElementById('avLive');
    if (live) {
      live.textContent = '';
      setTimeout(function () { live.textContent = text; }, 30);
    }
  }

  /** Всплывающее сообщение (им же пользуется favorites.js через window.showToast). */
  function showToast(message, type) {
    if (typeof bootstrap === 'undefined' || !bootstrap.Toast) { announce(message); return; }
    var ok = type === 'success';
    var toast = document.createElement('div');
    toast.className = 'toast align-items-center text-white border-0 position-fixed bg-' + (ok ? 'success' : 'danger');
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
    toast.setAttribute('role', ok ? 'status' : 'alert');
    var wrap = document.createElement('div');
    wrap.className = 'd-flex';
    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Закрыть');
    wrap.appendChild(body);
    wrap.appendChild(close);
    toast.appendChild(wrap);
    document.body.appendChild(toast);
    new bootstrap.Toast(toast).show();
    toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
  }

  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;width:2px;height:2px;opacity:0;';
    document.body.appendChild(ta);
    ta.focus();
    ta.setSelectionRange(0, ta.value.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  }

  /** Копирует текст; true/false — получилось ли. */
  function copyText(text) {
    text = String(text == null ? '' : text);
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
    }
    return Promise.resolve(legacyCopy(text));
  }

  /** Отметка «Скопировано» на самой кнопке на 1,4 с. */
  function flash(btn, ok) {
    if (!btn) return;
    btn.classList.remove('is-copied', 'is-failed');
    btn.classList.add(ok ? 'is-copied' : 'is-failed');
    clearTimeout(btn._avTimer);
    btn._avTimer = setTimeout(function () { btn.classList.remove('is-copied', 'is-failed'); }, 1400);
  }

  function copyFrom(btn, text, what) {
    return copyText(text).then(function (ok) {
      flash(btn, ok);
      announce(ok ? 'Скопировано: ' + what : 'Не удалось скопировать');
      if (!ok) showToast('Не удалось скопировать — браузер не дал доступ к буферу', 'error');
      return ok;
    });
  }

  function linesOf(pairs) {
    return (pairs || []).map(function (p) { return p[0] + ': ' + p[1]; }).join('\n');
  }

  function onClick(e) {
    var btn = e.target.closest('[data-copy-key], [data-copy-text], [data-copy-totp]');
    if (btn) {
      var text;
      if (btn.hasAttribute('data-copy-totp')) {
        text = (btn.getAttribute('data-code') || '').replace(/\s+/g, '');
        if (!text) return;
      } else if (btn.hasAttribute('data-copy-key')) {
        var k = btn.getAttribute('data-copy-key');
        text = payload && payload.row ? payload.row[k] : '';
      } else {
        text = btn.getAttribute('data-copy-text');
      }
      copyFrom(btn, text, btn.getAttribute('data-copy-label') || 'значение');
      return;
    }

    var toggle = e.target.closest('[data-long-toggle]');
    if (toggle) {
      var box = toggle.closest('.av-long');
      var open = box.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }

    var action = e.target.closest('[data-av-action]');
    if (!action) return;
    var name = action.getAttribute('data-av-action');
    if (name === 'copy-access') {
      if (!payload.access || !payload.access.length) { showToast('Доступов у аккаунта нет', 'error'); return; }
      copyFrom(action, linesOf(payload.access), 'доступы');
    } else if (name === 'copy-all') {
      copyFrom(action, linesOf(payload.all), 'все данные');
    } else if (name === 'copy-json') {
      copyFrom(action, JSON.stringify(payload.row, null, 2), 'JSON');
    } else if (name === 'toggle-empty') {
      setShowEmpty(!document.documentElement.classList.contains('av-show-empty'), true);
    } else if (name === 'duplicate') {
      duplicate(action);
    }
  }

  function setShowEmpty(show, remember) {
    document.documentElement.classList.toggle('av-show-empty', show);
    var btn = document.querySelector('[data-av-action="toggle-empty"]');
    if (btn) btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    if (remember) {
      try { localStorage.setItem(SHOW_EMPTY_KEY, show ? '1' : '0'); } catch (e) { /* приватный режим */ }
    }
  }

  function duplicate(btn) {
    if (!confirm('Создать копию этого аккаунта?')) return;
    btn.disabled = true;
    fetch('duplicate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': payload.csrf },
      body: JSON.stringify({ id: payload.id, csrf_token: payload.csrf })
    }).then(function (r) {
      if (!r.ok) throw new Error('сервер ответил ' + r.status);
      return r.json();
    }).then(function (res) {
      if (!res.success) throw new Error(res.error || 'неизвестная ошибка');
      showToast('Копия создана, открываю её', 'success');
      if (res.new_id) setTimeout(function () { location.href = 'view.php?id=' + encodeURIComponent(res.new_id); }, 900);
    }).catch(function (err) {
      showToast('Не удалось создать копию: ' + err.message, 'error');
    }).then(function () { btn.disabled = false; });
  }

  function startTotp() {
    var boxes = document.querySelectorAll('[data-totp-key]');
    if (!boxes.length) return;
    var subtle = window.isSecureContext && window.crypto && window.crypto.subtle;
    if (!subtle) return; // http без TLS — кода не будет, ключ остаётся виден

    var items = [];
    boxes.forEach(function (box) {
      var key = base32Decode(payload.row[box.getAttribute('data-totp-key')]);
      if (!key || !key.length) return;
      items.push({ box: box, key: key, counter: -1 });
      box.hidden = false;
    });
    if (!items.length) return;

    function tick() {
      var now = Date.now();
      var left = secondsLeft(now);
      var counter = Math.floor(now / 30000);
      items.forEach(function (it) {
        var timer = it.box.querySelector('.av-totp-left');
        if (timer) {
          timer.textContent = left + ' с';
          it.box.style.setProperty('--av-totp-progress', String(left / 30));
          it.box.classList.toggle('is-expiring', left <= 5);
        }
        if (it.counter === counter) return;
        it.counter = counter;
        totp(it.key, now, subtle).then(function (code) {
          var out = it.box.querySelector('.av-totp-code');
          if (out) out.textContent = groupCode(code);
          var copy = it.box.querySelector('[data-copy-totp]');
          if (copy) copy.setAttribute('data-code', code);
        });
      });
    }
    tick();
    setInterval(tick, 1000);
  }

  function setupJsonDownload() {
    var a = document.getElementById('downloadJsonBtn');
    if (!a || !payload) return;
    var blob = new Blob([JSON.stringify(payload.row, null, 2)], { type: 'application/json' });
    a.href = URL.createObjectURL(blob);
  }

  function init() {
    payload = readPayload();
    if (!payload) return;
    // favorites.js берёт CSRF-токен из DashboardConfig, а на этой странице
    // конфига дашборда нет. Без этой строки звёздочка «Избранное» здесь
    // всегда получала 403 «CSRF token is required» (так было и до 19.11.2026).
    window.DashboardConfig = window.DashboardConfig || {};
    if (!window.DashboardConfig.csrfToken) window.DashboardConfig.csrfToken = payload.csrf;
    var show = false;
    try { show = localStorage.getItem(SHOW_EMPTY_KEY) === '1'; } catch (e) { show = false; }
    setShowEmpty(show, false);
    document.addEventListener('click', onClick);
    setupJsonDownload();
    startTotp();
  }

  window.showToast = window.showToast || showToast;
  window.DashboardAccountView = {
    init: init,
    logic: { base32Decode: base32Decode, totp: totp, secondsLeft: secondsLeft, groupCode: groupCode }
  };
})();

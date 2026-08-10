/**
 * login.js — поведение страницы входа. Самодостаточный файл, ничего из
 * дашборда не требует и в его бандлы не входит (см. includes/AssetBundles.php).
 *
 * Что делает:
 *  1. Переключает тему и пишет её в тот же ключ localStorage, что и дашборд
 *     (`dashboard-theme`), — выбор переносится внутрь панели.
 *  2. Разбирает строку подключения на лету и показывает «показания»:
 *     хост / порт / база / пользователь. Разбор ПОВТОРЯЕТ логику
 *     parseConnectionString() из auth.php — набор ключей и значения по
 *     умолчанию обязаны совпадать, иначе панель будет врать о том, куда
 *     пойдёт подключение. Пароль не показывается никогда.
 *  3. Приватный режим — размытие поля (в строке лежит пароль от БД).
 *  4. Состояние отправки формы.
 *
 * Чего здесь осознанно НЕТ: клиентской валидации, блокирующей отправку.
 * Единственный источник правды о корректности строки — сервер; браузер лишь
 * показывает, как он эту строку понял.
 */
(function () {
  'use strict';

  var THEME_KEY = 'dashboard-theme';
  var root = document.documentElement;

  /* ---------------------------------------------------------------- тема */

  function currentTheme() {
    return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
  }

  function syncThemeButton(btn, theme) {
    var dark = theme === 'dark';
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    btn.setAttribute('title', dark ? 'Светлая тема' : 'Тёмная тема');
    btn.setAttribute('aria-label', dark ? 'Включить светлую тему' : 'Включить тёмную тему');
    var icons = btn.querySelectorAll('[data-theme-icon]');
    for (var i = 0; i < icons.length; i++) {
      var isSun = icons[i].getAttribute('data-theme-icon') === 'sun';
      icons[i].style.display = (dark === isSun) ? '' : 'none';
    }
  }

  function initTheme() {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    syncThemeButton(btn, currentTheme());
    btn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-bs-theme', next);
      try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* приватный режим */ }
      syncThemeButton(btn, next);
    });
  }

  /* ------------------------------------------------- разбор строки подключения */

  /**
   * Ключ строки подключения → поле показаний. Набор обязан совпадать с
   * parseConnectionString() в auth.php; расхождение стережёт
   * tests/test_login_conn_keys.php.
   */
  var KEY_MAP = {
    'server': 'host',
    'port': 'port',
    'user id': 'user',
    'userid': 'user',
    'user': 'user',
    'database': 'database',
    'dbname': 'database',
    'initial catalog': 'database'
  };

  /**
   * Ключи, которые сервер понимает, но панель показаний не отображает
   * намеренно: пароль показывать нельзя, кодировка ни о чём не говорит.
   */
  var IGNORED_KEYS = ['password', 'pwd', 'characterset', 'charset'];

  /**
   * Разбирает строку вида `server=host;port=3306;user id=u;database=d`.
   *
   * @param {string} raw
   * @returns {{host: string, port: string, database: string, user: string, unknown: string[]}}
   *          unknown — ключи, которых сервер не знает вовсе: он их молча
   *          пропустит, а человек увидит «Неверный формат» и не поймёт, почему.
   */
  function parseConnectionString(raw) {
    var out = { host: '', port: '', database: '', user: '', unknown: [] };
    var parts = String(raw).split(';');

    for (var i = 0; i < parts.length; i++) {
      var part = parts[i].trim();
      if (!part) continue;

      var eq = part.indexOf('=');
      if (eq === -1) continue;

      var key = part.slice(0, eq).trim().toLowerCase();
      var value = part.slice(eq + 1).trim();

      // hasOwnProperty, а не KEY_MAP[key]: ключ вида `constructor` или
      // `toString` иначе вернул бы метод прототипа и записал мусор в показания.
      if (Object.prototype.hasOwnProperty.call(KEY_MAP, key)) {
        out[KEY_MAP[key]] = value;
      } else if (IGNORED_KEYS.indexOf(key) === -1 && out.unknown.indexOf(key) === -1) {
        out.unknown.push(key);
      }
    }

    // Порт по умолчанию — тот же, что подставит PHP.
    if (out.host && !out.port) out.port = '3306';

    return out;
  }

  function initReadout(input) {
    var cells = document.querySelectorAll('[data-readout]');
    var warn = document.getElementById('connUnknown');
    if (!cells.length) return;

    function render() {
      var parsed = parseConnectionString(input.value);
      for (var i = 0; i < cells.length; i++) {
        var cell = cells[i];
        var value = parsed[cell.getAttribute('data-readout')] || '';
        var slot = cell.querySelector('.readout__val');
        if (slot) {
          slot.textContent = value || '—';
          slot.setAttribute('title', value);
        }
        if (value) cell.classList.add('is-filled');
        else cell.classList.remove('is-filled');
      }

      if (warn) {
        if (parsed.unknown.length) {
          warn.textContent = (parsed.unknown.length === 1 ? 'Ключ не распознан: ' : 'Ключи не распознаны: ')
            + parsed.unknown.join(', ');
          warn.hidden = false;
        } else {
          warn.hidden = true;
          warn.textContent = '';
        }
      }
    }

    input.addEventListener('input', render);
    render(); // строка могла вернуться с сервера после неудачной попытки
  }

  /* ------------------------------------------------------- высота под текст */

  /**
   * Поле растёт под содержимое до предела, дальше начинает прокручиваться.
   * Нативный ресайз выключен в CSS: его уголок ломал стык с панелью показаний.
   *
   * @param {HTMLTextAreaElement} input
   */
  function initAutoGrow(input) {
    var MAX = 260;

    function grow() {
      input.style.height = 'auto';
      var needed = input.scrollHeight;
      input.style.height = Math.min(needed, MAX) + 'px';
      input.style.overflowY = needed > MAX ? 'auto' : 'hidden';
    }

    input.addEventListener('input', grow);
    // При смене ширины строка перевёрстывается: без пересчёта высота осталась бы
    // от прежней ширины и текст обрезался бы (overflow скрыт).
    window.addEventListener('resize', grow);
    grow();
  }

  /* --------------------------------------------------------- приватный режим */

  function initMask(input) {
    var btn = document.getElementById('maskToggle');
    var box = input.closest ? input.closest('.field__box') : null;
    if (!btn || !box) return;

    btn.addEventListener('click', function () {
      var masked = box.classList.toggle('is-masked');
      btn.setAttribute('aria-pressed', masked ? 'true' : 'false');
      var label = btn.querySelector('[data-mask-label]');
      if (label) label.textContent = masked ? 'Показать' : 'Скрыть';
    });
  }

  /* ------------------------------------------------------- подсказка формата */

  function initHint() {
    var hint = document.querySelector('.hint');
    var btn = document.getElementById('hintToggle');
    if (!hint || !btn) return;

    btn.addEventListener('click', function () {
      var open = hint.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* --------------------------------------------- подпись у «запомнить меня» */

  function initRemember() {
    var box = document.getElementById('remember_me');
    var hint = document.querySelector('[data-remember-hint]');
    if (!box || !hint) return;

    var on = hint.getAttribute('data-on') || '';
    var off = hint.getAttribute('data-off') || '';

    function render() { hint.textContent = box.checked ? on : off; }
    box.addEventListener('change', render);
    render();
  }

  /* -------------------------------------------------------- отправка формы */

  function initSubmit() {
    var form = document.getElementById('loginForm');
    var btn = document.getElementById('submitBtn');
    if (!form || !btn) return;

    var idle = btn.innerHTML;

    form.addEventListener('submit', function () {
      // Отправку не отменяем: пустую строку отвергнет сервер с понятным текстом.
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>Подключение';
    });

    // Возврат «назад» отдаёт страницу из bfcache в том виде, в каком её увели, —
    // без этого кнопка навсегда осталась бы серой с надписью «Подключение».
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) {
        btn.disabled = false;
        btn.innerHTML = idle;
      }
    });
  }

  /* ------------------------------------------------------------------ старт */

  function init() {
    initTheme();
    initHint();
    initRemember();
    initSubmit();

    var input = document.getElementById('db_connection_string');
    if (input) {
      initReadout(input);
      initAutoGrow(input);
      initMask(input);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

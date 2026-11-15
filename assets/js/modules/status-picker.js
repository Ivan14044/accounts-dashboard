/**
 * Поле статуса «выбери из списка или впиши свой».
 *
 * Зачем (15.11.2026). В окне «Проверка на валидность» невалидные аккаунты
 * можно было перенести только в статус, который уже есть в базе: обычный
 * выпадающий список из ~100 строк. Нового статуса под свежую пачку (например,
 * «invalid_15_11») там не было — приходилось закрывать окно и переносить в
 * другом месте. Теперь в поле можно набрать любое имя: если такого статуса
 * нет, он появляется в момент переноса (статус — это просто значение в строке
 * аккаунта, отдельного справочника статусов в базе нет).
 *
 * Что поле делает сверх обычного списка:
 *   - ищет по мере набора, понимая русскую раскладку и транслит — тем же
 *     поиском, что фильтр статусов (DashboardStatusFilter.logic), если он есть;
 *   - заранее, под полем, говорит, что будет: «существующий» / «будет создан
 *     новый» / «такой уже есть как …» / что не так с именем;
 *   - не плодит двойники по регистру: «PERECHEK_NEW» при живом «perechek_new»
 *     уходит на сервер как «perechek_new». Коллация MySQL регистронезависимая,
 *     в фильтре и счётчиках такие статусы склеиваются, а в базе жили бы двумя
 *     написаниями — путаница без пользы. Похожие, но не равные имена
 *     («perechek new» при «perechek_new») не подменяются, а только
 *     подсвечиваются с кнопкой «взять его»: это может быть осознанный выбор;
 *   - проверяет имя по тем же правилам, что сервер (Validator::validateStatus):
 *     буквы, цифры, «_», «-», пробел. Длина — до 50 знаков, а не 100, как в
 *     валидаторе: колонка status в эталонной схеме VARCHAR(50), и длинное имя
 *     база молча обрезала бы (или отвергла, смотря по sql_mode);
 *   - помнит последние применённые статусы и ставит их первыми (localStorage,
 *     своя запись для каждой таблицы — по той же причине, что у фильтра
 *     статусов: все работают под одним входом в базу, «личное» на сервере
 *     оказалось бы общим).
 *
 * Чего здесь осознанно НЕТ:
 *   - отправки на сервер: поле только решает, какой статус выбран, применяет
 *     его вызывающий модуль (dashboard-validate.js);
 *   - отправки по Enter: Enter лишь выбирает строку списка. Случайный Enter не
 *     должен переносить сотни аккаунтов.
 *
 *   - показывает рядом с каждым статусом, сколько в нём аккаунтов (по всей
 *     таблице, те же числа, что в фильтре статусов) и обновляет их вместе с
 *     таблицей — после переноса числа сразу новые.
 *
 * Наружу: window.DashboardStatusPicker = { attach, addToSelects, updateCounts, logic }.
 * logic.* — чистые функции без DOM, их гоняет tests/status-picker.test.cjs.
 */
(function () {
  'use strict';

  /** Длина колонки status в эталонной схеме (DatabaseSchemaManager). */
  var MAX_LEN = 50;
  /** Сколько последних применённых статусов помним. */
  var RECENT_MAX = 8;

  // \p{L} в литерале регулярки — синтаксическая ошибка в старом движке, и она
  // уронила бы весь бандл dashboard.sync.js. Поэтому через конструктор в try:
  // нет поддержки — проверку по символам оставляем серверу.
  var ALLOWED = null;
  try { ALLOWED = new RegExp('^[\\p{L}0-9_\\-\\s]+$', 'u'); } catch (e) { ALLOWED = null; }

  // ── Чистая логика ────────────────────────────────────────────────────────

  /** Поиск фильтра статусов, если он загружен; берётся в момент вызова. */
  function filterLogic() {
    var f = typeof window !== 'undefined' ? window.DashboardStatusFilter : null;
    return f && f.logic && typeof f.logic.matchesQuery === 'function' ? f.logic : null;
  }

  function normalize(text) {
    var fl = filterLogic();
    if (fl && typeof fl.normalizeText === 'function') return fl.normalizeText(text);
    return String(text == null ? '' : text).toLowerCase().replace(/[\s_\-.]+/g, ' ').trim();
  }

  function variantsOf(query) {
    var fl = filterLogic();
    if (fl) return fl.queryVariants(query);
    var n = normalize(query);
    return n === '' ? [] : [n];
  }

  /** Убрать пробелы по краям и схлопнуть повторы внутри. */
  function clean(text) {
    return String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
  }

  function charLength(s) {
    // Array.from считает символы, а не UTF-16 половинки (эмодзи — один знак).
    return Array.from ? Array.from(s).length : s.length;
  }

  /**
   * Что выйдет из набранного текста.
   *
   * @param {*} typed что в поле
   * @param {string[]} existing статусы, которые уже есть в таблице
   * @return {{kind: string, value: string, error: (string|null), similar: (string|null)}}
   *   kind: 'empty'    — ничего не выбрано;
   *         'existing' — точное совпадение с существующим;
   *         'case'     — отличается только регистром, value = существующее написание;
   *         'new'      — будет создан, similar = похожий существующий или null;
   *         'invalid'  — имя не пройдёт сервер, error = почему (по-русски).
   *   Существующий статус проходит без проверки знаков: он уже лежит в базе.
   */
  function resolve(typed, existing) {
    var list = Array.isArray(existing) ? existing : [];
    var value = clean(typed);
    var out = { kind: 'empty', value: value, error: null, similar: null };
    if (value === '') return out;

    if (list.indexOf(value) !== -1) { out.kind = 'existing'; return out; }

    var lower = value.toLowerCase();
    for (var i = 0; i < list.length; i++) {
      if (String(list[i]).toLowerCase() === lower) {
        out.kind = 'case';
        out.value = list[i];
        return out;
      }
    }

    if (charLength(value) > MAX_LEN) {
      out.kind = 'invalid';
      out.error = 'Слишком длинное имя: не больше ' + MAX_LEN + ' знаков';
      return out;
    }
    if (ALLOWED && !ALLOWED.test(value)) {
      out.kind = 'invalid';
      out.error = 'Можно только буквы, цифры, пробел, «_» и «-»';
      return out;
    }

    out.kind = 'new';
    var n = normalize(value);
    for (var j = 0; j < list.length; j++) {
      if (normalize(list[j]) === n) { out.similar = list[j]; break; }
    }
    return out;
  }

  /**
   * Список подсказок под полем.
   *
   * @param {string[]} list все статусы в исходном порядке
   * @param {*} query набранный текст
   * @param {string[]} [recent] недавние — встают первыми, если они есть в list
   * @return {string[]} пустой запрос — весь список (недавние сверху); иначе
   *   совпадения: точные, потом начинающиеся с запроса, потом остальные,
   *   внутри группы — в исходном порядке.
   */
  function suggest(list, query, recent) {
    var all = Array.isArray(list) ? list : [];
    var present = {};
    all.forEach(function (s) { present[s] = true; });
    var head = [];
    (Array.isArray(recent) ? recent : []).forEach(function (s) {
      if (present[s] && head.indexOf(s) === -1) head.push(s);
    });
    var base = head.concat(all.filter(function (s) { return head.indexOf(s) === -1; }));

    var variants = variantsOf(query);
    if (variants.length === 0) return base;

    var fl = filterLogic();
    var ranked = [];
    base.forEach(function (s, idx) {
      var matched = fl ? fl.matchesQuery(s, variants) : normalize(s).indexOf(variants[0]) !== -1;
      if (!matched) return;
      var n = normalize(s);
      var rank = 2;
      for (var i = 0; i < variants.length; i++) {
        if (n === variants[i]) { rank = 0; break; }
        if (n.indexOf(variants[i]) === 0) rank = Math.min(rank, 1);
      }
      ranked.push({ s: s, rank: rank, idx: idx });
    });
    ranked.sort(function (a, b) { return a.rank - b.rank || a.idx - b.idx; });
    return ranked.map(function (r) { return r.s; });
  }

  /** Запомнить применённый статус: он встаёт первым, повторов нет, не больше RECENT_MAX. */
  function remember(recent, status) {
    var list = Array.isArray(recent) ? recent : [];
    return [status].concat(list.filter(function (s) { return s !== status; })).slice(0, RECENT_MAX);
  }

  /** Вставить статус в отсортированный без учёта регистра список (как отдаёт сервер). */
  function insertSorted(list, status) {
    var out = list.slice();
    if (out.indexOf(status) !== -1) return out;
    var low = status.toLowerCase();
    var at = out.length;
    for (var i = 0; i < out.length; i++) {
      if (String(out[i]).toLowerCase() > low) { at = i; break; }
    }
    out.splice(at, 0, status);
    return out;
  }

  /**
   * Подпись числа аккаунтов в статусе — тем же форматом, что в фильтре
   * статусов («14,319»), чтобы числа в двух местах читались одинаково.
   *
   * @param {Object<string, number>|null} counts byStatus с сервера (вся таблица, без фильтра)
   * @param {string} status
   * @return {string} нет данных или мусор — «0»
   */
  function countLabel(counts, status) {
    var n = 0;
    if (counts && typeof counts === 'object' && Object.prototype.hasOwnProperty.call(counts, status)) {
      n = Math.floor(Number(counts[status]));
      if (!isFinite(n) || n < 0) n = 0;
    }
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * Список статусов после свежих чисел с сервера: статусы, появившиеся в базе
   * (их создал кто-то другой или внешняя программа), добавляются на своё место
   * по алфавиту. Обнулившиеся НЕ убираются — в статус можно переносить и
   * тогда, когда в нём сейчас никого нет. Пустой статус ('') не статус.
   *
   * @param {string[]} list
   * @param {Object<string, number>|null} counts
   * @return {string[]}
   */
  function mergeStatuses(list, counts) {
    var out = Array.isArray(list) ? list.slice() : [];
    if (!counts || typeof counts !== 'object') return out;
    Object.keys(counts).forEach(function (st) {
      if (st !== '' && Number(counts[st]) > 0) out = insertSorted(out, st);
    });
    return out;
  }

  var logic = {
    MAX_LEN: MAX_LEN,
    countLabel: countLabel,
    mergeStatuses: mergeStatuses,
    RECENT_MAX: RECENT_MAX,
    clean: clean,
    resolve: resolve,
    suggest: suggest,
    remember: remember,
    insertSorted: insertSorted
  };

  // ── Хранилище недавних ───────────────────────────────────────────────────

  function storageKey(table) {
    var prefix = window.LS_KEY_STATUS_APPLIED || 'dashboard_status_applied_v1';
    return prefix + ':' + (table || 'accounts');
  }

  function loadRecent(table) {
    try {
      var raw = window.localStorage.getItem(storageKey(table));
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr.filter(function (s) { return typeof s === 'string' && s !== ''; }).slice(0, RECENT_MAX) : [];
    } catch (e) {
      return []; // запрет хранилища или мусор в нём — просто без недавних
    }
  }

  function saveRecent(table, list) {
    try { window.localStorage.setItem(storageKey(table), JSON.stringify(list)); } catch (e) { /* без недавних */ }
  }

  // ── Живое поле ───────────────────────────────────────────────────────────

  var uid = 0;

  /**
   * Превратить input в поле с подсказками.
   *
   * @param {HTMLInputElement} input
   * @param {Object} opts
   * @param {string[]} opts.statuses статусы таблицы
   * @param {string} [opts.table] имя таблицы — для недавних
   * @param {HTMLElement} [opts.hint] куда писать, что будет
   * @param {function(Object)} [opts.onChange] зовётся с результатом resolve()
   * @return {Object|null} управление полем; null — нет input
   */
  function attach(input, opts) {
    if (!input) return null;
    opts = opts || {};
    var statuses = Array.isArray(opts.statuses) ? opts.statuses.slice() : [];
    var counts = opts.counts && typeof opts.counts === 'object' ? opts.counts : null;
    var table = opts.table || 'accounts';
    var recent = loadRecent(table);
    var hint = opts.hint || null;
    var listId = 'statusPickerList' + (++uid);
    var items = [];       // [{value, create}]
    var active = -1;
    var open = false;

    var wrap = document.createElement('div');
    wrap.className = 'status-picker';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var list = document.createElement('ul');
    list.className = 'status-picker-list';
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    // Список живёт прямо в <body>, а не рядом с полем. position: fixed внутри
    // предка с transform считается от этого предка, а не от окна: в окне
    // проверки showPane() оставляет на панели результатов transform:
    // translateY(0), и список уезжал на 250px вниз за край модалки
    // (замерено на стенде 15.11.2026). Из <body> координаты всегда экранные,
    // и край модалки список не обрезает. Клики по нему модалку не закрывают:
    // Bootstrap закрывает её только по щелчку в саму подложку .modal.
    document.body.appendChild(list);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('maxlength', String(MAX_LEN + 20)); // запас, чтобы показать ошибку длины, а не молча резать

    function current() { return resolve(input.value, statuses); }

    function el(tag, cls, text) {
      var n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text != null) n.textContent = text;
      return n;
    }

    function renderHint(res) {
      if (!hint) return;
      hint.textContent = '';
      hint.className = 'status-picker-hint';
      if (res.kind === 'empty') {
        hint.classList.add('is-muted');
        hint.textContent = 'Выберите из списка или впишите новый статус';
      } else if (res.kind === 'existing') {
        hint.classList.add('is-muted');
        hint.textContent = 'Статус уже есть в базе';
      } else if (res.kind === 'case') {
        hint.classList.add('is-info');
        hint.appendChild(document.createTextNode('Такой статус уже есть — будет записан как '));
        hint.appendChild(el('b', null, '«' + res.value + '»'));
      } else if (res.kind === 'new') {
        hint.classList.add('is-new');
        hint.appendChild(el('i', 'fas fa-plus-circle me-1'));
        hint.appendChild(document.createTextNode('Новый статус — появится при переносе'));
        if (res.similar) {
          hint.appendChild(document.createTextNode('. Похожий уже есть: '));
          var take = el('button', 'status-picker-take', '«' + res.similar + '»');
          take.type = 'button';
          take.title = 'Взять существующий статус';
          take.addEventListener('click', function () { choose(res.similar); input.focus(); });
          hint.appendChild(take);
        }
      } else {
        hint.classList.add('is-error');
        hint.appendChild(el('i', 'fas fa-exclamation-circle me-1'));
        hint.appendChild(document.createTextNode(res.error));
      }
    }

    function notify() {
      var res = current();
      input.classList.toggle('is-invalid', res.kind === 'invalid');
      renderHint(res);
      if (typeof opts.onChange === 'function') opts.onChange(res);
      return res;
    }

    function setActive(i) {
      var nodes = list.querySelectorAll('[role="option"]');
      if (active >= 0 && nodes[active]) nodes[active].classList.remove('is-active');
      active = i;
      if (active >= 0 && nodes[active]) {
        nodes[active].classList.add('is-active');
        input.setAttribute('aria-activedescendant', nodes[active].id);
        nodes[active].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function render() {
      var res = current();
      var query = clean(input.value);
      var names = suggest(statuses, query, recent);
      items = [];
      list.textContent = '';

      if (res.kind === 'new') items.push({ value: res.value, create: true });
      names.forEach(function (s) { items.push({ value: s, create: false }); });

      var recentShown = query === '' ? names.filter(function (s) { return recent.indexOf(s) !== -1; }).length : 0;

      items.forEach(function (it, i) {
        if (recentShown > 0 && i === 0) list.appendChild(el('li', 'status-picker-group', 'Недавние'));
        if (recentShown > 0 && i === recentShown) list.appendChild(el('li', 'status-picker-group', 'Все статусы'));
        var li = el('li', 'status-picker-option' + (it.create ? ' is-create' : ''));
        li.id = listId + '-o' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('data-index', String(i));
        if (it.create) {
          li.appendChild(el('i', 'fas fa-plus me-2'));
          li.appendChild(document.createTextNode('Создать статус '));
          li.appendChild(el('b', null, '«' + it.value + '»'));
        } else {
          li.appendChild(el('span', 'status-picker-name', it.value));
          if (counts) {
            var c = el('span', 'status-picker-count', countLabel(counts, it.value));
            c.title = 'Аккаунтов в статусе';
            li.appendChild(c);
          }
          if (it.value === res.value && (res.kind === 'existing' || res.kind === 'case')) li.classList.add('is-selected');
        }
        list.appendChild(li);
      });

      if (items.length === 0) {
        list.appendChild(el('li', 'status-picker-empty', res.kind === 'invalid' ? res.error : 'Нет статусов'));
      }
      active = -1;
    }

    function place() {
      // Список фиксирован к окну: в модалке он иначе раздувал бы прокрутку
      // окна или обрезался его краем. Места снизу мало — раскрываемся вверх.
      var r = input.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var below = vh - r.bottom - 8;
      var above = r.top - 8;
      var up = below < 200 && above > below;
      var max = Math.max(120, Math.min(300, up ? above : below));
      var vw = window.innerWidth || document.documentElement.clientWidth;
      var width = Math.min(Math.max(r.width, 240), vw - 16);
      list.style.left = Math.round(Math.max(8, Math.min(r.left, vw - width - 8))) + 'px';
      list.style.width = Math.round(width) + 'px';
      list.style.maxHeight = Math.round(max) + 'px';
      if (up) {
        list.style.top = '';
        list.style.bottom = Math.round(vh - r.top + 4) + 'px';
      } else {
        list.style.bottom = '';
        list.style.top = Math.round(r.bottom + 4) + 'px';
      }
    }

    function show() {
      render();
      list.hidden = false;
      open = true;
      input.setAttribute('aria-expanded', 'true');
      place();
      window.addEventListener('resize', place);
      document.addEventListener('scroll', place, true);
    }

    function hide() {
      if (!open) return;
      // (список в <body>: прятать обязательно, иначе он переживёт закрытие окна)
      list.hidden = true;
      open = false;
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      window.removeEventListener('resize', place);
      document.removeEventListener('scroll', place, true);
    }

    function choose(value) {
      input.value = value;
      hide();
      notify();
    }

    input.addEventListener('focus', show);
    input.addEventListener('click', function () { if (!open) show(); });
    input.addEventListener('input', function () {
      if (!open) show(); else { render(); place(); }
      notify();
    });
    input.addEventListener('blur', function () {
      // Чистим лишние пробелы, когда человек ушёл из поля: в поле остаётся
      // ровно то, что уйдёт на сервер.
      var c = clean(input.value);
      if (c !== input.value) { input.value = c; notify(); }
      hide();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open) { show(); return; }
        if (items.length === 0) return;
        var d = e.key === 'ArrowDown' ? 1 : -1;
        var next = active + d;
        if (next < 0) next = items.length - 1;
        if (next >= items.length) next = 0;
        setActive(next);
      } else if (e.key === 'Enter') {
        // Enter выбирает подсвеченную строку и НИКОГДА не применяет перенос.
        e.preventDefault();
        if (open && active >= 0 && items[active]) choose(items[active].value);
        else hide();
      } else if (e.key === 'Escape') {
        if (open) {
          // Закрыть список, а не всё окно (Bootstrap закрывает модалку по Esc).
          e.preventDefault();
          e.stopPropagation();
          hide();
        }
      } else if (e.key === 'Tab') {
        hide();
      }
    });

    // mousedown, а не click: иначе blur поля успевает закрыть список раньше.
    list.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var li = e.target.closest ? e.target.closest('[role="option"]') : null;
      if (!li) return;
      var it = items[parseInt(li.getAttribute('data-index'), 10)];
      if (it) choose(it.value);
    });
    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest ? e.target.closest('[role="option"]') : null;
      if (!li) return;
      var i = parseInt(li.getAttribute('data-index'), 10);
      if (i !== active) setActive(i);
    });

    notify();

    var api = {
      /** @return {Object} результат resolve() для того, что сейчас в поле */
      getResolution: current,
      /** Очистить поле (новая проверка). */
      clear: function () { input.value = ''; hide(); notify(); },
      /** Статус применён: запомнить в недавних и, если новый, добавить в список. */
      applied: function (status) {
        statuses = insertSorted(statuses, status);
        recent = remember(recent, status);
        saveRecent(table, recent);
        notify();
      },
      focus: function () { input.focus(); },
      /** Свежие числа с сервера (byStatus): обновить счётчики и добрать новые статусы. */
      setCounts: function (byStatus) {
        if (!byStatus || typeof byStatus !== 'object') return;
        counts = byStatus;
        statuses = mergeStatuses(statuses, byStatus);
        if (open) { render(); place(); }
      }
    };
    attached.push(api);
    return api;
  }

  /** Все поля на странице — чтобы обновление таблицы раздало им свежие числа. */
  var attached = [];

  /**
   * Свежие числа по статусам после обновления таблицы (зовёт dashboard-refresh.js
   * рядом с DashboardStatusFilter.updateCounts — там те же данные).
   *
   * @param {Object<string, number>} byStatus
   */
  function updateCounts(byStatus) {
    attached.forEach(function (p) { p.setCounts(byStatus); });
  }

  /**
   * Добавить только что созданный статус в обычные выпадающие списки страницы
   * (select[data-status-options]): смена статуса, перенос, своя карточка. Без
   * этого новый статус появлялся бы там только после перезагрузки страницы.
   * Служебные пункты ('' и '__new__') остаются на своих местах.
   *
   * @param {string} status
   * @return {number} в сколько списков добавлен
   */
  function addToSelects(status) {
    var added = 0;
    var selects = document.querySelectorAll('select[data-status-options]');
    Array.prototype.forEach.call(selects, function (sel) {
      var exists = Array.prototype.some.call(sel.options, function (o) { return o.value === status; });
      if (exists) return;
      var opt = document.createElement('option');
      opt.value = status;
      opt.textContent = status;
      var low = status.toLowerCase();
      var before = null;
      for (var i = 0; i < sel.options.length; i++) {
        var o = sel.options[i];
        if (o.value === '') continue;
        if (o.value === '__new__' || o.value.toLowerCase() > low) { before = o; break; }
      }
      sel.insertBefore(opt, before);
      added++;
    });
    return added;
  }

  window.DashboardStatusPicker = {
    attach: attach,
    addToSelects: addToSelects,
    updateCounts: updateCounts,
    logic: logic
  };
})();

/**
 * Список статусов в фильтре дашборда: закреплённые наверху, недавние под
 * ними, «только этот статус» по щелчку на названии, поиск с поправкой на
 * раскладку и транслит, кнопки «Все»/«Очистить», подпись на кнопке списка и
 * счётчики в строках.
 *
 * Зачем (22.09.2026). На рабочей панели в списке 101 статус по алфавиту, за
 * раз видно около десяти — до конца восемь-девять экранов прокрутки. Владелец
 * по нескольку раз в день ходит по одним и тем же пяти статусам и каждый раз
 * листал или набирал имя. Там же нашлись две поломки: «Все»/«Очистить» меняли
 * галочки, но не применяли фильтр, а счётчик «Пустой статус» после обновления
 * таблицы становился нулём (подробности — tests/test_status_filter_markup.php).
 *
 * Что здесь есть:
 *   logic.*          — чистые функции без DOM, их гоняет tests/status-filter.test.cjs;
 *   init()           — связывает логику с разметкой templates/partials/dashboard/filters.php;
 *   updateCounts()   — счётчики в строках после обновления таблицы (зовёт dashboard-refresh.js);
 *   updateLabel()    — подпись на кнопке по текущим галочкам (зовёт syncFormFromUrl).
 *
 * Чего здесь осознанно НЕТ:
 *   - самого применения фильтра: URL и запрос строит filters-modern.js
 *     (applyFormFiltersWithoutReload), модуль лишь решает, какие галочки стоят;
 *   - хранения на сервере. Закреплённые и недавние живут в браузере
 *     (localStorage, отдельно для каждой таблицы). В журнале действий все
 *     правки записаны под одним и тем же входом в базу, поэтому «личная»
 *     настройка на сервере оказалась бы общей для всех сотрудников. Выбор
 *     владельца от 22.09.2026: у каждого браузера свой список.
 *
 * Каждый статус живёт в списке ровно одной строкой. Разделы собираются
 * ПЕРЕНОСОМ существующих строк, а не копированием: две галочки на один статус
 * разошлись бы, и в URL ушёл бы не тот набор.
 *
 * Зависимости (берутся в момент вызова, не при загрузке файла):
 *   window.LS_KEY_STATUS_QUICK — modules/constants.js;
 *   DashboardRefresh.onAfterRefresh — modules/dashboard-refresh.js (недавние);
 *   applyFormFiltersWithoutReload, getStatusValuesFromUrl — filters-modern.js;
 *   bootstrap.Dropdown — вендор; showToast — если есть на странице.
 */
(function () {
  'use strict';

  /** Сколько недавних показываем в списке. */
  var RECENT_LIMIT = 5;
  /** Сколько недавних помним: часть из них может оказаться закреплённой. */
  var RECENT_STORE_LIMIT = 20;
  /** Выбрали больше — это не «зашёл в статус», а выборка; в недавние не пишем. */
  var MAX_VISIT_STATUSES = 3;
  /** Защита от разросшегося мусора в хранилище. */
  var MAX_PINNED = 100;

  /** Движение строк — тот же темп и кривая, что у поля года Fan Page (filters-modern.js). */
  var MOTION_MS = 280;
  var FLIGHT_MS = 360;
  var MOTION_EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';

  var EMPTY_LABEL = 'Пустой статус';

  // ── Поиск ────────────────────────────────────────────────────────────────

  // Клавиши русской раскладки → те же клавиши в английской. Статусы пишутся
  // латиницей, и «зщлгзфд» — это «pokupal», набранный без переключения.
  var RU_KEYS = 'ёйцукенгшщзхъфывапролджэячсмитьбю';
  var EN_KEYS = "`qwertyuiop[]asdfghjkl;'zxcvbnm,.";

  // Транслит по звучанию — так, как названы статусы на панели:
  // perechek, nuzhen_telefon, privyazalsya, rychnoy, pochta.
  var TRANSLIT = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh',
    'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
    'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'c',
    'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu',
    'я': 'ya'
  };

  /**
   * Привести текст к виду для сравнения: нижний регистр, пробелы, «_», «-» и
   * «.» считаются одним разделителем. «Perechek_New» и «perechek new» равны.
   *
   * @param {*} text
   * @return {string}
   */
  function normalizeText(text) {
    return String(text == null ? '' : text).toLowerCase().replace(/[\s_\-.]+/g, ' ').trim();
  }

  function mapChars(text, fn) {
    var out = '';
    for (var i = 0; i < text.length; i++) out += fn(text.charAt(i));
    return out;
  }

  function toEnglishLayout(text) {
    return mapChars(text, function (ch) {
      var i = RU_KEYS.indexOf(ch);
      return i === -1 ? ch : EN_KEYS.charAt(i);
    });
  }

  function transliterate(text) {
    return mapChars(text, function (ch) {
      return Object.prototype.hasOwnProperty.call(TRANSLIT, ch) ? TRANSLIT[ch] : ch;
    });
  }

  /**
   * Варианты того, что имел в виду человек: как набрано, как если бы
   * раскладка была английской, и русские буквы, переписанные латиницей.
   *
   * @param {*} query строка из поля поиска
   * @return {string[]} нормализованные варианты без повторов; пустой запрос — []
   */
  function queryVariants(query) {
    var raw = String(query == null ? '' : query).toLowerCase();
    if (raw.trim() === '') return [];
    var out = [];
    [raw, toEnglishLayout(raw), transliterate(raw)].forEach(function (v) {
      var n = normalizeText(v);
      if (n !== '' && out.indexOf(n) === -1) out.push(n);
    });
    return out;
  }

  /**
   * Подходит ли статус под запрос. Пустой список вариантов — «показать всё».
   *
   * @param {string} name имя статуса
   * @param {string[]} variants результат queryVariants()
   * @return {boolean}
   */
  function matchesQuery(name, variants) {
    if (!variants || variants.length === 0) return true;
    var n = normalizeText(name);
    for (var i = 0; i < variants.length; i++) {
      if (n.indexOf(variants[i]) !== -1) return true;
    }
    return false;
  }

  // ── Состояние: закреплённые и недавние ───────────────────────────────────

  /** @return {{pinned: string[], recent: string[]}} */
  function emptyState() {
    return { pinned: [], recent: [] };
  }

  /** Непустые строки без повторов, не длиннее limit. Всегда новый массив. */
  function uniqueStrings(list, limit) {
    var out = [];
    if (!Array.isArray(list)) return out;
    for (var i = 0; i < list.length && out.length < limit; i++) {
      var v = list[i];
      if (typeof v === 'string' && v !== '' && out.indexOf(v) === -1) out.push(v);
    }
    return out;
  }

  /**
   * Очистить состояние от мусора (руками правленный localStorage, старый
   * формат). Возвращает НОВЫЙ объект — входной не меняется.
   */
  function sanitizeState(raw) {
    var src = raw && typeof raw === 'object' ? raw : {};
    return {
      pinned: uniqueStrings(src.pinned, MAX_PINNED),
      recent: uniqueStrings(src.recent, RECENT_STORE_LIMIT)
    };
  }

  /**
   * Закрепить статус (встаёт в конец закреплённых) или открепить, если уже
   * закреплён. Порядок — порядок закрепления: список не прыгает.
   *
   * @return {{pinned: string[], recent: string[]}} новое состояние
   */
  function togglePin(state, status) {
    var s = sanitizeState(state);
    if (typeof status !== 'string' || status === '') return s;
    var i = s.pinned.indexOf(status);
    if (i === -1) {
      if (s.pinned.length < MAX_PINNED) s.pinned.push(status);
    } else {
      s.pinned.splice(i, 1);
    }
    return s;
  }

  /**
   * Записать «зашёл в статус»: открытые статусы встают в начало недавних.
   * Выбор больше MAX_VISIT_STATUSES (например, кнопкой «Все») не пишется —
   * иначе один щелчок вытеснил бы все настоящие недавние.
   *
   * @param {Object} state
   * @param {string[]} statuses статусы из текущего фильтра
   * @return {{pinned: string[], recent: string[]}} новое состояние
   */
  function recordVisit(state, statuses) {
    var s = sanitizeState(state);
    var list = uniqueStrings(statuses, MAX_VISIT_STATUSES + 1);
    if (list.length === 0 || list.length > MAX_VISIT_STATUSES) return s;
    s.recent = list.concat(s.recent.filter(function (x) { return list.indexOf(x) === -1; }))
      .slice(0, RECENT_STORE_LIMIT);
    return s;
  }

  /**
   * Разложить статусы по разделам списка.
   *
   * Закреплённый статус, которого сейчас нет в базе (все аккаунты ушли из
   * него), остаётся в разделе с пометкой missing — молча он не пропадает.
   * Недавний, которого нет, просто не показывается.
   *
   * @param {string[]} allStatuses статусы с сервера, в его порядке (по алфавиту)
   * @param {Object} state
   * @param {number} recentLimit
   * @return {{pinned: {status: string, missing: boolean}[], recent: string[], rest: string[]}}
   */
  function computeSections(allStatuses, state, recentLimit) {
    var s = sanitizeState(state);
    var all = uniqueStrings(allStatuses, Infinity);
    var present = Object.create(null);
    all.forEach(function (st) { present[st] = true; });

    var taken = Object.create(null);
    var pinned = s.pinned.map(function (st) {
      taken[st] = true;
      return { status: st, missing: !present[st] };
    });
    var limit = typeof recentLimit === 'number' ? recentLimit : RECENT_LIMIT;
    var recent = s.recent.filter(function (st) { return present[st] && !taken[st]; }).slice(0, limit);
    recent.forEach(function (st) { taken[st] = true; });
    var rest = all.filter(function (st) { return !taken[st]; });
    return { pinned: pinned, recent: recent, rest: rest };
  }

  // ── Хранение ─────────────────────────────────────────────────────────────

  /**
   * Ключ localStorage для таблицы. Префикс — из constants.js; нет его —
   * null, и список честно работает без памяти, а не пишет под чужой ключ.
   */
  function storageKey(table) {
    var prefix = typeof window !== 'undefined' ? window.LS_KEY_STATUS_QUICK : '';
    if (!prefix) return null;
    return prefix + ':' + (table ? String(table) : 'accounts');
  }

  /**
   * Прочитать состояние. Никогда не бросает: испорченные данные, запрет
   * браузера (приватный режим) — пустое состояние, список работает как раньше.
   */
  function loadState(storage, table) {
    var key = storageKey(table);
    if (!storage || !key) return emptyState();
    try {
      var raw = storage.getItem(key);
      return raw ? sanitizeState(JSON.parse(raw)) : emptyState();
    } catch (e) {
      return emptyState();
    }
  }

  /**
   * Сохранить состояние.
   *
   * @return {boolean} false — браузер не дал записать; закрепление доживёт
   *                   только до перезагрузки, и об этом надо сказать человеку
   */
  function saveState(storage, table, state) {
    var key = storageKey(table);
    if (!storage || !key) return false;
    try {
      storage.setItem(key, JSON.stringify(sanitizeState(state)));
      return true;
    } catch (e) {
      return false;
    }
  }

  // ── Подпись и счётчики ───────────────────────────────────────────────────

  /**
   * Подпись на кнопке списка. Один статус — его имя: сразу видно, где ты.
   * Та же логика на сервере — status_filter_label() в includes/Utils.php.
   */
  function labelFor(statuses, emptySelected) {
    var list = uniqueStrings(statuses, Infinity);
    var n = list.length + (emptySelected ? 1 : 0);
    if (n === 0) return 'Все статусы';
    if (n === 1) return list.length === 1 ? list[0] : EMPTY_LABEL;
    return 'Выбрано: ' + n;
  }

  /**
   * Число аккаунтов в статусе из ответа refresh.php. Пустой статус сервер
   * кладёт под ключ '' (StatisticsService склеивает NULL и ''), а строка
   * списка помечена '__empty__' — отсюда был ноль после обновления таблицы.
   */
  function statusCountFor(byStatus, key) {
    if (!byStatus || typeof byStatus !== 'object') return 0;
    var k = key === '__empty__' ? '' : String(key);
    if (!Object.prototype.hasOwnProperty.call(byStatus, k)) return 0;
    var v = Number(byStatus[k]);
    return isFinite(v) && v > 0 ? Math.floor(v) : 0;
  }

  /** Как number_format() в PHP: 14319 → «14,319» — так рисует шаблон. */
  function formatCount(n) {
    var v = Math.floor(Number(n));
    if (!isFinite(v) || v < 0) v = 0;
    return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  var logic = {
    RECENT_LIMIT: RECENT_LIMIT,
    RECENT_STORE_LIMIT: RECENT_STORE_LIMIT,
    MAX_VISIT_STATUSES: MAX_VISIT_STATUSES,
    normalizeText: normalizeText,
    queryVariants: queryVariants,
    matchesQuery: matchesQuery,
    emptyState: emptyState,
    sanitizeState: sanitizeState,
    togglePin: togglePin,
    recordVisit: recordVisit,
    computeSections: computeSections,
    storageKey: storageKey,
    loadState: loadState,
    saveState: saveState,
    labelFor: labelFor,
    statusCountFor: statusCountFor,
    formatCount: formatCount
  };

  // ── Живой список ─────────────────────────────────────────────────────────

  var ui = null;
  var state = emptyState();
  var table = 'accounts';
  var baseOrder = [];                  // статусы с сервера, по алфавиту
  var rowsByStatus = Object.create(null);
  var ghostSeq = 0;
  var dirty = false;                   // раскладку надо обновить при закрытии списка

  function warn() {
    if (typeof logger !== 'undefined' && logger.warn) logger.warn.apply(logger, arguments);
  }

  function browserStorage() {
    try {
      return window.localStorage || null;
    } catch (e) {
      return null; // доступ к localStorage сам по себе может бросить (запрет cookies)
    }
  }

  function reducedMotion() {
    var media = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // «Лёгкая панель» (dashboard-init.js) — тоже просьба обойтись без движения.
    return media || document.body.classList.contains('dashboard-perf-light');
  }

  function isOpen() {
    return !!(ui && ui.menu.classList.contains('show'));
  }

  function statusOf(row) {
    return row.getAttribute('data-status-value') || '';
  }

  function allStatusRows() {
    return Array.prototype.slice.call(ui.menu.querySelectorAll('.status-checkbox-item[data-status-value]'));
  }

  function checkboxes() {
    var menu = ui ? ui.menu : document.querySelector('.status-dropdown-menu');
    return menu ? Array.prototype.slice.call(menu.querySelectorAll('input.status-checkbox')) : [];
  }

  function statusesInUrl(search) {
    var params = new URLSearchParams(search == null ? window.location.search : search);
    if (typeof window.getStatusValuesFromUrl === 'function') return window.getStatusValuesFromUrl(params);
    return params.getAll('status[]');
  }

  /**
   * Подпись на кнопке списка по текущим галочкам. Полное имя — ещё и в
   * подсказке: длинный статус на узкой кнопке обрезается многоточием.
   */
  function updateLabel() {
    var label = document.getElementById('statusDropdownLabel');
    if (!label) return;
    var selected = [];
    var empty = false;
    checkboxes().forEach(function (cb) {
      if (!cb.checked) return;
      if (cb.name === 'empty_status') empty = true;
      else selected.push(cb.value);
    });
    var text = labelFor(selected, empty);
    label.textContent = text;
    var toggle = document.getElementById('statusDropdown');
    if (toggle) toggle.setAttribute('title', text);
  }

  /**
   * Обновить числа в строках по ответу refresh.php.
   *
   * @param {Object<string, number>} byStatus
   */
  function updateCounts(byStatus) {
    if (!byStatus || typeof byStatus !== 'object') return;
    var els = document.querySelectorAll('.status-count');
    Array.prototype.forEach.call(els, function (el) {
      el.textContent = formatCount(statusCountFor(byStatus, el.getAttribute('data-status')));
    });
  }

  /** Применить галочки как фильтр сразу, без задержки. */
  function applyNow() {
    if (ui && ui.form && typeof window.applyFormFiltersWithoutReload === 'function') {
      window.applyFormFiltersWithoutReload(ui.form);
    } else {
      warn('Список статусов: applyFormFiltersWithoutReload недоступна, фильтр не применён');
    }
  }

  /**
   * Кнопки «Все» и «Очистить». Раньше они только меняли галочки: присваивание
   * .checked не порождает change, и фильтр не применялся вовсе, а надпись уже
   * говорила «Все статусы». Опустевшие закреплённые в «Все» не входят — в
   * них ноль аккаунтов, и в адресе они были бы лишним шумом.
   */
  function setAllStatuses(checked) {
    checkboxes().forEach(function (cb) {
      var row = cb.closest('.status-checkbox-item');
      cb.checked = checked && !(row && row.classList.contains('is-missing'));
    });
    updateLabel();
    applyNow();
  }

  /**
   * «Только этот статус»: снять остальные галочки, применить, закрыть список.
   * Так переход на другой статус — один щелчок, без поиска старой галочки.
   */
  function selectOnly(target, fromKeyboard) {
    checkboxes().forEach(function (cb) { cb.checked = cb === target; });
    updateLabel();
    applyNow();
    if (window.bootstrap && window.bootstrap.Dropdown) {
      window.bootstrap.Dropdown.getOrCreateInstance(ui.toggle).hide();
    }
    if (fromKeyboard) ui.toggle.focus();
  }

  // ── Раскладка по разделам ────────────────────────────────────────────────

  function syncPinButton(row, pinned) {
    var btn = row.querySelector('.status-pin-btn');
    var status = statusOf(row);
    row.classList.toggle('is-pinned', pinned);
    if (!btn) return;
    btn.setAttribute('aria-pressed', pinned ? 'true' : 'false');
    btn.setAttribute('aria-label', (pinned ? 'Открепить статус ' : 'Закрепить статус ') + status);
    btn.setAttribute('title', pinned ? 'Открепить' : 'Закрепить наверху');
  }

  /**
   * Строка для закреплённого статуса, которого сейчас нет в базе. Делается
   * клоном настоящей строки, чтобы разметка не разошлась с шаблоном.
   */
  function ghostRow(status) {
    var sample = baseOrder.length ? rowsByStatus[baseOrder[0]] : null;
    if (!sample) return null;
    var row = sample.cloneNode(true);
    var id = 'status_pinned_missing_' + (++ghostSeq);
    row.setAttribute('data-status-value', status);
    row.classList.add('is-missing');
    row.hidden = false;
    row.setAttribute('title', 'Сейчас в этом статусе нет аккаунтов');
    var cb = row.querySelector('input.status-checkbox');
    cb.value = status;
    cb.id = id;
    cb.checked = statusesInUrl().indexOf(status) !== -1;
    var label = row.querySelector('label');
    if (label) label.htmlFor = id;
    var name = row.querySelector('.status-name');
    if (name) name.textContent = status;
    var count = row.querySelector('.status-count');
    if (count) {
      count.setAttribute('data-status', status);
      count.textContent = '0';
    }
    return row;
  }

  function rowFor(status, missing) {
    if (!rowsByStatus[status] && missing) {
      var ghost = ghostRow(status);
      if (!ghost) return null;
      rowsByStatus[status] = ghost;
    }
    return rowsByStatus[status] || null;
  }

  /** Поставить строки в контейнер ровно в этом порядке (лишние DOM-операции не делаем). */
  function placeRows(list, rows) {
    rows.forEach(function (row, i) {
      if (list.children[i] !== row) list.insertBefore(row, list.children[i] || null);
    });
  }

  function layoutSections() {
    var sec = computeSections(baseOrder, state, RECENT_LIMIT);
    var pinnedRows = sec.pinned.map(function (p) { return rowFor(p.status, p.missing); }).filter(Boolean);
    var pinnedSet = Object.create(null);
    sec.pinned.forEach(function (p) { pinnedSet[p.status] = true; });

    placeRows(ui.sections.pinned.list, pinnedRows);
    placeRows(ui.sections.recent.list, sec.recent.map(function (st) { return rowsByStatus[st]; }));
    placeRows(ui.sections.all.list, sec.rest.map(function (st) { return rowsByStatus[st]; }));

    // Открепили опустевший статус — его строке больше негде жить.
    Object.keys(rowsByStatus).forEach(function (st) {
      var row = rowsByStatus[st];
      if (row.classList.contains('is-missing') && !pinnedSet[st]) {
        row.remove();
        delete rowsByStatus[st];
      }
    });
    Object.keys(rowsByStatus).forEach(function (st) {
      syncPinButton(rowsByStatus[st], !!pinnedSet[st]);
    });
    applySearch();
    dirty = false;
  }

  function visibleRowsIn(list) {
    return Array.prototype.filter.call(list.children, function (row) { return !row.hidden; });
  }

  /**
   * Показать строки под текущий запрос и спрятать пустые разделы.
   *
   * @return {HTMLElement|null} первая видимая строка (для Enter в поиске)
   */
  function applySearch() {
    var variants = queryVariants(ui.search ? ui.search.value : '');
    var searching = variants.length > 0;
    allStatusRows().forEach(function (row) {
      row.hidden = !matchesQuery(statusOf(row), variants);
    });
    if (ui.emptyRow) ui.emptyRow.hidden = !matchesQuery(EMPTY_LABEL, variants);

    var pinnedVisible = visibleRowsIn(ui.sections.pinned.list).length;
    var recentVisible = visibleRowsIn(ui.sections.recent.list).length;
    var restVisible = visibleRowsIn(ui.sections.all.list).length;
    var noPins = ui.sections.pinned.list.children.length === 0;

    // Подсказка про булавку — только пока ничего не закреплено и нет поиска.
    if (ui.hint) ui.hint.hidden = !(noPins && !searching);
    ui.sections.pinned.root.hidden = !(pinnedVisible > 0 || (noPins && !searching));
    ui.sections.recent.root.hidden = recentVisible === 0;
    var anyAbove = !ui.sections.pinned.root.hidden || !ui.sections.recent.root.hidden;
    ui.sections.all.title.hidden = !(anyAbove && restVisible > 0);
    if (ui.nothing) {
      var emptyRowHidden = !ui.emptyRow || ui.emptyRow.hidden;
      ui.nothing.hidden = !(searching && pinnedVisible + recentVisible + restVisible === 0 && emptyRowHidden);
    }

    var order = visibleRowsIn(ui.sections.pinned.list)
      .concat(visibleRowsIn(ui.sections.recent.list), visibleRowsIn(ui.sections.all.list));
    if (ui.emptyRow && !ui.emptyRow.hidden && searching) order.unshift(ui.emptyRow);
    return order[0] || null;
  }

  // ── Движение ─────────────────────────────────────────────────────────────

  /** Всё, что может сдвинуться при перекладке: строки, заголовки, подсказка. */
  function movableItems() {
    var items = allStatusRows();
    ['pinned', 'recent', 'all'].forEach(function (key) {
      if (ui.sections[key].title) items.push(ui.sections[key].title);
    });
    if (ui.hint) items.push(ui.hint);
    return items;
  }

  function isShown(el) {
    return el.isConnected && !el.hidden && !el.closest('[hidden]');
  }

  function stopMotion(el) {
    if (el._sfMove) { el._sfMove.cancel(); el._sfMove = null; }
    if (el._sfFlash) { el._sfFlash.cancel(); el._sfFlash = null; }
    el.classList.remove('is-flying');
  }

  /**
   * Короткая подсветка на месте, куда встала строка: глаз находит её сразу.
   * Цвет — из CSS (--sf-landing), у светлой и тёмной темы он свой. Это смена
   * цвета, а не движение, поэтому остаётся и при «уменьшить движение».
   */
  function flash(row, delay) {
    if (!row || typeof row.animate !== 'function' || !row.isConnected) return;
    var css = window.getComputedStyle(ui.menu);
    var from = css.getPropertyValue('--sf-landing').trim() || 'rgba(79, 70, 229, 0.16)';
    var to = css.getPropertyValue('--sf-landing-end').trim() || 'rgba(79, 70, 229, 0)';
    row._sfFlash = row.animate(
      [{ backgroundColor: from }, { backgroundColor: to }],
      { duration: 900, delay: delay || 0, easing: 'ease-out' }
    );
  }

  /**
   * Переложить строки и показать, куда что уехало (FLIP): запоминаем, где
   * всё было, перекладываем, затем плавно ведём каждый элемент из старого
   * положения в новое. Место в длинном списке держим сами (якорь — первая
   * видимая строка): иначе закрепление строки из середины сдвигало бы весь
   * видимый кусок на её высоту. Родной «scroll anchoring» отключён в CSS
   * (overflow-anchor: none) — в Safari его нет, а в Chrome он сложился бы с нашим.
   *
   * @param {Function} mutate перекладка DOM
   * @param {HTMLElement|null} moved строка, которую закрепили или открепили
   */
  function animateLayout(mutate, moved) {
    var menu = ui.menu;
    var items = movableItems();
    var menuRect = menu.getBoundingClientRect();
    var before = new Map();
    var anchor = null;
    items.forEach(function (el) {
      if (!isShown(el)) return;
      var r = el.getBoundingClientRect();
      before.set(el, r);
      if (!anchor && el !== moved && el.classList.contains('status-checkbox-item') && r.bottom > menuRect.top + 1) {
        anchor = el;
      }
    });
    var anchorTop = anchor ? before.get(anchor).top : 0;
    items.forEach(stopMotion);

    mutate();

    if (anchor && isShown(anchor)) {
      var shift = anchor.getBoundingClientRect().top - anchorTop;
      if (shift) menu.scrollTop += shift;
    }

    if (reducedMotion() || typeof menu.animate !== 'function') {
      flash(moved, 0);
      return;
    }

    // Элементы, которые и до, и после вне видимой части списка, не трогаем.
    var top = menuRect.top - 40;
    var bottom = menuRect.bottom + 40;
    var outside = function (r) { return r.bottom < top || r.top > bottom; };

    movableItems().forEach(function (el) {
      if (!isShown(el)) return;
      var a = el.getBoundingClientRect();
      var b = before.get(el);
      if (!b) {
        // Появился (первый закреплённый — заголовок раздела): мягко проявляем.
        if (outside(a)) return;
        el._sfMove = el.animate(
          [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }],
          { duration: 200, easing: MOTION_EASE }
        );
        return;
      }
      var dy = b.top - a.top;
      if (Math.abs(dy) < 0.5 || (outside(a) && outside(b))) return;
      if (el === moved) {
        // Сама строка «приподнимается» над соседями и летит на новое место.
        el.classList.add('is-flying');
        el._sfMove = el.animate([
          { transform: 'translateY(' + dy + 'px)', boxShadow: '0 0 0 rgba(15, 23, 42, 0)' },
          { offset: 0.3, transform: 'translateY(' + (dy * 0.72) + 'px) scale(1.015)', boxShadow: '0 8px 22px rgba(15, 23, 42, 0.18)' },
          { transform: 'none', boxShadow: '0 0 0 rgba(15, 23, 42, 0)' }
        ], { duration: FLIGHT_MS, easing: MOTION_EASE });
        el._sfMove.onfinish = function () { el.classList.remove('is-flying'); };
        el._sfMove.oncancel = el._sfMove.onfinish;
        return;
      }
      el._sfMove = el.animate(
        [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'none' }],
        { duration: MOTION_MS, easing: MOTION_EASE }
      );
    });
    flash(moved, moved && moved._sfMove ? FLIGHT_MS - 80 : 0);
  }

  function render(animated, moved) {
    if (!ui) return;
    if (animated && isOpen()) animateLayout(layoutSections, moved || null);
    else layoutSections();
  }

  function announce(text) {
    if (ui.live) ui.live.textContent = text;
  }

  function persist() {
    var ok = saveState(browserStorage(), table, state);
    if (!ok && typeof window.showToast === 'function') {
      window.showToast('Браузер не даёт запомнить закрепление — после перезагрузки страницы оно пропадёт', 'warning');
    }
    return ok;
  }

  function togglePinFor(btn) {
    var row = btn.closest('.status-checkbox-item');
    var status = row ? statusOf(row) : '';
    if (!status) return;
    var hadFocus = document.activeElement === btn;
    var wasPinned = state.pinned.indexOf(status) !== -1;
    state = togglePin(state, status);
    persist();
    render(true, row);
    // Перенос строки в DOM снимает с неё фокус — возвращаем, чтобы с
    // клавиатуры можно было сразу открепить обратно.
    if (hadFocus && row.isConnected) btn.focus({ preventScroll: true });
    announce((wasPinned ? 'Статус откреплён: ' : 'Статус закреплён наверху: ') + status);
  }

  function recordVisitFrom(search) {
    var next = recordVisit(state, statusesInUrl(search));
    if (next.recent.join('\n') === state.recent.join('\n')) return;
    state = next;
    saveState(browserStorage(), table, state);
    // Пока список открыт, строки под рукой не двигаем — переложим при закрытии.
    if (isOpen()) dirty = true;
    else layoutSections();
  }

  // ── Клавиатура ───────────────────────────────────────────────────────────

  function focusableCheckboxes() {
    return checkboxes().filter(function (cb) {
      var row = cb.closest('.status-checkbox-item');
      return row && isShown(row);
    });
  }

  function onKeydown(e) {
    var t = e.target;
    if (t === ui.search) {
      if (e.key === 'Enter') {
        // Enter в поле поиска отправил бы форму фильтров — не даём. С пустым
        // полем Enter ничего не выбирает: «первый в списке» тут случаен.
        e.preventDefault();
        if (queryVariants(ui.search.value).length === 0) return;
        var first = applySearch();
        var cb = first ? first.querySelector('input.status-checkbox') : null;
        if (cb) selectOnly(cb, true);
      } else if (e.key === 'ArrowDown') {
        var list = focusableCheckboxes();
        if (list.length) { e.preventDefault(); list[0].focus(); }
      }
      return;
    }
    if (!t.classList || !t.classList.contains('status-checkbox')) return;
    if (e.key === 'Enter') {
      // Enter на строке — «только этот», пробел — как и раньше, галочка.
      e.preventDefault();
      selectOnly(t, true);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      var all = focusableCheckboxes();
      var i = all.indexOf(t) + (e.key === 'ArrowDown' ? 1 : -1);
      e.preventDefault();
      if (i >= 0 && i < all.length) all[i].focus();
      else if (i < 0 && ui.search) ui.search.focus();
    }
  }

  // ── Подключение ──────────────────────────────────────────────────────────

  function bind() {
    // Клик внутри списка не должен его закрывать (Bootstrap закрывает по
    // клику в document) — так было и до модуля.
    ui.menu.addEventListener('click', function (e) {
      e.stopPropagation();
      var t = e.target;
      var pin = t.closest('.status-pin-btn');
      if (pin) { e.preventDefault(); togglePinFor(pin); return; }
      if (t.closest('#selectAllStatusesBtn')) { setAllStatuses(true); return; }
      if (t.closest('#clearAllStatusesBtn')) { setAllStatuses(false); return; }
      if (t.closest('#statusSearchClear')) {
        e.preventDefault();
        ui.search.value = '';
        applySearch();
        ui.search.focus();
        return;
      }
      var label = t.closest('.status-checkbox-item .form-check-label');
      if (label) {
        // Щелчок по названию — «только этот». Отменяем родное переключение
        // галочки через label[for]; сама галочка слева по-прежнему добавляет
        // статус к выбранным.
        var cb = label.closest('.status-checkbox-item').querySelector('input.status-checkbox');
        if (cb) { e.preventDefault(); selectOnly(cb, false); }
      }
    });

    ui.menu.addEventListener('change', function (e) {
      if (e.target.classList && e.target.classList.contains('status-checkbox')) updateLabel();
    });
    ui.menu.addEventListener('keydown', onKeydown);
    if (ui.search) ui.search.addEventListener('input', applySearch);

    ui.toggle.addEventListener('show.bs.dropdown', function () {
      if (dirty) layoutSections();
      ui.menu.scrollTop = 0; // закреплённые — наверху, туда и открываемся
    });
    ui.toggle.addEventListener('shown.bs.dropdown', function () {
      // На телефоне фокус в поле поиска поднял бы клавиатуру поверх списка.
      var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
      if (ui.search && finePointer) ui.search.focus({ preventScroll: true });
    });
    ui.toggle.addEventListener('hidden.bs.dropdown', function () {
      if (ui.search && ui.search.value !== '') ui.search.value = '';
      if (dirty) layoutSections();
      else applySearch();
    });

    // «Зашёл в статус» = его аккаунты легли на экран. Отменённый более свежим
    // или упавший запрос в недавние не пишем (applied === false).
    if (window.DashboardRefresh && typeof window.DashboardRefresh.onAfterRefresh === 'function') {
      window.DashboardRefresh.onAfterRefresh(function (info) {
        if (info && info.applied) recordVisitFrom(info.search);
      });
    } else {
      warn('Список статусов: нет DashboardRefresh.onAfterRefresh — недавние пишутся только при загрузке страницы');
    }
    // Закрепили в соседней вкладке — подхватываем без перезагрузки.
    window.addEventListener('storage', function (e) {
      if (e.key !== storageKey(table)) return;
      state = loadState(browserStorage(), table);
      if (isOpen()) render(true, null);
      else layoutSections();
    });
  }

  /**
   * Подключить список. Без разметки (другие страницы) молча ничего не делает.
   * Повторный вызов безопасен.
   */
  function init() {
    if (ui) return;
    var toggle = document.getElementById('statusDropdown');
    var menu = document.querySelector('.status-dropdown-menu');
    if (!toggle || !menu) return;
    var section = function (key) {
      var root = menu.querySelector('[data-status-section="' + key + '"]');
      return root ? {
        root: root,
        title: root.querySelector('.status-section-title'),
        list: root.querySelector('.status-section-list')
      } : null;
    };
    var sections = { pinned: section('pinned'), recent: section('recent'), all: section('all') };
    if (!sections.pinned || !sections.recent || !sections.all || !sections.all.list) {
      warn('Список статусов: нет разделов в разметке, закрепление отключено');
      return;
    }
    ui = {
      toggle: toggle,
      menu: menu,
      form: menu.closest('form') || document.getElementById('filtersForm'),
      search: document.getElementById('statusSearch'),
      live: document.getElementById('statusPinLive'),
      emptyRow: menu.querySelector('.status-empty-item'),
      hint: menu.querySelector('[data-status-hint]'),
      nothing: menu.querySelector('[data-status-nothing]'),
      sections: sections
    };
    table = (window.DashboardConfig && window.DashboardConfig.currentTable) || 'accounts';
    allStatusRows().forEach(function (row) {
      var st = statusOf(row);
      if (st && !rowsByStatus[st]) {
        rowsByStatus[st] = row;
        baseOrder.push(st);
      }
    });
    state = loadState(browserStorage(), table);
    // Страница открылась с фильтром по статусу (закладка, ссылка, перезагрузка) —
    // это тоже «зашёл в статус». Пишем, только если недавние и правда сменились.
    var visited = recordVisit(state, statusesInUrl());
    if (visited.recent.join('\n') !== state.recent.join('\n')) {
      state = visited;
      saveState(browserStorage(), table, state);
    }
    layoutSections();
    updateLabel();
    bind();
  }

  window.DashboardStatusFilter = {
    init: init,
    updateCounts: updateCounts,
    updateLabel: updateLabel,
    logic: logic
  };
})();

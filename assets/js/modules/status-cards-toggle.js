/**
 * Кнопка «Свернуть статусы» над карточками статусов дашборда.
 *
 * Зачем (15.11.2026). На рабочей панели 223 карточки статусов (видимых 75), и
 * их сетка занимала весь первый экран — 1207px на мониторе 1720×1213. До
 * фильтров и таблицы приходилось листать на каждом заходе.
 *
 * Как устроено:
 *   - Состояние «свёрнуто» — класс status-cards-collapsed на <html>. Его ставит
 *     крошечный скрипт в <head> шаблона (templates/dashboard.php) ДО отрисовки,
 *     поэтому свёрнутая панель не мелькает экраном карточек при загрузке.
 *   - Сами карточки прячет CSS (core-components.css) по этому классу, а не JS.
 *     Значит, карточки, которые кто-то дорисует позже, тоже окажутся спрятаны,
 *     и ни один другой модуль про сворачивание знать не обязан.
 *   - Обе надписи кнопки («Свернуть статусы» / «Показать статусы N») лежат в
 *     разметке и переключаются тем же классом — до запуска скриптов надпись уже
 *     верная. Модуль лишь меняет класс, пишет его в localStorage, держит
 *     aria-expanded и число на кнопке.
 *   - Прячутся только карточки статусов (data-card="status:*"). «Всего
 *     аккаунтов», «Пустые статусы» (в ней кнопка «Управление») и свои
 *     карточки пользователя остаются на месте.
 *
 * Чего здесь осознанно НЕТ:
 *   - хранения на сервере. Все работают под одним входом в базу (см. CLAUDE.md,
 *     «Список статусов в фильтре»), «личная» настройка на сервере стала бы общей.
 *     Состояние живёт в браузере, и одна вкладка подхватывает его из другой.
 *   - анимации высоты: сетка бывает выше экрана, и плавное схлопывание тысячи
 *     пикселей двигало бы таблицу под пальцем дольше, чем полезно.
 */
(function () {
  'use strict';

  var STORAGE_KEY = window.LS_KEY_STATUS_CARDS_COLLAPSED || 'dashboard_status_cards_collapsed_v1';
  var HIDDEN_KEY = window.LS_KEY_HIDDEN_CARDS || 'dashboard_hidden_cards';
  var ROOT_CLASS = 'status-cards-collapsed';

  // ── Чистая логика (её гоняет tests/status-cards-toggle.test.cjs) ─────────

  /** Свёрнуто только при сохранённой строке '1'; всё прочее — развёрнуто. */
  function parseStored(value) {
    return value === '1';
  }

  /**
   * Сколько карточек статусов спрятано под кнопкой.
   * Карточки, которые пользователь сам скрыл (крестик «Скрыть карточку» или
   * настройки), не считаются: их и без сворачивания не видно.
   * @param {ArrayLike<Element>|null} cards все .stat-card сетки
   * @param {Set<string>|null} hiddenSet data-card из списка скрытых в localStorage
   * @returns {number}
   */
  function countStatusCards(cards, hiddenSet) {
    if (!cards) return 0;
    var n = 0;
    for (var i = 0; i < cards.length; i++) {
      var c = cards[i];
      var id = c.getAttribute('data-card');
      if (!id || id.indexOf('status:') !== 0) continue;
      if (c.classList.contains('hidden') || c.hasAttribute('hidden')) continue;
      if (hiddenSet && hiddenSet.has(id)) continue;
      n++;
    }
    return n;
  }

  /** Число в стиле остальной панели: 1,234 — как в фильтре статусов. */
  function formatCount(n) {
    return String(Math.max(0, Math.floor(Number(n) || 0))).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * Что выставить кнопке.
   * @returns {{expanded: string, title: string, count: string}}
   */
  function describe(collapsed, count) {
    var c = formatCount(count);
    return collapsed
      ? { expanded: 'false', title: 'Показать карточки статусов (' + c + ')', count: c }
      : { expanded: 'true', title: 'Свернуть карточки статусов', count: c };
  }

  // ── Работа со страницей ──────────────────────────────────────────────────

  var btn = null;
  var grid = null;
  var recountTimer = 0;

  function readHiddenSet() {
    try {
      var arr = JSON.parse(localStorage.getItem(HIDDEN_KEY) || '[]');
      return Array.isArray(arr) && arr.length ? new Set(arr) : null;
    } catch (_) {
      return null;
    }
  }

  function readStored() {
    try {
      return parseStored(localStorage.getItem(STORAGE_KEY));
    } catch (_) {
      return false; // приватный режим / заблокированное хранилище — просто развёрнуто
    }
  }

  function isCollapsed() {
    return document.documentElement.classList.contains(ROOT_CLASS);
  }

  function render() {
    if (!btn || !grid) return;
    var count = countStatusCards(grid.querySelectorAll('.stat-card'), readHiddenSet());
    var d = describe(isCollapsed(), count);
    btn.setAttribute('aria-expanded', d.expanded);
    btn.title = d.title;
    var countEl = btn.querySelector('.status-cards-toggle-count');
    if (countEl) countEl.textContent = d.count;
    // Нечего сворачивать (все карточки статусов скрыты вручную или база пустая) —
    // кнопка не нужна. Но свёрнутую оставляем: иначе нечем вернуть карточки.
    btn.hidden = count === 0 && !isCollapsed();
  }

  /** Пересчёт после перерисовки сетки. Не через rAF: без кадра он не наступит. */
  function scheduleRender() {
    if (recountTimer) return;
    recountTimer = setTimeout(function () {
      recountTimer = 0;
      render();
    }, 50);
  }

  /**
   * Свернуть / развернуть.
   * @param {boolean} collapsed
   * @param {boolean} [persist=true] false — когда состояние пришло из другой вкладки
   */
  function setCollapsed(collapsed, persist) {
    document.documentElement.classList.toggle(ROOT_CLASS, !!collapsed);
    if (persist !== false) {
      try {
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
      } catch (_) { /* не запомнится — но на этой странице сработает */ }
    }
    render();
  }

  function init() {
    btn = document.getElementById('statusCardsToggle');
    grid = document.getElementById('statsRow');
    if (!btn || !grid || btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';

    // Скрипт в <head> мог не отработать (например, упал на старом браузере) —
    // догоняем по localStorage. Если отработал, это просто то же значение.
    if (readStored() !== isCollapsed()) setCollapsed(readStored(), false);

    btn.addEventListener('click', function () {
      setCollapsed(!isCollapsed());
    });

    // Вторая вкладка свернула/развернула — повторяем здесь же, без записи.
    window.addEventListener('storage', function (e) {
      if (e.key === STORAGE_KEY) setCollapsed(parseStored(e.newValue), false);
      else if (e.key === HIDDEN_KEY) scheduleRender();
    });

    // Карточку скрыли/вернули (крестик, настройки, cards-hide-sync) — число на
    // кнопке должно это учесть.
    if (typeof MutationObserver !== 'undefined') {
      new MutationObserver(scheduleRender).observe(grid, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'hidden'],
      });
    }

    render();
  }

  window.DashboardStatusCards = {
    init: init,
    isCollapsed: isCollapsed,
    setCollapsed: setCollapsed,
    logic: {
      STORAGE_KEY: STORAGE_KEY,
      parseStored: parseStored,
      countStatusCards: countStatusCards,
      describe: describe,
    },
  };
})();

/**
 * Cell Actions — плавающая панель действий ячейки + доступ к полным значениям.
 *
 * Зачем: раньше кнопки «копировать/редактировать» рендерились в КАЖДОЙ ячейке
 * (2-3 кнопки × ~45 колонок × N строк), а полные значения длинных полей
 * дублировались в data-full/data-copy-text. Строка весила ~30 КБ HTML, из-за
 * чего первая загрузка раздувалась до мегабайт, а виртуализация не успевала
 * парсить строки при скролле (рывки).
 *
 * Теперь разметка тонкая, а этот модуль даёт:
 *  1) CellValues — полное значение поля: из данных строки (tableModule.rowsById)
 *     или с сервера (field_value.php), с кэшем;
 *  2) одну переиспользуемую панель кнопок, которая при наведении вставляется
 *     в текущую ячейку. Кнопки edit/pw несут те же классы, что и раньше
 *     (.field-edit-btn, .pw-edit, .pw-toggle) — легаси-обработчики в
 *     dashboard-init.js/dashboard.js работают без изменений (closest от кнопки).
 *     Копирование — собственная кнопка .cha-copy (умеет обрезанные значения).
 *
 * ДВА ИНВАРИАНТА, которые нельзя нарушать (оба уже ломались, см.
 * tests/test_cell_hover_panel_layout.php):
 *  - панель обязана оставаться ВНЕ ПОТОКА (position:absolute из CSS). Иначе она
 *    меняет max-content ячейки, а таблица — table-layout:auto, и вся раскладка
 *    50×50 пересчитывается на каждое наведение: строка «скачет» под курсором;
 *  - панель обязана оставаться ПОТОМКОМ .editable-field-wrap / .pw-mask, потому
 *    что легаси-обработчики ищут контекст через closest() от кнопки. Поэтому
 *    вынести её в body нельзя, а перед снятием innerHTML в редакторах её надо
 *    отцеплять — для этого наружу торчит CellActions.detach().
 */
(function () {
  'use strict';

  // ---------------------------------------------------------------
  // CellValues: полное значение поля строки
  // ---------------------------------------------------------------
  const cache = new Map(); // 'id:field' -> string

  function resolveRef(el) {
    const holder = el.closest('[data-row-id][data-field]');
    if (holder) {
      return { id: holder.getAttribute('data-row-id'), field: holder.getAttribute('data-field') };
    }
    // fallback: ячейка + строка
    const td = el.closest('td[data-col]');
    const tr = el.closest('tr[data-id]');
    if (td && tr) return { id: tr.getAttribute('data-id'), field: td.getAttribute('data-col') };
    return null;
  }

  async function fetchValue(id, field) {
    const url = (typeof window.getTableAwareUrl === 'function'
      ? window.getTableAwareUrl('field_value.php')
      : 'field_value.php');
    const sep = url.indexOf('?') === -1 ? '?' : '&';
    const res = await fetch(url + sep + 'id=' + encodeURIComponent(id) + '&field=' + encodeURIComponent(field), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) return null;
    const json = await res.json().catch(function () { return null; });
    return json && json.success ? String(json.value === null ? '' : json.value) : null;
  }

  const CellValues = {
    /** Полное значение поля для элемента ячейки (или null при ошибке). */
    async getFor(el) {
      const ref = resolveRef(el);
      if (!ref) return null;
      const key = ref.id + ':' + ref.field;
      if (cache.has(key)) return cache.get(key);

      // 1) данные последнего AJAX-рендера уже в памяти
      if (window.tableModule && typeof window.tableModule.getRowValue === 'function') {
        const v = window.tableModule.getRowValue(ref.id, ref.field);
        if (v !== null) { cache.set(key, v); return v; }
      }
      // 2) сервер
      const fetched = await fetchValue(ref.id, ref.field);
      if (fetched !== null) cache.set(key, fetched);
      return fetched;
    },
    clearCache() { cache.clear(); }
  };
  window.CellValues = CellValues;

  // ---------------------------------------------------------------
  // Плавающая панель действий
  // ---------------------------------------------------------------
  let panel = null;

  function buildPanel() {
    const span = document.createElement('span');
    span.className = 'cell-hover-actions';
    // Геометрия панели — в CSS (.cell-hover-actions в core-tables.css):
    // position:absolute поверх правого края ячейки. Инлайнового display здесь
    // осознанно НЕТ: раньше стоял display:contents, кнопки становились
    // flex-элементами обёртки и меняли ширину колонки на каждое наведение
    // (table-layout:auto) — см. комментарий у правила в CSS и
    // tests/test_cell_hover_panel_layout.php.
    span.innerHTML =
      '<button type="button" class="pw-toggle" title="Показать/скрыть пароль"><i class="fas fa-eye"></i></button>' +
      '<button type="button" class="pw-edit" title="Редактировать пароль"><i class="fas fa-edit"></i></button>' +
      '<button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>' +
      '<button type="button" class="cha-copy copy-cell-btn" title="Копировать"><i class="fas fa-copy"></i></button>';
    return span;
  }

  function setVisible(btn, visible) {
    if (btn) btn.style.display = visible ? '' : 'none';
  }

  function placePanel(container, mode) {
    if (!panel || !panel.isConnected) panel = buildPanel();
    if (panel.parentElement === container) return;
    setVisible(panel.querySelector('.pw-toggle'), mode === 'pw');
    setVisible(panel.querySelector('.pw-edit'), mode === 'pw');
    setVisible(panel.querySelector('.field-edit-btn'), mode === 'edit');
    container.appendChild(panel);
  }

  function removePanel() {
    if (panel && panel.parentElement) panel.parentElement.removeChild(panel);
  }

  /**
   * Отцепляет панель от ячейки, если она сейчас внутри указанного элемента.
   *
   * Нужна редакторам (modules/inline-edit.js и ветка .pw-edit в
   * dashboard-init.js): они сохраняют `container.innerHTML`, чтобы вернуть
   * ячейку после сохранения/отмены. Панель в этот момент — ребёнок этого же
   * контейнера, поэтому попадала в снимок, а восстановление вклеивало её КОПИЮ
   * статической разметкой. Копию не убирал никто: removePanel() знает только про
   * живой узел. Ячейка навсегда становилась шире (замерено на стенде:
   * last_name 108,9 → 174,3 px, password 102,8 → 213,5 px) и получала второй
   * комплект кнопок; эффект копился с каждой правкой.
   *
   * @param {Element} [container] Если задан — отцепляем, только когда панель
   *   действительно внутри него. Без аргумента — отцепляем безусловно.
   * @returns {void}
   */
  function detachPanel(container) {
    if (!panel || !panel.parentElement) return;
    if (container && !container.contains(panel)) return;
    panel.parentElement.removeChild(panel);
  }

  window.CellActions = { detach: detachPanel };

  function onMouseOver(e) {
    const t = e.target;
    if (!(t instanceof Element)) return;
    if (panel && panel.contains(t)) return;

    const td = t.closest('#accountsTable tbody td.ac-cell, #accountsTable tbody td[data-col]');
    if (!td) {
      if (!t.closest('#accountsTable')) removePanel();
      return;
    }
    const column = td.getAttribute('data-column') || td.getAttribute('data-col') || '';
    if (column === 'checkbox' || column === 'favorite' || column === 'actions') { removePanel(); return; }

    const pwMask = td.querySelector('.pw-mask');
    const wrap = td.querySelector('.editable-field-wrap');
    const container = pwMask || wrap || td;
    if (container.getAttribute && container.getAttribute('data-editing') === 'true') return;

    placePanel(container, pwMask ? 'pw' : (wrap ? 'edit' : 'copy'));
  }

  async function onCopyClick(btn) {
    let text = null;
    const scope = btn.closest('td[data-col], td.ac-cell') || btn.parentElement;

    // Пароли: значение лежит инлайн
    const pwText = scope.querySelector('.pw-text');
    if (pwText) text = pwText.textContent || '';

    // Обрезанное поле: полное значение из данных строки / с сервера
    if (text === null) {
      const clipped = scope.querySelector('[data-clipped]');
      if (clipped) text = await CellValues.getFor(clipped);
    }

    // Обычное поле: видимый текст / href
    if (text === null || text === '') {
      const fv = scope.querySelector('.field-value, .fw-bold');
      if (fv) {
        text = (fv.textContent || '').trim();
        if (fv.tagName === 'A' && fv.href) text = fv.href.replace(/^mailto:/, '');
        if (text === '—') text = '';
        if (/^#\d+$/.test(text)) text = text.slice(1); // ячейка ID: «#123» → «123»
      }
    }

    if (text !== null && typeof window.copyToClipboard === 'function') {
      window.copyToClipboard(text);
    }
  }

  // ---------------------------------------------------------------
  // Модалка полного значения (клик по обрезанному полю).
  // Самодостаточна: обработчик [data-full] в классе Dashboard (dashboard.js)
  // в бою не активен (класс не инициализируется) — просмотр длинных значений
  // не работал ещё до тонкой разметки. Использует тот же id fullDataModal
  // с обновлением in-place, чтобы не плодить дубликаты.
  // ---------------------------------------------------------------
  function showValueModal(title, value) {
    let modal = document.getElementById('fullDataModal');
    if (modal) {
      const t = modal.querySelector('.fdm-title');
      const pre = modal.querySelector('pre');
      if (t) t.textContent = title;
      if (pre) pre.textContent = value;
      return;
    }
    modal = document.createElement('div');
    modal.id = 'fullDataModal';
    modal.innerHTML =
      '<div class="fdm-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
        '<div class="fdm-dialog" style="max-width:70vw;max-height:70vh;width:70vw;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#111);border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.2);display:flex;flex-direction:column;">' +
          '<div style="padding:12px 16px;border-bottom:1px solid rgba(0,0,0,.1);display:flex;align-items:center;justify-content:space-between;">' +
            '<div class="fdm-title" style="font-weight:600"></div>' +
            '<button type="button" class="fdm-close" style="border:none;background:transparent;font-size:20px;line-height:1;cursor:pointer;color:inherit">×</button>' +
          '</div>' +
          '<div style="padding:16px;overflow:auto"><pre style="white-space:pre-wrap;word-wrap:break-word;font-family:monospace;margin:0"></pre></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.querySelector('.fdm-title').textContent = title;
    modal.querySelector('pre').textContent = value;
    modal.addEventListener('click', function (e) {
      if (e.target.classList.contains('fdm-backdrop') || e.target.classList.contains('fdm-close')) modal.remove();
    });
  }

  async function onClippedClick(el) {
    const title = el.getAttribute('data-title') || 'Данные';
    showValueModal(title, 'Загружаю…');
    const value = el.getAttribute('data-full') || await CellValues.getFor(el);
    showValueModal(title, value === null ? 'Не удалось загрузить значение' : value);
  }

  function init() {
    const table = document.getElementById('accountsTable');
    if (!table) return; // страница без таблицы аккаунтов

    document.addEventListener('mouseover', onMouseOver, { passive: true });
    document.addEventListener('click', function (e) {
      if (!(e.target instanceof Element)) return;
      const btn = e.target.closest('.cha-copy');
      if (btn) { onCopyClick(btn); return; }
      const clipped = e.target.closest('#accountsTable [data-clipped], #accountsTable [data-full]');
      if (clipped && !clipped.closest('[data-editing="true"]')) onClippedClick(clipped);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

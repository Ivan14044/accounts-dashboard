/**
 * ============================================================================
 * TABLE MODULE - Единый модуль управления таблицей
 * ============================================================================
 * 
 * Объединяет сортировку, виртуализацию и управление таблицей
 * 
 * ВЕРСИЯ: 3.0
 * ДАТА: 2024
 * ============================================================================
 */

(function() {
  'use strict';

  const CLIP_LEN = 80;
  const TOKEN_CLIP = 20;
  const LONG_FIELDS = ['cookies', 'first_cookie', 'user_agent', 'extra_info_1', 'extra_info_2', 'extra_info_3', 'extra_info_4'];

  const getEl = (id) => (typeof domCache !== 'undefined' && domCache.getById ? domCache.getById(id) : document.getElementById(id));
  const getSel = (sel) => (typeof domCache !== 'undefined' && domCache.get ? domCache.get(sel) : document.querySelector(sel));

  class TableModule {
    constructor(root, options = {}) {
      this.root = root;
      this.table = getEl('accountsTable');
      this.tbody = this.table ? this.table.querySelector('tbody') : null;
      this.toolbar = getEl('rowsCounterBar');
      this.scrollContainer = getEl('tableWrap');
      this.options = Object.assign({
        virtualization: {
          threshold: 45,
          bufferSize: 15,
          rowHeight: 48,
          debug: false
        }
      }, options);
      this.boundSortHandler = this.handleSortClick.bind(this);
      this.virtualScroller = null;
      if (this.table) {
        this.init();
      }
    }

    init() {
      this.bindSortLinks();
      this.setupVirtualization();
      const params = new URLSearchParams(window.location.search);
      this.updateSortIndicators(params.get('sort') || 'id', params.get('dir') || 'asc');
      window.tableModule = this;
      window.tableLayoutManager = {
        refresh: () => this.refreshLayout()
      };
    }

    bindSortLinks() {
      document.addEventListener('click', this.boundSortHandler, true);
    }

    handleSortClick(event) {
      const sortLink = event.target.closest('[data-sort-link]');
      if (!sortLink) return;
      const column = sortLink.getAttribute('data-sort-column');
      if (!column) return;
      event.preventDefault();
      event.stopPropagation();
      const params = new URLSearchParams(window.location.search);
      const currentSort = params.get('sort') || 'id';
      const currentDir = params.get('dir') || 'asc';
      let newDir = 'asc';
      if (currentSort === column && currentDir === 'asc') {
        newDir = 'desc';
      }
      params.set('sort', column);
      params.set('dir', newDir);
      params.set('page', '1');
      history.pushState(null, '', window.location.pathname + '?' + params.toString());
      this.updateSortIndicators(column, newDir);
      if (typeof selectedAllFiltered !== 'undefined') {
        selectedAllFiltered = false;
      }
      if (typeof selectedIds !== 'undefined' && selectedIds instanceof Set) {
        selectedIds.clear();
      }
      if (typeof updateSelectedCount === 'function') {
        updateSelectedCount();
      }
      if (typeof refreshDashboardData === 'function') {
        refreshDashboardData();
      }
    }

    updateSortIndicators(activeColumn, dir) {
      if (!this.table) return;
      this.table.querySelectorAll('thead th[data-col] a[data-sort-link]').forEach(link => {
        const col = link.getAttribute('data-sort-column');
        const indicator = link.querySelector('.sort-indicator');
        if (indicator) {
          indicator.textContent = (col === activeColumn) ? (dir === 'asc' ? '▲' : '▼') : '';
        }
        const th = link.closest('th');
        if (th) {
          th.setAttribute('aria-sort', col === activeColumn ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
        }
      });
    }

    setupVirtualization() {
      this.virtualScroller = new TableVirtualization({
        table: this.table,
        scrollContainer: this.scrollContainer,
        threshold: this.options.virtualization.threshold,
        bufferSize: this.options.virtualization.bufferSize,
        rowHeight: this.options.virtualization.rowHeight,
        debug: this.options.virtualization.debug
      });
      this.virtualScroller.mount();
      window.tableVirtualization = this.virtualScroller;
    }

    updateRows(data) {
      if (typeof performance !== 'undefined' && performance.mark) {
        performance.mark('updateRows-start');
      }
      if (!this.table) {
        if (typeof performance !== 'undefined' && performance.mark) {
          performance.mark('updateRows-end');
          performance.measure('updateRows', 'updateRows-start', 'updateRows-end');
        }
        return;
      }
      const rows = Array.isArray(data?.rows) ? data.rows : [];
      const columns = Array.isArray(data?.columns) && data.columns.length
        ? data.columns
        : this.getColumnKeys();
      const tbody = this.table.querySelector('tbody');
      if (!tbody) {
        if (typeof performance !== 'undefined' && performance.mark) {
          performance.mark('updateRows-end');
          performance.measure('updateRows', 'updateRows-start', 'updateRows-end');
        }
        return;
      }
      if (!rows.length) {
        tbody.innerHTML = this.emptyStateHtml(columns.length);
        this.tbody = tbody;
        this.afterRender();
        if (typeof performance !== 'undefined' && performance.mark) {
          performance.mark('updateRows-end');
          performance.measure('updateRows', 'updateRows-start', 'updateRows-end');
        }
        return;
      }
      const urlParams = new URLSearchParams(window.location.search);
      const perPage = parseInt(urlParams.get('per_page') || '25', 10);
      const threshold = this.options.virtualization.threshold;
      const useVirtualFromStart = rows.length > threshold && perPage > 50;
      if (useVirtualFromStart && this.virtualScroller) {
        tbody.innerHTML = '';
        this.tbody = tbody;
        this.virtualScroller.enableFromData(rows, columns, (row, cols) => this.renderRow(row, cols));
        this.afterRender();
      } else {
        tbody.innerHTML = rows.map(row => this.renderRow(row, columns)).join('');
        this.tbody = tbody;
        this.afterRender();
      }
      if (typeof performance !== 'undefined' && performance.mark) {
        performance.mark('updateRows-end');
        performance.measure('updateRows', 'updateRows-start', 'updateRows-end');
      }
    }

    afterRender() {
      if (typeof applySavedColumnVisibility === 'function') {
        applySavedColumnVisibility();
      }
      if (typeof initCheckboxStates === 'function') {
        initCheckboxStates();
      }
      if (typeof updateAllSelectedRowsHighlight === 'function') {
        updateAllSelectedRowsHighlight();
      }
      if (window.favoritesManager && typeof window.favoritesManager.updateTableFavorites === 'function') {
        window.favoritesManager.updateTableFavorites();
      }
      if (typeof updateSelectedCount === 'function') {
        updateSelectedCount();
      }
      this.refreshLayout();
    }

    refreshLayout() {
      // Используем requestAnimationFrame для гарантии, что DOM полностью обновлен
      // перед обновлением виртуализации
      requestAnimationFrame(() => {
        if (this.virtualScroller && typeof this.virtualScroller.refresh === 'function') {
          this.virtualScroller.refresh();
        }
        if (typeof window.updateStickyScrollbar === 'function') {
          window.updateStickyScrollbar();
        }
        // Инвалидируем dom-cache и RowIdsCache после обновления виртуализации
        if (typeof domCache !== 'undefined' && typeof domCache.invalidate === 'function') {
          domCache.invalidate();
        }
        if (typeof RowIdsCache !== 'undefined' && typeof RowIdsCache.invalidate === 'function') {
          RowIdsCache.invalidate();
        }
      });
    }

    getColumnKeys() {
      if (!this.table) return [];
      return Array.from(this.table.querySelectorAll('thead th[data-col]')).map(th => th.getAttribute('data-col'));
    }

    emptyStateHtml(colCount) {
      const totalCols = Number(colCount || 0) + 3;
      return `<tr class="ac-row ac-row--empty"><td colspan="${totalCols}" class="text-center text-muted py-5">
        <i class="fas fa-search fa-2x mb-3 text-muted"></i>
        <div>Ничего не найдено</div>
      </td></tr>`;
    }

    renderRow(row, columnKeys) {
      const cellsHtml = columnKeys.map(col => {
        if (col === 'id') {
          const idCell = `<td class="ac-cell ac-cell--id" data-col="id" data-column="id">
            <span class="fw-bold text-primary">#${this.escape(row[col])}</span>
            <button type="button" class="copy-btn" data-copy-text="${this.escape(row[col])}" title="Копировать"><i class="fas fa-copy"></i></button>
          </td>`;
          const favoriteCell = `<td class="ac-cell ac-cell--favorite favorite-cell text-center" data-column="favorite" data-account-id="${row.id}">
            <button type="button" class="btn btn-sm btn-link favorite-btn p-0" data-account-id="${row.id}" title="Избранное">
              <i class="far fa-star"></i>
            </button>
          </td>`;
          return idCell + favoriteCell;
        }
        return `<td class="ac-cell" data-col="${col}" data-column="${col}">${this.renderCellContent(col, row)}</td>`;
      }).join('');

      return `<tr class="ac-row" data-id="${row.id}">
        <td class="ac-cell ac-cell--checkbox checkbox-cell" data-column="checkbox">
          <div class="form-check">
            <input class="form-check-input row-checkbox" type="checkbox" value="${row.id}" title="ID записи: ${row.id}">
          </div>
        </td>
        ${cellsHtml}
        <td class="ac-cell ac-cell--actions text-end" data-column="actions">
          <a class="btn btn-sm btn-outline-primary" href="view.php?id=${row.id}">
            <i class="fas fa-eye me-1"></i>Открыть
          </a>
        </td>
      </tr>`;
    }

    renderCellContent(col, row) {
      const value = row[col];
      if ((value === undefined || value === null || value === '') && !['password', 'email_password', 'id', 'actions'].includes(col)) {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="text-muted field-value">—</span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button type="button" class="copy-btn" data-copy-text="" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'email') {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <a href="mailto:${this.escape(value)}" class="text-decoration-none field-value">${this.escape(value)}</a>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button class="copy-btn" type="button" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'login') {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="fw-semibold field-value">${this.escape(value)}</span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button class="copy-btn" type="button" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'password' || col === 'email_password') {
        const displayDots = !value ? '<span class="pw-dots text-muted">(не задан)</span>' : '<span class="pw-dots">••••••••</span>';
        return `<div class="pw-mask" data-row-id="${row.id}" data-field="${col}">
          ${displayDots}
          <span class="pw-text d-none">${this.escape(value)}</span>
          <button type="button" class="pw-toggle" title="Показать/скрыть"><i class="fas fa-eye"></i></button>
          <button type="button" class="pw-edit" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button type="button" class="copy-btn" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'token') {
        const clipped = this.clip(value, TOKEN_CLIP);
        return `<div class="d-flex align-items-center gap-2">
          <span class="truncate mono" title="Нажмите для просмотра" data-full="${this.escape(value)}" data-title="Token">
            ${this.escape(clipped)}
          </span>
          <button class="copy-btn" type="button" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'status') {
        const statusValue = String(value || '').toLowerCase();
        let badgeClass = 'badge-default';
        let displayText = value || '—';
        if (value === null || value === '') {
          badgeClass = 'badge-empty-status';
          displayText = 'Пустой статус';
        } else if (statusValue.includes('new')) {
          badgeClass = 'badge-new';
        } else if (statusValue.includes('add_selphi_true')) {
          badgeClass = 'badge-add_selphi_true';
        } else if (statusValue.includes('error')) {
          badgeClass = 'badge-error_login';
        }
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="badge ${badgeClass} field-value">${this.escape(displayText)}</span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button type="button" class="copy-btn" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (col === 'social_url' && /^https?:\/\//i.test(String(value || ''))) {
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <a href="${this.escape(value)}" target="_blank" rel="noopener" class="text-decoration-none field-value">
            <i class="fas fa-external-link-alt me-2"></i>${this.escape(value)}
          </a>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button type="button" class="copy-btn" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      if (LONG_FIELDS.includes(col) || (typeof value === 'string' && value.length > CLIP_LEN)) {
        const clipped = this.clip(value, CLIP_LEN);
        return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
          <span class="truncate mono field-value" data-full="${this.escape(value)}" data-title="${this.escape(col)}">
            ${this.escape(clipped)}
          </span>
          <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
          <button type="button" class="copy-btn" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
        </div>`;
      }
      return `<div class="editable-field-wrap" data-row-id="${row.id}" data-field="${col}">
        <span class="field-value">${this.escape(value)}</span>
        <button type="button" class="field-edit-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
        <button type="button" class="copy-btn" data-copy-text="${this.escape(value)}" title="Копировать"><i class="fas fa-copy"></i></button>
      </div>`;
    }

    escape(value) {
      return String(value ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
    }

    clip(value, length) {
      const str = String(value ?? '');
      return str.length > length ? str.slice(0, length) + '…' : str;
    }
  }

  // Встроенная виртуализация
  class TableVirtualization {
    constructor(options = {}) {
      this.options = Object.assign({
        threshold: 45,
        bufferSize: 15,
        rowHeight: 48,
        debug: false,
        table: null,
        scrollContainer: null
      }, options);
      this.table = this.options.table || getEl('accountsTable');
      this.scrollTarget = this.options.scrollContainer || getEl('tableWrap') || window;
      this.tbody = this.table ? this.table.querySelector('tbody') : null;
      this.allRows = [];
      this.rowsData = [];
      this.columnKeys = [];
      this.renderRowFn = null;
      this.spacerTop = null;
      this.spacerBottom = null;
      this.scrollHandler = null;
      this.resizeHandler = null;
      this.visibleRange = { start: 0, end: 0 };
      this.tableOffsetTop = 0;
      this.enabled = false;
      this.useWindowScroll = this.scrollTarget === window;
      this.isRendering = false;
      if (!this.scrollTarget) {
        this.scrollTarget = window;
        this.useWindowScroll = true;
      }
    }

    mount() {
      this.table = this.options.table || getEl('accountsTable');
      this.scrollTarget = this.options.scrollContainer || getEl('tableWrap') || window;
      this.tbody = this.table ? this.table.querySelector('tbody') : null;
      this.useWindowScroll = this.scrollTarget === window;
      if (!this.table || !this.tbody) {
        return;
      }
      this.checkAndToggle();
    }

    refresh() {
      if (this.enabled && this.rowsData.length > 0) {
        this.updateTableOffset();
        this.updateVisibleRows();
        return;
      }
      this.mount();
      if (!this.table || !this.tbody) return;
      const rows = Array.from(this.tbody.querySelectorAll('tr[data-id]'));
      const urlParams = new URLSearchParams(window.location.search);
      const perPage = parseInt(urlParams.get('per_page') || '25', 10);
      const shouldEnableVirtualization = rows.length > this.options.threshold && perPage > 50;
      if (!shouldEnableVirtualization) {
        this.disable(true);
        return;
      }
      if (this.enabled && this.allRows.length > 0) {
        this.allRows = rows;
        this.updateVisibleRows();
      } else {
        this.disable(true);
        this.enable(rows);
      }
    }

    checkAndToggle() {
      if (!this.tbody) return;
      const dataRows = Array.from(this.tbody.querySelectorAll('tr[data-id]'));
      
      // Проверяем значение per_page из URL
      // Отключаем виртуализацию для малых значений per_page (<=100)
      // чтобы пользователь видел все строки сразу
      const urlParams = new URLSearchParams(window.location.search);
      const perPage = parseInt(urlParams.get('per_page') || '25', 10);
      const shouldEnableVirtualization = dataRows.length > this.options.threshold && perPage > 50;
      
      if (shouldEnableVirtualization) {
        if (!this.enabled) {
          this.enable(dataRows);
        } else {
          this.allRows = dataRows;
          this.updateVisibleRows();
          (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(() => this.updateVirtualizationHint());
        }
      } else if (this.enabled) {
        // Отключаем виртуализацию для малых значений per_page
        this.disable();
      }
    }

    enable(currentRows) {
      this.rowsData = [];
      this.renderRowFn = null;
      this.columnKeys = [];
      this.enabled = true;
      this.allRows = currentRows || Array.from(this.tbody.querySelectorAll('tr[data-id]'));
      if (!this.allRows.length) {
        this.enabled = false;
        return;
      }
      const sampleRow = this.allRows[0];
      this.options.rowHeight = sampleRow.offsetHeight || this.options.rowHeight;
      this.allRows.forEach(row => row.remove());
      this.createSpacers();
      this.scrollHandler = this.throttle(() => this.updateVisibleRows(), 16);
      const target = this.useWindowScroll ? window : this.scrollTarget;
      target.addEventListener('scroll', this.scrollHandler, { passive: true });
      this.resizeHandler = this.debounce(() => {
        this.updateTableOffset();
        this.recalculate();
      }, 150);
      window.addEventListener('resize', this.resizeHandler, { passive: true });
      this.updateTableOffset();
      this.updateVisibleRows();
      (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(() => this.updateVirtualizationHint());
    }

    enableFromData(rowsData, columnKeys, renderRowFn) {
      if (!rowsData.length || !renderRowFn || !this.tbody) return;
      this.rowsData = rowsData;
      this.columnKeys = columnKeys;
      this.renderRowFn = renderRowFn;
      this.allRows = [];
      this.enabled = true;
      this.createSpacers();
      this.scrollHandler = this.throttle(() => this.updateVisibleRows(), 16);
      const target = this.useWindowScroll ? window : this.scrollTarget;
      target.addEventListener('scroll', this.scrollHandler, { passive: true });
      this.resizeHandler = this.debounce(() => {
        this.updateTableOffset();
        this.recalculate();
      }, 150);
      window.addEventListener('resize', this.resizeHandler, { passive: true });
      this.updateTableOffset();
      this.updateVisibleRows();
      (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(() => this.updateVirtualizationHint());
    }

    disable(preserveDom = false) {
      if (!this.enabled && !preserveDom) return;
      const hadRowsData = this.rowsData.length > 0;
      const rd = this.rowsData.slice();
      const rfn = this.renderRowFn;
      const ck = this.columnKeys.slice();
      this.enabled = false;
      this.visibleRange = { start: 0, end: 0 };
      this.rowsData = [];
      this.renderRowFn = null;
      this.columnKeys = [];
      if (this.scrollHandler) {
        const target = this.useWindowScroll ? window : this.scrollTarget;
        target.removeEventListener('scroll', this.scrollHandler);
        this.scrollHandler = null;
      }
      if (this.resizeHandler) {
        window.removeEventListener('resize', this.resizeHandler);
        this.resizeHandler = null;
      }
      if (this.spacerTop) {
        this.spacerTop.remove();
        this.spacerTop = null;
      }
      if (this.spacerBottom) {
        this.spacerBottom.remove();
        this.spacerBottom = null;
      }
      if (!preserveDom && this.tbody) {
        if (hadRowsData && rd.length && rfn) {
          this.tbody.innerHTML = rd.map(row => rfn(row, ck)).join('');
        } else if (this.allRows.length) {
          const fragment = document.createDocumentFragment();
          this.allRows.forEach(row => fragment.appendChild(row));
          this.tbody.innerHTML = '';
          this.tbody.appendChild(fragment);
        }
      }
      this.allRows = [];
      (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(() => this.updateVirtualizationHint());
    }

    createSpacers() {
      if (!this.tbody) return;
      this.spacerTop = document.createElement('tr');
      this.spacerTop.className = 'spacer spacer-top';
      this.spacerTop.innerHTML = '<td colspan="100" style="height:0;padding:0;border:0;"></td>';
      this.spacerBottom = document.createElement('tr');
      this.spacerBottom.className = 'spacer spacer-bottom';
      this.spacerBottom.innerHTML = '<td colspan="100" style="height:0;padding:0;border:0;"></td>';
      this.tbody.innerHTML = '';
      this.tbody.appendChild(this.spacerTop);
      this.tbody.appendChild(this.spacerBottom);
    }

    updateTableOffset() {
      if (!this.table) return;
      const rect = this.table.getBoundingClientRect();
      const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
      this.tableOffsetTop = rect.top + scrollY;
    }

    getRelativeScrollTop() {
      if (!this.useWindowScroll && this.scrollTarget) {
        return this.scrollTarget.scrollTop;
      }
      const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
      return Math.max(0, scrollY - this.tableOffsetTop);
    }

    getViewportHeight() {
      return this.useWindowScroll ? window.innerHeight : this.scrollTarget.clientHeight;
    }

    updateVisibleRows() {
      const totalRows = this.rowsData.length > 0 ? this.rowsData.length : this.allRows.length;
      if (!this.enabled || this.isRendering || !totalRows) {
        return;
      }
      const editingRow = this.tbody ? this.tbody.querySelector('tr[data-id][data-editing="true"]') : null;
      if (editingRow) return;
      this.isRendering = true;
      const scrollTop = this.getRelativeScrollTop();
      const viewport = this.getViewportHeight();
      const totalHeight = totalRows * this.options.rowHeight;
      const maxScrollTop = Math.max(0, totalHeight - viewport);
      const safeScroll = Math.max(0, Math.min(scrollTop, maxScrollTop));
      const startIndex = Math.max(0, Math.floor(safeScroll / this.options.rowHeight) - this.options.bufferSize);
      const endIndex = Math.min(totalRows, Math.ceil((safeScroll + viewport) / this.options.rowHeight) + this.options.bufferSize);
      if (startIndex === this.visibleRange.start && endIndex === this.visibleRange.end) {
        this.isRendering = false;
        return;
      }
      this.visibleRange = { start: startIndex, end: endIndex };
      const topHeight = startIndex * this.options.rowHeight;
      const bottomHeight = Math.max(0, (totalRows - endIndex) * this.options.rowHeight);
      const topCell = this.spacerTop?.querySelector('td');
      const bottomCell = this.spacerBottom?.querySelector('td');
      if (topCell) topCell.style.height = `${topHeight}px`;
      if (bottomCell) bottomCell.style.height = `${bottomHeight}px`;
      const renderedRows = Array.from(this.tbody.querySelectorAll('tr:not(.spacer):not([data-editing="true"])'));
      renderedRows.forEach(row => row.remove());
      const fragment = document.createDocumentFragment();
      if (this.rowsData.length > 0 && this.renderRowFn) {
        for (let i = startIndex; i < endIndex; i += 1) {
          const html = this.renderRowFn(this.rowsData[i], this.columnKeys);
          const wrap = document.createElement('table');
          wrap.innerHTML = '<tbody>' + html + '</tbody>';
          const tr = wrap.querySelector('tbody').firstChild;
          if (tr) fragment.appendChild(tr);
        }
      } else {
        for (let i = startIndex; i < endIndex; i += 1) {
          const rowInDom = this.tbody.querySelector('tr[data-id="' + (this.allRows[i].getAttribute('data-id') || '') + '"]');
          if (!rowInDom || !rowInDom.hasAttribute('data-editing')) {
            fragment.appendChild(this.allRows[i]);
          }
        }
      }
      this.tbody.insertBefore(fragment, this.spacerBottom);
      if (typeof applySavedColumnVisibility === 'function') {
        applySavedColumnVisibility();
      }
      if (typeof initCheckboxStates === 'function') {
        initCheckboxStates();
      }
      if (typeof updateAllSelectedRowsHighlight === 'function') {
        updateAllSelectedRowsHighlight();
      }
      
      // Обновляем индикатор виртуализации в idle
      (window.scheduleIdle || function(fn) { setTimeout(fn, 0); })(() => this.updateVirtualizationHint());
      
      this.isRendering = false;
    }
    
    updateVirtualizationHint() {
      const hintEl = getEl('virtualizationHint');
      const visibleCountEl = getEl('visibleRowsCount');
      const totalCountEl = getEl('totalRowsOnPage');
      
      if (!hintEl) return;
      
      if (this.enabled && (this.allRows.length > 0 || this.rowsData.length > 0)) {
        const totalRows = this.rowsData.length > 0 ? this.rowsData.length : this.allRows.length;
        const visibleRows = this.visibleRange.end - this.visibleRange.start;
        
        if (visibleCountEl) visibleCountEl.textContent = visibleRows;
        if (totalCountEl) totalCountEl.textContent = totalRows;
        
        hintEl.classList.remove('d-none');
      } else {
        hintEl.classList.add('d-none');
      }
    }

    recalculate() {
      if (!this.enabled) return;
      if (this.rowsData.length > 0) {
        this.updateTableOffset();
        this.updateVisibleRows();
        return;
      }
      if (!this.allRows.length) return;
      const sampleRow = this.allRows[this.visibleRange.start] || this.allRows[0];
      if (sampleRow) {
        this.options.rowHeight = sampleRow.offsetHeight || this.options.rowHeight;
      }
      this.updateTableOffset();
      this.updateVisibleRows();
    }

    throttle(func, wait) {
      let timeout = null;
      let previous = 0;
      return (...args) => {
        const now = Date.now();
        const remaining = wait - (now - previous);
        if (remaining <= 0 || remaining > wait) {
          if (timeout) {
            clearTimeout(timeout);
            timeout = null;
          }
          previous = now;
          func.apply(this, args);
        } else if (!timeout) {
          timeout = setTimeout(() => {
            previous = Date.now();
            timeout = null;
            func.apply(this, args);
          }, remaining);
        }
      };
    }

    debounce(func, wait) {
      let timeout;
      return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
      };
    }
  }

  function bootstrapTableModule() {
    const root = getSel('[data-module="accounts-table"]');
    if (!root) return;
    new TableModule(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapTableModule);
  } else {
    bootstrapTableModule();
  }
})();

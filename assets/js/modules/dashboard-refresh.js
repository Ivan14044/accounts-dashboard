/**
 * Модуль обновления данных дашборда
 * refreshDashboardData, collectRefreshParams, syncNumericRange, setTableLoadingState
 * Ожидает глобальные: getElementById, getSel, logger, showToast, updateStatValue, adjustTableDensity, syncHeaderWidths
 */
(function() {
  'use strict';

  const getEl = (id) => (typeof getElementById === 'function' ? getElementById(id) : document.getElementById(id));
  const getS = (sel) => (typeof getSel === 'function' ? getSel(sel) : document.querySelector(sel));
  const log = (...args) => (typeof logger !== 'undefined' && logger.debug ? logger.debug(...args) : (typeof console !== 'undefined' && console.debug && console.debug(...args)));
  const logErr = (...args) => (typeof logger !== 'undefined' && logger.error ? logger.error(...args) : (typeof console !== 'undefined' && console.error && console.error(...args)));

  let refreshController = null;
  let refreshQueued = false;
  let isRefreshing = false;
  let overlayShownAt = 0;

  function collectRefreshParams() {
    const params = new URLSearchParams(window.location.search);
    syncNumericRange(params, 'pharma', 'pharma_from', 'pharma_to', 'pharmaSlider');
    syncNumericRange(params, 'friends', 'friends_from', 'friends_to', 'friendsSlider');
    return params;
  }

  function syncNumericRange(params, prefix, fromId, toId, sliderId) {
    const fromInput = getEl(fromId);
    const toInput = getEl(toId);
    const slider = getEl(sliderId);
    const min = slider ? parseInt(slider.getAttribute('data-min') || '0', 10) : null;
    const max = slider ? parseInt(slider.getAttribute('data-max') || '0', 10) : null;
    const fromVal = fromInput ? fromInput.value.trim() : '';
    const toVal = toInput ? toInput.value.trim() : '';

    if (fromVal !== '') {
      params.set(`${prefix}_from`, fromVal);
    } else {
      params.delete(`${prefix}_from`);
    }
    if (toVal !== '') {
      params.set(`${prefix}_to`, toVal);
    } else {
      params.delete(`${prefix}_to`);
    }
    if (min !== null && max !== null && fromVal !== '' && toVal !== '') {
      const numericFrom = parseInt(fromVal, 10);
      const numericTo = parseInt(toVal, 10);
      if (!Number.isNaN(numericFrom) && !Number.isNaN(numericTo) && numericFrom <= min && numericTo >= max) {
        params.delete(`${prefix}_from`);
        params.delete(`${prefix}_to`);
      }
    }
  }

  function setTableLoadingState(isLoading) {
    log('setTableLoadingState called with:', isLoading);
    const tableOverlay = getEl('tableLoading');
    const statsOverlay = getEl('statsLoading');
    const tableResponsive = getS('.table-responsive');

    if (isLoading) {
      if (tableOverlay) {
        tableOverlay.style.display = '';
        tableOverlay.classList.add('show');
        overlayShownAt = Date.now();
      }
      if (statsOverlay) {
        statsOverlay.style.display = '';
        statsOverlay.classList.add('show');
      }
      if (tableResponsive) {
        tableResponsive.classList.add('loading');
      }
      return;
    }
    if (tableOverlay) {
      const elapsed = Date.now() - (overlayShownAt || 0);
      const minMs = 300;
      const hide = () => tableOverlay.classList.remove('show');
      if (elapsed < minMs) {
        setTimeout(hide, Math.max(minMs - elapsed, 0));
      } else {
        hide();
      }
    }
    if (statsOverlay) {
      statsOverlay.classList.remove('show');
    }
    if (tableResponsive) {
      tableResponsive.classList.remove('loading');
    }
  }

  async function refreshDashboardData() {
    if (refreshController) {
      refreshQueued = true;
      try { refreshController.abort(); } catch(_) {}
    }
    const params = new URLSearchParams(window.location.search);
    const url = 'refresh.php?' + params.toString();
    refreshController = new AbortController();
    const signal = refreshController.signal;
    try {
      const res = await fetch(url, {
        credentials: 'same-origin',
        signal,
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data.success) return;

      const totalEl = getS('[data-card="total"] .stat-value');
      if (totalEl && data.totals && typeof data.totals.all === 'number') {
        if (typeof updateStatValue === 'function') {
          updateStatValue(totalEl, data.totals.all);
        } else {
          totalEl.textContent = String(data.totals.all);
        }
      }
      if (typeof data.filteredTotal === 'number' && window.DashboardSelection) {
        window.DashboardSelection.setFilteredTotalLive(data.filteredTotal);
      }

      const statusCards = document.querySelectorAll('.stat-card[data-card^="status:"]');
      log('Обновление карточек статистики:', { cards_found: statusCards.length, byStatus_keys: data.byStatus ? Object.keys(data.byStatus) : [] });
      statusCards.forEach(cardElement => {
        const statusKey = cardElement.getAttribute('data-status');
        if (!statusKey) return;
        const cnt = data.byStatus && typeof data.byStatus[statusKey] !== 'undefined' ? data.byStatus[statusKey] : null;
        const valEl = cardElement.querySelector('.stat-value');
        if (valEl) {
          if (typeof updateStatValue === 'function') {
            updateStatValue(valEl, cnt !== null ? cnt : 0);
          } else {
            valEl.textContent = String(cnt !== null ? cnt : 0);
          }
        }
      });

      if (data.byStatus) {
        document.querySelectorAll('.status-count').forEach(el => {
          const status = el.getAttribute('data-status');
          el.textContent = data.byStatus[status] || 0;
        });
      }

      if (window.tableModule && typeof window.tableModule.updateRows === 'function') {
        window.tableModule.updateRows(data);
      } else {
        const fallbackBody = getS('#accountsTable tbody');
        if (fallbackBody && Array.isArray(data.rows)) {
          const columnsCount = document.querySelectorAll('#accountsTable thead th').length || 1;
          fallbackBody.innerHTML = !data.rows.length
            ? `<tr><td colspan="${columnsCount}" class="text-center text-muted py-5">Ничего не найдено</td></tr>`
            : data.rows.map(row => `<tr><td colspan="${columnsCount}" class="text-muted">#${row.id}</td></tr>`).join('');
        }
      }

      if (typeof domCache !== 'undefined' && typeof domCache.invalidate === 'function') {
        domCache.invalidate();
      }
      if (window.DashboardSelection && typeof window.DashboardSelection.invalidateCache === 'function') {
        window.DashboardSelection.invalidateCache();
      }

      // Обновляем информацию о пагинации (номер текущей страницы, всего страниц и активный элемент пагинации)
      if (typeof data.page === 'number') {
        const pageNumEl = getEl('pageNum');
        if (pageNumEl) {
          pageNumEl.textContent = String(data.page);
        }
        const pageSelectEl = getEl('pageSelect');
        if (pageSelectEl) {
          pageSelectEl.value = String(data.page);
        }
      }
      if (typeof data.pages === 'number') {
        const pagesCountEl = getEl('pagesCount');
        if (pagesCountEl) {
          pagesCountEl.textContent = String(data.pages);
        }
      }

      // Переключаем .active в пагинации в соответствии с текущей страницей
      if (typeof data.page === 'number') {
        document.querySelectorAll('.dashboard-table__pagination ul.pagination').forEach(ul => {
          ul.querySelectorAll('li.page-item').forEach(li => {
            const linkEl = li.querySelector('.page-link');
            if (!linkEl) return;

            const text = (linkEl.textContent || '').trim();
            const pageNum = parseInt(text, 10);
            if (!Number.isFinite(pageNum)) {
              // Стрелки, многоточия и т.п. пропускаем
              return;
            }

            if (pageNum === data.page) {
              li.classList.add('active');
              linkEl.setAttribute('aria-current', 'page');
            } else {
              li.classList.remove('active');
              if (linkEl.getAttribute('aria-current') === 'page') {
                linkEl.removeAttribute('aria-current');
              }
            }
          });
        });
      }

      const showingCountTopEl = getEl('showingCountTop');
      if (showingCountTopEl && Array.isArray(data.rows)) {
        showingCountTopEl.textContent = String(data.rows.length);
      }
      const showingOnPageTopEl = getEl('showingOnPageTop');
      if (showingOnPageTopEl && Array.isArray(data.rows)) {
        showingOnPageTopEl.textContent = String(data.rows.length);
      }
      // Общее число записей по текущему фильтру («из N») — чтобы при смене фильтра показывалось 3271, а не 135281
      if (typeof data.filteredTotal === 'number') {
        const foundTotalTopEl = getEl('foundTotalTop');
        if (foundTotalTopEl) {
          foundTotalTopEl.textContent = String(data.filteredTotal);
        }
        const foundTotalEl = getS('#foundTotal');
        if (foundTotalEl) {
          foundTotalEl.textContent = String(data.filteredTotal);
        }
        const tableEl = getEl('accountsTable');
        if (tableEl) {
          tableEl.setAttribute('aria-rowcount', String(data.filteredTotal));
        }
      }
      if (window.DashboardSelection && typeof window.DashboardSelection.updateSelectedOnPageCounter === 'function') {
        window.DashboardSelection.updateSelectedOnPageCounter();
      }

    } catch (error) {
      if (error.name === 'AbortError' || (error.message && error.message.includes && error.message.includes('aborted'))) {
        return;
      }
      logErr('Ошибка обновления данных:', error);
      const errorMessage = error.message || 'Не удалось обновить данные';
      if (typeof showToast === 'function') {
        showToast(`Ошибка обновления: ${errorMessage}`, 'error');
      }
      const tableOverlay = getEl('tableLoading');
      const statsOverlay = getEl('statsLoading');
      if (tableOverlay) tableOverlay.classList.remove('show');
      if (statsOverlay) {
        statsOverlay.classList.remove('show');
        statsOverlay.style.display = 'none';
      }
      const retryButton = document.createElement('button');
      retryButton.textContent = 'Повторить попытку';
      retryButton.className = 'btn btn-sm btn-primary mt-2';
      retryButton.onclick = () => {
        retryButton.remove();
        refreshDashboardData();
      };
      const tbody = getS('#accountsTable tbody');
      if (tbody && tbody.children.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 100;
        td.className = 'text-center py-5';
        td.innerHTML = `<i class="fas fa-exclamation-triangle fa-2x mb-3 text-danger"></i><div class="mb-3">${errorMessage}</div>`;
        td.appendChild(retryButton);
        tr.appendChild(td);
        tbody.innerHTML = '';
        tbody.appendChild(tr);
      }
    } finally {
      if (typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
      isRefreshing = false;
      setTimeout(() => {
        const table = getEl('accountsTable');
        if (!table) return;
        void table.offsetHeight;
        if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
          window.tableLayoutManager.refresh();
        } else if (typeof adjustTableDensity === 'function' && typeof syncHeaderWidths === 'function') {
          requestAnimationFrame(() => {
            adjustTableDensity();
            syncHeaderWidths();
          });
        }
        if (window.tableVirtualization && typeof window.tableVirtualization.refresh === 'function') {
          window.tableVirtualization.refresh();
        }
        if (typeof window.updateStickyScrollbar === 'function') {
          window.updateStickyScrollbar();
        }
      }, 200);

      const tableOverlay = getEl('tableLoading');
      const statsOverlay = getEl('statsLoading');
      const tableResponsive = getS('.table-responsive');
      if (tableOverlay) {
        const elapsed = Date.now() - (overlayShownAt || 0);
        const minMs = 300;
        if (elapsed < minMs) {
          setTimeout(() => tableOverlay.classList.remove('show'), minMs - elapsed);
        } else {
          tableOverlay.classList.remove('show');
        }
      }
      if (statsOverlay) {
        statsOverlay.classList.remove('show');
        statsOverlay.style.display = 'none';
      }
      if (tableResponsive) {
        tableResponsive.classList.remove('loading');
      }
    }
  }

  window.refreshDashboardData = refreshDashboardData;
  window.collectRefreshParams = collectRefreshParams;
  window.syncNumericRange = syncNumericRange;
  window.setTableLoadingState = setTableLoadingState;
  Object.defineProperty(window, 'refreshController', {
    get: () => refreshController,
    configurable: true
  });
})();

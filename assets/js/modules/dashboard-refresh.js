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

  /**
   * Контроллер ЖИВОГО запроса или null. Обнуляется владельцем по завершении,
   * поэтому «не null» действительно означает «есть что отменять».
   * Раньше он не обнулялся никогда, и каждый новый refresh звал abort() по уже
   * завершённому контроллеру — безобидно, но маскировало реальную картину.
   *
   * Порядок применения ответов гарантирован именно им: новый refresh отменяет
   * предыдущий СИНХРОННО, до своего первого await, поэтому живой запрос всегда
   * один. Отдельный счётчик «поколений» для этого не нужен — см. applyDataToDom.
   */
  let refreshController = null;

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

  /** Показать/скрыть прелоадер таблицы и карточек. Скрытие при полном refresh делается после обновления DOM (счётчики + таблица). */
  function setTableLoadingState(isLoading) {
    log('setTableLoadingState called with:', isLoading);
    const tableOverlay = getEl('tableLoading');
    const statsOverlay = getEl('statsLoading');
    const tableResponsive = getS('.table-responsive');

    if (isLoading) {
      if (tableOverlay) {
        tableOverlay.style.display = '';
        tableOverlay.classList.add('show');
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
    if (tableOverlay) tableOverlay.classList.remove('show');
    if (statsOverlay) {
      statsOverlay.classList.remove('show');
      statsOverlay.style.display = 'none';
    }
    if (tableResponsive) tableResponsive.classList.remove('loading');
  }

  async function refreshDashboardData(options) {
    if (typeof performance !== 'undefined' && performance.mark) {
      performance.mark('refresh-start');
    }
    if (refreshController) {
      try { refreshController.abort(); } catch(_) {}
    }
    const params = new URLSearchParams(window.location.search);
    if (options && (options.light === true || options.light === 'true')) {
      params.set('light', '1');
    }
    const url = 'refresh.php?' + params.toString();
    const myController = new AbortController();
    refreshController = myController;
    const signal = myController.signal;

    const isLight = options && (options.light === true || options.light === 'true');
    if (!isLight) {
      setTableLoadingState(true);
    }

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
      if (typeof performance !== 'undefined' && performance.mark) {
        performance.mark('refresh-data-received');
      }

      const rowsLen = Array.isArray(data.rows) ? data.rows.length : 0;
      const filteredTotalNum = typeof data.filteredTotal === 'number' ? data.filteredTotal : null;

      // ── Фаза 1: Обновляем счётчики СИНХРОННО (мгновенно, до тяжёлых операций) ──
      const byId = (id) => document.getElementById(id);

      const showingCountTopEl = byId('showingCountTop');
      if (showingCountTopEl) showingCountTopEl.textContent = String(rowsLen);
      const showingOnPageTopEl = byId('showingOnPageTop');
      if (showingOnPageTopEl) showingOnPageTopEl.textContent = String(rowsLen);
      if (filteredTotalNum !== null) {
        const foundTotalTopEl = byId('foundTotalTop');
        if (foundTotalTopEl) foundTotalTopEl.textContent = String(filteredTotalNum);
        const foundTotalEl = document.querySelector('#foundTotal');
        if (foundTotalEl) foundTotalEl.textContent = String(filteredTotalNum);
      }

      // Плашка о режиме поиска («показаны похожие» / «только точные»).
      // Здесь же, где счётчики, и синхронно: иначе число найденного обновится,
      // а объяснение к нему останется от прошлого запроса.
      if (typeof data.searchNoticeHtml === 'string') {
        const noticeSlot = byId('searchNoticeSlot');
        if (noticeSlot) noticeSlot.innerHTML = data.searchNoticeHtml;
      }

      // Обновляем пагинацию синхронно
      if (typeof data.page === 'number') {
        const pageNumEl = getEl('pageNum');
        if (pageNumEl) pageNumEl.textContent = String(data.page);
        const pageJumpInputEl = getEl('pageJumpInput');
        if (pageJumpInputEl) {
          pageJumpInputEl.value = String(data.page);
          if (typeof data.pages === 'number') {
            pageJumpInputEl.max = String(data.pages);
          }
        }
      }
      if (typeof data.pages === 'number') {
        const pagesCountEl = getEl('pagesCount');
        if (pagesCountEl) pagesCountEl.textContent = String(data.pages);
      }

      if (typeof data.filteredTotal === 'number') {
        const tableEl = getEl('accountsTable');
        if (tableEl) tableEl.setAttribute('aria-rowcount', String(data.filteredTotal));
        if (window.DashboardSelection) {
          window.DashboardSelection.setFilteredTotalLive(data.filteredTotal);
        }
      }

      // ── Фаза 1.5: Пагинация — обновляем СИНХРОННО. outerHTML-замена лёгкая,
      //    а всё, что откладывалось до кадра, рисковало не примениться вовсе. ──
      if (data.paginationHtml !== undefined) {
        const nav = document.getElementById('paginationNav');
        if (nav) {
          if (data.paginationHtml) {
            nav.outerHTML = data.paginationHtml;
          } else {
            nav.style.display = 'none';
            nav.innerHTML = '';
          }
        } else if (data.paginationHtml) {
          const footerNav = document.querySelector('.dashboard-table__footer-nav');
          if (footerNav) {
            footerNav.insertAdjacentHTML('beforeend', data.paginationHtml);
          }
        }
      }
      // Обёртки (footer-nav div + divider + pageinfo span) рендерятся всегда,
      // но могут быть скрыты через style="display:none" при initial pages<=1.
      // Переключаем видимость на основе текущего data.pages.
      if (typeof data.pages === 'number') {
        const multi = data.pages > 1;
        const footerNav = document.querySelector('.dashboard-table__footer-nav');
        const footerDivider = document.querySelector('.dashboard-table__footer-divider');
        const pageInfo = document.querySelector('.dashboard-table__footer-pageinfo');
        if (footerNav) footerNav.style.display = multi ? '' : 'none';
        if (footerDivider) footerDivider.style.display = multi ? '' : 'none';
        if (pageInfo) pageInfo.style.display = multi ? '' : 'none';
      }

      // ── Фаза 2: применение данных к DOM ──────────────────────────────────
      // СИНХРОННО, а не в requestAnimationFrame. Раньше весь этот блок лежал в
      // rAF «ради одного reflow», и это молча теряло обновление целиком: кадр
      // браузер выдаёт не всегда (скрытая вкладка, перекрытое окно, дросселя-
      // ция рендера), а без кадра rAF не вызывается вовсе. Наблюдалось как
      // «крестик на chip сменил URL и снял галочку, а таблица и чипы старые»:
      // ответ сервера уже пришёл, применять его было некому.
      // Потери reflow тут нет — блок делает только записи в DOM, а чтения
      // (ширины, плотность, виртуализация) остались во вложенном rAF ниже:
      // их пропуск без кадра безвреден, потому что смотреть всё равно некому.
      // Побочный эффект переноса: исключение отсюда теперь ловит catch ниже —
      // пользователь видит тост и кнопку «Повторить», а не молчаливую ошибку в
      // консоли с навсегда висящим прелоадером, как было при вызове из rAF.
      function applyDataToDom() {
        // Карточка «Всего»
        const totalEl = getS('[data-card="total"] .stat-value');
        if (totalEl && data.totals && typeof data.totals.all === 'number') {
          if (typeof updateStatValue === 'function') {
            updateStatValue(totalEl, data.totals.all);
          } else {
            totalEl.textContent = String(data.totals.all);
          }
        }

        // Карточки статусов
        if (data.byStatus && Object.keys(data.byStatus).length > 0) {
          const statusCards = document.querySelectorAll('.stat-card[data-card^="status:"]');
          log('Обновление карточек статистики:', { cards_found: statusCards.length, byStatus_keys: Object.keys(data.byStatus) });
          statusCards.forEach(cardElement => {
            const statusKey = cardElement.getAttribute('data-status');
            if (!statusKey) return;
            const cnt = typeof data.byStatus[statusKey] !== 'undefined' ? data.byStatus[statusKey] : 0;
            const valEl = cardElement.querySelector('.stat-value');
            if (valEl) {
              if (typeof updateStatValue === 'function') {
                updateStatValue(valEl, cnt);
              } else {
                valEl.textContent = String(cnt);
              }
            }
          });
          document.querySelectorAll('.status-count').forEach(el => {
            const status = el.getAttribute('data-status');
            el.textContent = data.byStatus[status] || 0;
          });
        }

        // Таблица (основная тяжёлая операция)
        if (window.tableModule && typeof window.tableModule.updateRows === 'function') {
          window.tableModule.updateRows(data);
        }
        if (typeof performance !== 'undefined' && performance.mark) {
          performance.mark('refresh-end');
          performance.measure('refresh-total', 'refresh-start', 'refresh-end');
        }
        if (!(window.tableModule && typeof window.tableModule.updateRows === 'function')) {
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

        // Пагинация уже обновлена СИНХРОННО в Фазе 1.5 (до rAF) — здесь только pageJumpInput
        if (typeof data.page === 'number') {
          const pageJumpInput = document.getElementById('pageJumpInput');
          if (pageJumpInput) {
            pageJumpInput.value = String(data.page);
            if (typeof data.pages === 'number') pageJumpInput.max = String(data.pages);
          }
        }

        if (window.DashboardSelection && typeof window.DashboardSelection.updateSelectedOnPageCounter === 'function') {
          window.DashboardSelection.updateSelectedOnPageCounter();
        }

        if (typeof window.renderActiveFiltersFromUrl === 'function') {
          window.renderActiveFiltersFromUrl();
        }

        // Скрываем прелоадер после полного обновления DOM
        if (!isLight) setTableLoadingState(false);

        // Layout-обновления в следующем кадре (после reflow от таблицы)
        requestAnimationFrame(() => {
          const table = getEl('accountsTable');
          if (!table) return;
          if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
            window.tableLayoutManager.refresh();
          } else if (typeof adjustTableDensity === 'function' && typeof syncHeaderWidths === 'function') {
            adjustTableDensity();
            syncHeaderWidths();
          }
          if (window.tableVirtualization && typeof window.tableVirtualization.refresh === 'function') {
            window.tableVirtualization.refresh();
          }
          if (typeof window.updateStickyScrollbar === 'function') {
            window.updateStickyScrollbar();
          }
        });
      }
      applyDataToDom();

    } catch (error) {
      if (error.name === 'AbortError' || (error.message && error.message.includes && error.message.includes('aborted'))) {
        return;
      }
      logErr('Ошибка обновления данных:', error);
      const errorMessage = error.message || 'Не удалось обновить данные';
      if (typeof showToast === 'function') {
        showToast(`Ошибка обновления: ${errorMessage}`, 'error');
      }
      if (!isLight) setTableLoadingState(false);
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
      // Освобождаем контроллер, только если он всё ещё наш: более поздний
      // refresh мог уже положить свой, и обнулять чужой живой запрос нельзя.
      if (refreshController === myController) {
        refreshController = null;
      }
      if (typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
      notifyAfterRefresh();
    }
  }

  // ── Подписка на «данные обновились» ──────────────────────────────────────
  // Раньше модули (favorites, undo) оборачивали window.refreshDashboardData
  // своей функцией. Такая цепочка обёрток зависела от порядка подключения
  // скриптов, а обёртка в favorites.js глотала AbortError всей цепочки —
  // ошибку соседнего обработчика было не видно. Теперь — список подписчиков.
  const afterRefreshHandlers = [];

  /**
   * Подписаться на завершение обновления таблицы.
   * Обработчик вызывается ПОСЛЕ обновления — и при успехе, и при ошибке
   * (данные могли частично обновиться, подписчикам нужно пересчитать своё).
   * Исключение внутри одного обработчика не мешает остальным.
   *
   * @param {Function} handler
   * @returns {Function} функция отписки
   */
  function onAfterRefresh(handler) {
    if (typeof handler !== 'function') return function () {};
    afterRefreshHandlers.push(handler);
    return function unsubscribe() {
      const i = afterRefreshHandlers.indexOf(handler);
      if (i !== -1) afterRefreshHandlers.splice(i, 1);
    };
  }

  /** Дёргает подписчиков, изолируя падения каждого. */
  function notifyAfterRefresh() {
    for (const handler of afterRefreshHandlers.slice()) {
      try {
        const result = handler();
        if (result && typeof result.catch === 'function') {
          result.catch(err => logErr('Ошибка обработчика afterRefresh:', err));
        }
      } catch (err) {
        logErr('Ошибка обработчика afterRefresh:', err);
      }
    }
  }

  window.refreshDashboardData = refreshDashboardData;
  window.DashboardRefresh = Object.assign(window.DashboardRefresh || {}, {
    onAfterRefresh: onAfterRefresh
  });
  window.collectRefreshParams = collectRefreshParams;
  window.syncNumericRange = syncNumericRange;
  window.setTableLoadingState = setTableLoadingState;
  Object.defineProperty(window, 'refreshController', {
    get: () => refreshController,
    configurable: true
  });
})();

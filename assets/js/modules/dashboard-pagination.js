/**
 * Модуль пагинации таблицы дашборда.
 * Единая логика перехода по страницам (клик по ссылке, поле «Перейти», Enter), обновление UI после refresh.
 * Ожидает глобальные: getElementById (или document.getElementById), refreshDashboardData через onPageChange.
 */
(function() {
  'use strict';

  const getEl = (id) => (typeof getElementById === 'function' ? getElementById(id) : document.getElementById(id));

  let initOptions = null;

  /**
   * Валидация и приведение номера страницы к диапазону 1..totalPages.
   * @param {string|number} value - введённое значение
   * @param {number} totalPages - максимальное число страниц
   * @returns {{ valid: boolean, page: number, message?: string }}
   */
  function validateAndClampPageNumber(value, totalPages) {
    const total = Math.max(1, parseInt(String(totalPages), 10) || 1);
    const num = parseInt(String(value), 10);
    if (!Number.isFinite(num) || num < 1) {
      return { valid: false, page: 1, message: 'Введите число от 1 до ' + total };
    }
    const page = Math.min(Math.max(1, num), total);
    return { valid: true, page };
  }

  /**
   * Внутренний переход: URL, UI, вызов onPageChange. Не очищает selectedIds.
   */
  function doTransition(pageNum, opts) {
    const options = opts || initOptions;
    if (!options || !options.onPageChange) return;
    const url = new URL(window.location);
    url.searchParams.set('page', String(pageNum));
    history.replaceState(null, '', url.toString());

    const pageNumEl = getEl(options.pageNumElId);
    if (pageNumEl) pageNumEl.textContent = String(pageNum);
    const pageJumpInput = getEl(options.pageJumpInputId);
    if (pageJumpInput) pageJumpInput.value = String(pageNum);

    if (window.DashboardSelection) {
      window.DashboardSelection.setSelectedAllFiltered(false);
      window.DashboardSelection.updateSelectedCount();
    }
    options.onPageChange(pageNum);
  }

  /**
   * Инициализация пагинации: делегирование кликов, кнопка «Перейти», Enter в поле.
   * @param {Object} options - pageNumElId, pagesCountElId, pageJumpInputId, pageJumpBtnId, paginationNavSelector, onPageChange
   */
  function initPagination(options) {
    const opts = {
      pageNumElId: 'pageNum',
      pagesCountElId: 'pagesCount',
      pageJumpInputId: 'pageJumpInput',
      pageJumpBtnId: 'pageJumpBtn',
      paginationNavSelector: '.dashboard-table__pagination ul.pagination',
      ...options
    };
    initOptions = opts;

    const pageJumpInput = getEl(opts.pageJumpInputId);
    const pageJumpBtn = getEl(opts.pageJumpBtnId);

    document.addEventListener('click', function(e) {
      const a = e.target.closest(opts.paginationNavSelector + ' a.page-link');
      if (!a) return;
      const li = a.closest('li');
      if (li && li.classList.contains('disabled')) {
        e.preventDefault();
        return;
      }
      e.preventDefault();
      const href = a.getAttribute('href') || '';
      if (!href) return;
      const url = new URL(href, window.location.origin);
      const pageParam = parseInt(url.searchParams.get('page') || '1', 10);
      if (!Number.isFinite(pageParam) || pageParam < 1) return;
      doTransition(pageParam, opts);
    });

    if (pageJumpBtn && pageJumpInput) {
      function applyPageJump() {
        const pagesEl = getEl(opts.pagesCountElId);
        const totalPages = pagesEl ? parseInt(pagesEl.textContent, 10) : 1;
        const result = validateAndClampPageNumber(pageJumpInput.value, totalPages);
        pageJumpInput.value = String(result.page);
        if (result.valid) {
          doTransition(result.page, opts);
        } else if (typeof showToast === 'function' && result.message) {
          showToast(result.message, 'warning');
        } else {
          doTransition(result.page, opts);
        }
      }
      pageJumpBtn.addEventListener('click', applyPageJump);
      pageJumpInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyPageJump();
        }
      });
    }
  }

  /**
   * Обновление UI пагинации после ответа refresh (вызывается из dashboard-refresh.js).
   * @param {{ page: number, pages?: number }} data
   */
  function updatePaginationUI(data) {
    if (typeof data.page !== 'number') return;
    const opts = initOptions || {
      pageNumElId: 'pageNum',
      pagesCountElId: 'pagesCount',
      pageJumpInputId: 'pageJumpInput',
      paginationNavSelector: '.dashboard-table__pagination ul.pagination'
    };

    const pageNumEl = getEl(opts.pageNumElId);
    if (pageNumEl) pageNumEl.textContent = String(data.page);

    const pageJumpInput = getEl(opts.pageJumpInputId);
    if (pageJumpInput) {
      pageJumpInput.value = String(data.page);
      if (typeof data.pages === 'number') {
        pageJumpInput.setAttribute('max', String(data.pages));
        const currentVal = parseInt(pageJumpInput.value, 10);
        if (Number.isFinite(currentVal) && currentVal > data.pages) {
          pageJumpInput.value = String(data.page);
        }
      }
    }

    if (typeof data.pages === 'number') {
      const pagesCountEl = getEl(opts.pagesCountElId);
      if (pagesCountEl) pagesCountEl.textContent = String(data.pages);
    }

    const nav = document.querySelector(opts.paginationNavSelector);
    if (nav) {
      nav.querySelectorAll('li.page-item').forEach(li => {
        const linkEl = li.querySelector('.page-link');
        if (!linkEl) return;
        const text = (linkEl.textContent || '').trim();
        const pageNum = parseInt(text, 10);
        if (!Number.isFinite(pageNum)) return;
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
    }
  }

  /**
   * Публичный переход на страницу (валидация по totalPages из DOM или options).
   * @param {number} page
   * @param {{ totalPages?: number }} options
   */
  function goToPage(page, options) {
    let totalPages = options && typeof options.totalPages === 'number' ? options.totalPages : null;
    if (totalPages == null) {
      const pagesEl = getEl(initOptions ? initOptions.pagesCountElId : 'pagesCount');
      totalPages = pagesEl ? parseInt(pagesEl.textContent, 10) : 1;
    }
    totalPages = Math.max(1, totalPages || 1);
    const p = Math.min(Math.max(1, parseInt(String(page), 10) || 1), totalPages);
    doTransition(p, initOptions);
  }

  window.DashboardPagination = {
    initPagination,
    validateAndClampPageNumber,
    updatePaginationUI,
    goToPage
  };
})();

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
   * Строит href для перехода на страницу pageNum (текущий path + query с page=pageNum).
   */
  function buildPageHref(pageNum) {
    const url = new URL(window.location);
    url.searchParams.set('page', String(pageNum));
    return url.pathname + '?' + url.searchParams.toString();
  }

  /**
   * Обновление UI пагинации после ответа refresh (вызывается из dashboard-refresh.js).
   * Делает текущую страницу некликабельной (span), остальные — кликабельными (a).
   * Обновляет disabled у кнопок «Первая», «Предыдущая», «Следующая», «Последняя».
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
      const allItems = Array.from(nav.querySelectorAll('li.page-item'));
      const totalPages = typeof data.pages === 'number' ? data.pages : 1;

      // Числовые ссылки: текущая страница — span (некликабельна), остальные — a с href
      allItems.forEach(li => {
        const linkEl = li.querySelector('.page-link');
        if (!linkEl) return;
        const text = (linkEl.textContent || '').trim();
        const pageNum = parseInt(text, 10);
        if (!Number.isFinite(pageNum)) return;

        if (pageNum === data.page) {
          li.classList.add('active');
          if (linkEl.tagName !== 'SPAN') {
            const span = document.createElement('span');
            span.className = 'page-link';
            span.setAttribute('aria-current', 'page');
            span.textContent = String(pageNum);
            li.replaceChild(span, linkEl);
          } else {
            linkEl.setAttribute('aria-current', 'page');
          }
        } else {
          li.classList.remove('active');
          if (linkEl.getAttribute('aria-current') === 'page') {
            linkEl.removeAttribute('aria-current');
          }
          if (linkEl.tagName !== 'A') {
            const a = document.createElement('a');
            a.className = 'page-link';
            a.href = buildPageHref(pageNum);
            a.textContent = String(pageNum);
            li.replaceChild(a, linkEl);
          } else {
            linkEl.href = buildPageHref(pageNum);
          }
        }
      });

      // Кнопки «Первая», «Предыдущая», «Следующая», «Последняя»: порядок в footer — первые два и последние два li
      if (allItems.length >= 4) {
        const firstLi = allItems[0];
        const prevLi = allItems[1];
        const nextLi = allItems[allItems.length - 2];
        const lastLi = allItems[allItems.length - 1];

        if (data.page <= 1) {
          firstLi.classList.add('disabled');
          prevLi.classList.add('disabled');
        } else {
          firstLi.classList.remove('disabled');
          prevLi.classList.remove('disabled');
          const firstA = firstLi.querySelector('a.page-link');
          const prevA = prevLi.querySelector('a.page-link');
          if (firstA) firstA.href = buildPageHref(1);
          if (prevA) prevA.href = buildPageHref(Math.max(1, data.page - 1));
        }

        if (data.page >= totalPages) {
          nextLi.classList.add('disabled');
          lastLi.classList.add('disabled');
        } else {
          nextLi.classList.remove('disabled');
          lastLi.classList.remove('disabled');
          const nextA = nextLi.querySelector('a.page-link');
          const lastA = lastLi.querySelector('a.page-link');
          if (nextA) nextA.href = buildPageHref(Math.min(totalPages, data.page + 1));
          if (lastA) lastA.href = buildPageHref(totalPages);
        }
      }
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

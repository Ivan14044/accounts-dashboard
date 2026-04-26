/**
 * =============================================================================
 * PAGINATION — единственный обработчик пагинации дашборда
 * =============================================================================
 *
 * Заменяет разрозненные обработчики из dashboard.js, dashboard-init.js.
 * Принцип работы:
 *   1. Клик по [data-page] → обновляем URL → вызываем refreshDashboardData()
 *   2. refresh.php возвращает paginationHtml → dashboard-refresh.js заменяет #paginationNav
 *   3. Кнопки "Перейти" и Enter в поле → аналогично п.1
 *   4. Смена per_page → сброс на страницу 1 → refresh
 *
 * Все обработчики используют делегирование событий на document —
 * работают и после замены HTML пагинации через outerHTML.
 * =============================================================================
 */
(function () {
  'use strict';

  /* ─────────────────────────────────────────────────────────────────────────
   * Переход на страницу: обновляем URL и запрашиваем данные с сервера
   * ───────────────────────────────────────────────────────────────────────── */
  function goToPage(pageNum) {
    var total = parseInt((document.getElementById('pagesCount') || {}).textContent || '1', 10);
    var page  = Math.max(1, Math.min(pageNum, isNaN(total) ? pageNum : total));

    var url = new URL(window.location.href);
    url.searchParams.set('page', String(page));
    history.replaceState(null, '', url.toString());

    // Обновляем счётчик «Стр. X из N» немедленно (до ответа сервера)
    var pageNumEl = document.getElementById('pageNum');
    if (pageNumEl) pageNumEl.textContent = String(page);

    // Обновляем поле быстрого перехода
    var jumpInput = document.getElementById('pageJumpInput');
    if (jumpInput) jumpInput.value = String(page);

    // Сбрасываем «выбрать все отфильтрованные» — выбор конкретных строк сохраняется
    if (window.DashboardSelection && typeof window.DashboardSelection.setSelectedAllFiltered === 'function') {
      window.DashboardSelection.setSelectedAllFiltered(false);
    }

    // Запрашиваем новые данные (глобальная функция из dashboard-refresh.js)
    if (typeof window.refreshDashboardData === 'function') {
      window.refreshDashboardData();
    }
  }

  /* ─────────────────────────────────────────────────────────────────────────
   * 1. Клик по кнопке пагинации [data-page]
   * ───────────────────────────────────────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    // Ищем ближайшую ссылку с data-page внутри pagination
    var link = e.target.closest && e.target.closest('.pagination a.page-link[data-page]');
    if (!link) return;

    // Игнорируем disabled-кнопки
    var li = link.closest('li.page-item');
    if (li && li.classList.contains('disabled')) {
      e.preventDefault();
      return;
    }

    e.preventDefault();

    var page = parseInt(link.getAttribute('data-page') || '', 10);
    if (!isNaN(page) && page > 0) {
      goToPage(page);
    }
  }, false);

  /* ─────────────────────────────────────────────────────────────────────────
   * 2. Кнопка «Перейти»
   * ───────────────────────────────────────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    if (e.target.id !== 'pageJumpBtn') return;

    var input = document.getElementById('pageJumpInput');
    if (!input) return;

    var page = parseInt(input.value, 10);
    if (!isNaN(page) && page > 0) {
      goToPage(page);
    }
  }, false);

  /* ─────────────────────────────────────────────────────────────────────────
   * 3. Enter в поле «Перейти на стр.»
   * ───────────────────────────────────────────────────────────────────────── */
  document.addEventListener('keydown', function (e) {
    if (e.target.id !== 'pageJumpInput') return;
    if (e.key !== 'Enter') return;

    e.preventDefault();
    var page = parseInt(e.target.value, 10);
    if (!isNaN(page) && page > 0) {
      goToPage(page);
    }
  }, false);

  /* ─────────────────────────────────────────────────────────────────────────
   * 4. Смена количества записей на странице (per_page)
   *    — сбрасываем на первую страницу и обновляем
   * ───────────────────────────────────────────────────────────────────────── */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('select[name="per_page"]')) return;

    var url = new URL(window.location.href);
    var v = parseInt(e.target.value, 10);

    if (!isNaN(v) && v > 0) {
      url.searchParams.set('per_page', String(v));
    } else {
      url.searchParams.delete('per_page');
    }
    // При смене per_page всегда возвращаемся на первую страницу
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());

    // Сбрасываем выделение строк
    if (window.DashboardSelection && typeof window.DashboardSelection.clearSelection === 'function') {
      window.DashboardSelection.clearSelection();
    }

    if (typeof window.refreshDashboardData === 'function') {
      window.refreshDashboardData();
    }
  }, false);

  // Экспорт для возможной отладки
  window.Pagination = { goToPage: goToPage };

})();

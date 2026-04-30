/**
 * Per-page selector — переключение количества строк на странице через URL.
 * При смене сбрасываем page=1 (иначе можно попасть на пустую страницу,
 * если текущий номер выходит за новые границы).
 */
(function () {
  'use strict';

  function init() {
    var select = document.getElementById('perPageSelect');
    if (!select) return;

    select.addEventListener('change', function () {
      var value = parseInt(select.value, 10);
      if (!value || value < 1) return;

      // Берём базовый набор GET-параметров (без page и per_page) из data-атрибута
      var wrap = select.closest('.dashboard-table__per-page');
      var baseQs = (wrap && wrap.getAttribute('data-base-qs')) || '';

      var params = new URLSearchParams(baseQs);
      params.set('per_page', String(value));
      params.set('page', '1');

      // Перенаправляемся на новый URL — серверный path не меняем
      window.location.search = '?' + params.toString();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

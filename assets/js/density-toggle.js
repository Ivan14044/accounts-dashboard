/**
 * Density Toggle — переключатель плотности строк таблицы.
 * Состояния: comfortable (default) / cozy / compact.
 * Сохраняется в localStorage, восстанавливается на следующем визите.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'dashboard.density';
  var VALID = ['comfortable', 'cozy', 'compact'];

  function applyDensity(density) {
    if (VALID.indexOf(density) === -1) density = 'comfortable';

    var table = document.querySelector('.dashboard-table');
    if (!table) return;

    VALID.forEach(function (v) {
      table.classList.remove('density-' + v);
    });
    if (density !== 'comfortable') {
      table.classList.add('density-' + density);
    }

    // Синхронизируем UI-кнопки
    var buttons = document.querySelectorAll('.density-toggle__btn');
    buttons.forEach(function (btn) {
      var pressed = btn.getAttribute('data-density') === density;
      btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    });
  }

  function readSaved() {
    try {
      return localStorage.getItem(STORAGE_KEY) || 'comfortable';
    } catch (e) {
      return 'comfortable';
    }
  }

  function saveDensity(density) {
    try {
      localStorage.setItem(STORAGE_KEY, density);
    } catch (e) {
      /* private mode / quota — ignore */
    }
  }

  function init() {
    applyDensity(readSaved());

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.density-toggle__btn');
      if (!btn) return;
      var density = btn.getAttribute('data-density');
      if (!density) return;
      applyDensity(density);
      saveDensity(density);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

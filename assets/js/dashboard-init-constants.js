/**
 * Константы дашборда из конфига и ключи localStorage. Подключается после config-script.php.
 */
(function () {
  'use strict';
  var cfg = window.__DASHBOARD_CONFIG__ || {};
  window.LS_KEY_COLUMNS = 'dashboard_visible_columns';
  window.LS_KEY_CARDS = 'dashboard_visible_cards';
  window.LS_KEY_KNOWN_COLS = 'dashboard_known_columns';
  window.LS_KEY_HIDDEN_CARDS = 'dashboard_hidden_cards';
  window.ACTIVE_FILTERS_COUNT = cfg.activeFiltersCount || 0;
})();

/**
 * Ключи localStorage и константы из серверного конфига.
 *
 * Зачем отдельный файл. Ключи объявлялись в dashboard-init.js через `const`, а
 * `const` верхнего уровня НЕ попадает в window и не виден из других файлов.
 * Из-за этого каждый вынос куска в модуль тянул за собой копию строкового
 * ключа: разъедутся копии — пользователь потеряет свои настройки колонок или
 * скрытых карточек, причём молча. Теперь источник один, а модули читают
 * window.LS_KEY_*.
 *
 * Подключается ПЕРВЫМ среди скриптов дашборда, до dashboard-init.js и модулей.
 *
 * История: файл существовал как assets/js/dashboard-init-constants.js, но его
 * не подключал ни один шаблон — остался от неоконченного разбора dashboard-init.js
 * (там же были features/custom-cards.js и features/settings-columns-cards.js).
 * Переехал в modules/ и включён в работу 2026-08-09.
 */
(function () {
  'use strict';

  // Ключи localStorage. Менять значения нельзя без миграции: у пользователей
  // в браузере уже лежат настройки под этими именами.
  window.LS_KEY_COLUMNS     = 'dashboard_visible_columns';
  window.LS_KEY_CARDS       = 'dashboard_visible_cards';
  window.LS_KEY_KNOWN_COLS  = 'dashboard_known_columns';
  window.LS_KEY_HIDDEN_CARDS = 'dashboard_hidden_cards';
  window.LS_KEY_CUSTOM_CARDS = 'dashboard_custom_cards_v3';

  // Число активных фильтров приходит с сервера через init-script.php.
  // Старая версия файла читала window.__DASHBOARD_CONFIG__ — такого объекта в
  // проекте нет, актуальный называется DashboardConfig.
  var cfg = window.DashboardConfig || {};
  window.ACTIVE_FILTERS_COUNT = cfg.activeFiltersCount || 0;
})();

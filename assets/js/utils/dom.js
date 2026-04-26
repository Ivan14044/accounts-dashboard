/**
 * Утилиты доступа к DOM. Подключается до остальных модулей дашборда.
 */
(function () {
  'use strict';
  function getElementById(id) {
    if (typeof domCache !== 'undefined' && domCache.getById) {
      return domCache.getById(id);
    }
    return document.getElementById(id);
  }
  function getSel(selector) {
    if (typeof domCache !== 'undefined' && domCache.get) {
      return domCache.get(selector);
    }
    return document.querySelector(selector);
  }
  window.getElementById = getElementById;
  window.getSel = getSel;
})();

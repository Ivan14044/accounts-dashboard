/**
 * Настройки колонок и видимости карточек (loadSettings, saveSettings, toggleColumnVisibility, toggleCardVisibility).
 * Зависит: getSel, logger, showToast, LS_KEY_*, dom.js
 */
(function () {
  'use strict';
  var getSel = window.getSel;
  var logger = window.logger || { warn: function () { }, error: function () { }, debug: function () { } };
  var showToast = window.showToast || function () { };

  function toggleColumnVisibility(colName, visible) {
    var colElements = document.querySelectorAll('[data-col="' + colName + '"]');
    colElements.forEach(function (el) {
      el.style.display = visible ? '' : 'none';
    });
  }

  function toggleCardVisibility(cardName, visible) {
    if (!cardName || !cardName.trim()) {
      logger.warn('toggleCardVisibility: cardName is empty');
      return;
    }
    var escaped = cardName.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    var cardElement = getSel('.stat-card[data-card="' + escaped + '"]');
    if (!cardElement) {
      logger.warn('Card not found: ' + cardName);
      return;
    }
    if (visible) {
      cardElement.classList.remove('hidden', 'd-none', 'force-hidden');
      cardElement.removeAttribute('hidden');
      cardElement.style.setProperty('display', 'flex', 'important');
      cardElement.style.setProperty('opacity', '1', 'important');
      cardElement.style.setProperty('visibility', 'visible', 'important');
      cardElement.style.setProperty('pointer-events', 'auto', 'important');
      requestAnimationFrame(function () {
        if (cardElement && !cardElement.classList.contains('hidden')) {
          cardElement.style.removeProperty('display');
          cardElement.style.removeProperty('opacity');
          cardElement.style.removeProperty('visibility');
          cardElement.style.removeProperty('pointer-events');
        }
      });
    } else {
      cardElement.classList.add('hidden');
      cardElement.setAttribute('hidden', '');
      cardElement.style.setProperty('display', 'none', 'important');
      cardElement.style.setProperty('opacity', '0', 'important');
      cardElement.style.setProperty('visibility', 'hidden', 'important');
      cardElement.style.setProperty('pointer-events', 'none', 'important');
      cardElement.classList.remove('d-none', 'force-hidden');
    }
    // Forced reflow (void offsetHeight) removed — unnecessary performance cost
  }

  function loadSettings() {
    try {
      var savedColumns = localStorage.getItem(window.LS_KEY_COLUMNS);
      var visibleColumns = savedColumns ? JSON.parse(savedColumns) : null;
      var knownCols = [];
      try {
        var k = localStorage.getItem(window.LS_KEY_KNOWN_COLS);
        if (k) knownCols = JSON.parse(k) || [];
      } catch (_) { }
      var toggles = document.querySelectorAll('.column-toggle');
      var ALL_COL_KEYS = Array.from(toggles).map(function (cb) { return cb.getAttribute('data-col'); });
      var newCols = ALL_COL_KEYS.filter(function (c) { return knownCols.indexOf(c) === -1; });
      toggles.forEach(function (cb) {
        var colName = cb.getAttribute('data-col');
        var isChecked = cb.checked;
        if (visibleColumns) isChecked = visibleColumns.indexOf(colName) !== -1 || newCols.indexOf(colName) !== -1;
        cb.checked = isChecked;
        toggleColumnVisibility(colName, isChecked);
      });
      localStorage.setItem(window.LS_KEY_KNOWN_COLS, JSON.stringify(ALL_COL_KEYS));
      var hiddenCards = [];
      try {
        var savedHidden = localStorage.getItem(window.LS_KEY_HIDDEN_CARDS);
        if (savedHidden) hiddenCards = JSON.parse(savedHidden);
      } catch (e) { logger.error('Error loading hidden cards in loadSettings:', e); }
      document.querySelectorAll('.card-toggle').forEach(function (cb) {
        var cardName = cb.getAttribute('data-card');
        if (!cardName || !cardName.trim()) return;
        cb.checked = hiddenCards.indexOf(cardName) === -1;
        toggleCardVisibility(cardName, cb.checked);
      });
    } catch (e) { logger.error('Error loading settings:', e); }
  }

  function saveSettings() {
    try {
      var visibleColumns = [];
      document.querySelectorAll('.column-toggle:checked').forEach(function (cb) {
        visibleColumns.push(cb.getAttribute('data-col'));
      });
      localStorage.setItem(window.LS_KEY_COLUMNS, JSON.stringify(visibleColumns));
      var toggles = document.querySelectorAll('.column-toggle');
      var ALL_COL_KEYS = Array.from(toggles).map(function (cb) { return cb.getAttribute('data-col'); });
      localStorage.setItem(window.LS_KEY_KNOWN_COLS, JSON.stringify(ALL_COL_KEYS));
      showToast('Настройки сохранены', 'success');
    } catch (e) {
      logger.error('Error saving settings:', e);
      showToast('Ошибка сохранения настроек', 'error');
    }
  }

  function applySavedColumnVisibility() {
    try {
      var savedColumns = localStorage.getItem(window.LS_KEY_COLUMNS);
      if (!savedColumns) return;
      var visibleColumns = JSON.parse(savedColumns);
      var allToggles = document.querySelectorAll('.column-toggle');
      var allCols = Array.from(allToggles).map(function (cb) { return cb.getAttribute('data-col'); });
      allCols.forEach(function (col) {
        toggleColumnVisibility(col, visibleColumns.indexOf(col) !== -1);
      });
    } catch (_) { }
  }

  window.loadSettings = loadSettings;
  window.saveSettings = saveSettings;
  window.toggleColumnVisibility = toggleColumnVisibility;
  window.applySavedColumnVisibility = applySavedColumnVisibility;
  window.toggleCardVisibility = toggleCardVisibility;
})();

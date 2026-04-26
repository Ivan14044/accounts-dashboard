/**
 * Скрытие/показ карточек статистики (loadHiddenCards, saveHiddenCards, hideCard, showCard).
 * Зависит: getSel, toggleCardVisibility, logger, LS_KEY_HIDDEN_CARDS
 */
(function () {
  'use strict';
  var getSel = window.getSel;
  var toggleCardVisibility = window.toggleCardVisibility;
  var logger = window.logger || { warn: function () {}, error: function () {}, debug: function () {} };
  var LS_KEY = window.LS_KEY_HIDDEN_CARDS || 'dashboard_hidden_cards';

  function loadHiddenCardsFromLocalStorage() {
    try {
      var saved = localStorage.getItem(LS_KEY);
      if (!saved) return;
      var hiddenIds = JSON.parse(saved);
      hiddenIds.forEach(function (cardId) {
        var card = getSel('.stat-card[data-card="' + cardId + '"]');
        if (card) {
          card.classList.add('hidden');
          card.style.display = 'none';
        }
      });
    } catch (_) {}
  }

  async function saveHiddenCards() {
    try {
      var allHidden = document.querySelectorAll('.stat-card.hidden');
      var hiddenCards = Array.from(allHidden)
        .map(function (card) { return card.getAttribute('data-card'); })
        .filter(function (id) { return id; });
      // Синхронизируем _hiddenCardsToHide (если observer ещё активен)
      if (window._hiddenCardsToHide) {
        window._hiddenCardsToHide = new Set(hiddenCards);
      }
      try {
        localStorage.setItem(LS_KEY, JSON.stringify(hiddenCards));
      } catch (_) { logger.error('Ошибка сохранения в localStorage'); }
      try {
        var response = await fetch('/api/settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ type: 'hidden_cards', value: hiddenCards, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
        });
        if (!response.ok) logger.warn('Failed to save hidden cards to server, saved to localStorage only');
      } catch (e) {
        logger.error('Ошибка при сохранении в БД:', e);
      }
    } catch (e) { logger.error('Error saving hidden cards:', e); }
  }

  async function loadHiddenCards() {
    try {
      var localHidden = [];
      try {
        var saved = localStorage.getItem(LS_KEY);
        if (saved) localHidden = JSON.parse(saved);
      } catch (_) {}
      var response = await fetch('/api/settings?type=hidden_cards');
      if (response.ok) {
        var data = await response.json();
        if (data.success && Array.isArray(data.value)) {
          var cardsToHide = data.value;
          if (cardsToHide.length === 0 && localHidden.length > 0) {
            cardsToHide = localHidden;
            try {
              await fetch('/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'hidden_cards', value: cardsToHide, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
              });
            } catch (_) {}
          } else if (cardsToHide.length > 0) {
            try { localStorage.setItem(LS_KEY, JSON.stringify(cardsToHide)); } catch (_) {}
          }
          cardsToHide.forEach(function (cardId) {
            var card = getSel('.stat-card[data-card="' + cardId + '"]');
            if (card) {
              card.classList.add('hidden');
              card.style.display = 'none';
            }
          });
          return;
        }
      }
      loadHiddenCardsFromLocalStorage();
    } catch (e) {
      logger.warn('Error loading hidden cards from server:', e);
      loadHiddenCardsFromLocalStorage();
    }
  }

  async function hideCard(cardId) {
    if (!cardId || !cardId.trim()) { logger.warn('hideCard: cardId is empty'); return; }
    try {
      toggleCardVisibility(cardId, false);
      await saveHiddenCards();
      var escaped = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
      var checkbox = getSel('.card-toggle[data-card="' + escaped + '"]');
      if (checkbox) checkbox.checked = false;
    } catch (e) {
      logger.error('Error hiding card:', e);
      toggleCardVisibility(cardId, true);
      throw e;
    }
  }

  async function showCard(cardId) {
    if (!cardId || !cardId.trim()) { logger.warn('showCard: cardId is empty'); return; }
    try {
      toggleCardVisibility(cardId, true);
      await saveHiddenCards();
      var escaped = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
      var checkbox = getSel('.card-toggle[data-card="' + escaped + '"]');
      if (checkbox) checkbox.checked = true;
    } catch (e) {
      logger.error('Error showing card:', e);
      toggleCardVisibility(cardId, false);
      throw e;
    }
  }

  window.loadHiddenCards = loadHiddenCards;
  window.loadHiddenCardsFromLocalStorage = loadHiddenCardsFromLocalStorage;
  window.saveHiddenCards = saveHiddenCards;
  window.hideCard = hideCard;
  window.showCard = showCard;
})();

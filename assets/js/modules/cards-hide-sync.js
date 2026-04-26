/**
 * Синхронное скрытие карточек до/во время загрузки DOM.
 * Загружается в head для предотвращения мигания скрытых карточек.
 * Не зависит от logger (проверяет typeof перед вызовом).
 *
 * ВАЖНО: _hiddenCardsToHide используется ТОЛЬКО при начальной загрузке.
 * После DOMContentLoaded observer отключается, чтобы не конфликтовать
 * с showCard/hideCard, которые управляют видимостью в runtime.
 */
(function() {
  try {
    var saved = localStorage.getItem('dashboard_hidden_cards');
    if (!saved) return;

    var hiddenIds = JSON.parse(saved);
    if (!Array.isArray(hiddenIds) || hiddenIds.length === 0) return;

    window._hiddenCardsToHide = new Set(hiddenIds);

    function hideCardImmediately(card) {
      var cardId = card.getAttribute('data-card');
      if (!cardId) return;
      if (!window._hiddenCardsToHide || !window._hiddenCardsToHide.has(cardId)) return;

      card.classList.add('hidden');
      card.style.setProperty('display', 'none', 'important');
      card.style.setProperty('visibility', 'hidden', 'important');
      card.style.setProperty('opacity', '0', 'important');
      card.setAttribute('hidden', '');
    }

    // MutationObserver только для начальной отрисовки (до window.load)
    var observer = new MutationObserver(function(mutations) {
      if (!window._hiddenCardsToHide) return;
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node.nodeType !== 1) return;
          if (node.classList && node.classList.contains('stat-card')) {
            hideCardImmediately(node);
          }
          if (node.querySelectorAll) {
            node.querySelectorAll('.stat-card').forEach(hideCardImmediately);
          }
        });
      });
    });

    function startObserving() {
      var statsContainer = document.querySelector('.stats-grid');
      if (statsContainer) {
        observer.observe(statsContainer, { childList: true, subtree: true });
      } else if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
      }
    }

    if (document.body) startObserving();
    else document.addEventListener('DOMContentLoaded', startObserving);

    function applyHidingToExistingCards() {
      if (!window._hiddenCardsToHide || !document.querySelectorAll) return;
      document.querySelectorAll('.stat-card').forEach(function(card) {
        var cardId = card.getAttribute('data-card');
        if (cardId && window._hiddenCardsToHide.has(cardId)) {
          hideCardImmediately(card);
        }
      });
    }

    // Применяем скрытие при первой возможности
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', applyHidingToExistingCards);
    } else {
      applyHidingToExistingCards();
    }

    // После полной загрузки — финальное применение, затем ОТКЛЮЧАЕМ observer
    window.addEventListener('load', function() {
      applyHidingToExistingCards();
      // Observer больше не нужен: runtime-управление через showCard/hideCard
      observer.disconnect();
      window._hiddenCardsToHide = null;
    });
  } catch (e) {
    if (typeof logger !== 'undefined') logger.error('Error in cards-hide-sync:', e);
  }
})();

/**
 * Синхронное скрытие карточек до/во время загрузки DOM.
 * Загружается в head для предотвращения мигания скрытых карточек.
 * Не зависит от logger (проверяет typeof перед вызовом).
 */
(function() {
  try {
    const saved = localStorage.getItem('dashboard_hidden_cards');
    if (saved) {
      const hiddenIds = JSON.parse(saved);
      if (Array.isArray(hiddenIds) && hiddenIds.length > 0) {
        window._hiddenCardsToHide = new Set(hiddenIds);

        function hideCardImmediately(card) {
          const cardId = card.getAttribute('data-card');
          if (!cardId) return;

          if (window._hiddenCardsToHide.has(cardId)) {
            card.classList.add('hidden');
            card.style.setProperty('display', 'none', 'important');
            card.style.setProperty('visibility', 'hidden', 'important');
            card.style.setProperty('opacity', '0', 'important');
            card.setAttribute('hidden', '');
            if (typeof logger !== 'undefined') logger.debug('⚡ Немедленно скрыта карточка (MutationObserver):', cardId);
          } else if (cardId === 'custom:email_twofa' && typeof logger !== 'undefined') {
            logger.debug('🔍 Карточка "Email + 2FA" найдена, но НЕ в списке скрытых.');
          }
        }

        const observer = new MutationObserver(function(mutations) {
          const cardsToHide = new Set();
          mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
              if (node.nodeType === 1) {
                if (node.classList && node.classList.contains('stat-card')) cardsToHide.add(node);
                if (node.querySelectorAll) {
                  const cards = node.querySelectorAll('.stat-card');
                  cards.forEach(function(c) { cardsToHide.add(c); });
                }
              }
            });
          });
          if (cardsToHide.size > 0) {
            requestAnimationFrame(function() {
              cardsToHide.forEach(hideCardImmediately);
            });
          }
        });

        function startObserving() {
          const statsContainer = document.querySelector('.stats-grid');
          if (statsContainer) {
            observer.observe(statsContainer, { childList: true, subtree: true });
          } else if (document.body) {
            observer.observe(document.body, { childList: true, subtree: true });
          }
        }

        if (document.body) startObserving();
        else document.addEventListener('DOMContentLoaded', startObserving);

        function applyHidingToExistingCards() {
          if (!document.querySelectorAll) return;
          const cards = document.querySelectorAll('.stat-card');
          var hiddenCount = 0, emailTwoFaFound = false;
          cards.forEach(function(card) {
            const cardId = card.getAttribute('data-card');
            if (!cardId) return;
            if (cardId === 'custom:email_twofa') {
              emailTwoFaFound = true;
              if (!window._hiddenCardsToHide.has(cardId)) {
                if (typeof logger !== 'undefined') logger.warn('⚠️ Карточка "Email + 2FA" найдена, но НЕ в списке скрытых.');
                window._hiddenCardsToHide.add(cardId);
                try {
                  localStorage.setItem('dashboard_hidden_cards', JSON.stringify(Array.from(window._hiddenCardsToHide)));
                } catch (e) { /* ignore */ }
              }
            }
            if (window._hiddenCardsToHide.has(cardId)) {
              hideCardImmediately(card);
              hiddenCount++;
            }
          });
          if (hiddenCount > 0 && typeof logger !== 'undefined') logger.debug('⚡ Применено скрытие к существующим карточкам:', hiddenCount);
          if (!emailTwoFaFound && typeof logger !== 'undefined') logger.warn('⚠️ Карточка "Email + 2FA" не найдена в DOM при применении скрытия');
        }

        function tryApplyHiding() {
          if (document.body && document.querySelectorAll) {
            applyHidingToExistingCards();
            setTimeout(applyHidingToExistingCards, 10);
            setTimeout(applyHidingToExistingCards, 50);
            setTimeout(applyHidingToExistingCards, 100);
          }
        }

        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', tryApplyHiding);
        } else {
          tryApplyHiding();
        }

        window.addEventListener('load', function() { setTimeout(applyHidingToExistingCards, 0); });
      }
    }
  } catch (e) {
    if (typeof logger !== 'undefined') logger.error('Error reading hidden cards:', e);
  }
})();

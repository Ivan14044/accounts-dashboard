(function() {
      try {
        const saved = localStorage.getItem('dashboard_hidden_cards');
        if (saved) {
          const hiddenIds = JSON.parse(saved);
          if (Array.isArray(hiddenIds) && hiddenIds.length > 0) {
            // Сохраняем список для применения после загрузки DOM
            window._hiddenCardsToHide = new Set(hiddenIds);
            
            // Функция для немедленного скрытия карточки
            function hideCardImmediately(card) {
              const cardId = card.getAttribute('data-card');
              if (!cardId) {
                return; // Пропускаем карточки без ID
              }
              
              if (window._hiddenCardsToHide.has(cardId)) {
                // Применяем все способы скрытия для надежности
                card.classList.add('hidden');
                card.style.setProperty('display', 'none', 'important');
                card.style.setProperty('visibility', 'hidden', 'important');
                card.style.setProperty('opacity', '0', 'important');
                card.setAttribute('hidden', '');
                console.log('⚡ Немедленно скрыта карточка (MutationObserver):', cardId);
              } else {
                // Логируем карточки, которые не в списке скрытых (для отладки)
                if (cardId === 'custom:email_twofa') {
                  console.log('🔍 Карточка "Email + 2FA" найдена, но НЕ в списке скрытых. Список скрытых:', Array.from(window._hiddenCardsToHide));
                }
              }
            }
            
            // Используем MutationObserver для отслеживания появления карточек
            const observer = new MutationObserver(function(mutations) {
              mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                  if (node.nodeType === 1) { // Element node
                    // Проверяем сам узел
                    if (node.classList && node.classList.contains('stat-card')) {
                      hideCardImmediately(node);
                    }
                    // Проверяем дочерние элементы
                    if (node.querySelectorAll) {
                      const cards = node.querySelectorAll('.stat-card');
                      cards.forEach(hideCardImmediately);
                    }
                  }
                });
              });
            });
            
            // Начинаем наблюдение сразу
            if (document.body) {
              observer.observe(document.body, {
                childList: true,
                subtree: true
              });
            } else {
              // Если body еще не готов, ждем его
              document.addEventListener('DOMContentLoaded', function() {
                observer.observe(document.body, {
                  childList: true,
                  subtree: true
                });
              });
            }
            
            // Также применяем скрытие к уже существующим карточкам
            function applyHidingToExistingCards() {
              if (document.querySelectorAll) {
                const cards = document.querySelectorAll('.stat-card');
                let hiddenCount = 0;
                let emailTwoFaFound = false;
                
                cards.forEach(function(card) {
                  const cardId = card.getAttribute('data-card');
                  if (!cardId) return;
                  
                  // Специальная проверка для карточки "Email + 2FA"
                  if (cardId === 'custom:email_twofa') {
                    emailTwoFaFound = true;
                    // Если карточка должна быть скрыта, но не в списке - добавляем в список
                    if (!window._hiddenCardsToHide.has(cardId)) {
                      console.warn('⚠️ Карточка "Email + 2FA" найдена, но НЕ в списке скрытых. Добавляем в список для скрытия.');
                      window._hiddenCardsToHide.add(cardId);
                      // Сохраняем обновленный список в localStorage
                      try {
                        const updatedList = Array.from(window._hiddenCardsToHide);
                        localStorage.setItem('dashboard_hidden_cards', JSON.stringify(updatedList));
                        console.log('✅ Обновлен список скрытых карточек в localStorage');
                      } catch (e) {
                        console.error('❌ Ошибка обновления localStorage:', e);
                      }
                    }
                  }
                  
                  if (window._hiddenCardsToHide.has(cardId)) {
                    hideCardImmediately(card);
                    hiddenCount++;
                  }
                });
                
                if (hiddenCount > 0) {
                  console.log('⚡ Применено скрытие к существующим карточкам:', hiddenCount);
                }
                
                if (!emailTwoFaFound) {
                  console.warn('⚠️ Карточка "Email + 2FA" не найдена в DOM при применении скрытия');
                }
              }
            }
            
            // Пытаемся применить сразу, если DOM уже готов
            // Используем несколько попыток для надежности
            function tryApplyHiding() {
              if (document.body && document.querySelectorAll) {
                applyHidingToExistingCards();
                // Повторяем через небольшую задержку на случай, если карточки еще не загружены
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
            
            // Дополнительная попытка после полной загрузки
            window.addEventListener('load', function() {
              setTimeout(applyHidingToExistingCards, 0);
            });
          }
        }
      } catch (e) {
        console.error('Error reading hidden cards:', e);
      }
    })();
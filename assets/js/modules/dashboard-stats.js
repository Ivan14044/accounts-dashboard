/**
 * Модуль управления статистикой дашборда
 * Отвечает за обновление карточек статистики, скрытие/показ карточек, работу с MutationObserver
 */

// Константы
const LS_KEY_HIDDEN_CARDS = 'dashboard_hidden_cards';

// Состояние модуля
let hiddenCards = new Set();

// Вспомогательная функция для безопасного получения элемента через dom-cache
function getElementById(id) {
  if (typeof domCache !== 'undefined' && domCache.getById) {
    return domCache.getById(id);
  }
  return document.getElementById(id);
}

// Функция для немедленного скрытия карточки
function hideCardImmediately(card) {
  const cardId = card.getAttribute('data-card');
  if (!cardId) {
    return; // Пропускаем карточки без ID
  }
  
  if (hiddenCards.has(cardId)) {
    // Применяем все способы скрытия для надежности
    card.classList.add('hidden');
    card.style.setProperty('display', 'none', 'important');
    card.style.setProperty('visibility', 'hidden', 'important');
    card.style.setProperty('opacity', '0', 'important');
    card.setAttribute('hidden', '');
    if (typeof logger !== 'undefined') {
      logger.debug('⚡ Немедленно скрыта карточка (MutationObserver):', cardId);
    }
  }
}

// Загрузка скрытых карточек из localStorage
function loadHiddenCardsFromLocalStorage() {
  try {
    const saved = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
    if (saved) {
      const hiddenIds = JSON.parse(saved);
      if (Array.isArray(hiddenIds)) {
        hiddenCards = new Set(hiddenIds);
        window._hiddenCardsToHide = new Set(hiddenIds);
      }
    }
  } catch (e) {
    if (typeof logger !== 'undefined') {
      logger.error('Error reading hidden cards:', e);
    }
  }
}

// Сохранение скрытых карточек в localStorage
async function saveHiddenCards() {
  try {
    const hiddenArray = Array.from(hiddenCards);
    localStorage.setItem(LS_KEY_HIDDEN_CARDS, JSON.stringify(hiddenArray));
    
    // Сохраняем в БД через API
    const response = await fetch('api_user_settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        action: 'save_hidden_cards',
        hidden_cards: hiddenArray
      })
    });
    
    if (!response.ok) {
      throw new Error('Failed to save hidden cards');
    }
  } catch (error) {
    if (typeof logger !== 'undefined') {
      logger.error('Error saving hidden cards:', error);
    }
  }
}

// Применение скрытия к существующим карточкам
function applyHidingToExistingCards() {
  if (document.querySelectorAll) {
    const cards = document.querySelectorAll('.stat-card');
    let hiddenCount = 0;
    
    cards.forEach(card => {
      const cardId = card.getAttribute('data-card');
      if (cardId && hiddenCards.has(cardId)) {
        hideCardImmediately(card);
        hiddenCount++;
      }
    });
    
    if (typeof logger !== 'undefined') {
      logger.debug('⚡ Применено скрытие к существующим карточкам:', hiddenCount);
    }
    
    // Проверяем специальную карточку "Email + 2FA"
    const emailTwoFaCard = document.querySelector('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard && !hiddenCards.has('custom:email_twofa')) {
      if (typeof logger !== 'undefined') {
        logger.warn('⚠️ Карточка "Email + 2FA" не найдена в DOM при применении скрытия');
      }
    }
  }
}

// Переключение видимости карточки
function toggleCardVisibility(cardId, isVisible) {
  const card = document.querySelector(`.stat-card[data-card="${cardId}"]`);
  if (!card) return;
  
  if (isVisible) {
    card.classList.remove('hidden');
    card.style.removeProperty('display');
    card.style.removeProperty('visibility');
    card.style.removeProperty('opacity');
    card.removeAttribute('hidden');
    hiddenCards.delete(cardId);
  } else {
    card.classList.add('hidden');
    card.style.setProperty('display', 'none', 'important');
    card.style.setProperty('visibility', 'hidden', 'important');
    card.style.setProperty('opacity', '0', 'important');
    card.setAttribute('hidden', '');
    hiddenCards.add(cardId);
  }
}

// Скрытие карточки
async function hideCard(cardId) {
  try {
    toggleCardVisibility(cardId, false);
    await saveHiddenCards();
    
    // Синхронизируем чекбокс, если он существует
    const escapedCardId = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    const checkbox = document.querySelector(`.card-toggle[data-card="${escapedCardId}"]`);
    if (checkbox) {
      checkbox.checked = false;
    }
  } catch (error) {
    if (typeof logger !== 'undefined') {
      logger.error('Error hiding card:', error, { cardId });
    }
    toggleCardVisibility(cardId, true);
    throw error;
  }
}

// Показ карточки
async function showCard(cardId) {
  try {
    toggleCardVisibility(cardId, true);
    await saveHiddenCards();
    
    // Синхронизируем чекбокс, если он существует
    const escapedCardId = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    const checkbox = document.querySelector(`.card-toggle[data-card="${escapedCardId}"]`);
    if (checkbox) {
      checkbox.checked = true;
    }
  } catch (error) {
    if (typeof logger !== 'undefined') {
      logger.error('Error showing card:', error, { cardId });
    }
    toggleCardVisibility(cardId, false);
    throw error;
  }
}

// Обновление значения статистики с анимацией
function updateStatValue(element, newValue) {
  if (!element) return;
  
  const oldValue = parseInt(element.textContent.replace(/\s/g, '')) || 0;
  const formattedNewValue = newValue.toLocaleString('ru-RU');
  
  if (oldValue !== newValue) {
    element.textContent = formattedNewValue;
    element.classList.add('updated');
    setTimeout(() => {
      element.classList.remove('updated');
    }, 500);
  }
}

// Обновление карточек статистики по статусам
function updateStatusCards(byStatus) {
  if (!byStatus) return;
  
  const statusCards = document.querySelectorAll('.stat-card[data-card^="status:"]');
  
  if (typeof logger !== 'undefined') {
    logger.debug('🔄 Обновление карточек статистики:', {
      'cards_found': statusCards.length,
      'byStatus_keys': Object.keys(byStatus)
    });
  }
  
  statusCards.forEach(cardElement => {
    const statusKey = cardElement.getAttribute('data-status');
    if (!statusKey) return;
    
    const count = byStatus[statusKey] || 0;
    const valueElement = cardElement.querySelector('.stat-value');
    
    if (valueElement) {
      updateStatValue(valueElement, count);
    }
  });
}

// Инициализация MutationObserver для отслеживания появления карточек
function initMutationObserver() {
  // Оптимизированная версия с батчингом и ограниченной областью наблюдения
  const observer = new MutationObserver(function(mutations) {
    // Батчинг: собираем все карточки для скрытия в Set
    const cardsToHide = new Set();
    
    mutations.forEach(function(mutation) {
      mutation.addedNodes.forEach(function(node) {
        if (node.nodeType === 1) { // Element node
          // Проверяем сам узел
          if (node.classList && node.classList.contains('stat-card')) {
            cardsToHide.add(node);
          }
          // Проверяем дочерние элементы
          if (node.querySelectorAll) {
            const cards = node.querySelectorAll('.stat-card');
            cards.forEach(card => cardsToHide.add(card));
          }
        }
      });
    });
    
    // Применяем изменения батчем через requestAnimationFrame
    if (cardsToHide.size > 0) {
      requestAnimationFrame(() => {
        cardsToHide.forEach(card => hideCardImmediately(card));
      });
    }
  });
  
  // Начинаем наблюдение только за контейнером статистики (оптимизация)
  function startObserving() {
    const statsContainer = document.querySelector('.stats-grid');
    if (statsContainer) {
      observer.observe(statsContainer, {
        childList: true,
        subtree: true // Но только внутри stats-grid!
      });
    } else {
      // Fallback: если контейнер еще не готов, наблюдаем за body
      if (document.body) {
        observer.observe(document.body, {
          childList: true,
          subtree: true
        });
      }
    }
  }
  
  // Начинаем наблюдение
  if (document.body) {
    startObserving();
  } else {
    // Если body еще не готов, ждем его
    document.addEventListener('DOMContentLoaded', startObserving);
  }
  
  return observer;
}

// Инициализация модуля статистики
function initStatsModule() {
  // Загружаем скрытые карточки из localStorage
  loadHiddenCardsFromLocalStorage();
  
  // Применяем скрытие к существующим карточкам
  applyHidingToExistingCards();
  
  // Инициализируем MutationObserver
  initMutationObserver();
  
  // Регистрируем обработчики для переключения видимости карточек
  document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('card-toggle')) {
      const cardId = e.target.getAttribute('data-card');
      if (cardId) {
        if (e.target.checked) {
          showCard(cardId);
        } else {
          hideCard(cardId);
        }
      }
    }
  });
  
  if (typeof logger !== 'undefined') {
    logger.debug('✅ Модуль статистики инициализирован');
  }
}

// Экспорт функций для глобального использования
window.DashboardStats = {
  init: initStatsModule,
  loadHiddenCardsFromLocalStorage: loadHiddenCardsFromLocalStorage,
  saveHiddenCards: saveHiddenCards,
  hideCard: hideCard,
  showCard: showCard,
  toggleCardVisibility: toggleCardVisibility,
  updateStatValue: updateStatValue,
  updateStatusCards: updateStatusCards,
  applyHidingToExistingCards: applyHidingToExistingCards,
  getHiddenCards: () => hiddenCards
};

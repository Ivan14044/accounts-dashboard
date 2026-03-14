<script>
// Тёмная тема отключена

// Флаг для внешних скриптов, чтобы не дублировать обработчики
window.__INLINE_DASHBOARD_ACTIVE__ = true;

// ===== Основные функции =====
// Переведены в assets/js/dashboard.js; ниже — защитные определения на случай отсутствия глобальных версий
if (typeof window.copyToClipboard !== 'function') {
  window.copyToClipboard = function(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => {
        if (typeof window.showToast === 'function') window.showToast('Скопировано в буфер обмена', 'success');
      }).catch(() => {
        window.fallbackCopyTextToClipboard(text);
      });
    } else {
      window.fallbackCopyTextToClipboard(text);
    }
  };
}

if (typeof window.fallbackCopyTextToClipboard !== 'function') {
  window.fallbackCopyTextToClipboard = function(text) {
    const textArea = document.createElement('textarea');
    textArea.value = String(text || '');
    // Для Firefox: элемент должен быть видимым, но можно сделать его очень маленьким
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.width = '2px';
    textArea.style.height = '2px';
    textArea.style.padding = '0';
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';
    textArea.setAttribute('readonly', '');
    document.body.appendChild(textArea);
    
    // Для Firefox: используем setSelectionRange вместо select()
    textArea.focus();
    textArea.setSelectionRange(0, textArea.value.length);
    
    try {
      const successful = document.execCommand('copy');
      if (successful && typeof window.showToast === 'function') {
        window.showToast('Скопировано в буфер обмена', 'success');
      } else if (!successful && typeof window.showToast === 'function') {
        window.showToast('Ошибка копирования', 'error');
      }
    } catch (err) {
      if (typeof window.showToast === 'function') {
        window.showToast('Ошибка копирования', 'error');
      }
    } finally {
      document.body.removeChild(textArea);
    }
  };
}

if (typeof window.showToast !== 'function') {
  window.showToast = function(message, type = 'info', duration = 3000) {
    // Используем улучшенный класс Toast с progress bar
    if (typeof window.Toast !== 'undefined' && window.Toast.show) {
      // Нормализуем тип
      const normalizedType = type === 'danger' || type === 'error' ? 'error' : 
                            type === 'warning' ? 'warning' : 
                            type === 'success' ? 'success' : 'info';
      
      return window.Toast.show(message, {
        type: normalizedType,
        duration: duration,
        closable: true
      });
    }
    
    // Fallback для старых версий
    const toast = document.createElement('div');
    const bgColor = type === 'success' ? 'success' : (type === 'error' ? 'danger' : 'info');
    toast.className = `toast align-items-center text-white bg-${bgColor} border-0 position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
    toast.innerHTML = `
      <div class="d-flex">
        <div class="toast-body">
          <i class="fas fa-${type === 'success' ? 'check' : (type === 'error' ? 'exclamation-triangle' : 'info-circle')} me-2"></i>
          ${message}
        </div>
        <button type="button" class="toast-close" aria-label="Закрыть" title="Закрыть"><i class="fas fa-times"></i></button>
      </div>
    `;
    document.body.appendChild(toast);
    const closeBtn = toast.querySelector('.toast-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        toast.style.opacity = '0';
        setTimeout(() => {
          if (toast.parentNode) {
            document.body.removeChild(toast);
          }
        }, 300);
      });
    }
    setTimeout(() => {
      toast.style.opacity = '1';
    }, 10);
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => {
        if (toast.parentNode) {
          document.body.removeChild(toast);
        }
      }, 300);
    }, duration);
  };
}

// ===== Управление настройками =====
// Ключи колонок/карточек остаются здесь, а состояние выбранных ID и скрытых карточек
// перенесено в модули `dashboard-selection.js` и `dashboard-stats.js`
const LS_KEY_COLUMNS = 'dashboard_visible_columns';
const LS_KEY_CARDS = 'dashboard_visible_cards';
const LS_KEY_KNOWN_COLS = 'dashboard_known_columns';
const LS_KEY_HIDDEN_CARDS = 'dashboard_hidden_cards';

// ===== Управление чекбоксами =====
// Состояние selectedIds / selectedAllFiltered / filteredTotalLive теперь
// хранится и управляется в модуле `dashboard-selection.js`
const ACTIVE_FILTERS_COUNT = <?= (int)$activeFiltersCount ?>;

// ===== Слайдеры pharma/friends перенесены в модуль dashboard-filters.js =====
// Используйте window.DashboardFilters.initializePharmaSlider / initializeFriendsSlider

// ===== Функции выбора строк перенесены в модуль dashboard-selection.js =====
// Используйте window.DashboardSelection для доступа к функциям:
// - window.DashboardSelection.loadSelectedIds()
// - window.DashboardSelection.saveSelectedIds()
// - window.DashboardSelection.updateSelectedCount()
// - window.DashboardSelection.updateSelectedOnPageCounter()
// - window.DashboardSelection.toggleRowSelection(id, checked)
// - window.DashboardSelection.initCheckboxStates()
// - window.DashboardSelection.getAllRowIdsOnPage()
// - window.DashboardSelection.updateRowSelectedClass(row, isSelected)
// - window.DashboardSelection.getSelectedIds()
// - window.DashboardSelection.getSelectedAllFiltered()
// - window.DashboardSelection.setSelectedAllFiltered(value)
// - window.DashboardSelection.setFilteredTotalLive(value)
// - window.DashboardSelection.clearSelection()
// - window.DashboardSelection.invalidateCache()

// Вспомогательная функция для безопасного получения элемента через dom-cache
// Оставляем для обратной совместимости, но рекомендуется использовать domCache.getById напрямую
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

// ===== Управление скрытием карточек =====
// Загрузка скрытых карточек из БД
async function loadHiddenCards() {
  try {
    
    // Сначала проверяем localStorage
    const localHiddenCards = (() => {
      try {
        const saved = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
        return saved ? JSON.parse(saved) : [];
      } catch (_) {
        return [];
      }
    })();
    
    // Пытаемся загрузить из БД
    const response = await fetch('/api/settings?type=hidden_cards');
    if (response.ok) {
      const data = await response.json();
      if (data.success && Array.isArray(data.value)) {
        let cardsToHide = data.value;
        
        // КРИТИЧНО: Если БД возвращает пустой массив, но в localStorage есть данные,
        // используем localStorage и синхронизируем с БД
        if (cardsToHide.length === 0 && localHiddenCards.length > 0) {
          cardsToHide = localHiddenCards;
          
          // Синхронизируем БД с localStorage
          try {
            const syncResponse = await fetch('/api/settings', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
              },
              body: JSON.stringify({
                type: 'hidden_cards',
                value: cardsToHide,
                csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
              })
            });
            if (syncResponse.ok) {
              // БД синхронизирована с localStorage
            }
          } catch (syncError) {
            logger.warn('⚠️ Ошибка синхронизации БД:', syncError);
          }
        } else if (cardsToHide.length > 0) {
          // Если БД содержит данные, обновляем localStorage
          try {
            localStorage.setItem(LS_KEY_HIDDEN_CARDS, JSON.stringify(cardsToHide));
          } catch (_) {}
        }
        
        // Применяем скрытие к карточкам
        if (cardsToHide.length > 0) {
          cardsToHide.forEach(cardId => {
            const card = getSel(`.stat-card[data-card="${cardId}"]`);
            if (card) {
              card.classList.add('hidden');
              card.style.display = 'none'; // Дополнительное скрытие
            }
          });
        }
        return;
      }
    }
    
    // Fallback на localStorage
    loadHiddenCardsFromLocalStorage();
  } catch (error) {
    logger.warn('Error loading hidden cards from server:', error);
    loadHiddenCardsFromLocalStorage();
  }
}

// Резервная загрузка из localStorage
function loadHiddenCardsFromLocalStorage() {
  try {
    const saved = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
    if (saved) {
      const hiddenIds = JSON.parse(saved);
      hiddenIds.forEach(cardId => {
        const card = getSel(`.stat-card[data-card="${cardId}"]`);
        if (card) {
          card.classList.add('hidden');
          card.style.display = 'none'; // Дополнительное скрытие
        }
      });
    }
  } catch (e) {
    logger.error('Error loading hidden cards from localStorage:', e);
  }
}

// Сохранение скрытых карточек в БД
async function saveHiddenCards() {
  try {
    // Собираем все скрытые карточки
    const allHiddenCards = document.querySelectorAll('.stat-card.hidden');
    logger.debug('🔍 Найдено скрытых карточек в DOM:', allHiddenCards.length);
    
    // Логируем все найденные карточки для отладки
    allHiddenCards.forEach((card, index) => {
      const cardId = card.getAttribute('data-card');
      logger.debug(`  [${index}] Карточка ID: "${cardId}", классы:`, card.className);
    });
    
    const hiddenCards = Array.from(allHiddenCards)
      .map(card => card.getAttribute('data-card'))
      .filter(id => id !== null && id !== '');
    
    // Проверяем, есть ли карточка "Email + 2FA"
    const emailTwoFaCard = getSel('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard) {
      const isHidden = emailTwoFaCard.classList.contains('hidden');
      logger.debug('🔍 Карточка "Email + 2FA" найдена, скрыта:', isHidden, 'ID:', emailTwoFaCard.getAttribute('data-card'));
    } else {
      logger.warn('⚠️ Карточка "Email + 2FA" не найдена в DOM!');
    }
    
    
    // Сохраняем в localStorage как резервную копию
    try {
      localStorage.setItem(LS_KEY_HIDDEN_CARDS, JSON.stringify(hiddenCards));
    } catch (_) {
      logger.error('❌ Ошибка сохранения в localStorage');
    }
    
    // Сохраняем в БД
    try {
      const response = await fetch('/api/settings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          type: 'hidden_cards',
          value: hiddenCards,
          csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
        })
      });
      
      if (!response.ok) {
        const errorText = await response.text();
        logger.warn('⚠️ Failed to save hidden cards to server:', response.status, errorText);
        logger.warn('⚠️ Saved to localStorage only');
      } else {
        const data = await response.json();
      }
    } catch (fetchError) {
      logger.error('❌ Ошибка при сохранении в БД:', fetchError);
      logger.warn('⚠️ Saved to localStorage only');
    }
  } catch (e) {
    logger.error('❌ Error saving hidden cards:', e);
  }
}

async function hideCard(cardId) {
  if (!cardId || cardId.trim() === '') {
    logger.warn('hideCard: cardId is empty');
    return;
  }
  
  
  try {
    // Используем единую функцию для обновления UI
    toggleCardVisibility(cardId, false);
    
    // Проверяем, что карточка действительно скрыта
    const card = getSel(`.stat-card[data-card="${cardId}"]`);
    if (card) {
      const isHidden = card.classList.contains('hidden');
      logger.debug('🔍 Карточка после скрытия - класс hidden:', isHidden, 'display:', window.getComputedStyle(card).display);
    }
    
    // Сохраняем в БД и localStorage
    await saveHiddenCards();
    
    // Синхронизируем чекбокс, если он существует
    const escapedCardId = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    const checkbox = getSel(`.card-toggle[data-card="${escapedCardId}"]`);
    if (checkbox) {
      checkbox.checked = false;
      }
  } catch (error) {
    logger.error('❌ Error hiding card:', error, { cardId });
    // Откатываем изменения UI при ошибке
    toggleCardVisibility(cardId, true);
    throw error;
  }
}

async function showCard(cardId) {
  if (!cardId || cardId.trim() === '') {
    logger.warn('showCard: cardId is empty');
    return;
  }
  
  try {
    // Используем единую функцию для обновления UI
    toggleCardVisibility(cardId, true);
    
    // Сохраняем в БД и localStorage
    await saveHiddenCards();
    
    // Синхронизируем чекбокс, если он существует
    const escapedCardId = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    const checkbox = getSel(`.card-toggle[data-card="${escapedCardId}"]`);
    if (checkbox) {
      checkbox.checked = true;
    }
  } catch (error) {
    logger.error('Error showing card:', error, { cardId });
    // Откатываем изменения UI при ошибке
    toggleCardVisibility(cardId, false);
    throw error;
  }
}

// ===== Функции выбора строк перенесены в модуль dashboard-selection.js =====
// RowIdsCache, getAllRowIdsOnPage, initCheckboxStates, updateAllSelectedRowsHighlight
// и updateRowSelectedClass теперь доступны через window.DashboardSelection

// ===== Функции настроек =====
function loadSettings() {
  try {
    // Загружаем настройки колонок
    const savedColumns = localStorage.getItem(LS_KEY_COLUMNS);
    const visibleColumns = savedColumns ? JSON.parse(savedColumns) : null;
    // Определяем новые колонки (в схеме появились новые поля)
    let knownCols = [];
    try { const k = localStorage.getItem(LS_KEY_KNOWN_COLS); if (k) knownCols = JSON.parse(k) || []; } catch(_) {}
    const ALL_COL_KEYS = Array.from(document.querySelectorAll('.column-toggle')).map(cb => cb.getAttribute('data-col'));
    const newCols = ALL_COL_KEYS.filter(c => !knownCols.includes(c));

    document.querySelectorAll('.column-toggle').forEach(cb => {
      const colName = cb.getAttribute('data-col');
      let isChecked = cb.checked; // дефолт по HTML
      if (visibleColumns) {
        isChecked = visibleColumns.includes(colName) || newCols.includes(colName);
      }
      cb.checked = isChecked;
      toggleColumnVisibility(colName, isChecked);
    });
    // Сохраняем актуально известный список колонок
    localStorage.setItem(LS_KEY_KNOWN_COLS, JSON.stringify(ALL_COL_KEYS));
    
    // Упрощенная логика: используем только список скрытых карточек
    // Загружаем скрытые карточки из localStorage
    const hiddenCards = [];
    try {
      const savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
      if (savedHidden) {
        hiddenCards.push(...JSON.parse(savedHidden));
      }
    } catch (e) {
      logger.error('Error loading hidden cards in loadSettings:', e);
    }
    
    // Упрощенная логика: используем только список скрытых карточек
    // Карточка видима, если она НЕ в списке скрытых
    document.querySelectorAll('.card-toggle').forEach(cb => {
      const cardName = cb.getAttribute('data-card');
      if (!cardName || cardName.trim() === '') {
        logger.warn('loadSettings: card-toggle has empty data-card attribute', {
          element: cb,
          id: cb.id,
          value: cb.value
        });
        return;
      }
      const isVisible = !hiddenCards.includes(cardName);
      cb.checked = isVisible;
      toggleCardVisibility(cardName, isVisible);
    });

    // Компактный режим отключен
  } catch (e) {
    logger.error('Error loading settings:', e);
  }
}

function saveSettings() {
  try {
    // Сохраняем настройки колонок
    const visibleColumns = [];
    document.querySelectorAll('.column-toggle:checked').forEach(cb => {
      visibleColumns.push(cb.getAttribute('data-col'));
    });
    localStorage.setItem(LS_KEY_COLUMNS, JSON.stringify(visibleColumns));
    // Обновляем известные колонки (для детекта будущих изменений схемы)
    const ALL_COL_KEYS = Array.from(document.querySelectorAll('.column-toggle')).map(cb => cb.getAttribute('data-col'));
    localStorage.setItem(LS_KEY_KNOWN_COLS, JSON.stringify(ALL_COL_KEYS));
    
    // Упрощенная логика: настройки карточек сохраняются через saveHiddenCards()
    // Здесь только синхронизируем скрытые карточки с чекбоксами
    // Список скрытых карточек уже сохранен в saveHiddenCards()
    
    showToast('Настройки сохранены', 'success');
  } catch (e) {
    logger.error('Error saving settings:', e);
    showToast('Ошибка сохранения настроек', 'error');
  }
}

function toggleColumnVisibility(colName, visible) {
  const colElements = document.querySelectorAll(`[data-col="${colName}"]`);
  colElements.forEach(el => {
    if (visible) {
      el.style.display = '';
    } else {
      el.style.display = 'none';
    }
  });
}

// Применяет сохранённую видимость колонок к текущей таблице (включая новые строки)
function applySavedColumnVisibility() {
  try {
    const savedColumns = localStorage.getItem(LS_KEY_COLUMNS);
    if (!savedColumns) return;
    const visibleColumns = JSON.parse(savedColumns);
    const allToggles = Array.from(document.querySelectorAll('.column-toggle'));
    const allCols = allToggles.map(cb => cb.getAttribute('data-col'));
    allCols.forEach(col => {
      const isVisible = visibleColumns.includes(col);
      toggleColumnVisibility(col, isVisible);
    });
  } catch (_) { /* ignore */ }
}

function toggleCardVisibility(cardName, visible) {
  if (!cardName || cardName.trim() === '') {
    logger.warn('toggleCardVisibility: cardName is empty');
    return;
  }
  
  // Экранируем специальные символы в селекторе для безопасности
  const escapedCardName = cardName.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
  
  // Используем селектор для поиска карточки с правильным атрибутом
  const cardElement = getSel(`.stat-card[data-card="${escapedCardName}"]`);
  
  if (!cardElement) {
    logger.warn(`Card not found: ${cardName}`, {
      searched: escapedCardName,
      available: Array.from(document.querySelectorAll('.stat-card')).map(c => c.getAttribute('data-card'))
    });
    return;
  }
  
  if (visible) {
    // КРИТИЧНО: сначала убираем класс hidden, иначе CSS правило с !important не даст показать карточку
    cardElement.classList.remove('hidden', 'd-none', 'force-hidden');
    cardElement.removeAttribute('hidden');
    
    // Принудительно устанавливаем стили через setProperty с important, чтобы переопределить CSS
    // Это необходимо, так как CSS имеет правила с !important для показа карточек
    cardElement.style.setProperty('display', 'flex', 'important');
    cardElement.style.setProperty('opacity', '1', 'important');
    cardElement.style.setProperty('visibility', 'visible', 'important');
    cardElement.style.setProperty('pointer-events', 'auto', 'important');
    
    // Через небольшую задержку сбрасываем important, чтобы не ломать другие стили
    // Но оставляем класс hidden удаленным
    requestAnimationFrame(() => {
      if (cardElement && !cardElement.classList.contains('hidden')) {
        // Проверяем, что карточка все еще должна быть видима
        // Сбрасываем inline стили, чтобы CSS правила работали нормально
        cardElement.style.removeProperty('display');
        cardElement.style.removeProperty('opacity');
        cardElement.style.removeProperty('visibility');
        cardElement.style.removeProperty('pointer-events');
      }
    });
  } else {
    // Скрываем карточку: добавляем класс hidden и устанавливаем стили
    cardElement.classList.add('hidden');
    cardElement.setAttribute('hidden', '');
    
    // Используем setProperty с important для переопределения CSS правил с !important
    cardElement.style.setProperty('display', 'none', 'important');
    cardElement.style.setProperty('opacity', '0', 'important');
    cardElement.style.setProperty('visibility', 'hidden', 'important');
    cardElement.style.setProperty('pointer-events', 'none', 'important');
    
    // Убираем другие классы скрытия для чистоты
    cardElement.classList.remove('d-none', 'force-hidden');
  }
  
  // Принудительно обновляем отображение через reflow
  void cardElement.offsetHeight;
}

// ===== Обработчики событий =====
// Обработчик скрытия карточек (делегирование событий)
document.addEventListener('click', function(e) {
  const hideBtn = e.target.closest('.stat-card-hide-btn');
  if (hideBtn) {
    e.preventDefault();
    e.stopPropagation();
    
    const cardId = hideBtn.getAttribute('data-card');
    if (cardId) {
      hideCard(cardId).catch(err => logger.error('Error hiding card:', err));
    }
    return;
  }
  
  // Обработчик клика на кастомные карточки
  const card = e.target.closest('.stat-card[data-card-type="custom"]');
  if (card) {
    // Игнорируем клик на кнопку скрытия
    if (e.target.closest('.stat-card-hide-btn')) {
      return;
    }
    
    // Подсвечиваем карточку
    document.querySelectorAll('.stat-card[data-card-type="custom"]').forEach(c => {
      c.classList.remove('active');
    });
    card.classList.add('active');
    
    // Принудительно применяем стили через inline стили для надежности
    card.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.4) 0%, rgba(59, 130, 246, 0.6) 100%)';
    card.style.border = '2px solid var(--card-color, #3b82f6)';
    card.style.boxShadow = '0 0 0 3px var(--card-color, #3b82f6), 0 14px 24px rgba(59, 130, 246, 0.4)';
    card.style.opacity = '1';
    
    // Логируем для отладки
    logger.debug('Card clicked, active class added:', card);
    logger.debug('Card has active class:', card.classList.contains('active'));
    logger.debug('Card computed styles:', window.getComputedStyle(card).background);
    
    // Применяем фильтры
    handleCardSwipe(card);
  }
});

      document.addEventListener('DOMContentLoaded', function() {
  if (window.DashboardSelection) window.DashboardSelection.loadSelectedIds();
  // ВАЖНО: Сначала применяем скрытие карточек СИНХРОННО из localStorage
  // Это предотвращает мигание скрытых карточек
  if (window._hiddenCardsToHide) {
    const hiddenCardsSet = window._hiddenCardsToHide instanceof Set 
      ? window._hiddenCardsToHide 
      : new Set(Array.isArray(window._hiddenCardsToHide) ? window._hiddenCardsToHide : []);
    
    // Специальная проверка для карточки "Email + 2FA"
    // Если пользователь говорит, что она должна быть скрыта, но её нет в списке,
    // добавляем её в список и скрываем
    const emailTwoFaCard = getSel('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard && !hiddenCardsSet.has('custom:email_twofa')) {
      hiddenCardsSet.add('custom:email_twofa');
      window._hiddenCardsToHide = hiddenCardsSet; // Обновляем глобальную переменную
      
      // Сохраняем обновленный список в localStorage
      try {
        const updatedList = Array.from(hiddenCardsSet);
        localStorage.setItem('dashboard_hidden_cards', JSON.stringify(updatedList));
      } catch (e) {
        logger.error('❌ Ошибка обновления localStorage:', e);
      }
    }
    
    // Применяем скрытие ко всем карточкам сразу
    hiddenCardsSet.forEach(cardId => {
      const card = getSel(`.stat-card[data-card="${cardId}"]`);
      if (card) {
        // Применяем все способы скрытия для надежности
        card.classList.add('hidden');
        card.style.setProperty('display', 'none', 'important');
        card.style.setProperty('visibility', 'hidden', 'important');
        card.style.setProperty('opacity', '0', 'important');
      }
    });
    
    // Очищаем после применения, но оставляем Set для MutationObserver
    // window._hiddenCardsToHide остается для MutationObserver
  } else {
    // Если список скрытых карточек не загружен, проверяем карточку "Email + 2FA"
    // и скрываем её, если она должна быть скрыта
    const emailTwoFaCard = getSel('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard) {
      try {
        const saved = localStorage.getItem('dashboard_hidden_cards');
        if (saved) {
          const hiddenIds = JSON.parse(saved);
          if (Array.isArray(hiddenIds) && hiddenIds.includes('custom:email_twofa')) {
            emailTwoFaCard.classList.add('hidden');
            emailTwoFaCard.style.setProperty('display', 'none', 'important');
            emailTwoFaCard.style.setProperty('visibility', 'hidden', 'important');
            emailTwoFaCard.style.setProperty('opacity', '0', 'important');
          }
        }
      } catch (e) {
        logger.error('❌ Ошибка проверки localStorage:', e);
      }
    }
  }
  
  // Проверяем прелоадеры сразу
  const statsLoading = getElementById('statsLoading');
  const tableLoading = getElementById('tableLoading');
  
  if (statsLoading) {
    // Скрываем прелоадер сразу (несколько способов для надежности)
    statsLoading.classList.remove('show');
    statsLoading.style.display = 'none';
    statsLoading.style.visibility = 'hidden';
    statsLoading.style.opacity = '0';
  } else {
    logger.error('❌ statsLoading элемент не найден!');
  }
  
  if (tableLoading) {
    tableLoading.classList.remove('show');
    tableLoading.style.display = 'none';
  }
  
  // Загружаем скрытые карточки из БД (синхронное скрытие уже применено выше)
  // Это обновит список из БД и синхронизирует с localStorage
  loadHiddenCards().catch(err => logger.error('Error loading hidden cards:', err));
  
  // Инициализируем кастомные карточки
  initializeCustomCards().catch(err => logger.error('Error initializing custom cards:', err));
  
  // ===== ОПТИМИЗАЦИЯ ПРОИЗВОДИТЕЛЬНОСТИ =====
  // Определение слабых устройств
  const isLowEndDevice = 
    (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2) || 
    (navigator.deviceMemory && navigator.deviceMemory <= 2) ||
    /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  
  // Применяем оптимизации для слабых устройств
  if (isLowEndDevice) {
    document.documentElement.classList.add('low-end-device');
    // Отключаем анимации через CSS переменную
    document.documentElement.style.setProperty('--animation-duration', '0ms');
    document.documentElement.style.setProperty('--transition-duration', '0ms');
    
    // Упрощаем sticky элементы (они могут тормозить)
    const stickyElements = document.querySelectorAll('.sticky-id, .sticky-actions');
    stickyElements.forEach(el => {
      el.style.position = 'relative';
      el.style.left = 'auto';
      el.style.right = 'auto';
    });
    
    // Уменьшаем количество строк по умолчанию
    const perPageSelect = getSel('select[name="per_page"]');
    if (perPageSelect && !perPageSelect.value) {
      perPageSelect.value = '25';
    }
  }
  
  // Кэширование часто используемых селекторов (используем dom-cache если доступен)
  const cachedSelectors = {
    tbody: getSel('#accountsTable tbody'),
    table: getElementById('accountsTable'),
    tableWrap: getElementById('tableWrap'),
    selectAll: getElementById('selectAll'),
    tableLoading: getElementById('tableLoading')
  };
  
  // Тёмная тема отключена
  
  // Глобальная конфигурация дашборда (CSRF и прочее)
  window.DashboardConfig = window.DashboardConfig || {};
  window.DashboardConfig.csrfToken = <?= json_encode((string)($csrfToken ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  
  // НЕ сохраняем выбранные строки при перезагрузке - очищаем выбор
  if (window.DashboardSelection) {
    // Инициализируем filteredTotalLive из серверного значения
    window.DashboardSelection.setFilteredTotalLive(<?= (int)($filteredTotal ?? 0) ?>);
    
    window.DashboardSelection.clearSelection();
    window.DashboardSelection.initCheckboxStates();
    window.DashboardSelection.updateSelectedCount();
  }
  loadSettings();
  // Пересчитываем ширины колонок после применения видимости
  requestAnimationFrame(() => {
    syncHeaderWidths();
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
  });
  // Слайдеры инициализируются через DashboardFilters.init() в dashboard-main.js
  // Гарантируем синхронизацию значений ползунков перед отправкой формы
  document.addEventListener('submit', function(e){
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    // Pharma
    const p = getElementById('pharmaSlider');
    if (p && p.noUiSlider) {
      const [vFrom, vTo] = p.noUiSlider.get().map(Number);
      const pf = getElementById('pharma_from');
      const pt = getElementById('pharma_to');
      if (pf) pf.value = String(vFrom);
      if (pt) pt.value = String(vTo);
    }
    // Friends
    const f = getElementById('friendsSlider');
    if (f && f.noUiSlider) {
      const [vFrom, vTo] = f.noUiSlider.get().map(Number);
      const ff = getElementById('friends_from');
      const ft = getElementById('friends_to');
      if (ff) ff.value = String(vFrom);
      if (ft) ft.value = String(vTo);
    }
  });
  // Синхронизация чекбоксов в настройках с фактически скрытыми карточками
  function syncCardCheckboxesWithHidden() {
    try {
      // Получаем скрытые карточки из localStorage
      const hiddenCards = [];
      const savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
      if (savedHidden) {
        try {
          hiddenCards.push(...JSON.parse(savedHidden));
        } catch (e) {
          logger.error('Error parsing hidden cards:', e);
        }
      }
      
      // Синхронизируем все чекбоксы с реальным состоянием карточек в DOM
      document.querySelectorAll('.card-toggle').forEach(cb => {
        const cardName = cb.getAttribute('data-card');
        if (!cardName) return;
        
        // Экранируем специальные символы в селекторе
        const escapedCardName = cardName.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
        
        // Находим соответствующую карточку в DOM
        const cardElement = getSel(`.stat-card[data-card="${escapedCardName}"]`);
        
        if (cardElement) {
          // Проверяем реальное состояние карточки в DOM
          // Используем getComputedStyle для получения финального значения display
          const computedStyle = window.getComputedStyle(cardElement);
          const displayValue = computedStyle.display;
          
          const isHiddenInDOM = cardElement.classList.contains('hidden') || 
                               cardElement.style.display === 'none' ||
                               displayValue === 'none' ||
                               cardElement.hasAttribute('hidden') ||
                               cardElement.classList.contains('d-none') ||
                               cardElement.classList.contains('force-hidden');
          
          // Проверяем состояние в localStorage
          const isHiddenInStorage = hiddenCards.includes(cardName);
          
          // Карточка скрыта, если она скрыта в DOM ИЛИ в localStorage
          const isHidden = isHiddenInDOM || isHiddenInStorage;
          
          // Обновляем чекбокс в соответствии с реальным состоянием
          cb.checked = !isHidden;
        } else {
          // Если карточка не найдена в DOM, проверяем только localStorage
          const isHiddenInStorage = hiddenCards.includes(cardName);
          cb.checked = !isHiddenInStorage;
          
          // Логируем для отладки
          if (cardName && !cardName.includes('custom:')) {
            logger.warn(`syncCardCheckboxesWithHidden: Card not found in DOM: ${cardName}`, {
              searched: escapedCardName,
              available: Array.from(document.querySelectorAll('.stat-card')).slice(0, 5).map(c => c.getAttribute('data-card'))
            });
          }
        }
      });
    } catch (e) {
      logger.error('Error syncing card checkboxes:', e);
    }
  }

  // Обработчик открытия модального окна настроек
  const settingsModalEl = getElementById('settingsModal');
  if (settingsModalEl) {
    settingsModalEl.addEventListener('show.bs.modal', function() {
      // Синхронизируем чекбоксы при открытии модального окна
      syncCardCheckboxesWithHidden();
    });
  }

  // Реакция на переключение чекбоксов настроек (колонки/карточки)
  document.addEventListener('change', function(e) {
    const t = e.target;
    if (t && t.classList && t.classList.contains('column-toggle')) {
      const colName = t.getAttribute('data-col');
      const isVisible = !!t.checked;
      toggleColumnVisibility(colName, isVisible);
      saveSettings();
      // Пересчитываем ширины колонок после изменения видимости
      requestAnimationFrame(() => {
        syncHeaderWidths();
        // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
      });
    }
    if (t && t.classList && t.classList.contains('card-toggle')) {
      const cardName = t.getAttribute('data-card');
      
      // Проверяем, что cardName существует и не пустой
      if (!cardName || cardName.trim() === '') {
        logger.warn('card-toggle: data-card attribute is empty or missing', {
          element: t,
          id: t.id,
          value: t.value
        });
        return;
      }
      
      const isVisible = !!t.checked;
      
      logger.debug('Card toggle changed:', { cardName, isVisible, element: t });
      
      // Сохраняем исходное состояние для отката при ошибке
      const previousState = !isVisible;
      
      // Используем единые функции hideCard/showCard, которые уже содержат toggleCardVisibility
      // и обработку ошибок с откатом
      if (isVisible) {
        // Показываем карточку и сохраняем в БД
        showCard(cardName).catch(err => {
          logger.error('Error showing card:', err, { cardName });
          // Откатываем чекбокс при ошибке
          t.checked = previousState;
          showToast('Ошибка показа карточки', 'error');
        });
      } else {
        // Скрываем карточку и сохраняем в БД
        hideCard(cardName).catch(err => {
          logger.error('Error hiding card:', err, { cardName });
          // Откатываем чекбокс при ошибке
          t.checked = previousState;
          showToast('Ошибка скрытия карточки', 'error');
        });
      }
      
      // Сохраняем настройки (колонки и другие)
      saveSettings();
    }
    // uiCompactToggle отключен
  });
  
  // Редактирование названий статистических блоков отключено

  // Отключаем JavaScript обработчик пагинации - пусть работают обычные ссылки
  // document.addEventListener('click', function(e){
  //   const a = e.target.closest('.pagination a.page-link');
  //   if (!a) return;
  //   // если пункт disabled — игнорируем
  //   const li = a.closest('li');
  //   if (li && li.classList.contains('disabled')) { 
  //     e.preventDefault(); 
  //     return; 
  //   }
  //   // Обычный переход по href - это должно работать
  //   logger.debug('Pagination click:', a.getAttribute('href'), 'data-page:', a.getAttribute('data-page'));
  // });
  
  // Select All и Individual checkboxes теперь обрабатываются через делегирование событий ниже
  // Удалён дублирующийся код (см. строки 4778+ и 5315+)
  
  // Password toggle
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.pw-toggle');
    if (!btn) return;
    
    const wrap = btn.closest('.pw-mask');
    const dots = wrap.querySelector('.pw-dots');
    const text = wrap.querySelector('.pw-text');
    const icon = btn.querySelector('i');
    
    if (text.classList.contains('d-none')) {
      // Показываем пароль
      text.classList.remove('d-none');
      dots.classList.add('d-none');
      icon.className = 'fas fa-eye-slash';
      btn.title = 'Скрыть пароль';
    } else {
      // Скрываем пароль
      text.classList.add('d-none');
      dots.classList.remove('d-none');
      icon.className = 'fas fa-eye';
      btn.title = 'Показать пароль';
    }
  });

  // Password edit
  document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.pw-edit');
    if (!editBtn) return;
    
    const wrap = editBtn.closest('.pw-mask');
    const rowId = parseInt(wrap.getAttribute('data-row-id'));
    const field = wrap.getAttribute('data-field');
    const pwText = wrap.querySelector('.pw-text');
    const currentPassword = pwText.textContent.trim();
    
    // Создаем input для редактирования
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control form-control-sm';
    input.value = currentPassword;
    input.style.width = '150px';
    input.style.display = 'inline-block';
    
    // Создаем кнопки сохранения и отмены
    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-sm btn-success ms-1';
    saveBtn.innerHTML = '<i class="fas fa-check"></i>';
    saveBtn.title = 'Сохранить';
    
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
    cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
    cancelBtn.title = 'Отмена';
    
    // Сохраняем оригинальное содержимое
    const originalContent = wrap.innerHTML;
    
    // Заменяем содержимое на поля редактирования
    wrap.innerHTML = '';
    wrap.appendChild(input);
    wrap.appendChild(saveBtn);
    wrap.appendChild(cancelBtn);
    input.focus();
    input.select();
    
    // Обработчик сохранения
    const save = async () => {
      const newPassword = input.value.trim();
      
      try {
        const response = await fetch('update_field.php', {
          method: 'POST',
          headers: { 
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            id: rowId,
            field: field,
            value: newPassword,
            csrf: '<?= e($csrfToken) ?>'
          })
        });
        
        const data = await response.json();
        
        if (data.success) {
          // Обновляем отображение пароля
          wrap.innerHTML = originalContent;
          const updatedPwText = wrap.querySelector('.pw-text');
          const updatedPwDots = wrap.querySelector('.pw-dots');
          updatedPwText.textContent = newPassword;
          // Обновляем отображение точек
          if (newPassword === '') {
            updatedPwDots.innerHTML = '<span class="text-muted">(не задан)</span>';
          } else {
            updatedPwDots.textContent = '••••••••';
          }
          showToast('Пароль успешно обновлен', 'success');
        } else {
          showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
          wrap.innerHTML = originalContent;
        }
      } catch (error) {
        logger.error('Error:', error);
        showToast('Ошибка при сохранении пароля', 'error');
        wrap.innerHTML = originalContent;
      }
    };
    
    // Обработчик отмены
    const cancel = () => {
      wrap.innerHTML = originalContent;
    };
    
    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', cancel);
    
    // Сохранение по Enter
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape') {
        cancel();
      }
    });
  });
  
  // Cell modal
  document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-full]');
    if (!target) return;
    
    const full = target.getAttribute('data-full') || '';
    const title = target.getAttribute('data-title') || 'Полное значение';
    
    const cellModalTitle = getElementById('cellModalTitle');
    const cellModalBody = getElementById('cellModalBody');
    const cellModal = getElementById('cellModal');
    
    if (cellModalTitle) cellModalTitle.textContent = title;
    if (cellModalBody) cellModalBody.textContent = full;
    
    if (cellModal) {
      const modal = new bootstrap.Modal(cellModal);
      modal.show();
    }
  });
  
  // Copy cell content
  const cellCopyBtn = getElementById('cellCopyBtn');
  if (cellCopyBtn) {
    cellCopyBtn.addEventListener('click', function() {
      const body = getElementById('cellModalBody');
      copyToClipboard(body.textContent || '');
    });
  }
  
  // Обработчик для всех кнопок копирования (совместимость с Firefox)
  // Используем делегирование событий для динамически созданных элементов
  document.addEventListener('click', function(e) {
    const copyBtn = e.target.closest('.copy-btn');
    if (!copyBtn) return;
    
    // Получаем текст для копирования из data-атрибута или из ближайшего элемента
    let textToCopy = copyBtn.getAttribute('data-copy-text');
    
    // Если data-атрибут не задан, пытаемся найти значение из контекста
    if (!textToCopy) {
      // Для паролей - берем из .pw-text
      const pwMask = copyBtn.closest('.pw-mask');
      if (pwMask) {
        const pwText = pwMask.querySelector('.pw-text');
        if (pwText) {
          textToCopy = pwText.textContent || pwText.innerText || '';
        }
      }
      
      // Для email/login - берем из .field-value или ссылки
      if (!textToCopy) {
        const fieldWrap = copyBtn.closest('.editable-field-wrap');
        if (fieldWrap) {
          const fieldValue = fieldWrap.querySelector('.field-value');
          if (fieldValue) {
            textToCopy = fieldValue.textContent || fieldValue.innerText || '';
            // Если это ссылка, берем href
            if (fieldValue.tagName === 'A' && fieldValue.href) {
              textToCopy = fieldValue.href.replace('mailto:', '');
            }
          }
        }
      }
      
      // Для token и других длинных полей
      if (!textToCopy) {
        const truncateSpan = copyBtn.previousElementSibling;
        if (truncateSpan && truncateSpan.hasAttribute('data-full')) {
          textToCopy = truncateSpan.getAttribute('data-full') || '';
        }
      }
      
      // Если все еще не нашли, пытаемся взять из любого соседнего элемента с текстом
      if (!textToCopy) {
        const parent = copyBtn.parentElement;
        if (parent) {
          // Ищем span или другой элемент с текстом
          const textElement = parent.querySelector('span, a, pre');
          if (textElement) {
            textToCopy = textElement.textContent || textElement.innerText || '';
            // Если это ссылка, убираем mailto:
            if (textElement.tagName === 'A' && textElement.href) {
              textToCopy = textElement.href.replace(/^mailto:/, '');
            }
          }
        }
      }
    }
    
    if (textToCopy) {
      copyToClipboard(textToCopy);
    } else {
      logger.warn('Не удалось найти текст для копирования', copyBtn);
    }
  });

  // Пагинация: инициализация модуля DashboardPagination (клики, поле «Перейти», Enter)
  if (window.DashboardPagination && typeof window.DashboardPagination.initPagination === 'function') {
    window.DashboardPagination.initPagination({
      onPageChange: function() { refreshDashboardData({ light: true }); }
    });
  }

  // Export selected CSV
  const exportSelectedCsv = getElementById('exportSelectedCsv');
  if (exportSelectedCsv) {
    exportSelectedCsv.addEventListener('click', function() {
      const DS = window.DashboardSelection;
      if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
      
      // Создаем скрытую форму для корректной обработки заголовков скачивания
      const form = document.createElement('form');
      form.method = 'GET';
      form.action = 'export.php';
      // Не указываем target, чтобы браузер правильно обработал Content-Disposition: attachment
      
      const currentSort = <?= json_encode((string)($sort ?? 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
      const currentDir = <?= json_encode((string)($dir ?? 'ASC'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
      
      if (DS.getSelectedAllFiltered()) {
        // Добавляем все параметры из текущего URL
        const params = new URLSearchParams(window.location.search);
        params.set('select', 'all');
        params.set('format', 'csv');
        params.set('sort', currentSort);
        params.set('dir', currentDir);
        
        // Добавляем все параметры как скрытые поля формы
        params.forEach((value, key) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          form.appendChild(input);
        });
      } else {
        // Экспорт выбранных ID
        const ids = Array.from(DS.getSelectedIds()).join(',');
        
        const fields = {
          'ids': ids,
          'format': 'csv',
          'sort': currentSort,
          'dir': currentDir
        };
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
      }

      // Добавляем форму в DOM, отправляем и удаляем
      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    });
  }
   
  // Export selected TXT (pipe-delimited, только видимые колонки)
  const exportSelectedTxt = getElementById('exportSelectedTxt');
  if (exportSelectedTxt) {
    exportSelectedTxt.addEventListener('click', function() {
      const DS = window.DashboardSelection;
      if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
      const currentSort = <?= json_encode((string)($sort ?? 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
      const currentDir = <?= json_encode((string)($dir ?? 'ASC'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
      let visibleCols = [];
      try { const saved = localStorage.getItem('dashboard_visible_columns'); if (saved) visibleCols = JSON.parse(saved); } catch (_) {}
      if (!Array.isArray(visibleCols) || visibleCols.length === 0) {
        visibleCols = Array.from(document.querySelectorAll('#accountsTable thead th[data-col]')).map(th => th.getAttribute('data-col'));
      }
      const ALL_COL_KEYS = <?= json_encode(array_keys($ALL_COLUMNS)) ?>;
      visibleCols = (visibleCols || []).filter(c => ALL_COL_KEYS.includes(c));
      // Убираем ID из экспорта, если он есть
      visibleCols = visibleCols.filter(c => c !== 'id');

      // Создаем скрытую форму для корректной обработки заголовков скачивания
      const form = document.createElement('form');
      form.method = 'GET';
      form.action = 'export.php';
      // Не указываем target, чтобы браузер правильно обработал Content-Disposition: attachment

      if (DS.getSelectedAllFiltered()) {
        // Добавляем все параметры из текущего URL
        const params = new URLSearchParams(window.location.search);
        params.set('select', 'all');
        params.set('format', 'txt');
        params.set('sort', currentSort);
        params.set('dir', currentDir);
        params.set('cols', visibleCols.join(','));
        
        // Добавляем все параметры как скрытые поля формы
        params.forEach((value, key) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          form.appendChild(input);
        });
      } else {
        // Экспорт выбранных ID
        const ids = Array.from(DS.getSelectedIds()).join(',');
        const cols = visibleCols.join(',');
        
        const fields = {
          'ids': ids,
          'format': 'txt',
          'sort': currentSort,
          'dir': currentDir,
          'cols': cols
        };
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
      }

      // Добавляем форму в DOM, отправляем и удаляем
      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    });
  }
  
  // Delete selected
  const deleteSelectedBtn = getElementById('deleteSelected');
  if (deleteSelectedBtn) {
    deleteSelectedBtn.addEventListener('click', function() {
      const DS = window.DashboardSelection;
      if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
      
      // Обновляем счётчик в модальном окне
      const deleteCount = getElementById('deleteCount');
      if (deleteCount) {
        deleteCount.textContent = DS.getSelectedAllFiltered() 
          ? 'все по фильтру' 
          : DS.getSelectedIds().size;
      }
      
      const modalEl = getElementById('deleteConfirmModal');
      if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
  }
  
  // Настройки сохраняются автоматически при изменении, обработчик кнопки не нужен
  
  // Логика reset/preview названий блоков вынесена в модуль `dashboard-stats.js`.
  
  // Confirm delete - КРИТИЧЕСКИ ВАЖНО для работы удаления!
  const confirmDeleteBtn = getElementById('confirmDelete');
  if (confirmDeleteBtn) {
    confirmDeleteBtn.addEventListener('click', async function() {
      const btn = this;
      const originalText = btn.innerHTML;
    
    // Показываем индикатор загрузки
    btn.disabled = true;
    btn.innerHTML = '<span class="loader loader-sm loader-white me-2" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;"></span>Удаление...';
    
    try {
      let response;
      
      // Режим "все по фильтру"
      const DS = window.DashboardSelection;
      if (DS && DS.getSelectedAllFiltered()) {
        logger.debug('🗑️ Удаление всех по фильтру');
        const params = new URLSearchParams(window.location.search);
        response = await fetch('delete.php?select=all&' + params.toString(), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ ids: [], csrf: '<?= e($csrfToken) ?>' })
        });
      } 
      // Обычный режим - удаление выбранных ID
      else {
        if (!DS || DS.getSelectedIds().size === 0) {
          logger.warn('⚠️ Попытка удаления без выбранных ID');
          showToast('Не выбрано ни одной записи для удаления', 'warning');
          btn.disabled = false;
          btn.innerHTML = originalText;
          return;
        }
        
        const ids = Array.from(DS.getSelectedIds());
        const requestBody = { ids: ids, csrf: '<?= e($csrfToken) ?>' };
        
        logger.group('🗑️ Отправка запроса на удаление');
        logger.debug('ID для удаления:', ids);
        logger.debug('Количество:', ids.length);
        logger.debug('Тело запроса:', requestBody);
        logger.groupEnd();
        
        response = await fetch('delete.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(requestBody)
        });
        
        logger.debug('📡 Статус ответа:', response.status, response.statusText);
      }
      
      if (!response.ok) {
        logger.error('❌ HTTP ошибка:', response.status, response.statusText);
        const text = await response.text();
        logger.error('Тело ответа:', text);
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      
      const data = await response.json();
      
      if (data.success) {
        if (data.deleted_count === 0) {
          showToast('⚠️ Ни одна запись не была удалена. Возможно, записи уже нет в базе.', 'warning');
        } else {
          showToast(data.message, 'success');
        }
        
        // Очищаем выбор
        if (window.DashboardSelection) {
          window.DashboardSelection.clearSelection();
          window.DashboardSelection.initCheckboxStates(); // Синхронизируем все чекбоксы включая selectAll
        }
        
        // Закрываем модалку
        const modal = bootstrap.Modal.getInstance(getElementById('deleteConfirmModal'));
        if (modal) {
          modal.hide();
        }
        
        logger.debug('✅ Удаление завершено успешно. Обновляем статистику...');
        
        // Обновляем данные через AJAX вместо перезагрузки страницы
        await refreshDashboardData();
        showToast(`Удалено ${data.deleted || 0} записей`, 'success');
      } else {
        showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
      }
    } catch (error) {
      logger.error('Error:', error);
      showToast('Ошибка сети при удалении', 'error');
    } finally {
      // Восстанавливаем кнопку
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
    });
  }
  
});

// ===== Адаптивность таблицы =====
// isRefreshing, overlayShownAt — в dashboard-refresh.js

// Простая функция настройки плотности таблицы
function adjustTableDensity() {
  if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
    window.tableLayoutManager.refresh();
  }
}

// applyCompactMode отключен

// Функции для управления глобальным прелоадером
function showPageLoader() {
  let loader = getElementById('pageLoader');
  if (!loader) {
    // Создаём прелоадер если его нет
    loader = document.createElement('div');
    loader.className = 'page-loader';
    loader.id = 'pageLoader';
    loader.innerHTML = `
      <div class="middle">
        <span class="loader loader-primary"></span>
      </div>
    `;
    document.body.appendChild(loader);
  }
  loader.classList.remove('hidden');
}

function hidePageLoader() {
  const loader = getElementById('pageLoader');
  if (loader && !loader.classList.contains('hidden')) {
    loader.classList.add('hidden');
    // НЕ удаляем элемент - он будет использоваться повторно
  }
}

// ===== collectRefreshParams, syncNumericRange, setTableLoadingState перенесены в dashboard-refresh.js =====

// ===== Фиксированный горизонтальный скролл таблицы =====
// Код перемещен в assets/js/sticky-scrollbar.js
// Оптимизированный обработчик resize с троттлингом
let resizeTimeout;
const optimizedResizeHandler = () => {
  if (resizeTimeout) return;
  resizeTimeout = requestAnimationFrame(() => {
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    // Пересчитываем плотность таблицы при изменении размера окна
    adjustTableDensity();
    resizeTimeout = null;
  });
};
window.addEventListener('resize', optimizedResizeHandler, { passive: true });
// Оптимизированный обработчик скролла с дебаунсингом
let scrollTimeout;
const optimizedUpdateStickyHScroll = () => {
  clearTimeout(scrollTimeout);
  scrollTimeout = requestAnimationFrame(() => {
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
  });
};
window.addEventListener('scroll', optimizedUpdateStickyHScroll, { passive: true });

// ===== Редактирование названий статистических блоков =====
function initializeStatCardEditing() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  
  statLabels.forEach(label => {
    label.addEventListener('click', function(e) {
      // Не редактируем при клике на иконку
      if (e.target.classList.contains('fas') || e.target.classList.contains('edit-icon')) {
        return;
      }
      
      startEditing(this);
    });
  });
}

function startEditing(labelElement) {
  const labelText = labelElement.querySelector('.label-text');
  const originalText = labelText.textContent;
  const cardType = labelElement.getAttribute('data-card');
  
  // Создаем поле ввода
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'form-control form-control-sm stat-edit-input';
  input.value = originalText;
  input.style.cssText = `
    text-align: center;
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: 2px solid #667eea;
    border-radius: 8px;
    padding: 0.25rem 0.5rem;
    background: white;
    color: #495057;
    width: 100%;
    max-width: 200px;
  `;
  
  // Заменяем текст на поле ввода
  labelText.style.display = 'none';
  labelElement.appendChild(input);
  input.focus();
  input.select();
  
  // Обработчики событий
  function finishEditing() {
    const newText = input.value.trim();
    
    if (newText === '') {
      newText = originalText;
    }
    
    // Обновляем текст
    labelText.textContent = newText;
    labelText.style.display = 'inline';
    
    // Удаляем поле ввода
    input.remove();
    
    // Сохраняем в localStorage
    saveStatLabel(cardType, newText);
    
    // Показываем уведомление
    if (newText !== originalText) {
      showToast(`Название блока "${originalText}" изменено на "${newText}"`, 'success');
    }
  }
  
  input.addEventListener('blur', finishEditing);
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      finishEditing();
    } else if (e.key === 'Escape') {
      labelText.textContent = originalText;
      labelText.style.display = 'inline';
      input.remove();
    }
  });
}

function saveStatLabel(cardType, label) {
  const key = `stat_label_${cardType}`;
  localStorage.setItem(key, label);
}

function loadStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  
  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const key = `stat_label_${cardType}`;
    const savedLabel = localStorage.getItem(key);
    
    if (savedLabel) {
      const labelText = label.querySelector('.label-text');
      labelText.textContent = savedLabel;
    }
  });
}

// Загружаем сохраненные названия при инициализации
document.addEventListener('DOMContentLoaded', function() {
  // Загружаем выбранные ID из localStorage при инициализации
  if (window.DashboardSelection) window.DashboardSelection.loadSelectedIds();
  
  // ВАЖНО: Сначала применяем скрытие карточек СИНХРОННО из localStorage
  // Это предотвращает мигание скрытых карточек
  loadHiddenCardsFromLocalStorage();
  
  loadStatLabels();
  initStatValues();
  initializeAutoRefresh();
  initializeTouchGestures();
  initScrollToTop();
  // loadEmptyStatusCount(); // Отключено - функционал встроен в основной фильтр
  
  // Скрываем прелоадеры при загрузке страницы (данные уже загружены сервером)
  const statsOverlay = getElementById('statsLoading');
  if (statsOverlay) {
    statsOverlay.classList.remove('show');
    statsOverlay.style.display = 'none';
  }
  
  const tableOverlay = getElementById('tableLoading');
  if (tableOverlay) {
    tableOverlay.classList.remove('show');
    tableOverlay.style.display = 'none';
  }
});

// Загрузка количества пустых статусов (ОТКЛЮЧЕНО - функционал встроен в основной фильтр)
/*
async function loadEmptyStatusCount() {
  try {
    logger.debug('📊 Загружаем количество пустых статусов...');
    const response = await fetch('empty_status_manager.php?action=get_empty_status_count');
    const data = await response.json();
    
    logger.debug('📊 Ответ API пустых статусов:', data);
    
    if (data.success) {
      const countEl = getElementById('emptyStatusCount');
      const cardEl = getSel('[data-card="empty_status"]');
      const navBtnEl = getElementById('emptyStatusNavBtn');
      
      logger.debug('📊 Элементы найдены:', {
        countEl: !!countEl,
        cardEl: !!cardEl,
        navBtnEl: !!navBtnEl,
        count: data.count
      });
      
      if (countEl && cardEl) {
        // Обновляем значение
        updateStatValue(countEl, data.count);
        
        // Показываем/скрываем плитку и кнопку навигации в зависимости от количества
        if (data.count > 0) {
          logger.debug('📊 Показываем плитку пустых статусов (count > 0)');
          cardEl.classList.remove('force-hidden', 'd-none');
          cardEl.removeAttribute('hidden');
          if (navBtnEl) {
            navBtnEl.classList.remove('force-hidden', 'd-none');
            navBtnEl.removeAttribute('hidden');
          }
        } else {
          cardEl.classList.add('force-hidden', 'd-none');
          cardEl.setAttribute('hidden', 'true');
          if (navBtnEl) {
            navBtnEl.classList.add('force-hidden', 'd-none');
            navBtnEl.setAttribute('hidden', 'true');
          }
        }
      }
    } else {
      logger.error('📊 API вернул ошибку:', data.error);
    }
  } catch (error) {
    logger.error('Ошибка загрузки пустых статусов:', error);
  }
}
*/

// Анимация чисел в статистических блоках
function animateStatNumbers() {
  const statValues = document.querySelectorAll('.stat-value');
  
  statValues.forEach(valueElement => {
    const finalNumber = parseInt(valueElement.textContent.replace(/,/g, ''));
    const duration = 2000; // 2 секунды
    const steps = 60;
    const stepValue = finalNumber / steps;
    let currentStep = 0;
    
    valueElement.textContent = '0';
    
    const timer = setInterval(() => {
      currentStep++;
      const currentValue = Math.floor(stepValue * currentStep);
      
      if (currentStep >= steps) {
        valueElement.textContent = finalNumber.toLocaleString();
        clearInterval(timer);
      } else {
        valueElement.textContent = currentValue.toLocaleString();
      }
    }, duration / steps);
  });
}

// Инициализация числовых значений без анимации и анимированное обновление только изменившихся
function getElementNumericValue(el) {
  const ds = el.getAttribute('data-value');
  if (ds !== null && ds !== '') {
    const n = Number(ds);
    if (!Number.isNaN(n)) return n;
  }
  const t = (el.textContent || '').replace(/[^\d\-]/g, '');
  const n = parseInt(t || '0', 10);
  return Number.isNaN(n) ? 0 : n;
}

function initStatValues() {
  const statValues = document.querySelectorAll('.stat-value');
  statValues.forEach(el => {
    const n = getElementNumericValue(el);
    el.setAttribute('data-value', String(n));
    // Приводим отображение к локализованному формату без анимации
    el.textContent = Number(n).toLocaleString();
  });
}

function updateStatValue(el, nextNumber, duration = 600) {
  const next = Number(nextNumber);
  if (Number.isNaN(next)) return;
  const from = getElementNumericValue(el);
  if (from === next) return; // Нет изменений — без анимации
  // Отменяем предыдущую анимацию, если была
  if (el.__animFrameId) { try { cancelAnimationFrame(el.__animFrameId); } catch(_) {} }
  const startTime = performance.now();
  const animate = (now) => {
    const p = Math.min(1, (now - startTime) / duration);
    const current = Math.round(from + (next - from) * p);
    el.textContent = Number(current).toLocaleString();
    if (p < 1) {
      el.__animFrameId = requestAnimationFrame(animate);
    } else {
      el.__animFrameId = null;
      el.setAttribute('data-value', String(next));
      el.textContent = Number(next).toLocaleString();
    }
  };
  el.__animFrameId = requestAnimationFrame(animate);
}

// Сброс названий блоков к исходным значениям
function resetStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  
  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const originalText = label.getAttribute('data-original');
    const labelText = label.querySelector('.label-text');
    
    // Восстанавливаем исходное название
    labelText.textContent = originalText;
    
    // Удаляем из localStorage
    const key = `stat_label_${cardType}`;
    localStorage.removeItem(key);
  });
}

// Предварительный просмотр названий блоков
function previewStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  let previewText = 'Текущие названия блоков:\n\n';
  
  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const currentText = label.querySelector('.label-text').textContent;
    const originalText = label.getAttribute('data-original');
    
    previewText += `• ${cardType}: "${currentText}"`;
    if (currentText !== originalText) {
      previewText += ` (было: "${originalText}")`;
    }
    previewText += '\n';
  });
  
  // Показываем в модальном окне
  const previewModal = getElementById('previewModal');
  const previewModalTitle = getElementById('previewModalTitle');
  const previewModalBody = getElementById('previewModalBody');
  
  if (previewModalTitle) previewModalTitle.textContent = 'Предварительный просмотр названий';
  if (previewModalBody) previewModalBody.textContent = previewText;
  
  if (previewModal) {
    const modal = new bootstrap.Modal(previewModal);
    modal.show();
  }
}



// ===== Автообновление данных =====
let autoRefreshInterval = null;
let isAutoRefreshEnabled = false;
// refreshController, refreshQueued — в dashboard-refresh.js

function initializeAutoRefresh() {
  const toggleBtn = getElementById('autoRefreshToggle');
  if (!toggleBtn) return;
  
  toggleBtn.addEventListener('click', function() {
    if (isAutoRefreshEnabled) {
      stopAutoRefresh();
    } else {
      startAutoRefresh();
    }
  });
  
  // Загружаем состояние из localStorage
  const savedState = localStorage.getItem('dashboard_auto_refresh');
  if (savedState === 'enabled') {
    startAutoRefresh();
  }
}

function startAutoRefresh() {
  isAutoRefreshEnabled = true;
  const toggleBtn = getElementById('autoRefreshToggle');
  if (!toggleBtn) return;
  
  toggleBtn.classList.add('active');
  toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
  toggleBtn.title = 'Остановить автообновление';
  
  // Обновляем каждые 30 секунд; сбросим предыдущий интервал на всякий случай
  if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
  autoRefreshInterval = setInterval(() => {
    refreshDashboardData();
  }, 30000);
  
  localStorage.setItem('dashboard_auto_refresh', 'enabled');
  // Не показываем уведомление постоянно
}

function stopAutoRefresh() {
  isAutoRefreshEnabled = false;
  const toggleBtn = getElementById('autoRefreshToggle');
  if (!toggleBtn) return;
  
  toggleBtn.classList.remove('active');
  toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
  toggleBtn.title = 'Включить автообновление';
  
  if (autoRefreshInterval) {
    clearInterval(autoRefreshInterval);
    autoRefreshInterval = null;
  }
  // Отменяем текущий запрос, если он есть
  try { if (window.refreshController) window.refreshController.abort(); } catch(_) {}
  
  localStorage.setItem('dashboard_auto_refresh', 'disabled');
  showToast('Автообновление отключено', 'info');
}

// ===== refreshDashboardData перенесена в dashboard-refresh.js =====

// ===== Кнопка "Наверх" =====
function initScrollToTop() {
  const scrollToTopBtn = getElementById('scrollToTop');
  if (!scrollToTopBtn) return;

  // Показываем/скрываем кнопку в зависимости от позиции скролла
  function toggleScrollToTop() {
    if (window.pageYOffset > 300) {
      scrollToTopBtn.classList.add('show');
    } else {
      scrollToTopBtn.classList.remove('show');
    }
  }

  // Плавный скролл наверх
  function scrollToTop() {
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  }

  // Обработчики событий
  window.addEventListener('scroll', toggleScrollToTop);
  scrollToTopBtn.addEventListener('click', scrollToTop);

  // Инициализация
  toggleScrollToTop();
}

// ===== Touch-жесты и адаптивные карточки =====
function initializeTouchGestures() {
  const touchCards = document.querySelectorAll('.touch-card');
  
  touchCards.forEach(card => {
    let startX = 0;
    let startY = 0;
    let currentX = 0;
    let currentY = 0;
    
    // Touch события
    card.addEventListener('touchstart', function(e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      currentX = startX;
      currentY = startY;
      
      this.classList.add('touching');
    });
    
    card.addEventListener('touchmove', function(e) {
      currentX = e.touches[0].clientX;
      currentY = e.touches[0].clientY;
      
      const deltaX = currentX - startX;
      const deltaY = currentY - startY;
      
      // Swipe влево - показать детали
      if (deltaX < -50 && Math.abs(deltaY) < 50) {
        this.style.transform = `translateX(${deltaX}px)`;
      }
    });
    
    card.addEventListener('touchend', function(e) {
      const deltaX = currentX - startX;
      const deltaY = currentY - startY;
      
      this.classList.remove('touching');
      this.style.transform = '';
      
      // Swipe влево - показать детали
      if (deltaX < -100 && Math.abs(deltaY) < 50) {
        handleCardSwipe(this);
      }
      
      // Tap - редактирование названия
      if (Math.abs(deltaX) < 10 && Math.abs(deltaY) < 10) {
        const label = this.querySelector('.stat-label.editable');
        if (label) {
          startEditing(label);
        }
      }
    });
    
    // Mouse события для десктопа
    card.addEventListener('mousedown', function(e) {
      startX = e.clientX;
      startY = e.clientY;
      this.classList.add('touching');
    });
    
    card.addEventListener('mousemove', function(e) {
      if (this.classList.contains('touching')) {
        currentX = e.clientX;
        currentY = e.clientY;
        
        const deltaX = currentX - startX;
        const deltaY = currentY - startY;
        
        if (deltaX < -50 && Math.abs(deltaY) < 50) {
          this.style.transform = `translateX(${deltaX}px)`;
        }
      }
    });
    
    card.addEventListener('mouseup', function(e) {
      if (this.classList.contains('touching')) {
        const deltaX = currentX - startX;
        const deltaY = currentY - startY;
        
        this.classList.remove('touching');
        this.style.transform = '';
        
        if (deltaX < -100 && Math.abs(deltaY) < 50) {
          handleCardSwipe(this);
        }
      }
    });
    
    // Hover эффекты для десктопа
    card.addEventListener('mouseenter', function() {
      if (!this.classList.contains('touching')) {
        this.style.transform = 'translateY(-5px) scale(1.02)';
      }
    });
    
    card.addEventListener('mouseleave', function() {
      if (!this.classList.contains('touching')) {
        this.style.transform = '';
      }
    });
  });
}

async function handleCardSwipe(card) {
  const cardType = card.getAttribute('data-card-type');
  const status = card.getAttribute('data-status');
  
  if (cardType === 'total') {
    // Показать общую статистику
    showToast('Показать детальную статистику по всем аккаунтам', 'info');
  } else if (cardType === 'status') {
    // Фильтровать по статусу - БЕЗ перезагрузки страницы
    const url = new URL(window.location);
    // Удаляем все старые статусы
    const keysToDelete = [];
    for (const key of url.searchParams.keys()) {
      if (key === 'status[]' || key === 'status') {
        keysToDelete.push(key);
      }
    }
    keysToDelete.forEach(key => {
      while (url.searchParams.has(key)) {
        url.searchParams.delete(key);
      }
    });
    // Добавляем новый статус
    url.searchParams.append('status[]', status);
    url.searchParams.set('page', '1');
    // Обновляем URL без перезагрузки
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    // Обновляем данные через AJAX
    refreshDashboardData();
  } else if (cardType === 'custom') {
    // Применяем все фильтры из кастомной карточки
    const cardKey = card.getAttribute('data-card-key');
    if (!cardKey) {
      logger.warn('Card swipe: no card key found');
      return;
    }
    
    // Используем синхронную загрузку из localStorage для быстрого доступа
    const cards = loadCustomCardsFromLocalStorage();
    const cardData = cards.find(c => c.key === cardKey);
    if (!cardData) {
      logger.warn('Card swipe: card not found', cardKey);
      showToast('Карточка не найдена', 'error');
      return;
    }
    
    const url = new URL(window.location);
    url.search = ''; // Очищаем все текущие фильтры
    
    const filters = cardData.filters || {};
    
    // Логируем для отладки
    logger.debug('Applying filters from card:', cardKey, filters);
    
    // Статусы (множественный выбор - передаем как массив)
    if (filters.status && Array.isArray(filters.status) && filters.status.length > 0) {
      // Для множественного выбора статусов используем параметр status[] (массив)
      // URLSearchParams.append с одинаковым ключом создаст массив в PHP
      filters.status.forEach(st => {
        url.searchParams.append('status[]', st);
      });
    } else if (filters.status && typeof filters.status === 'string' && filters.status !== '') {
      // Если статус передан как строка (для обратной совместимости)
      url.searchParams.set('status', filters.status);
    }
    
    // Булевы фильтры
    if (filters.has_email) url.searchParams.set('has_email', '1');
    if (filters.has_two_fa) url.searchParams.set('has_two_fa', '1');
    if (filters.has_token) url.searchParams.set('has_token', '1');
    if (filters.has_avatar) url.searchParams.set('has_avatar', '1');
    if (filters.has_cover) url.searchParams.set('has_cover', '1');
    if (filters.has_password) url.searchParams.set('has_password', '1');
    if (filters.has_fan_page) url.searchParams.set('has_fan_page', '1');
    if (filters.full_filled) url.searchParams.set('full_filled', '1');
    if (filters.favorites_only) url.searchParams.set('favorites_only', '1');
    
    // Диапазоны
    if (filters.pharma_from) url.searchParams.set('pharma_from', filters.pharma_from);
    if (filters.pharma_to) url.searchParams.set('pharma_to', filters.pharma_to);
    if (filters.friends_from) url.searchParams.set('friends_from', filters.friends_from);
    if (filters.friends_to) url.searchParams.set('friends_to', filters.friends_to);
    if (filters.year_created_from) url.searchParams.set('year_created_from', filters.year_created_from);
    if (filters.year_created_to) url.searchParams.set('year_created_to', filters.year_created_to);
    
    // Одиночные фильтры
    if (filters.status_marketplace) url.searchParams.set('status_marketplace', filters.status_marketplace);
    if (filters.currency) url.searchParams.set('currency', filters.currency);
    if (filters.geo) url.searchParams.set('geo', filters.geo);
    if (filters.status_rk) url.searchParams.set('status_rk', filters.status_rk);
    
    // Limit RK (диапазон)
    if (filters.limit_rk_from) url.searchParams.set('limit_rk_from', filters.limit_rk_from);
    if (filters.limit_rk_to) url.searchParams.set('limit_rk_to', filters.limit_rk_to);
    
    // Поиск
    if (filters.q) url.searchParams.set('q', filters.q);
    
    // Убираем автоматическое обновление статуса при клике
    // Статус больше не обновляется автоматически - просто применяются фильтры
    
    // Сохраняем активную карточку в URL для восстановления после перезагрузки
    url.searchParams.set('active_card', cardKey);
    url.searchParams.set('page', '1');
    
    // Обновляем URL без перезагрузки страницы
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    // Обновляем данные через AJAX
    refreshDashboardData();
  }
}

// ===== Адаптивность для мобильных устройств =====
function adjustForMobile() {
  const isMobile = window.innerWidth <= 768;
  
  if (isMobile) {
    document.body.classList.add('touch-friendly');
    
    // Увеличиваем размеры кнопок для touch
    document.querySelectorAll('.btn').forEach(btn => {
      btn.classList.add('touch-friendly');
    });
    
    // Адаптируем карточки
    document.querySelectorAll('.stat-card').forEach(card => {
      card.classList.add('touch-friendly');
    });
  } else {
    document.body.classList.remove('touch-friendly');
  }
}

// Вызываем адаптацию при загрузке и изменении размера
window.addEventListener('resize', adjustForMobile);
    window.addEventListener('load', function() {
  adjustForMobile();
  loadHiddenCards().catch(err => logger.error('Error loading hidden cards:', err)); // Загружаем скрытые карточки при загрузке страницы
});

// ===== КАСТОМНЫЕ КАРТОЧКИ СТАТИСТИКИ =====
// Полностью переписанный функционал с нуля - версия 3.0
const LS_KEY_CUSTOM_CARDS = 'dashboard_custom_cards_v3';

// Вспомогательная функция для конвертации HEX в RGB
function hexToRgb(hex) {
  if (!hex) return null;
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  return result ? {
    r: parseInt(result[1], 16),
    g: parseInt(result[2], 16),
    b: parseInt(result[3], 16)
  } : null;
}

// ===== БАЗОВЫЕ ФУНКЦИИ РАБОТЫ С ХРАНИЛИЩЕМ =====

/**
 * Загрузка кастомных карточек из БД с fallback на localStorage
 */
async function loadCustomCardsFromStorage() {
  try {
    const response = await fetch('/api/settings?type=custom_cards', {
      method: 'GET',
      credentials: 'same-origin'
    });
    
    if (response.ok) {
      const data = await response.json();
      if (data.success && Array.isArray(data.value)) {
        const cards = data.value.filter(x => x && typeof x === 'object' && x.key);
        // Сохраняем в localStorage как резервную копию
        try {
          localStorage.setItem(LS_KEY_CUSTOM_CARDS, JSON.stringify(cards));
        } catch (e) {
          logger.warn('Failed to save to localStorage:', e);
        }
        return cards;
      }
    }
  } catch (error) {
    logger.warn('Error loading from server, using localStorage:', error);
  }
  
  // Fallback на localStorage
  return loadCustomCardsFromLocalStorage();
}

/**
 * Загрузка из localStorage (резервная)
 */
function loadCustomCardsFromLocalStorage() {
  try {
    const raw = localStorage.getItem(LS_KEY_CUSTOM_CARDS);
    if (!raw) return [];
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr)) return [];
    return arr.filter(x => x && typeof x === 'object' && x.key);
  } catch (e) {
    logger.error('Error loading from localStorage:', e);
    return [];
  }
}

/**
 * Сохранение кастомных карточек в БД и localStorage
 */
async function saveCustomCardsToStorage(cards) {
  if (!Array.isArray(cards)) {
    logger.error('Invalid cards array');
    return false;
  }
  
  // Сохраняем в localStorage сразу
  try {
    localStorage.setItem(LS_KEY_CUSTOM_CARDS, JSON.stringify(cards));
  } catch (e) {
    logger.warn('Failed to save to localStorage:', e);
  }
  
  // Сохраняем в БД
  try {
    const response = await fetch('/api/settings', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        type: 'custom_cards',
        value: cards,
        csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
      })
    });
    
    if (!response.ok) {
      logger.warn('Failed to save to server, saved to localStorage only');
      return false;
    }
    
    return true;
  } catch (error) {
    logger.error('Error saving to server:', error);
    return false;
  }
}

// ===== ФУНКЦИИ ОТОБРАЖЕНИЯ =====

/**
 * Отображение списка карточек в настройках
 */
async function renderCustomCardsSettings() {
  const list = getElementById('customCardsList');
  if (!list) {
    logger.warn('customCardsList element not found');
    return;
  }
  
  const cards = await loadCustomCardsFromStorage();
  
  if (!cards.length) {
    list.innerHTML = '<div class="text-muted text-center py-3">Нет кастомных карточек. Нажмите "Создать карточку" для добавления.</div>';
    return;
  }
  
  list.innerHTML = cards.map((c, idx) => {
    const filters = c.filters || {};
    const filterDesc = [];
    
    if (filters.status && Array.isArray(filters.status) && filters.status.length > 0) {
      filterDesc.push(`Статусы: ${filters.status.length}`);
    }
    if (filters.has_email) filterDesc.push('Email');
    if (filters.has_two_fa) filterDesc.push('2FA');
    if (filters.has_token) filterDesc.push('Token');
    if (filters.has_avatar) filterDesc.push('Аватар');
    if (filters.has_cover) filterDesc.push('Обложка');
    if (filters.has_password) filterDesc.push('Пароль');
    if (filters.has_fan_page) filterDesc.push('Fan Page');
    if (filters.full_filled) filterDesc.push('Полностью заполнено');
    if (c.targetStatus) filterDesc.push(`→ ${c.targetStatus}`);
    
    return `
    <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
      <div class="flex-grow-1">
        <div class="fw-semibold d-flex align-items-center gap-2">
          ${c.settings?.color ? `<span class="badge" style="background-color: ${c.settings.color}; width: 16px; height: 16px; border-radius: 4px; display: inline-block;"></span>` : ''}
          ${(c.name || 'Без названия')}
        </div>
        <div class="text-muted small">${filterDesc.length > 0 ? filterDesc.join(' • ') : 'Без фильтров'}</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <div class="form-check">
          <input class="form-check-input card-toggle" type="checkbox" data-card="custom:${c.key}" id="card_custom_${idx}" ${c.visible !== false ? 'checked' : ''}>
          <label class="form-check-label" for="card_custom_${idx}">Показывать</label>
        </div>
        ${c.targetStatus ? `<button type="button" class="btn btn-sm btn-outline-info" data-register-status="${c.targetStatus}" title="Повторно зарегистрировать статус"><i class="fas fa-sync-alt"></i> Обновить</button>` : ''}
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom-card="${c.key}" title="Удалить"><i class="fas fa-trash"></i></button>
      </div>
    </div>
    `;
  }).join('');
}

/**
 * Отображение карточек на дашборде
 */
async function renderCustomCardsOnDashboard() {
  const row = getElementById('statsRow');
  if (!row) {
    logger.warn('statsRow element not found');
    setTimeout(() => renderCustomCardsOnDashboard(), 200);
    return;
  }
  
  // Удаляем старые кастомные карточки
  row.querySelectorAll('[data-card^="custom:"]').forEach(n => n.remove());
  
  const cards = await loadCustomCardsFromStorage();
  if (!cards.length) return;
  
  // Загружаем скрытые карточки
  const hiddenCards = new Set();
  try {
    const savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
    if (savedHidden) {
      JSON.parse(savedHidden).forEach(id => {
        if (typeof id === 'string') {
          hiddenCards.add(id);
        }
      });
    }
  } catch (e) {
    logger.error('Error loading hidden cards:', e);
  }
  
  // Создаем карточки
  cards.forEach(c => {
    // Проверяем видимость
    if (c.visible === false) return;
    const cardId = `custom:${c.key}`;
    const isHiddenByUser = hiddenCards.has(cardId);
    
    // Создаем элемент карточки
    const cardElement = document.createElement('div');
    cardElement.className = 'stat-card fade-in';
    cardElement.setAttribute('data-card', cardId);
    cardElement.setAttribute('data-card-type', 'custom');
    cardElement.setAttribute('data-card-key', c.key);
    
    // Применяем фильтры как data-атрибуты
    const filters = c.filters || {};
    if (filters.has_email) cardElement.setAttribute('data-has-email', '1');
    if (filters.has_two_fa) cardElement.setAttribute('data-has-two-fa', '1');
    if (filters.has_token) cardElement.setAttribute('data-has-token', '1');
    if (filters.has_avatar) cardElement.setAttribute('data-has-avatar', '1');
    if (filters.has_cover) cardElement.setAttribute('data-has-cover', '1');
    if (filters.full_filled) cardElement.setAttribute('data-full-filled', '1');
    if (filters.pharma_from) cardElement.setAttribute('data-pharma-from', filters.pharma_from);
    if (filters.pharma_to) cardElement.setAttribute('data-pharma-to', filters.pharma_to);
    if (c.targetStatus) cardElement.setAttribute('data-target-status', c.targetStatus);
    
    // Применяем цвет
    const cardColor = c.settings?.color || '#3b82f6';
    const rgb = hexToRgb(cardColor);
    const darkerColor = rgb ? `rgb(${Math.max(0, rgb.r - 30)}, ${Math.max(0, rgb.g - 30)}, ${Math.max(0, rgb.b - 30)})` : cardColor;
    cardElement.style.setProperty('--card-color', cardColor);
    cardElement.style.setProperty('--card-color-dark', darkerColor);
    
    cardElement.innerHTML = `
      <button type="button" class="stat-card-hide-btn" data-card="${cardId}" title="Скрыть карточку">
        <i class="fas fa-eye-slash"></i>
      </button>
      <div class="stat-header">
        <h3 class="stat-title">${(c.name || 'Кастом')}</h3>
      </div>
      <div class="stat-value">0</div>
      <div class="stat-trend"><small class="text-muted">${c.targetStatus ? `→ ${c.targetStatus}` : 'Кастомные условия'}</small></div>
    `;
    
    if (isHiddenByUser) {
      cardElement.classList.add('hidden');
    }
    
    row.appendChild(cardElement);
  });
  
  // Восстанавливаем активную карточку из URL параметров
  const urlParams = new URLSearchParams(window.location.search);
  const activeCardKey = urlParams.get('active_card');
  if (activeCardKey) {
    // Небольшая задержка, чтобы карточки успели отрендериться
    setTimeout(() => {
      const activeCard = getSel(`.stat-card[data-card-key="${activeCardKey}"]`);
      if (activeCard) {
        activeCard.classList.add('active');
        
        // Принудительно применяем стили через inline стили для надежности
        const cardColor = activeCard.style.getPropertyValue('--card-color') || '#3b82f6';
        activeCard.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.4) 0%, rgba(59, 130, 246, 0.6) 100%)';
        activeCard.style.border = `2px solid ${cardColor}`;
        activeCard.style.boxShadow = `0 0 0 3px ${cardColor}, 0 14px 24px rgba(59, 130, 246, 0.4)`;
        activeCard.style.opacity = '1';
        
        logger.debug('Active card restored from URL:', activeCardKey, activeCard);
        
        // Удаляем параметр из URL без перезагрузки страницы
        urlParams.delete('active_card');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, '', newUrl);
      } else {
        logger.warn('Active card not found:', activeCardKey);
      }
    }, 100);
  }
  
  // Обновляем счетчики
  await refreshCustomCardCounts();
}

/**
 * Обновление счетчиков для всех кастомных карточек
 */
async function refreshCustomCardCounts() {
  const cards = await loadCustomCardsFromStorage();
  if (!cards.length) return;
  
  // Обновляем все карточки параллельно
  const updatePromises = cards.map(async (c) => {
    try {
      const filters = c.filters || {};
      
      // Обратная совместимость со старыми карточками
      if (Object.keys(filters).length === 0) {
        if (c.hasEmail) filters.has_email = true;
        if (c.hasTwoFa) filters.has_two_fa = true;
        if (c.hasToken) filters.has_token = true;
        if (c.hasAvatar) filters.has_avatar = true;
        if (c.hasCover) filters.has_cover = true;
        if (c.fullFilled) filters.full_filled = true;
        if (c.pharmaFrom) filters.pharma_from = c.pharmaFrom;
        if (c.pharmaTo) filters.pharma_to = c.pharmaTo;
      }
      
      const response = await fetch('/api/accounts/custom-card', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          ...filters,
          csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
        })
      });
      
      if (!response.ok) {
        logger.warn(`Failed to refresh card ${c.key}: ${response.status}`);
        return;
      }
      
      const json = await response.json();
      if (!json.success || typeof json.count !== 'number') {
        logger.warn(`Invalid response for card ${c.key}:`, json);
        return;
      }
      
      const wrap = getSel(`[data-card="custom:${c.key}"] .stat-value`);
      if (wrap) {
        updateStatValue(wrap, json.count);
      }
      
      // Применяем цвет карточки
      const cardEl = getSel(`[data-card="custom:${c.key}"]`);
      if (cardEl && c.settings?.color) {
        cardEl.style.setProperty('--card-color', c.settings.color);
        const rgb = hexToRgb(c.settings.color);
        const darkerColor = rgb ? `rgb(${Math.max(0, rgb.r - 30)}, ${Math.max(0, rgb.g - 30)}, ${Math.max(0, rgb.b - 30)})` : c.settings.color;
        cardEl.style.setProperty('--card-color-dark', darkerColor);
      }
    } catch (e) {
      logger.error(`Error refreshing custom card ${c.key}:`, e);
    }
  });
  
  await Promise.all(updatePromises);
}

/**
 * Создание новой кастомной карточки
 */
async function createCustomCard() {
  const name = (getElementById('customCardName')?.value || '').trim();
  if (!name) {
    showToast('Введите название карточки', 'error');
    return;
  }
  
  // Собираем фильтры
  const filters = {};
  
  // Статусы (множественный выбор)
  const statusSelect = getElementById('customCardStatuses');
  if (statusSelect) {
    const selectedStatuses = Array.from(statusSelect.selectedOptions).map(opt => opt.value);
    if (selectedStatuses.length > 0) {
      filters.status = selectedStatuses;
    }
  }
  
  // Булевы фильтры
  filters.has_email = !!getElementById('customHasEmail')?.checked;
  filters.has_two_fa = !!getElementById('customHasTwoFa')?.checked;
  filters.has_token = !!getElementById('customHasToken')?.checked;
  filters.has_avatar = !!getElementById('customHasAvatar')?.checked;
  filters.has_cover = !!getElementById('customHasCover')?.checked;
  filters.has_password = !!getElementById('customHasPassword')?.checked;
  filters.has_fan_page = !!getElementById('customHasFanPage')?.checked;
  filters.full_filled = !!getElementById('customFullFilled')?.checked;
  
  // Диапазоны
  const pharmaFrom = (getElementById('customPharmaFrom')?.value || '').trim();
  const pharmaTo = (getElementById('customPharmaTo')?.value || '').trim();
  if (pharmaFrom) filters.pharma_from = pharmaFrom;
  if (pharmaTo) filters.pharma_to = pharmaTo;
  
  const friendsFrom = (getElementById('customFriendsFrom')?.value || '').trim();
  const friendsTo = (getElementById('customFriendsTo')?.value || '').trim();
  if (friendsFrom) filters.friends_from = friendsFrom;
  if (friendsTo) filters.friends_to = friendsTo;
  
  const yearFrom = (getElementById('customYearCreatedFrom')?.value || '').trim();
  const yearTo = (getElementById('customYearCreatedTo')?.value || '').trim();
  if (yearFrom) filters.year_created_from = yearFrom;
  if (yearTo) filters.year_created_to = yearTo;
  
  // Одиночные фильтры
  const statusMarketplace = getElementById('customStatusMarketplace')?.value;
  if (statusMarketplace) filters.status_marketplace = statusMarketplace;
  
  const statusRk = getElementById('customStatusRk')?.value;
  if (statusRk) filters.status_rk = statusRk;
  
  // Limit RK (диапазон)
  const limitRkFrom = (getElementById('customLimitRkFrom')?.value || '').trim();
  const limitRkTo = (getElementById('customLimitRkTo')?.value || '').trim();
  if (limitRkFrom) filters.limit_rk_from = limitRkFrom;
  if (limitRkTo) filters.limit_rk_to = limitRkTo;
  
  const currency = getElementById('customCurrency')?.value;
  if (currency) filters.currency = currency;
  
  const geo = getElementById('customGeo')?.value;
  if (geo) filters.geo = geo;
  
  // Булевы фильтры
  const favoritesOnly = getSel('input[type="checkbox"][name="favorites_only"]')?.checked;
  if (favoritesOnly) filters.favorites_only = true;
  
  // Целевой статус
  let targetStatus = (getElementById('customCardTargetStatus')?.value || '').trim();
  const wasNewStatus = (targetStatus === '__new__');
  
  if (targetStatus === '__new__') {
    targetStatus = (getElementById('customCardNewStatus')?.value || '').trim();
    if (!targetStatus) {
      showToast('Введите название нового статуса', 'error');
      return;
    }
  }
  
  // Автоматически регистрируем статус в БД
  if (targetStatus && targetStatus.trim() !== '') {
    try {
      const registerResponse = await fetch('/api/status/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          status: targetStatus,
          csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
        })
      });
      
      if (registerResponse.ok) {
        const registerData = await registerResponse.json();
        if (registerData.success) {
          logger.debug(`Статус "${targetStatus}" ${registerData.exists ? 'уже существует' : 'зарегистрирован'}`);
        }
      }
    } catch (error) {
      logger.error('Error registering status:', error);
    }
  }
  
  // Создаем карточку
  const key = `c_${Date.now()}`;
  const card = {
    key,
    name,
    visible: true,
    filters: filters,
    targetStatus: targetStatus || null,
    settings: {
      color: getElementById('customCardColor')?.value || '#3b82f6'
    }
  };
  
  // Сохраняем
  const cards = await loadCustomCardsFromStorage();
  cards.push(card);
  await saveCustomCardsToStorage(cards);
  
  // Закрываем модальное окно
  const modal = bootstrap.Modal.getInstance(getElementById('customCardModal'));
  if (modal) modal.hide();
  
  // Обновляем UI
  await renderCustomCardsSettings();
  await renderCustomCardsOnDashboard();
  loadStatLabels();
  
  // Уведомление
  if (targetStatus && targetStatus.trim() !== '') {
    sessionStorage.removeItem('statuses_registered');
    if (wasNewStatus) {
      showToast(`Кастомная карточка добавлена. Новый статус "${targetStatus}" зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.`, 'success', 5000);
    } else {
      showToast(`Кастомная карточка добавлена. Статус "${targetStatus}" проверен.`, 'success', 4000);
    }
  } else {
    showToast('Кастомная карточка добавлена', 'success');
  }
}

/**
 * Автоматическая регистрация отсутствующих статусов
 */
async function registerMissingStatuses() {
  try {
    const cards = await loadCustomCardsFromStorage();
    const statusesToRegister = cards
      .map(c => c.targetStatus)
      .filter(s => s && s.trim() !== '')
      .map(s => s.trim());
    
    if (statusesToRegister.length === 0) return;
    
    const uniqueStatuses = [...new Set(statusesToRegister)];
    let registeredCount = 0;
    
    for (const status of uniqueStatuses) {
      try {
        const response = await fetch('/api/status/register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            status: status,
            csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
          })
        });
        
        if (response.ok) {
          const data = await response.json();
          if (data.success && !data.exists) {
            registeredCount++;
            logger.debug(`Статус "${status}" автоматически зарегистрирован`);
          }
        }
      } catch (error) {
        logger.warn(`Не удалось зарегистрировать статус "${status}":`, error);
      }
    }
    
    if (registeredCount > 0) {
      showToast(`Зарегистрировано ${registeredCount} новых статусов. Обновите страницу, чтобы увидеть их в фильтрах.`, 'success', 5000);
    }
  } catch (error) {
    logger.error('Error registering missing statuses:', error);
  }
}

/**
 * Инициализация кастомных карточек
 */
async function initializeCustomCards() {
  await renderCustomCardsSettings();
  await renderCustomCardsOnDashboard();
  
  // Автоматически регистрируем статусы один раз за сессию
  if (!sessionStorage.getItem('statuses_registered')) {
    await registerMissingStatuses();
    sessionStorage.setItem('statuses_registered', 'true');
  }
  
  // Обработчик кнопки создания карточки
  const addBtn = getElementById('addCustomCardBtn');
  if (addBtn) {
    addBtn.addEventListener('click', () => {
      getElementById('customCardForm')?.reset();
      getElementById('customCardColor').value = '#3b82f6';
      const newStatusInputGroup = getElementById('newStatusInputGroup');
      if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
    });
  }
  
  // Обработчик изменения селекта целевого статуса
  const targetStatusSelect = getElementById('customCardTargetStatus');
  const newStatusInputGroup = getElementById('newStatusInputGroup');
  const newStatusInput = getElementById('customCardNewStatus');
  
  if (targetStatusSelect) {
    targetStatusSelect.addEventListener('change', function() {
      if (this.value === '__new__') {
        if (newStatusInputGroup) newStatusInputGroup.style.display = 'block';
        if (newStatusInput) {
          newStatusInput.focus();
          newStatusInput.required = true;
        }
      } else {
        if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
        if (newStatusInput) {
          newStatusInput.value = '';
          newStatusInput.required = false;
        }
      }
    });
  }
  
  // Обработчик сохранения карточки
  const saveBtn = getElementById('saveCustomCardBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      await createCustomCard();
    });
  }
  
  // Обработчик закрытия модального окна
  const modal = getElementById('customCardModal');
  if (modal) {
    modal.addEventListener('hidden.bs.modal', () => {
      getElementById('customCardForm')?.reset();
      if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
      if (newStatusInput) {
        newStatusInput.value = '';
        newStatusInput.required = false;
      }
    });
  }
  
  // Обработчик переключения видимости карточки
  document.addEventListener('change', async (e) => {
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.classList.contains('card-toggle') && t.getAttribute('data-card')?.startsWith('custom:')) {
      const key = t.getAttribute('data-card')?.slice(7);
      const cards = await loadCustomCardsFromStorage();
      const card = cards.find(x => x.key === key);
      if (card) {
        card.visible = !!t.checked;
        await saveCustomCardsToStorage(cards);
        await renderCustomCardsOnDashboard();
      }
    }
  });
  
  // Обработчик удаления карточки
  document.addEventListener('click', async (e) => {
    const removeBtn = (e.target instanceof HTMLElement) ? e.target.closest('[data-remove-custom-card]') : null;
    if (removeBtn) {
      const key = removeBtn.getAttribute('data-remove-custom-card');
      const cards = (await loadCustomCardsFromStorage()).filter(x => x.key !== key);
      await saveCustomCardsToStorage(cards);
      await renderCustomCardsSettings();
      await renderCustomCardsOnDashboard();
      showToast('Кастомная карточка удалена', 'success');
      return;
    }
    
    // Обработчик регистрации статуса
    const registerBtn = (e.target instanceof HTMLElement) ? e.target.closest('[data-register-status]') : null;
    if (registerBtn) {
      const status = registerBtn.getAttribute('data-register-status');
      if (!status) return;
      
      registerBtn.disabled = true;
      const originalHtml = registerBtn.innerHTML;
      registerBtn.innerHTML = '<span class="loader loader-sm loader-white" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;margin-right:8px;"></span> Регистрация...';
      
      try {
        const response = await fetch('/api/status/register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            status: status,
            csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || ''
          })
        });
        
        if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.error || 'Ошибка регистрации статуса');
        }
        
        const data = await response.json();
        if (data.success) {
          showToast(`Статус "${status}" успешно зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.`, 'success', 5000);
        } else {
          throw new Error('Не удалось зарегистрировать статус');
        }
      } catch (error) {
        logger.error('Error registering status:', error);
        showToast(`Ошибка регистрации статуса: ${error.message}`, 'error');
        registerBtn.disabled = false;
        registerBtn.innerHTML = originalHtml;
      }
    }
  });
}

// ===== ДУБЛИРУЮЩИЙСЯ КОД УДАЛЕН =====
// Все функции кастомных карточек определены выше в новой версии (строки 6300-6924)

// Логика массовой смены статуса вынесена в модуль `dashboard-modals.js` (initStatusModal).

document.addEventListener('click', function(e) {
  const selAll = e.target && e.target.id === 'selectAllFilteredLink';
  const clearSel = e.target && e.target.id === 'clearSelectionLink';
  if (selAll) {
    e.preventDefault();
    if (window.DashboardSelection) {
      // Включаем режим "выделены все по фильтру"
      window.DashboardSelection.setSelectedAllFiltered(true);
      const selectedIds = window.DashboardSelection.getSelectedIds();
      if (selectedIds && typeof selectedIds.clear === 'function') {
        selectedIds.clear();
      }
      // Выделяем все строки на текущей странице через handleSelectAllChange,
      // но не сбрасываем флаг selectedAllFiltered (keepAllFilteredMode = true)
      const selectAllCheckbox = getElementById('selectAll');
      if (selectAllCheckbox) {
        selectAllCheckbox.checked = true;
        window.DashboardSelection.handleSelectAllChange(true, true);
      } else {
        // На всякий случай синхронизируем чекбоксы и счётчики напрямую
        window.DashboardSelection.initCheckboxStates();
        window.DashboardSelection.updateSelectedCount();
        window.DashboardSelection.updateSelectedOnPageCounter();
      }
    }
  }
  if (clearSel) {
    e.preventDefault();
    if (window.DashboardSelection) {
      window.DashboardSelection.clearSelection();
      window.DashboardSelection.initCheckboxStates(); // Синхронизируем все чекбоксы
    }
  }
});

// Select All - обработчик удалён, используется делегирование событий ниже (см. строку 5315+)

function debounce(fn, delay) {
  let t; return function(...args){ clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delay); };
}

// Дебаунсированная версия refreshDashboardData для использования в фильтрах
// Определяется после debounce и refreshDashboardData
const debouncedRefreshDashboardData = debounce(() => {
  refreshDashboardData();
}, 300); // 300ms дебаунс для фильтров

document.addEventListener('DOMContentLoaded', function() {
  const searchInput = getElementById('modernSearchInput');
  if (searchInput) {
    const applyLiveSearch = debounce(() => {
      const url = new URL(window.location);
      url.searchParams.set('q', searchInput.value || '');
      url.searchParams.set('page', '1');
      history.replaceState(null, '', url.toString());
      window.DashboardSelection && window.DashboardSelection.clearSelection();
      refreshDashboardData();
      
      // Показываем/скрываем кнопку очистки
      const clearBtn = getSel('.header-search-clear');
      if (clearBtn) {
        clearBtn.style.display = searchInput.value ? 'flex' : 'none';
      }
    }, 300);
    searchInput.addEventListener('input', applyLiveSearch);
    searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    
    // Показываем/скрываем кнопку очистки при загрузке
    const clearBtn = getSel('.header-search-clear');
    if (clearBtn) {
      clearBtn.style.display = searchInput.value ? 'flex' : 'none';
    }
  }
  // Блокируем сабмит формы фильтров
  const filterForm = getSel('.card.mb-4 form');
  if (filterForm) {
    filterForm.addEventListener('submit', (e) => e.preventDefault());
  }
  // Статус (множественный выбор через чекбоксы)
  const statusCheckboxes = document.querySelectorAll('.status-checkbox');
  const statusDropdownLabel = getElementById('statusDropdownLabel');
  const statusDropdownMenu = getSel('.status-dropdown-menu');
  
  // Функция обновления UI (мгновенно)
  function updateStatusUI() {
    const checkedBoxes = Array.from(statusCheckboxes).filter(cb => cb.checked);
    const selectedCount = checkedBoxes.length;
    const totalCount = statusCheckboxes.length;
    
    // Обновляем метку на кнопке
    if (selectedCount === 0) {
      statusDropdownLabel.textContent = 'Все статусы';
    } else if (selectedCount === totalCount) {
      statusDropdownLabel.textContent = 'Все выбраны';
    } else {
      statusDropdownLabel.textContent = `Выбрано: ${selectedCount}`;
    }
  }
  
  // Функция применения фильтра (с debounce)
  function applyStatusFilter() {
    const checkedBoxes = Array.from(statusCheckboxes).filter(cb => cb.checked);
    const selectedCount = checkedBoxes.length;
    
    // Обновляем URL и данные
    const url = new URL(window.location);
    // Удаляем все старые параметры status и empty_status
    const keysToDelete = [];
    for (const key of url.searchParams.keys()) {
      if (key === 'status[]' || key === 'status' || key === 'empty_status') {
        keysToDelete.push(key);
      }
    }
    keysToDelete.forEach(key => {
      while (url.searchParams.has(key)) {
        url.searchParams.delete(key);
      }
    });
    
    // Добавляем выбранные статусы
    if (selectedCount > 0) {
      checkedBoxes.forEach(cb => {
        if (cb.value === '__empty__') {
          url.searchParams.set('empty_status', '1');
        } else {
          url.searchParams.append('status[]', cb.value);
        }
      });
    }
    
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }
  
  // Debounced версия для применения фильтра
  const debouncedApplyStatusFilter = debounce(applyStatusFilter, 300);
  
  // Обработчик изменения чекбоксов
  statusCheckboxes.forEach(cb => {
    cb.addEventListener('change', () => {
      updateStatusUI(); // Обновляем UI мгновенно
      // НЕ применяем автоматически - только показываем индикатор
      if (typeof markFiltersAsChanged === 'function') {
        markFiltersAsChanged();
      }
    });
  });
  
  // Предотвращаем закрытие dropdown при клике внутри
  if (statusDropdownMenu) {
    statusDropdownMenu.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }
  
  // Кнопка "Выбрать все"
  const selectAllStatusesBtn = getElementById('selectAllStatusesBtn');
  if (selectAllStatusesBtn) {
    selectAllStatusesBtn.addEventListener('click', () => {
      statusCheckboxes.forEach(cb => cb.checked = true);
      updateStatusUI();
      // НЕ применяем автоматически - только показываем индикатор
      if (typeof markFiltersAsChanged === 'function') {
        markFiltersAsChanged();
      }
    });
  }
  
  // Кнопка "Очистить все"
  const clearAllStatusesBtn = getElementById('clearAllStatusesBtn');
  if (clearAllStatusesBtn) {
    clearAllStatusesBtn.addEventListener('click', () => {
      statusCheckboxes.forEach(cb => cb.checked = false);
      updateStatusUI();
      // НЕ применяем автоматически - только показываем индикатор
      if (typeof markFiltersAsChanged === 'function') {
        markFiltersAsChanged();
      }
    });
  }
  
  // Поиск по статусам
  const statusSearch = getElementById('statusSearch');
  if (statusSearch) {
    statusSearch.addEventListener('input', (e) => {
      const searchTerm = e.target.value.toLowerCase();
      const checkboxItems = document.querySelectorAll('.status-checkbox-item');
      
      checkboxItems.forEach(item => {
        const label = item.querySelector('.form-check-label span');
        const text = label ? label.textContent.toLowerCase() : '';
        const matches = text.includes(searchTerm);
        
        item.style.display = matches ? 'flex' : 'none';
      });
    });
    
    // Предотвращаем закрытие dropdown при клике на поиск
    statusSearch.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }
  // Статус Marketplace (dropdown с красивым дизайном)
  const statusMarketplaceItems = document.querySelectorAll('.status-marketplace-item');
  const statusMarketplaceDropdownLabel = getElementById('statusMarketplaceDropdownLabel');
  const statusMarketplaceInput = getElementById('statusMarketplaceInput');
  
  if (statusMarketplaceItems.length > 0 && statusMarketplaceDropdownLabel && statusMarketplaceInput) {
    statusMarketplaceItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        statusMarketplaceItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        statusMarketplaceDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        statusMarketplaceInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('status_marketplace', value); else url.searchParams.delete('status_marketplace');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        window.DashboardSelection && window.DashboardSelection.clearSelection();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(getElementById('statusMarketplaceDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Currency фильтр (dropdown с красивым дизайном)
  const currencyItems = document.querySelectorAll('.currency-item');
  const currencyDropdownLabel = getElementById('currencyDropdownLabel');
  const currencyInput = getElementById('currencyInput');
  
  if (currencyItems.length > 0 && currencyDropdownLabel && currencyInput) {
    currencyItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        currencyItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        currencyDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        currencyInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('currency', value); else url.searchParams.delete('currency');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        window.DashboardSelection && window.DashboardSelection.clearSelection();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(getElementById('currencyDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Geo фильтр (dropdown с красивым дизайном)
  const geoItems = document.querySelectorAll('.geo-item');
  const geoDropdownLabel = getElementById('geoDropdownLabel');
  const geoInput = getElementById('geoInput');
  
  if (geoItems.length > 0 && geoDropdownLabel && geoInput) {
    geoItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        geoItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        geoDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        geoInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('geo', value); else url.searchParams.delete('geo');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        window.DashboardSelection && window.DashboardSelection.clearSelection();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(getElementById('geoDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Status RK фильтр (dropdown с красивым дизайном)
  const statusRkItems = document.querySelectorAll('.status-rk-item');
  const statusRkDropdownLabel = getElementById('statusRkDropdownLabel');
  const statusRkInput = getElementById('statusRkInput');
  
  if (statusRkItems.length > 0 && statusRkDropdownLabel && statusRkInput) {
    statusRkItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        statusRkItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        statusRkDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        statusRkInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('status_rk', value); else url.searchParams.delete('status_rk');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        window.DashboardSelection && window.DashboardSelection.clearSelection();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(getElementById('statusRkDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Пер-страница (селект)
  const perPageSelect = getSel('select[name="per_page"]');
  if (perPageSelect) {
    perPageSelect.addEventListener('change', () => {
      const url = new URL(window.location);
      const v = parseInt(perPageSelect.value || '');
      if (!isNaN(v)) url.searchParams.set('per_page', String(v)); else url.searchParams.delete('per_page');
      url.searchParams.set('page', '1');
      history.replaceState(null, '', url.toString());
      window.DashboardSelection && window.DashboardSelection.clearSelection();
      debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
    });
  }
  // Чекбоксы доп. фильтров
  const boolFilters = ['has_email','has_two_fa','has_token','has_avatar','has_cover','has_password','full_filled'];
  boolFilters.forEach(name => {
    document.querySelectorAll(`input[type="checkbox"][name="${name}"]`).forEach(cb => {
      cb.addEventListener('change', () => {
        const url = new URL(window.location);
        if (cb.checked) url.searchParams.set(name, '1'); else url.searchParams.delete(name);
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        window.DashboardSelection && window.DashboardSelection.clearSelection();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
      });
    });
  });
  // Классическая фильтрация: для числовых диапазонов применяем при вводе (debounce)
  const pharmaFrom = document.getElementsByName('pharma_from')[0];
  const pharmaTo   = document.getElementsByName('pharma_to')[0];
  const applyPharma = debounce(() => {
    const url = new URL(window.location);
    const fromVal = pharmaFrom ? pharmaFrom.value.trim() : '';
    const toVal   = pharmaTo ? pharmaTo.value.trim() : '';
    if (fromVal !== '') url.searchParams.set('pharma_from', fromVal); else url.searchParams.delete('pharma_from');
    if (toVal   !== '') url.searchParams.set('pharma_to', toVal); else url.searchParams.delete('pharma_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (pharmaFrom) pharmaFrom.addEventListener('input', applyPharma);
  if (pharmaTo)   pharmaTo.addEventListener('input', applyPharma);

  const friendsFrom = document.getElementsByName('friends_from')[0];
  const friendsTo   = document.getElementsByName('friends_to')[0];
  const applyFriends = debounce(() => {
    const url = new URL(window.location);
    const fromVal = friendsFrom ? friendsFrom.value.trim() : '';
    const toVal   = friendsTo ? friendsTo.value.trim() : '';
    if (fromVal !== '') url.searchParams.set('friends_from', fromVal); else url.searchParams.delete('friends_from');
    if (toVal   !== '') url.searchParams.set('friends_to', toVal); else url.searchParams.delete('friends_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (friendsFrom) friendsFrom.addEventListener('input', applyFriends);
  if (friendsTo)   friendsTo.addEventListener('input', applyFriends);

  // Автоприменение диапазонов годов (year_created)
  const yearCreatedFromEl = document.getElementsByName('year_created_from')[0];
  const yearCreatedToEl   = document.getElementsByName('year_created_to')[0];
  
  const applyYear = debounce(() => {
    const url = new URL(window.location);
    const ycf = yearCreatedFromEl ? yearCreatedFromEl.value.trim() : '';
    const yct = yearCreatedToEl   ? yearCreatedToEl.value.trim()   : '';
    if (ycf) url.searchParams.set('year_created_from', ycf); else url.searchParams.delete('year_created_from');
    if (yct) url.searchParams.set('year_created_to',   yct); else url.searchParams.delete('year_created_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (yearCreatedFromEl) yearCreatedFromEl.addEventListener('input', applyYear);
  if (yearCreatedToEl)   yearCreatedToEl.addEventListener('input', applyYear);

  // Автоприменение диапазона Limit RK
  const limitRkFromEl = document.getElementsByName('limit_rk_from')[0];
  const limitRkToEl   = document.getElementsByName('limit_rk_to')[0];
  
  const applyLimitRk = debounce(() => {
    const url = new URL(window.location);
    const fromVal = limitRkFromEl ? limitRkFromEl.value.trim() : '';
    const toVal   = limitRkToEl ? limitRkToEl.value.trim() : '';
    if (fromVal !== '') url.searchParams.set('limit_rk_from', fromVal); else url.searchParams.delete('limit_rk_from');
    if (toVal   !== '') url.searchParams.set('limit_rk_to', toVal); else url.searchParams.delete('limit_rk_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    window.DashboardSelection && window.DashboardSelection.clearSelection();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (limitRkFromEl) limitRkFromEl.addEventListener('input', applyLimitRk);
  if (limitRkToEl)   limitRkToEl.addEventListener('input', applyLimitRk);
});

document.addEventListener('click', function(e) {
  const a = e.target && e.target.closest('.pagination a.page-link');
  if (!a) return;
  e.preventDefault();
  let targetPage = '1';
  const href = a.getAttribute('href') || '';
  try {
    const u = new URL(href, window.location.href);
    targetPage = u.searchParams.get('page') || '1';
  } catch (_) { /* fallback */ }
  const cur = new URL(window.location);
  cur.searchParams.set('page', String(targetPage));
  history.replaceState(null, '', cur.toString());
  // НЕ очищаем selectedIds при пагинации - выбранные строки должны сохраняться между страницами
  // selectedAllFiltered сбрасываем, так как это относится к текущему фильтру
  if (window.DashboardSelection) {
    window.DashboardSelection.setSelectedAllFiltered(false);
    window.DashboardSelection.updateSelectedCount();
  }
  // Обновляем без перезагрузки
  refreshDashboardData();
  // Убрано автоскролл вверх по запросу
});

function getActionsWidth() {
  const td = getSel('#accountsTable tbody tr td.sticky-actions');
  if (td) return td.offsetWidth;
  const th = getSel('#accountsTable thead th[data-col="actions"]');
  return th ? th.offsetWidth : 0;
}

/**
 * Функция синхронизации ширины заголовков (обертка над TableLayoutManager)
 * Использует новый менеджер верстки для правильного расчета размеров
 */
// Простая функция синхронизации ширины заголовков
function syncHeaderWidths() {
  if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
    window.tableLayoutManager.refresh();
  }
}

// Оптимизированный обработчик resize с троттлингом
let resizeTimeout2;
const optimizedResizeHandler2 = () => {
  if (resizeTimeout2) return;
  resizeTimeout2 = requestAnimationFrame(() => {
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    syncHeaderWidths();
    // Пересчитываем плотность таблицы при изменении размера окна
    adjustTableDensity();
    resizeTimeout2 = null;
  });
};
window.addEventListener('resize', optimizedResizeHandler2, { passive: true });
window.addEventListener('load', () => { 
  adjustForMobile(); 
  
  // Пересчитываем верстку таблицы при загрузке страницы
  const initTableLayout = () => {
    // Используем новый менеджер верстки, если он доступен
    if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
      window.tableLayoutManager.refresh();
    } else {
      // Fallback на старую функцию
      syncHeaderWidths();
    }
    
    adjustTableDensity();
    
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    
    // Финальная проверка через небольшую задержку
    setTimeout(() => {
      if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
        window.tableLayoutManager.refresh();
      } else {
        syncHeaderWidths();
      }
      if (typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
    }, 200);
  };
  
  // Запускаем инициализацию с небольшой задержкой для гарантии полного рендера
  setTimeout(initTableLayout, 150);
  
  // Дополнительный пересчет верстки после полной загрузки страницы
  // Это особенно важно после сортировки, когда страница перезагружается
  window.addEventListener('load', () => {
    setTimeout(() => {
      if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
        window.tableLayoutManager.refresh();
      } else {
        syncHeaderWidths();
        adjustTableDensity();
      }
    }, 300);
  });
  
  // Обработка сортировки теперь выполняется модулем table-sorting.js
  // Старый обработчик удален
});

// Обработчик редактирования полей через кнопку
document.addEventListener('click', function(e) {
  const editBtn = e.target.closest('.field-edit-btn');
  if (!editBtn) return;
  
  const wrap = editBtn.closest('.editable-field-wrap');
  if (!wrap) return;
  
  const rowId = parseInt(wrap.getAttribute('data-row-id'));
  const field = wrap.getAttribute('data-field');
  const fieldType = wrap.getAttribute('data-field-type'); // Получаем тип поля
  
  // Получаем текущее значение
  const fieldValue = wrap.querySelector('.field-value');
  let oldVal = '';
  
  // Для числовых полей извлекаем значение по-другому
  if (fieldType === 'numeric') {
    // Для числовых полей берем textContent и очищаем от форматирования
    const textContent = fieldValue.textContent.trim();
    if (textContent === '—' || textContent === '') {
      oldVal = '';
    } else {
      // Извлекаем только число, убирая все нечисловые символы (кроме точки и минуса)
      oldVal = textContent.replace(/[^\d.-]/g, '');
    }
  } else {
    // Для текстовых полей используем стандартную логику
    oldVal = fieldValue.textContent.trim();
    
    // Если поле пустое (показывается "—"), используем пустую строку
    if (oldVal === '—') {
      oldVal = '';
    }
  }
  
  // Для полей с data-full (token, cookies и т.д.) берём полное значение
  const fullValue = fieldValue.getAttribute('data-full');
  if (fullValue !== null) {
    oldVal = fullValue;
  }
  
  // Для ссылок берём href
  if (fieldValue.tagName === 'A') {
    if (field === 'email') {
      // Для email убираем mailto:
      oldVal = fieldValue.href.replace('mailto:', '');
    } else if (field === 'social_url') {
      // Для social_url берём полный URL из href (с протоколом!)
      // Убираем только origin если это относительная ссылка
      oldVal = fieldValue.href;
      if (oldVal.startsWith(window.location.origin)) {
        oldVal = oldVal.substring(window.location.origin.length);
      }
      // Если URL не начинается с http/https, берем из textContent без иконки
      if (!oldVal.match(/^https?:\/\//)) {
        const textWithoutIcon = fieldValue.textContent.replace(/^\s*\S+\s*/, '').trim();
        oldVal = textWithoutIcon || fieldValue.textContent.trim();
      }
    } else {
      // Для остальных ссылок берём текст
      oldVal = fieldValue.textContent.trim();
    }
  }
  
  // Определяем, нужен ли textarea для длинных полей
  const longFields = ['token', 'cookies', 'first_cookie', 'user_agent', 'extra_info_1', 'extra_info_2', 'extra_info_3', 'extra_info_4'];
  const isLongField = longFields.includes(field);
  
  // Создаём элемент ввода
  const input = document.createElement(isLongField ? 'textarea' : 'input');
  
  if (!isLongField) {
    // Для числовых полей используем type='number'
    if (fieldType === 'numeric') {
      input.type = 'number';
      input.step = 'any'; // Разрешаем десятичные числа
    } else {
      input.type = 'text';
    }
  } else {
    input.rows = 4;
    input.style.resize = 'vertical';
    input.style.minWidth = '300px';
  }
  
  input.className = 'form-control form-control-sm';
  // Устанавливаем значение после создания input
  input.value = oldVal || '';
  
  // ВАЖНО: Блокируем виртуализацию перед созданием input
  const tableModule = window.tableModule;
  const virtualization = tableModule && tableModule.virtualScroller;
  let virtualizationWasEnabled = false;
  if (virtualization && virtualization.enabled) {
    virtualizationWasEnabled = true;
    virtualization.disable(true); // Временно отключаем виртуализацию
  }
  
  // Создаем кнопки сохранения и отмены
  const saveBtn = document.createElement('button');
  saveBtn.className = 'btn btn-sm btn-success ms-1';
  saveBtn.innerHTML = '<i class="fas fa-check"></i>';
  saveBtn.title = 'Сохранить';
  
  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
  cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
  cancelBtn.title = 'Отмена';
  
  // Сохраняем оригинальное содержимое И оригинальное значение ДО замены
  const originalContent = wrap.innerHTML;
  const originalValue = oldVal; // Сохраняем значение отдельно для восстановления при ошибках
  
  // Добавляем флаг редактирования для защиты от виртуализации
  wrap.setAttribute('data-editing', 'true');
  const row = wrap.closest('tr[data-id]');
  if (row) {
    row.setAttribute('data-editing', 'true');
  }
  // Также устанавливаем флаг на ячейку td для CSS стилей
  const cell = wrap.closest('td');
  if (cell) {
    cell.setAttribute('data-editing', 'true');
  }
  
  // Заменяем содержимое на поля редактирования
  wrap.innerHTML = '';
  wrap.appendChild(input);
  wrap.appendChild(saveBtn);
  wrap.appendChild(cancelBtn);
  
  // Убеждаемся, что input видим и имеет правильные стили
  input.style.display = 'block';
  input.style.visibility = 'visible';
  input.style.opacity = '1';
  input.style.width = 'auto';
  input.style.minWidth = '120px';
  input.style.flex = '1';
  
  // Устанавливаем фокус и выделяем текст
  // Используем setTimeout для гарантии, что DOM обновился
  setTimeout(() => {
    input.focus();
    // Для числовых полей выделяем весь текст, если он есть
    if (oldVal && oldVal !== '') {
      input.select();
    } else {
      // Если значение пустое, просто устанавливаем курсор
      if (input.setSelectionRange) {
        input.setSelectionRange(0, 0);
      }
    }
  }, 0);
  
  // Блокируем скролл во время редактирования для защиты от проблем с виртуализацией
  const scrollContainer = getElementById('tableWrap');
  let scrollBlocked = false;
  let savedScrollTop = 0;
  
  if (scrollContainer) {
    scrollBlocked = true;
    savedScrollTop = scrollContainer.scrollTop;
    scrollContainer.style.overflow = 'hidden';
  }
  
  // Функция разблокировки скролла и виртуализации
  const unlockScroll = () => {
    if (scrollBlocked && scrollContainer) {
      scrollContainer.style.overflow = '';
      scrollContainer.scrollTop = savedScrollTop; // Восстанавливаем позицию
      scrollBlocked = false;
    }
    // Удаляем флаг редактирования
    wrap.removeAttribute('data-editing');
    if (row) {
      row.removeAttribute('data-editing');
    }
    // Также удаляем флаг с ячейки td
    const cell = wrap.closest('td');
    if (cell) {
      cell.removeAttribute('data-editing');
    }
    // Восстанавливаем виртуализацию после завершения редактирования
    if (virtualizationWasEnabled && virtualization && tableModule) {
      setTimeout(() => {
        // Проверяем, что редактирование действительно завершено
        const stillEditing = tableModule.tbody && tableModule.tbody.querySelector('tr[data-id][data-editing="true"]');
        if (!stillEditing && tableModule.tbody) {
          const rows = Array.from(tableModule.tbody.querySelectorAll('tr[data-id]'));
          if (rows.length > (virtualization.options.threshold || 80)) {
            virtualization.enable(rows);
          }
        }
      }, 100);
    }
  };
  
  // Функция восстановления оригинального состояния
  const restoreOriginal = () => {
    unlockScroll();
    wrap.innerHTML = originalContent;
    // Восстанавливаем значение в DOM, если оно изменилось
    const restoredFieldValue = wrap.querySelector('.field-value');
    if (restoredFieldValue && originalValue !== oldVal) {
      // Если значение было изменено, но нужно восстановить старое
      if (originalValue === '') {
        restoredFieldValue.textContent = '—';
        restoredFieldValue.classList.add('text-muted');
      } else {
        restoredFieldValue.textContent = originalValue;
      }
    }
  };
  
  // Обработчик сохранения
  const save = async () => {
    let newVal = isLongField ? input.value : input.value.trim();
    
    // Валидация типа на фронтенде
    const fieldType = wrap.getAttribute('data-field-type');
    if (fieldType === 'numeric') {
      // Для числовых полей проверяем, что значение является числом
      if (newVal !== '' && newVal !== null) {
        const trimmed = newVal.trim();
        if (trimmed === '') {
          newVal = ''; // Пустое значение разрешено (будет обработано на бэкенде)
        } else if (isNaN(trimmed) || trimmed === '') {
          showToast('Поле должно содержать число', 'error');
          input.focus();
          input.select();
          return; // Прерываем сохранение
        }
        // Можно также убрать пробелы и лишние символы
        newVal = trimmed;
      }
    }
    
    try {
      const res = await fetch('update_field.php', {
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ id: rowId, field: field, value: newVal, csrf: '<?= e($csrfToken) ?>' })
      });
      
      // Пытаемся прочитать JSON из ответа (даже если статус не OK)
      let json;
      try {
        const text = await res.text();
        json = text ? JSON.parse(text) : { success: false, error: 'Empty response' };
      } catch (parseErr) {
        // Если не удалось распарсить JSON, создаем объект с ошибкой
        json = { success: false, error: `HTTP error! status: ${res.status}` };
      }
      
      // Проверяем статус ответа
      if (!res.ok) {
        throw new Error(json.error || `HTTP error! status: ${res.status}`);
      }
      
      if (!json.success) {
        throw new Error(json.error || 'update failed');
      }
      
      // Восстанавливаем оригинальную структуру и обновляем значение
      wrap.innerHTML = originalContent;
      const updatedFieldValue = wrap.querySelector('.field-value');
      
      if (newVal === '' || newVal === null) {
        updatedFieldValue.textContent = '—';
        updatedFieldValue.classList.add('text-muted');
      } else if (field === 'email') {
        updatedFieldValue.href = 'mailto:' + newVal;
        updatedFieldValue.textContent = newVal;
      } else if (field === 'social_url') {
        // Для social_url всегда пересоздаем структуру
        if (/^https?:\/\//i.test(newVal)) {
          // Если есть протокол - создаем ссылку
          updatedFieldValue.href = newVal;
          updatedFieldValue.target = '_blank';
          updatedFieldValue.rel = 'noopener';
          updatedFieldValue.className = 'text-decoration-none field-value';
          updatedFieldValue.innerHTML = `<i class="fas fa-external-link-alt me-2"></i>${newVal}`;
        } else if (newVal !== '' && newVal !== null) {
          // Если нет протокола но есть значение - добавляем http://
          const urlWithProtocol = 'http://' + newVal;
          updatedFieldValue.href = urlWithProtocol;
          updatedFieldValue.target = '_blank';
          updatedFieldValue.rel = 'noopener';
          updatedFieldValue.className = 'text-decoration-none field-value';
          updatedFieldValue.innerHTML = `<i class="fas fa-external-link-alt me-2"></i>${urlWithProtocol}`;
        } else {
          // Если пустое - показываем прочерк
          updatedFieldValue.textContent = '—';
          updatedFieldValue.classList.add('text-muted');
        }
      } else if (isLongField) {
        const clip = newVal.substring(0, 100) + (newVal.length > 100 ? '…' : '');
        updatedFieldValue.setAttribute('data-full', newVal);
        updatedFieldValue.textContent = clip;
      } else if (field === 'status') {
        updatedFieldValue.textContent = newVal;
        // Обновляем класс badge
        let statusClass = 'badge-default';
        let statusDisplay = newVal;
        const statusValue = String(newVal).toLowerCase();
        
        // Специальная обработка для пустых статусов
        if (newVal === null || newVal === '' || newVal === undefined) {
          statusClass = 'badge-empty-status';
          statusDisplay = 'Пустой статус';
        } else if (statusValue.includes('new')) {
          statusClass = 'badge-new';
        } else if (statusValue.includes('add_selphi_true')) {
          statusClass = 'badge-add_selphi_true';
        } else if (statusValue.includes('error')) {
          statusClass = 'badge-error_login';
        }
        
        updatedFieldValue.className = 'badge ' + statusClass + ' field-value';
        updatedFieldValue.textContent = statusDisplay;
      } else {
        updatedFieldValue.textContent = newVal;
      }
      
      unlockScroll(); // Разблокируем скролл при успешном сохранении
      showToast('Поле успешно обновлено', 'success');
    } catch (err) {
      // Восстанавливаем оригинальное состояние при любой ошибке (сеть, сервер, парсинг)
      restoreOriginal();
      
      // Показываем понятное сообщение об ошибке
      let errorMessage = 'Ошибка сохранения';
      if (err instanceof TypeError && err.message.includes('fetch')) {
        errorMessage = 'Ошибка сети. Проверьте подключение к интернету.';
      } else if (err.message) {
        errorMessage = 'Ошибка сохранения: ' + err.message;
      }
      
      showToast(errorMessage, 'error');
      logger.error('Field update error:', err);
    }
  };
  
  // Обработчик отмены
  const cancel = () => {
    unlockScroll();
    wrap.innerHTML = originalContent;
  };
  
  saveBtn.addEventListener('click', save);
  cancelBtn.addEventListener('click', cancel);
  
  // Сохранение по Enter / Ctrl+Enter
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      if (isLongField) {
        if (ev.ctrlKey) {
          ev.preventDefault();
          save();
        }
      } else {
        if (!ev.shiftKey) {
          ev.preventDefault();
          save();
        }
      }
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      cancel();
    }
  });
});

// ===== Обработка чекбоксов перенесена в dashboard-selection.js =====
// selectAll и row-checkbox обрабатываются в dashboard-selection.js через initSelectionModule()

// Обработка клика по строке таблицы (для выбора строки кликом в любом месте)
document.addEventListener('click', function(e) {
  // Находим строку таблицы
  const row = e.target.closest('tr[data-id]');
  if (!row) return;
  
  // Исключаем клики по самому чекбоксу (его обрабатывает событие change отдельно)
  if (e.target.classList && e.target.classList.contains('row-checkbox')) {
    return;
  }
  
  // Исключаем клики по интерактивным элементам и их дочерним элементам:
  // - ссылки (a)
  // - кнопки (button, .btn)
  // - кнопки редактирования (.field-edit-btn)
  // - кнопки копирования (.copy-btn)
  // - элементы внутри pw-mask (для паролей)
  // - все input, select, textarea
  // Проверяем как сам элемент, так и его родителей
  const interactiveSelectors = 'a, button, .row-checkbox, .field-edit-btn, .copy-btn, .btn, .pw-mask, input, select, textarea, .pw-toggle, .pw-edit';
  
  // Проверяем, не является ли сам кликнутый элемент интерактивным
  const isDirectlyInteractive = e.target.matches && e.target.matches(interactiveSelectors);
  
  // Проверяем, не находится ли кликнутый элемент внутри интерактивного элемента
  const isInsideInteractive = e.target.closest(interactiveSelectors);
  
  // Также проверяем иконки и SVG, но только если они внутри кнопок или ссылок
  const isIconInButton = (e.target.tagName === 'I' || e.target.tagName === 'SVG' || e.target.closest('i, svg')) && 
                         e.target.closest('button, a, .btn');
  
  if (isDirectlyInteractive || isInsideInteractive || isIconInButton) {
    // Если клик был по интерактивному элементу, не переключаем чекбокс
    return;
  }
  
  // Находим чекбокс в этой строке
  const checkbox = row.querySelector('.row-checkbox');
  if (!checkbox) return;
  
  // Предотвращаем двойное срабатывание - проверяем, не был ли это клик по чекбоксу
  if (e.target === checkbox || checkbox.contains(e.target)) {
    return;
  }
  
  // Переключаем состояние чекбокса
  const wasChecked = checkbox.checked;
  checkbox.checked = !wasChecked;
  
  // Обновляем состояние напрямую, без dispatchEvent, чтобы избежать двойного срабатывания
  if (window.DashboardSelection) {
    window.DashboardSelection.setSelectedAllFiltered(false);
    window.DashboardSelection.toggleRowSelection(parseInt(checkbox.value), checkbox.checked);
    window.DashboardSelection.updateRowSelectedClass(row, checkbox.checked);
  }
  
  if (window.DashboardSelection) {
    window.DashboardSelection.updateSelectedCount();
  }
  
  const selectAllCheckbox = getElementById('selectAll');
  if (selectAllCheckbox) {
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    const checkedCheckboxes = document.querySelectorAll('.row-checkbox:checked');
    selectAllCheckbox.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkedCheckboxes.length;
  }
});

// Bulk edit: open modal
const bulkFieldSelect = getElementById('bulkFieldSelect');
const bulkGlobalWarning = getElementById('bulkGlobalWarning');
const bulkGlobalFieldLabel = bulkGlobalWarning ? bulkGlobalWarning.querySelector('.bulk-global-field') : null;
const bulkGlobalCountLabel = bulkGlobalWarning ? bulkGlobalWarning.querySelector('.bulk-global-count') : null;
const bulkGlobalConfirm = getElementById('bulkGlobalConfirm');
const bulkFieldModalEl = getElementById('bulkFieldModal');
const bulkEditBtn = getElementById('bulkEditFieldBtn');
const applyBulkFieldBtn = getElementById('applyBulkFieldBtn');

function shouldWarnGlobalBulk() {
  const DS = window.DashboardSelection;
  return DS && DS.getSelectedAllFiltered() && ACTIVE_FILTERS_COUNT === 0;
}

function updateBulkWarningState() {
  if (!bulkGlobalWarning) return;
  const needWarning = shouldWarnGlobalBulk();
  if (!needWarning) {
    bulkGlobalWarning.style.display = 'none';
    if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
    if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = false;
    return;
  }
  bulkGlobalWarning.style.display = '';
  if (bulkGlobalFieldLabel && bulkFieldSelect) {
    const optionText = bulkFieldSelect.options[bulkFieldSelect.selectedIndex]?.textContent?.trim() || 'поле';
    bulkGlobalFieldLabel.textContent = optionText;
  }
  if (bulkGlobalCountLabel && window.DashboardSelection) {
    bulkGlobalCountLabel.textContent = window.DashboardSelection.getFilteredTotalLive().toLocaleString('ru-RU');
  }
  if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
  if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = true;
}

if (bulkEditBtn && bulkFieldModalEl) {
  bulkEditBtn.addEventListener('click', function() {
    const DS = window.DashboardSelection;
    if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
    const modal = bootstrap.Modal.getOrCreateInstance(bulkFieldModalEl);
    // Сбрасываем введённое значение перед открытием
    const input = getElementById('bulkFieldValue');
    if (input) input.value = '';
    updateBulkWarningState();
    modal.show();
  });
}

if (bulkGlobalConfirm) {
  bulkGlobalConfirm.addEventListener('change', () => {
    if (!applyBulkFieldBtn) return;
    if (!shouldWarnGlobalBulk()) {
      applyBulkFieldBtn.disabled = false;
      return;
    }
    applyBulkFieldBtn.disabled = !bulkGlobalConfirm.checked;
  });
}

if (bulkFieldModalEl) {
  bulkFieldModalEl.addEventListener('hidden.bs.modal', () => {
    if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
    if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = false;
  });
}

if (bulkFieldSelect) {
  bulkFieldSelect.addEventListener('change', () => {
    if (shouldWarnGlobalBulk()) {
      updateBulkWarningState();
    }
  });
}

// Кнопка "Сбросить выбор" - очищает выбранные строки
const clearAllSelectedBtn = getElementById('clearAllSelectedBtn');
if (clearAllSelectedBtn) {
  clearAllSelectedBtn.addEventListener('click', function() {
    const DS = window.DashboardSelection;
    if (DS) {
      DS.clearSelection();
      DS.initCheckboxStates(); // Синхронизируем все чекбоксы включая selectAll
      
      const exportBtns = document.querySelectorAll('#exportSelectedCsv, #exportSelectedTxt, #deleteSelected, #changeStatusSelected, #bulkEditFieldBtn');
      exportBtns.forEach(btn => btn.disabled = true);
    }
  });
}

// ===== Массовый перенос аккаунтов (V3.0) =====
// Логика массового переноса аккаунтов вынесена в модуль `dashboard-modals.js` (initTransferModal).
const applyTransferBtn = getElementById('applyTransferBtn');
if (false && applyTransferBtn) {
  applyTransferBtn.addEventListener('click', async function() {
    // Получаем значения из формы
    const text = (getElementById('transferText')?.value || '').trim();
    const statusSelect = (getElementById('transferStatusSelect')?.value || '').trim();
    const statusCustom = (getElementById('transferStatusCustom')?.value || '').trim();
    const status = statusCustom || statusSelect;
    const enableLike = getElementById('transferEnableLike')?.checked ?? false;
    
    // Валидация полей
    if (!text) { 
      showToast('Вставьте текст с ID аккаунтов', 'error'); 
      return; 
    }
    
    if (!status) { 
      showToast('Укажите новый статус', 'error'); 
      return; 
    }
    
    // Проверка размера перед отправкой
    const lines = text.split('\n').filter(l => l.trim() !== '');
    const sizeInBytes = new Blob([text]).size;
    const maxSize = 20 * 1024 * 1024; // 20MB
    const maxLines = 50000;
    const recommendedLines = 2000;
    
    if (sizeInBytes > maxSize) {
      showToast(`⚠️ Слишком большой текст (${(sizeInBytes/1024/1024).toFixed(1)}MB). Максимум 20MB`, 'error');
      return;
    }
    
    if (lines.length > maxLines) {
      showToast(`⚠️ Слишком много строк (${lines.length.toLocaleString()}). Максимум ${maxLines.toLocaleString()}`, 'error');
      return;
    }
    
    // Предупреждение для больших объёмов
    if (lines.length > recommendedLines) {
      const confirmMsg = `⚠️ Вы вставили ${lines.length.toLocaleString()} строк.\n\n` +
        `Рекомендуется обрабатывать не более ${recommendedLines.toLocaleString()} строк за раз.\n` +
        `При большом объёме обработка может занять 30-60 секунд.\n\n` +
        `Продолжить?`;
      
      if (!confirm(confirmMsg)) {
        return;
      }
    }
    
    try {
      // Показываем информативный индикатор загрузки
      if (typeof showPageLoader === 'function') {
        showPageLoader();
      }
      
      // Добавляем информационное сообщение для больших объемов
      const loadingInfoEl = document.createElement('div');
      loadingInfoEl.id = 'massTransferLoadingInfo';
      loadingInfoEl.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10001;background:#fff;padding:30px;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);text-align:center;min-width:350px;';
      loadingInfoEl.innerHTML = `
        <div style="font-size:48px;margin-bottom:15px;">⏳</div>
        <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:10px;">Обработка массового переноса</div>
        <div style="font-size:14px;color:#666;margin-bottom:15px;">Обрабатывается ${lines.length.toLocaleString()} строк...</div>
        <div style="font-size:12px;color:#999;">Пожалуйста, подождите. Это может занять некоторое время.</div>
        <div id="transferTimer" style="font-size:13px;color:#0d6efd;margin-top:15px;font-weight:500;">Прошло: 0 сек</div>
      `;
      document.body.appendChild(loadingInfoEl);
      
      // Запускаем таймер для отображения времени
      const startTime = Date.now();
      const timerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        const timerEl = getElementById('transferTimer');
        if (timerEl) {
          timerEl.textContent = `Прошло: ${elapsed} сек`;
        }
      }, 1000);
      
      // Формируем тело запроса
      const body = { 
        text, 
        status, 
        csrf: '<?= e($csrfToken) ?>',
        options: {
          enable_exact: true,
          enable_numeric: true,
          enable_like: enableLike
        }
      };
      
      // Отправляем запрос на новый API endpoint с увеличенным таймаутом
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 минут таймаут
      
      const res = await fetch('mass_transfer.php', { 
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }, 
        body: JSON.stringify(body),
        signal: controller.signal
      });
      
      clearTimeout(timeoutId);
      clearInterval(timerInterval);
      
      logger.debug('📥 MASS TRANSFER: Ответ получен', {
        status: res.status,
        statusText: res.statusText,
        ok: res.ok,
        contentType: res.headers.get('content-type')
      });
      
      if (!res.ok) {
        // Пытаемся прочитать детали ошибки из JSON ответа
        let errorMessage = `HTTP ${res.status}: ${res.statusText}`;
        const contentType = res.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
          try {
            const errorData = await res.json();
            logger.error('❌ MASS TRANSFER: Ошибка (JSON):', errorData);
            errorMessage = errorData.error || errorMessage;
          } catch (e) {
            logger.error('❌ MASS TRANSFER: Ошибка парсинга JSON ошибки:', e);
          }
        } else {
          const errorText = await res.text().catch(() => '');
          logger.error('❌ MASS TRANSFER: Ошибка (текст):', errorText.substring(0, 500));
          errorMessage = errorText || errorMessage;
        }
        throw new Error(errorMessage);
      }
      
      const contentType = res.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        const textResponse = await res.text().catch(() => '');
        logger.error('❌ MASS TRANSFER: Ответ не JSON:', textResponse.substring(0, 500));
        throw new Error('Сервер вернул некорректный ответ. Ожидается JSON.');
      }
      
      const json = await res.json();
      logger.debug('✅ MASS TRANSFER: JSON получен', json);
      
      if (!json.success) {
        logger.error('❌ MASS TRANSFER: Импорт не успешен', json);
        throw new Error(json.error || 'Неизвестная ошибка');
      }
      
      // Выводим детальную статистику в консоль
      logger.debug('Обновлено записей:', json.affected);
      logger.debug('Статистика:');
      logger.table({
        'Распознано токенов (ID аккаунтов)': json.statistics?.parsed_tokens || 0,
        'Распознано числовых ID': json.statistics?.parsed_numeric || 0,
        'Всего строк обработано': json.statistics?.total_lines || 0,
        'Нераспознанных строк': json.statistics?.unparsed_lines || 0,
        'Найдено по id_soc_account (точно)': json.statistics?.matched_exact_id_soc || 0,
        'Найдено по social_url (LIKE)': json.statistics?.matched_like_url || 0,
        'Найдено по cookies (LIKE)': json.statistics?.matched_like_cookies || 0,
        'Всего найдено': json.statistics?.total_found || 0
      });
      logger.debug('Новый статус:', json.status);
      logger.groupEnd();
      
      // Показываем успешное уведомление
      const stats = json.statistics || {};
      const message = `✅ Успешно обновлено: ${json.affected} записей\n` +
        `📊 Найдено: ${stats.total_found || 0} из ${(stats.parsed_tokens || 0) + (stats.parsed_numeric || 0)} распознанных ID`;
      
      showToast(message, 'success');
      
      // Очищаем форму
      getElementById('transferText').value = '';
      getElementById('transferStatusSelect').value = '';
      getElementById('transferStatusCustom').value = '';
      getElementById('transferEnableLike').checked = false;
      
      // Закрываем модальное окно
      const modalEl = getElementById('transferAccountsModal');
      if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
      }
      
      // Перезагружаем страницу для обновления данных
      setTimeout(() => window.location.reload(), 1500);
      
    } catch (e) {
      logger.error('❌ Ошибка массового переноса:', e);
      
      // Проверяем, не был ли это таймаут
      if (e.name === 'AbortError') {
        showToast('⏱️ Превышено время ожидания. Попробуйте разбить данные на меньшие части (по 1000 строк).', 'error');
      } else {
        showToast('Ошибка массового переноса: ' + e.message, 'error');
      }
    } finally {
      // Скрываем индикатор загрузки
      if (typeof hidePageLoader === 'function') hidePageLoader();
      
      // Удаляем информационное окно
      const loadingInfo = getElementById('massTransferLoadingInfo');
      if (loadingInfo) loadingInfo.remove();
      
      // Очищаем таймер если он ещё работает
      if (typeof timerInterval !== 'undefined') clearInterval(timerInterval);
      if (typeof timeoutId !== 'undefined') clearTimeout(timeoutId);
    }
  });
}

// Логика применения массового редактирования полей вынесена в модуль `dashboard-modals.js` (initBulkEditModal).

(function(){
  document.addEventListener('DOMContentLoaded', function(){
    // Отключено для повышения плавности (убираем перерисовки на mousemove)
  });
  })();

window.addEventListener('load', () => {
  if (window.DashboardSelection && typeof window.DashboardSelection.updateSelectedOnPageCounter === 'function') {
    window.DashboardSelection.updateSelectedOnPageCounter();
  }
  
  // Скрываем прелоадер после загрузки страницы
  // Не удаляем элемент, а просто скрываем его
  const pageLoader = getElementById('pageLoader');
  if (pageLoader) {
    // Скрываем прелоадер немедленно, не ждем асинхронных операций
    pageLoader.classList.add('hidden');
    // НЕ удаляем элемент - он может понадобиться для обновлений таблицы
  }
});

// ===== Прилипающий горизонтальный скроллбар (новая реализация) =====
// Код перемещен в assets/js/sticky-scrollbar.js
</script>

</script>

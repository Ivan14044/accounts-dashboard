// Тёмная тема отключена

// Флаг для внешних скриптов, чтобы не дублировать обработчики
window.__INLINE_DASHBOARD_ACTIVE__ = true;

/**
 * Добавляет параметр table= к URL, если выбрана не дефолтная таблица.
 * Используется во всех fetch-запросах к бэкенду, чтобы передать контекст таблицы.
 * @param {string} url - базовый URL (может содержать query string)
 * @returns {string} URL с параметром table (если нужно)
 */
window.getTableAwareUrl = function(url) {
  var table = (window.DashboardConfig && window.DashboardConfig.currentTable) ||
              (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.currentTable) || '';
  if (!table || table === 'accounts') return url;
  var sep = url.indexOf('?') === -1 ? '?' : '&';
  return url + sep + 'table=' + encodeURIComponent(table);
};

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

    // Строим DOM через createElement/textContent — НЕ через innerHTML со строкой,
    // чтобы не было XSS через message (например, когда приходит из data.error).
    const wrap = document.createElement('div');
    wrap.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';

    const icon = document.createElement('i');
    icon.className = 'fas me-2 fa-' + (type === 'success' ? 'check' : (type === 'error' ? 'exclamation-triangle' : 'info-circle'));
    body.appendChild(icon);
    body.appendChild(document.createTextNode(' ' + String(message == null ? '' : message)));

    const closeBtnEl = document.createElement('button');
    closeBtnEl.type = 'button';
    closeBtnEl.className = 'toast-close';
    closeBtnEl.setAttribute('aria-label', 'Закрыть');
    closeBtnEl.setAttribute('title', 'Закрыть');
    const closeIcon = document.createElement('i');
    closeIcon.className = 'fas fa-times';
    closeBtnEl.appendChild(closeIcon);

    wrap.appendChild(body);
    wrap.appendChild(closeBtnEl);
    toast.appendChild(wrap);
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
// Ключи localStorage переехали в modules/constants.js (2026-08-09): const не
// виден из других файлов, и каждый вынос модуля тянул за собой копию строки.
// Здесь они доступны как window.LS_KEY_* — обращения по имени ниже работают.

// ===== Управление чекбоксами =====
// Состояние selectedIds / selectedAllFiltered / filteredTotalLive теперь
// хранится и управляется в модуле `dashboard-selection.js`
const ACTIVE_FILTERS_COUNT = window.DashboardConfig.activeFiltersCount;

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
        
        // Синхронизируем _hiddenCardsToHide (если observer ещё активен)
        if (window._hiddenCardsToHide) {
          window._hiddenCardsToHide = new Set(cardsToHide);
        }

        // Применяем скрытие к карточкам
        if (cardsToHide.length > 0) {
          cardsToHide.forEach(cardId => {
            const card = getSel(`.stat-card[data-card="${cardId}"]`);
            if (card) {
              card.classList.add('hidden');
              card.setAttribute('hidden', '');
              card.style.setProperty('display', 'none', 'important');
              card.style.setProperty('visibility', 'hidden', 'important');
              card.style.setProperty('opacity', '0', 'important');
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
          card.setAttribute('hidden', '');
          card.style.setProperty('display', 'none', 'important');
          card.style.setProperty('visibility', 'hidden', 'important');
          card.style.setProperty('opacity', '0', 'important');
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
    const allHiddenCards = document.querySelectorAll('.stat-card.hidden');
    const hiddenCards = Array.from(allHiddenCards)
      .map(card => card.getAttribute('data-card'))
      .filter(id => id !== null && id !== '');

    // Синхронизируем _hiddenCardsToHide (если observer ещё активен при ранней загрузке)
    if (window._hiddenCardsToHide) {
      window._hiddenCardsToHide = new Set(hiddenCards);
    }

    try {
      localStorage.setItem(LS_KEY_HIDDEN_CARDS, JSON.stringify(hiddenCards));
    } catch (_) {
      logger.error('Ошибка сохранения в localStorage');
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
// Переехали в modules/columns-cards-settings.js (2026-08-09): loadSettings,
// saveSettings, toggleColumnVisibility, applySavedColumnVisibility,
// toggleCardVisibility. Модуль кладёт их в window, вызовы ниже по имени работают.

// ===== Обработчики событий =====
// Единый делегированный обработчик кликов (FPS: один listener вместо многих)
function handleDocumentClick(e) {
  var t = e.target;
  var hideBtn = t.closest && t.closest('.stat-card-hide-btn');
  if (hideBtn) {
    e.preventDefault();
    e.stopPropagation();
    var cardId = hideBtn.getAttribute('data-card');
    if (cardId) hideCard(cardId).catch(function(err) { logger.error('Error hiding card:', err); });
    return;
  }
  var card = t.closest && t.closest('.stat-card[data-card-type="custom"]');
  if (card && !t.closest('.stat-card-hide-btn')) {
    document.querySelectorAll('.stat-card[data-card-type="custom"]').forEach(function(c) { c.classList.remove('active'); });
    card.classList.add('active');
    card.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.4) 0%, rgba(59, 130, 246, 0.6) 100%)';
    card.style.border = '2px solid var(--card-color, #3b82f6)';
    card.style.boxShadow = '0 0 0 3px var(--card-color, #3b82f6), 0 14px 24px rgba(59, 130, 246, 0.4)';
    card.style.opacity = '1';
    if (typeof logger !== 'undefined') logger.debug('Card clicked, active class added:', card);
    handleCardSwipe(card);
    return;
  }
  var pwToggle = t.closest && t.closest('.pw-toggle');
  if (pwToggle) {
    var wrap = pwToggle.closest('.pw-mask');
    var dots = wrap.querySelector('.pw-dots');
    var text = wrap.querySelector('.pw-text');
    var icon = pwToggle.querySelector('i');
    if (text.classList.contains('d-none')) {
      text.classList.remove('d-none');
      dots.classList.add('d-none');
      icon.className = 'fas fa-eye-slash';
      pwToggle.title = 'Скрыть пароль';
    } else {
      text.classList.add('d-none');
      dots.classList.remove('d-none');
      icon.className = 'fas fa-eye';
      pwToggle.title = 'Показать пароль';
    }
    return;
  }
  var pwEditBtn = t.closest && t.closest('.pw-edit');
  if (pwEditBtn) {
    var pwWrap = pwEditBtn.closest('.pw-mask');
    var rowId = parseInt(pwWrap.getAttribute('data-row-id'), 10);
    var field = pwWrap.getAttribute('data-field');
    var pwText = pwWrap.querySelector('.pw-text');
    var currentPassword = pwText.textContent.trim();
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control form-control-sm';
    input.value = currentPassword;
    input.style.width = '150px';
    input.style.display = 'inline-block';
    var saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-sm btn-success ms-1';
    saveBtn.innerHTML = '<i class="fas fa-check"></i>';
    saveBtn.title = 'Сохранить';
    var cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
    cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
    cancelBtn.title = 'Отмена';
    // См. inline-edit.js: панель действий сейчас внутри pwWrap, и без отцепления
    // её копия запечётся в ячейку при восстановлении из снимка.
    if (window.CellActions) window.CellActions.detach(pwWrap);
    var originalContent = pwWrap.innerHTML;
    pwWrap.innerHTML = '';
    pwWrap.appendChild(input);
    pwWrap.appendChild(saveBtn);
    pwWrap.appendChild(cancelBtn);
    input.focus();
    input.select();
    var save = async function() {
      var newPassword = input.value.trim();
      try {
        var response = await fetch(window.getTableAwareUrl('update_field.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ id: rowId, field: field, value: newPassword, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
        });
        var data = await response.json();
        if (data.success) {
          pwWrap.innerHTML = originalContent;
          var updatedPwText = pwWrap.querySelector('.pw-text');
          var updatedPwDots = pwWrap.querySelector('.pw-dots');
          updatedPwText.textContent = newPassword;
          if (newPassword === '') updatedPwDots.innerHTML = '<span class="text-muted">(не задан)</span>';
          else updatedPwDots.textContent = '••••••••';
          showToast('Пароль успешно обновлен', 'success');
        } else {
          showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
          pwWrap.innerHTML = originalContent;
        }
      } catch (error) {
        logger.error('Error:', error);
        showToast('Ошибка при сохранении пароля', 'error');
        pwWrap.innerHTML = originalContent;
      }
    };
    var cancel = function() { pwWrap.innerHTML = originalContent; };
    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', cancel);
    input.addEventListener('keydown', function(ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); save(); } else if (ev.key === 'Escape') cancel();
    });
    return;
  }
  // Popup полного значения управляется единственным handler'ом в dashboard.js
  // (метод showFullDataModal класса DashboardManager). Дубликат удалён — раньше
  // и dashboard-init.js, и dashboard.js оба ловили клик на [data-full] и
  // открывали ДВЕ модалки одновременно (Bootstrap cellModal + кастомная
  // fullDataModal). Backdrop Bootstrap'а застревал после закрытия и блокировал
  // все клики на странице.
  var copyBtn = t.closest && t.closest('.copy-btn');
  if (copyBtn) {
    var textToCopy = copyBtn.getAttribute('data-copy-text');
    if (!textToCopy) {
      var pwMask = copyBtn.closest('.pw-mask');
      if (pwMask) {
        var pt = pwMask.querySelector('.pw-text');
        if (pt) textToCopy = pt.textContent || pt.innerText || '';
      }
      if (!textToCopy) {
        var fieldWrap = copyBtn.closest('.editable-field-wrap');
        if (fieldWrap) {
          var fieldValue = fieldWrap.querySelector('.field-value');
          if (fieldValue) {
            textToCopy = fieldValue.textContent || fieldValue.innerText || '';
            if (fieldValue.tagName === 'A' && fieldValue.href) textToCopy = fieldValue.href.replace('mailto:', '');
          }
        }
      }
      if (!textToCopy) {
        var truncateSpan = copyBtn.previousElementSibling;
        if (truncateSpan && truncateSpan.hasAttribute('data-full')) textToCopy = truncateSpan.getAttribute('data-full') || '';
      }
      if (!textToCopy) {
        var parent = copyBtn.parentElement;
        if (parent) {
          var textElement = parent.querySelector('span, a, pre');
          if (textElement) {
            textToCopy = textElement.textContent || textElement.innerText || '';
            if (textElement.tagName === 'A' && textElement.href) textToCopy = textElement.href.replace(/^mailto:/, '');
          }
        }
      }
    }
    if (textToCopy) copyToClipboard(textToCopy);
    else if (typeof logger !== 'undefined') logger.warn('Не удалось найти текст для копирования', copyBtn);
    return;
  }
  // Пагинация обрабатывается в pagination.js
  var removeBtn = (t instanceof HTMLElement) ? t.closest('[data-remove-custom-card]') : null;
  if (removeBtn) {
    (async function() {
      var key = removeBtn.getAttribute('data-remove-custom-card');
      var cards = (await loadCustomCardsFromStorage()).filter(function(x) { return x.key !== key; });
      await saveCustomCardsToStorage(cards);
      await renderCustomCardsSettings();
      await renderCustomCardsOnDashboard();
      showToast('Кастомная карточка удалена', 'success');
    })();
    return;
  }
  var registerBtn = (t instanceof HTMLElement) ? t.closest('[data-register-status]') : null;
  if (registerBtn) {
    var status = registerBtn.getAttribute('data-register-status');
    if (status) {
      registerBtn.disabled = true;
      var originalHtml = registerBtn.innerHTML;
      registerBtn.innerHTML = '<span class="loader loader-sm loader-white" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;margin-right:8px;"></span> Регистрация...';
      fetch('/api/status/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: status, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
      }).then(function(res) {
        if (!res.ok) return res.json().then(function(d) { throw new Error(d.error || 'Ошибка регистрации статуса'); });
        return res.json();
      }).then(function(data) {
        if (data.success) showToast('Статус "' + status + '" успешно зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.', 'success', 5000);
        else throw new Error('Не удалось зарегистрировать статус');
      }).catch(function(err) {
        logger.error('Error registering status:', err);
        showToast('Ошибка регистрации статуса: ' + err.message, 'error');
        registerBtn.disabled = false;
        registerBtn.innerHTML = originalHtml;
      });
    }
    return;
  }
  if (t.id === 'selectAllFilteredLink') {
    e.preventDefault();
    if (window.DashboardSelection) {
      window.DashboardSelection.setSelectedAllFiltered(true);
      var selectedIds = window.DashboardSelection.getSelectedIds();
      if (selectedIds && typeof selectedIds.clear === 'function') selectedIds.clear();
      var selectAllCheckbox = getElementById('selectAll');
      if (selectAllCheckbox) {
        selectAllCheckbox.checked = true;
        window.DashboardSelection.handleSelectAllChange(true, true);
      } else {
        window.DashboardSelection.initCheckboxStates();
        window.DashboardSelection.updateSelectedCount();
        window.DashboardSelection.updateSelectedOnPageCounter();
      }
    }
    return;
  }
  if (t.id === 'clearSelectionLink') {
    e.preventDefault();
    if (window.DashboardSelection) {
      window.DashboardSelection.clearSelection();
      window.DashboardSelection.initCheckboxStates();
    }
    return;
  }
  var fieldEditBtn = t.closest && t.closest('.field-edit-btn');
  if (fieldEditBtn) {
    // Логика редактирования — в модуле modules/inline-edit.js.
    // Зовём отсюда, а не отдельным слушателем: порядок веток в этом обработчике
    // значим (редактирование должно перехватить клик раньше клика по строке).
    if (window.DashboardInlineEdit) {
      window.DashboardInlineEdit.handleEditClick(fieldEditBtn);
    }
    return;
  }
  var tableRow = t.closest && t.closest('tr[data-id]');
  if (tableRow) {
    if (t.classList && t.classList.contains('row-checkbox')) return;
    var interactiveSelectors = 'a, button, .row-checkbox, .field-edit-btn, .copy-btn, .btn, .pw-mask, input, select, textarea, .pw-toggle, .pw-edit';
    if (t.matches && t.matches(interactiveSelectors)) return;
    if (t.closest(interactiveSelectors)) return;
    if ((t.tagName === 'I' || t.tagName === 'SVG' || t.closest('i, svg')) && t.closest('button, a, .btn')) return;
    var rowCheckbox = tableRow.querySelector('.row-checkbox');
    if (!rowCheckbox) return;
    if (t === rowCheckbox || rowCheckbox.contains(t)) return;
    var wasChecked = rowCheckbox.checked;
    rowCheckbox.checked = !wasChecked;
    if (window.DashboardSelection) {
      window.DashboardSelection.setSelectedAllFiltered(false);
      window.DashboardSelection.toggleRowSelection(parseInt(rowCheckbox.value, 10), rowCheckbox.checked);
      window.DashboardSelection.updateRowSelectedClass(tableRow, rowCheckbox.checked);
    }
    if (window.DashboardSelection) window.DashboardSelection.updateSelectedCount();
    var selectAllCb = getElementById('selectAll');
    if (selectAllCb) {
      var allCbs = document.querySelectorAll('.row-checkbox');
      var checkedCbs = document.querySelectorAll('.row-checkbox:checked');
      selectAllCb.checked = allCbs.length > 0 && allCbs.length === checkedCbs.length;
    }
  }
}
document.addEventListener('click', handleDocumentClick, { passive: false });

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
  window.DashboardConfig.csrfToken = window.DashboardConfig.csrfToken;
  
  // НЕ сохраняем выбранные строки при перезагрузке - очищаем выбор
  if (window.DashboardSelection) {
    // Инициализируем filteredTotalLive из серверного значения
    window.DashboardSelection.setFilteredTotalLive(window.DashboardConfig.filteredTotal);
    
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

  // Pagination клики обрабатываются обычным href-переходом (без JS).
  // Select All и individual checkbox-ы — через делегирование в handleDocumentChange.
  // Password toggle / cell modal — в handleDocumentClick.
  
  // Copy cell content
  const cellCopyBtn = getElementById('cellCopyBtn');
  if (cellCopyBtn) {
    cellCopyBtn.addEventListener('click', function() {
      const body = getElementById('cellModalBody');
      copyToClipboard(body.textContent || '');
    });
  }
  
  // .copy-btn и пагинация — в handleDocumentClick
  
  // Export selected CSV
  const exportSelectedCsv = getElementById('exportSelectedCsv');
  if (exportSelectedCsv) {
    exportSelectedCsv.addEventListener('click', function() {
      const DS = window.DashboardSelection;
      if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
      
      // Создаем скрытую форму для корректной обработки заголовков скачивания.
      // POST обязателен — export.php требует POST + CSRF.
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = window.getTableAwareUrl('export.php');
      // Не указываем target, чтобы браузер правильно обработал Content-Disposition: attachment

      const currentSort = window.DashboardConfig.currentSort;
      const currentDir = window.DashboardConfig.currentDir;

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

      // CSRF-токен
      const csrfInput1 = document.createElement('input');
      csrfInput1.type = 'hidden';
      csrfInput1.name = 'csrf';
      csrfInput1.value = (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
      form.appendChild(csrfInput1);

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
      const currentSort = window.DashboardConfig.currentSort;
      const currentDir = window.DashboardConfig.currentDir;
      let visibleCols = [];
      try { const saved = localStorage.getItem('dashboard_visible_columns'); if (saved) visibleCols = JSON.parse(saved); } catch (_) {}
      if (!Array.isArray(visibleCols) || visibleCols.length === 0) {
        visibleCols = Array.from(document.querySelectorAll('#accountsTable thead th[data-col]')).map(th => th.getAttribute('data-col'));
      }
      const ALL_COL_KEYS = window.DashboardConfig.allColKeys;
      visibleCols = (visibleCols || []).filter(c => ALL_COL_KEYS.includes(c));
      // Убираем ID из экспорта, если он есть
      visibleCols = visibleCols.filter(c => c !== 'id');

      // Качаем частями (chunked): браузер собирает один файл из множества быстрых
      // запросов к export_chunk.php — большой объём не упирается в ~120s таймаут сервера.
      if (!window.DashboardExport || typeof window.DashboardExport.downloadTxtChunked !== 'function') {
        showToast('Модуль экспорта не загружен', 'error');
        return;
      }

      const allFiltered = DS.getSelectedAllFiltered();
      window.DashboardExport.downloadTxtChunked({
        scope: allFiltered ? 'all' : 'selected',
        ids: allFiltered ? null : Array.from(DS.getSelectedIds()),
        cols: visibleCols,
        sort: currentSort,
        dir: currentDir,
        limit: 0,
        filterSearch: window.location.search
      });
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
        response = await fetch(window.getTableAwareUrl('delete.php?select=all&' + params.toString()), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ ids: [], csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
        });
      } 
      // Обычный режим - удаление выбранных ID (батчами по 1000)
      else {
        if (!DS || DS.getSelectedIds().size === 0) {
          logger.warn('⚠️ Попытка удаления без выбранных ID');
          showToast('Не выбрано ни одной записи для удаления', 'warning');
          btn.disabled = false;
          btn.innerHTML = originalText;
          return;
        }

        const allIds = Array.from(DS.getSelectedIds());
        const BATCH_SIZE = 1000;
        const totalCount = allIds.length;
        let totalDeleted = 0;
        let batchErrors = 0;

        logger.group('🗑️ Удаление ' + totalCount + ' записей (батчами по ' + BATCH_SIZE + ')');

        for (let i = 0; i < allIds.length; i += BATCH_SIZE) {
          const batchIds = allIds.slice(i, i + BATCH_SIZE);
          const batchNum = Math.floor(i / BATCH_SIZE) + 1;
          const totalBatches = Math.ceil(allIds.length / BATCH_SIZE);

          // Обновляем текст кнопки с прогрессом
          if (totalBatches > 1) {
            btn.innerHTML = '<span class="loader loader-sm loader-white me-2" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;"></span>Удаление ' + batchNum + '/' + totalBatches + '...';
          }

          logger.debug('Батч ' + batchNum + '/' + totalBatches + ': ' + batchIds.length + ' ID');

          try {
            const resp = await fetch(window.getTableAwareUrl('delete.php'), {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: JSON.stringify({ ids: batchIds, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
            });

            if (!resp.ok) {
              logger.error('❌ Батч ' + batchNum + ' HTTP ошибка:', resp.status);
              batchErrors++;
              continue;
            }

            const batchData = await resp.json();
            if (batchData.success) {
              totalDeleted += (batchData.deleted_count || 0);
            } else {
              logger.error('❌ Батч ' + batchNum + ' ошибка:', batchData.error);
              batchErrors++;
            }
          } catch (batchErr) {
            logger.error('❌ Батч ' + batchNum + ' сетевая ошибка:', batchErr);
            batchErrors++;
          }
        }

        logger.groupEnd();

        // Результат
        if (totalDeleted === 0 && batchErrors > 0) {
          showToast('Ошибка при удалении записей', 'error');
        } else {
          if (batchErrors > 0) {
            showToast('Удалено ' + totalDeleted + ' из ' + totalCount + ' (часть батчей завершилась с ошибкой)', 'warning');
          } else if (totalDeleted === 0) {
            showToast('Ни одна запись не была удалена. Возможно, записи уже нет в базе.', 'warning');
          } else {
            showToast('Удалено ' + totalDeleted + ' записей', 'success');
          }

          // Очищаем выбор
          if (window.DashboardSelection) {
            window.DashboardSelection.clearSelection();
            window.DashboardSelection.initCheckboxStates();
          }

          // Закрываем модалку
          const modal = bootstrap.Modal.getInstance(getElementById('deleteConfirmModal'));
          if (modal) {
            modal.hide();
          }

          logger.debug('✅ Удаление завершено. Удалено: ' + totalDeleted);
          await refreshDashboardData();
        }

        // Пропускаем общую обработку ответа — уже обработано выше
        btn.disabled = false;
        btn.innerHTML = originalText;
        return;
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
          showToast('Ни одна запись не была удалена. Возможно, записи уже нет в базе.', 'warning');
        } else {
          showToast(data.message, 'success');
        }

        // Очищаем выбор
        if (window.DashboardSelection) {
          window.DashboardSelection.clearSelection();
          window.DashboardSelection.initCheckboxStates();
        }

        // Закрываем модалку
        const modal = bootstrap.Modal.getInstance(getElementById('deleteConfirmModal'));
        if (modal) {
          modal.hide();
        }

        logger.debug('✅ Удаление завершено успешно. Обновляем статистику...');

        await refreshDashboardData();
        showToast('Удалено ' + (data.deleted_count || 0) + ' записей', 'success');
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
  
  // Пагинация (goToPage, pageJump, Enter) — обрабатывается в pagination.js
});

// goToPage — делегируем в pagination.js (обратная совместимость)
function goToPage(selectedPage) {
  if (window.Pagination && typeof window.Pagination.goToPage === 'function') {
    window.Pagination.goToPage(selectedPage);
  }
}

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
    if (typeof window.updateStickyScrollbar === 'function') window.updateStickyScrollbar();
    syncHeaderWidths();
    adjustTableDensity();
    resizeTimeout = null;
  });
};
window.addEventListener('resize', optimizedResizeHandler, { passive: true });

// Sticky scrollbar обновляем только когда блок таблицы в viewport (FPS)
window._tableSectionInView = false;
function setupTableSectionObserver() {
  var section = document.getElementById('accountsTableSection');
  if (!section || typeof IntersectionObserver === 'undefined') return;
  var wasIntersecting = false;
  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      window._tableSectionInView = entry.isIntersecting;
      if (entry.isIntersecting && !wasIntersecting && typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
      wasIntersecting = entry.isIntersecting;
    });
  }, { root: null, rootMargin: '0px', threshold: 0.1 });
  observer.observe(section);
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setupTableSectionObserver);
} else {
  setupTableSectionObserver();
}

// Режим «лёгкая панель»: по prefers-reduced-motion или сохранённому выбору в localStorage
function applyDashboardPerfLight() {
  var stored = null;
  try { stored = localStorage.getItem('dashboard-perf-light'); } catch (e) {}
  var useLight = stored === 'true' || (stored !== 'false' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  if (useLight) document.body.classList.add('dashboard-perf-light');
  else document.body.classList.remove('dashboard-perf-light');
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', applyDashboardPerfLight);
} else {
  applyDashboardPerfLight();
}
window.setDashboardPerfLight = function(enabled) {
  try { localStorage.setItem('dashboard-perf-light', enabled ? 'true' : 'false'); } catch (e) {}
  applyDashboardPerfLight();
};

// Оптимизированный обработчик скролла: обновляем sticky scrollbar только если таблица видна
let scrollTimeout;
const optimizedUpdateStickyHScroll = () => {
  clearTimeout(scrollTimeout);
  scrollTimeout = requestAnimationFrame(() => {
    if (window._tableSectionInView !== true) return;
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



// ===== Автообновление данных и кнопка «Наверх» =====
// Переехали в modules/auto-refresh.js (2026-08-09): initializeAutoRefresh,
// startAutoRefresh, stopAutoRefresh, initScrollToTop. Состояние таймера теперь
// внутри модуля, а не в глобальных let этого файла.
// ===== Touch-жесты и адаптивные карточки =====
// Переехали в modules/touch-gestures.js (2026-08-09):
// initializeTouchGestures и handleCardSwipe.
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

// Throttle resize для FPS (adjustForMobile не блокирует, passive по возможности)
var adjustForMobileTimer;
function throttledAdjustForMobile() {
  if (adjustForMobileTimer) return;
  adjustForMobileTimer = requestAnimationFrame(function() {
    adjustForMobile();
    adjustForMobileTimer = null;
  });
}
window.addEventListener('resize', throttledAdjustForMobile, { passive: true });
    window.addEventListener('load', function() {
  adjustForMobile();
  // loadHiddenCards() уже вызывается в initDashboard() (строка ~1101).
  // Повторный вызов здесь перезатирал localStorage серверными данными,
  // если POST при saveHiddenCards ранее не прошёл.
});

// ===== КАСТОМНЫЕ КАРТОЧКИ СТАТИСТИКИ =====
// Перенесены в модуль assets/js/modules/custom-cards.js (2026-08-08).
// Он подключается ДО этого файла и кладёт свои функции в window, поэтому
// вызовы ниже по имени (initializeCustomCards, renderCustomCardsOnDashboard,
// loadCustomCardsFromStorage, saveCustomCardsToStorage) продолжают работать.
// ===== ДУБЛИРУЮЩИЙСЯ КОД УДАЛЕН =====
// Все функции кастомных карточек определены выше в новой версии (строки 6300-6924)

// Логика массовой смены статуса вынесена в модуль `dashboard-modals.js` (initStatusModal).

// selectAllFilteredLink / clearSelectionLink — в handleDocumentClick

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
      // Точный режим включается кнопкой в плашке и относится к тому запросу,
      // на котором её нажали. Новый ввод — новый поиск, режим сбрасываем.
      url.searchParams.delete('exact');
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
    // Удаляем все старые параметры status (включая индексированные status[N]) и empty_status
    if (typeof window.deleteAllStatusKeys === 'function') {
      window.deleteAllStatusKeys(url);
      url.searchParams.delete('empty_status');
    } else {
      const keysToDelete = [];
      for (const key of url.searchParams.keys()) {
        if (key === 'status' || key === 'status[]' || /^status\[\d+\]$/.test(key) || key === 'empty_status') {
          keysToDelete.push(key);
        }
      }
      keysToDelete.forEach(key => url.searchParams.delete(key));
    }

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
  
  // per_page select обрабатывается в pagination.js
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

// Пагинация .pagination a.page-link — в handleDocumentClick

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

// Второй resize убран: один общий обработчик выше (updateStickyScrollbar + syncHeaderWidths + adjustTableDensity)
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

// ===== Обработка чекбоксов перенесена в dashboard-selection.js =====
// selectAll и row-checkbox обрабатываются в dashboard-selection.js через initSelectionModule()
// Обработчик .field-edit-btn и клик по строке таблицы — в handleDocumentClick

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
      
      const exportBtns = document.querySelectorAll('#exportSelectedCsv, #exportSelectedTxt, #deleteSelected, #changeStatusSelected, #bulkEditFieldBtn, #validateAccountsBtn');
      exportBtns.forEach(btn => btn.disabled = true);
    }
  });
}

// ===== Массовый перенос аккаунтов =====
// Живёт в modules/dashboard-modals.js (initTransferModal).
// Здесь до 2026-08-09 лежала прежняя реализация — 210 строк, обёрнутых в
// `if (false && applyTransferBtn)`. Так её отключили при переносе в модуль и
// оставили в файле: код недостижим по определению, но каждый, кто искал логику
// переноса, находил сначала его. Удалено; восстанавливается из истории git.

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

// ===== Поиск и управление в модалке настроек =====
(function() {
  function updateSettingsCounter(toggleSelector, counterId) {
    var all = document.querySelectorAll(toggleSelector);
    var checked = document.querySelectorAll(toggleSelector + ':checked');
    var el = document.getElementById(counterId);
    if (el) el.textContent = 'Выбрано ' + checked.length + ' из ' + all.length;
  }

  function updateAllSettingsCounters() {
    updateSettingsCounter('.column-toggle', 'columnCounter');
    updateSettingsCounter('.card-toggle', 'cardCounter');
  }

  document.addEventListener('DOMContentLoaded', function() {
    // Поиск по колонкам и карточкам
    document.querySelectorAll('.settings-search').forEach(function(input) {
      input.addEventListener('input', function() {
        var query = this.value.toLowerCase().trim();
        var container = document.querySelector(this.getAttribute('data-target'));
        if (!container) return;
        container.querySelectorAll('.form-check').forEach(function(item) {
          var label = item.querySelector('.form-check-label');
          if (!label) return;
          var text = label.textContent.toLowerCase();
          item.style.display = text.indexOf(query) !== -1 ? '' : 'none';
        });
      });
    });

    // Кнопки "Выбрать все" / "Снять все"
    document.querySelectorAll('.btn-select-all').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.querySelectorAll(this.getAttribute('data-target')).forEach(function(cb) {
          if (!cb.checked && cb.closest('.form-check').style.display !== 'none') {
            cb.checked = true;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
        updateAllSettingsCounters();
      });
    });
    document.querySelectorAll('.btn-deselect-all').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.querySelectorAll(this.getAttribute('data-target')).forEach(function(cb) {
          if (cb.checked && cb.closest('.form-check').style.display !== 'none') {
            cb.checked = false;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
        updateAllSettingsCounters();
      });
    });

    // Обновляем счётчики при изменении чекбоксов
    document.addEventListener('change', function(e) {
      if (e.target.classList.contains('column-toggle') || e.target.classList.contains('card-toggle')) {
        updateAllSettingsCounters();
      }
    });

    // При открытии модалки: сбрасываем поиск, обновляем счётчики
    var modal = document.getElementById('settingsModal');
    if (modal) {
      modal.addEventListener('shown.bs.modal', function() {
        updateAllSettingsCounters();
        document.querySelectorAll('.settings-search').forEach(function(input) {
          input.value = '';
          input.dispatchEvent(new Event('input'));
        });
      });
    }
  });

  window.updateAllSettingsCounters = updateAllSettingsCounters;
})();

// ===== Прилипающий горизонтальный скроллбар (новая реализация) =====
// Код перемещен в assets/js/sticky-scrollbar.js

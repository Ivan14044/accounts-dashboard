/**
 * Кастомные карточки статистики: хранилище, отрисовка, создание и удаление.
 *
 * Вынесено из dashboard-init.js 2026-08-08 (тот файл был на 4033 строки и делал
 * всё сразу). Здесь — только карточки: загрузка с сервера с fallback на
 * localStorage, отрисовка на дашборде и в настройках, пересчёт счётчиков,
 * создание карточки и регистрация недостающих статусов.
 *
 * Подключается ДО dashboard-init.js: тот вызывает initializeCustomCards() и
 * несколько функций отсюда по имени.
 *
 * Зависимости (берутся из глобальной области в момент вызова, не при загрузке):
 *   getElementById, getSel — из dashboard-init.js;
 *   logger, showToast, DashboardConfig — из core/logger.js и init-script;
 *   updateStatValue, loadStatLabels — из dashboard-init.js;
 *   bootstrap.Modal — из вендора.
 *
 * Ключи localStorage берутся из modules/constants.js (window.LS_KEY_*). Своих
 * копий здесь нет — раньше были, и это была мина: разъедутся значения, и
 * пользователь молча потеряет настройки.
 */
(function () {
  'use strict';


  // ===== КАСТОМНЫЕ КАРТОЧКИ СТАТИСТИКИ =====
  // Полностью переписанный функционал с нуля - версия 3.0
  // Ключи приходят из modules/constants.js — своих копий здесь больше нет.

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
  // Кэш одной загрузки карточек на страницу.
  // Инициализация дёргает loadCustomCardsFromStorage() трижды подряд
  // (renderCustomCardsSettings → renderCustomCardsOnDashboard → registerMissingStatuses),
  // и в сети было три одинаковых GET /api/settings?type=custom_cards.
  // Храним именно Promise, чтобы параллельные вызовы разделили один запрос,
  // а не запустили по своему. Сбрасывается после любой записи карточек.
  let _customCardsPromise = null;

  /**
   * Сбрасывает кэш карточек. Вызывать после любого изменения набора карточек,
   * иначе следующий рендер покажет устаревшие данные.
   */
  function invalidateCustomCardsCache() {
    _customCardsPromise = null;
  }

  async function loadCustomCardsFromStorage() {
    if (!_customCardsPromise) {
      _customCardsPromise = fetchCustomCardsFromStorage().catch(err => {
        // Неудачную попытку не кэшируем — следующий вызов попробует снова.
        _customCardsPromise = null;
        throw err;
      });
    }

    // Копия: вызывающий код фильтрует и сортирует результат, и мутация
    // не должна протекать в кэш.
    const cards = await _customCardsPromise;
    return cards.slice();
  }

  /**
   * Реальная загрузка карточек: БД, при недоступности — localStorage.
   *
   * @returns {Promise<Array>} массив карточек (может быть пустым)
   */
  async function fetchCustomCardsFromStorage() {
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

    // Набор карточек меняется — кэш чтения больше не актуален.
    invalidateCustomCardsCache();

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
  
    // Удаление карточки и регистрация статуса — в handleDocumentClick
  }


  // Наружу отдаём только то, что вызывает dashboard-init.js и cards-hide-sync.js.
  window.invalidateCustomCardsCache   = invalidateCustomCardsCache;
  window.loadCustomCardsFromStorage   = loadCustomCardsFromStorage;
  window.loadCustomCardsFromLocalStorage = loadCustomCardsFromLocalStorage;
  window.saveCustomCardsToStorage     = saveCustomCardsToStorage;
  window.renderCustomCardsSettings    = renderCustomCardsSettings;
  window.renderCustomCardsOnDashboard = renderCustomCardsOnDashboard;
  window.refreshCustomCardCounts      = refreshCustomCardCounts;
  window.createCustomCard             = createCustomCard;
  window.registerMissingStatuses      = registerMissingStatuses;
  window.initializeCustomCards        = initializeCustomCards;
})();

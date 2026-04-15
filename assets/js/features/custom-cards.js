/**
 * Кастомные карточки статистики: загрузка/сохранение, отрисовка, создание/удаление.
 * Зависит: getElementById, getSel, logger, showToast, LS_KEY_HIDDEN_CARDS (из constants);
 * при вызове — updateStatValue, loadStatLabels (из dashboard-init), bootstrap.Modal.
 */
(function () {
  'use strict';

  var getElementById = window.getElementById || function (id) { return document.getElementById(id); };
  var getSel = window.getSel || function (sel) { return document.querySelector(sel); };
  var logger = window.logger || { warn: function () {}, error: function () {}, debug: function () {} };
  var showToast = window.showToast || function () {};
  var LS_KEY_HIDDEN_CARDS = window.LS_KEY_HIDDEN_CARDS || 'dashboard_hidden_cards';
  var LS_KEY_CUSTOM_CARDS = 'dashboard_custom_cards_v3';

  function hexToRgb(hex) {
    if (!hex) return null;
    var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? {
      r: parseInt(result[1], 16),
      g: parseInt(result[2], 16),
      b: parseInt(result[3], 16)
    } : null;
  }

  function loadCustomCardsFromLocalStorage() {
    try {
      var raw = localStorage.getItem(LS_KEY_CUSTOM_CARDS);
      if (!raw) return [];
      var arr = JSON.parse(raw);
      if (!Array.isArray(arr)) return [];
      return arr.filter(function (x) { return x && typeof x === 'object' && x.key; });
    } catch (e) {
      logger.error('Error loading from localStorage:', e);
      return [];
    }
  }

  async function loadCustomCardsFromStorage() {
    try {
      var response = await fetch('/api/settings?type=custom_cards', {
        method: 'GET',
        credentials: 'same-origin'
      });
      if (response.ok) {
        var data = await response.json();
        if (data.success && Array.isArray(data.value)) {
          var cards = data.value.filter(function (x) { return x && typeof x === 'object' && x.key; });
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
    return loadCustomCardsFromLocalStorage();
  }

  async function saveCustomCardsToStorage(cards) {
    if (!Array.isArray(cards)) {
      logger.error('Invalid cards array');
      return false;
    }
    try {
      localStorage.setItem(LS_KEY_CUSTOM_CARDS, JSON.stringify(cards));
    } catch (e) {
      logger.warn('Failed to save to localStorage:', e);
    }
    try {
      var response = await fetch('/api/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ type: 'custom_cards', value: cards, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
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

  async function renderCustomCardsSettings() {
    var list = getElementById('customCardsList');
    if (!list) {
      logger.warn('customCardsList element not found');
      return;
    }
    var cards = await loadCustomCardsFromStorage();
    if (!cards.length) {
      list.innerHTML = '<div class="text-muted text-center py-3">Нет кастомных карточек. Нажмите "Создать карточку" для добавления.</div>';
      return;
    }
    list.innerHTML = cards.map(function (c, idx) {
      var filters = c.filters || {};
      var filterDesc = [];
      if (filters.status && Array.isArray(filters.status) && filters.status.length > 0) {
        filterDesc.push('Статусы: ' + filters.status.length);
      }
      if (filters.has_email) filterDesc.push('Email');
      if (filters.has_two_fa) filterDesc.push('2FA');
      if (filters.has_token) filterDesc.push('Token');
      if (filters.has_avatar) filterDesc.push('Аватар');
      if (filters.has_cover) filterDesc.push('Обложка');
      if (filters.has_password) filterDesc.push('Пароль');
      if (filters.has_fan_page) filterDesc.push('Fan Page');
      if (filters.full_filled) filterDesc.push('Полностью заполнено');
      if (c.targetStatus) filterDesc.push('→ ' + c.targetStatus);
      var colorBadge = c.settings && c.settings.color
        ? '<span class="badge" style="background-color:' + c.settings.color + ';width:16px;height:16px;border-radius:4px;display:inline-block;"></span>'
        : '';
      return '<div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">' +
        '<div class="flex-grow-1">' +
          '<div class="fw-semibold d-flex align-items-center gap-2">' + colorBadge + (c.name || 'Без названия') + '</div>' +
          '<div class="text-muted small">' + (filterDesc.length > 0 ? filterDesc.join(' • ') : 'Без фильтров') + '</div>' +
        '</div>' +
        '<div class="d-flex align-items-center gap-2">' +
          '<div class="form-check">' +
            '<input class="form-check-input card-toggle" type="checkbox" data-card="custom:' + c.key + '" id="card_custom_' + idx + '" ' + (c.visible !== false ? 'checked' : '') + '>' +
            '<label class="form-check-label" for="card_custom_' + idx + '">Показывать</label>' +
          '</div>' +
          (c.targetStatus ? '<button type="button" class="btn btn-sm btn-outline-info" data-register-status="' + c.targetStatus + '" title="Повторно зарегистрировать статус"><i class="fas fa-sync-alt"></i> Обновить</button>' : '') +
          '<button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom-card="' + c.key + '" title="Удалить"><i class="fas fa-trash"></i></button>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  async function renderCustomCardsOnDashboard() {
    var row = getElementById('statsRow');
    if (!row) {
      logger.warn('statsRow element not found');
      setTimeout(function () { renderCustomCardsOnDashboard(); }, 200);
      return;
    }
    row.querySelectorAll('[data-card^="custom:"]').forEach(function (n) { n.remove(); });
    var cards = await loadCustomCardsFromStorage();
    if (!cards.length) return;

    var hiddenCards = new Set();
    try {
      var savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
      if (savedHidden) {
        JSON.parse(savedHidden).forEach(function (id) {
          if (typeof id === 'string') hiddenCards.add(id);
        });
      }
    } catch (e) {
      logger.error('Error loading hidden cards:', e);
    }

    cards.forEach(function (c) {
      if (c.visible === false) return;
      var cardId = 'custom:' + c.key;
      var isHiddenByUser = hiddenCards.has(cardId);
      var cardElement = document.createElement('div');
      cardElement.className = 'stat-card fade-in';
      cardElement.setAttribute('data-card', cardId);
      cardElement.setAttribute('data-card-type', 'custom');
      cardElement.setAttribute('data-card-key', c.key);

      var filters = c.filters || {};
      if (filters.has_email) cardElement.setAttribute('data-has-email', '1');
      if (filters.has_two_fa) cardElement.setAttribute('data-has-two-fa', '1');
      if (filters.has_token) cardElement.setAttribute('data-has-token', '1');
      if (filters.has_avatar) cardElement.setAttribute('data-has-avatar', '1');
      if (filters.has_cover) cardElement.setAttribute('data-has-cover', '1');
      if (filters.full_filled) cardElement.setAttribute('data-full-filled', '1');
      if (filters.pharma_from) cardElement.setAttribute('data-pharma-from', filters.pharma_from);
      if (filters.pharma_to) cardElement.setAttribute('data-pharma-to', filters.pharma_to);
      if (c.targetStatus) cardElement.setAttribute('data-target-status', c.targetStatus);

      var cardColor = (c.settings && c.settings.color) ? c.settings.color : '#3b82f6';
      var rgb = hexToRgb(cardColor);
      var darkerColor = rgb ? 'rgb(' + Math.max(0, rgb.r - 30) + ', ' + Math.max(0, rgb.g - 30) + ', ' + Math.max(0, rgb.b - 30) + ')' : cardColor;
      cardElement.style.setProperty('--card-color', cardColor);
      cardElement.style.setProperty('--card-color-dark', darkerColor);

      cardElement.innerHTML = '<button type="button" class="stat-card-hide-btn" data-card="' + cardId + '" title="Скрыть карточку"><i class="fas fa-eye-slash"></i></button>' +
        '<div class="stat-header"><h3 class="stat-title">' + (c.name || 'Кастом') + '</h3></div>' +
        '<div class="stat-value">0</div>' +
        '<div class="stat-trend"><small class="text-muted">' + (c.targetStatus ? '→ ' + c.targetStatus : 'Кастомные условия') + '</small></div>';

      if (isHiddenByUser) cardElement.classList.add('hidden');
      row.appendChild(cardElement);
    });

    var urlParams = new URLSearchParams(window.location.search);
    var activeCardKey = urlParams.get('active_card');
    if (activeCardKey) {
      setTimeout(function () {
        var activeCard = getSel('.stat-card[data-card-key="' + activeCardKey + '"]');
        if (activeCard) {
          activeCard.classList.add('active');
          var cardColor = activeCard.style.getPropertyValue('--card-color') || '#3b82f6';
          activeCard.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.4) 0%, rgba(59, 130, 246, 0.6) 100%)';
          activeCard.style.border = '2px solid ' + cardColor;
          activeCard.style.boxShadow = '0 0 0 3px ' + cardColor + ', 0 14px 24px rgba(59, 130, 246, 0.4)';
          activeCard.style.opacity = '1';
          urlParams.delete('active_card');
          var newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
          window.history.replaceState({}, '', newUrl);
        }
      }, 100);
    }

    if (typeof window.updateStatValue === 'function') {
      await refreshCustomCardCounts();
    }
  }

  async function refreshCustomCardCounts() {
    var cards = await loadCustomCardsFromStorage();
    if (!cards.length) return;
    var updateStatValue = window.updateStatValue || function (el, n) { if (el) el.textContent = Number(n).toLocaleString(); };
    var getSel = window.getSel || function (s) { return document.querySelector(s); };

    var updatePromises = cards.map(async function (c) {
      try {
        var filters = c.filters || {};
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
        var filtersWithCsrf = Object.assign({}, filters, { csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' });
        var response = await fetch('/api/accounts/custom-card', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: JSON.stringify(filtersWithCsrf)
        });
        if (!response.ok) return;
        var json = await response.json();
        if (!json.success || typeof json.count !== 'number') return;
        var wrap = getSel('[data-card="custom:' + c.key + '"] .stat-value');
        if (wrap) updateStatValue(wrap, json.count);
        var cardEl = getSel('[data-card="custom:' + c.key + '"]');
        if (cardEl && c.settings && c.settings.color) {
          cardEl.style.setProperty('--card-color', c.settings.color);
          var rgb = hexToRgb(c.settings.color);
          var darkerColor = rgb ? 'rgb(' + Math.max(0, rgb.r - 30) + ', ' + Math.max(0, rgb.g - 30) + ', ' + Math.max(0, rgb.b - 30) + ')' : c.settings.color;
          cardEl.style.setProperty('--card-color-dark', darkerColor);
        }
      } catch (e) {
        logger.error('Error refreshing custom card ' + c.key + ':', e);
      }
    });
    await Promise.all(updatePromises);
  }

  async function createCustomCard() {
    var name = (getElementById('customCardName') && getElementById('customCardName').value || '').trim();
    if (!name) {
      showToast('Введите название карточки', 'error');
      return;
    }
    var filters = {};
    var statusSelect = getElementById('customCardStatuses');
    if (statusSelect) {
      var selectedStatuses = Array.from(statusSelect.selectedOptions).map(function (opt) { return opt.value; });
      if (selectedStatuses.length > 0) filters.status = selectedStatuses;
    }
    filters.has_email = !!(getElementById('customHasEmail') && getElementById('customHasEmail').checked);
    filters.has_two_fa = !!(getElementById('customHasTwoFa') && getElementById('customHasTwoFa').checked);
    filters.has_token = !!(getElementById('customHasToken') && getElementById('customHasToken').checked);
    filters.has_avatar = !!(getElementById('customHasAvatar') && getElementById('customHasAvatar').checked);
    filters.has_cover = !!(getElementById('customHasCover') && getElementById('customHasCover').checked);
    filters.has_password = !!(getElementById('customHasPassword') && getElementById('customHasPassword').checked);
    filters.has_fan_page = !!(getElementById('customHasFanPage') && getElementById('customHasFanPage').checked);
    filters.full_filled = !!(getElementById('customFullFilled') && getElementById('customFullFilled').checked);

    var pharmaFrom = (getElementById('customPharmaFrom') && getElementById('customPharmaFrom').value || '').trim();
    var pharmaTo = (getElementById('customPharmaTo') && getElementById('customPharmaTo').value || '').trim();
    if (pharmaFrom) filters.pharma_from = pharmaFrom;
    if (pharmaTo) filters.pharma_to = pharmaTo;
    var friendsFrom = (getElementById('customFriendsFrom') && getElementById('customFriendsFrom').value || '').trim();
    var friendsTo = (getElementById('customFriendsTo') && getElementById('customFriendsTo').value || '').trim();
    if (friendsFrom) filters.friends_from = friendsFrom;
    if (friendsTo) filters.friends_to = friendsTo;
    var yearFrom = (getElementById('customYearCreatedFrom') && getElementById('customYearCreatedFrom').value || '').trim();
    var yearTo = (getElementById('customYearCreatedTo') && getElementById('customYearCreatedTo').value || '').trim();
    if (yearFrom) filters.year_created_from = yearFrom;
    if (yearTo) filters.year_created_to = yearTo;
    var statusMarketplace = getElementById('customStatusMarketplace') && getElementById('customStatusMarketplace').value;
    if (statusMarketplace) filters.status_marketplace = statusMarketplace;
    var statusRk = getElementById('customStatusRk') && getElementById('customStatusRk').value;
    if (statusRk) filters.status_rk = statusRk;
    var limitRkFrom = (getElementById('customLimitRkFrom') && getElementById('customLimitRkFrom').value || '').trim();
    var limitRkTo = (getElementById('customLimitRkTo') && getElementById('customLimitRkTo').value || '').trim();
    if (limitRkFrom) filters.limit_rk_from = limitRkFrom;
    if (limitRkTo) filters.limit_rk_to = limitRkTo;
    var currency = getElementById('customCurrency') && getElementById('customCurrency').value;
    if (currency) filters.currency = currency;
    var geo = getElementById('customGeo') && getElementById('customGeo').value;
    if (geo) filters.geo = geo;
    var favoritesOnly = getSel('input[type="checkbox"][name="favorites_only"]') && getSel('input[type="checkbox"][name="favorites_only"]').checked;
    if (favoritesOnly) filters.favorites_only = true;

    var targetStatus = (getElementById('customCardTargetStatus') && getElementById('customCardTargetStatus').value || '').trim();
    var wasNewStatus = (targetStatus === '__new__');
    if (targetStatus === '__new__') {
      targetStatus = (getElementById('customCardNewStatus') && getElementById('customCardNewStatus').value || '').trim();
      if (!targetStatus) {
        showToast('Введите название нового статуса', 'error');
        return;
      }
    }

    if (targetStatus && targetStatus.trim() !== '') {
      try {
        var registerResponse = await fetch('/api/status/register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ status: targetStatus, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
        });
        if (registerResponse.ok) {
          var registerData = await registerResponse.json();
          if (registerData.success) logger.debug('Статус "' + targetStatus + '" ' + (registerData.exists ? 'уже существует' : 'зарегистрирован'));
        }
      } catch (error) {
        logger.error('Error registering status:', error);
      }
    }

    var key = 'c_' + Date.now();
    var card = {
      key: key,
      name: name,
      visible: true,
      filters: filters,
      targetStatus: targetStatus || null,
      settings: { color: (getElementById('customCardColor') && getElementById('customCardColor').value) || '#3b82f6' }
    };

    var cards = await loadCustomCardsFromStorage();
    cards.push(card);
    await saveCustomCardsToStorage(cards);

    var modal = window.bootstrap && window.bootstrap.Modal && window.bootstrap.Modal.getInstance(getElementById('customCardModal'));
    if (modal) modal.hide();

    await renderCustomCardsSettings();
    await renderCustomCardsOnDashboard();
    if (typeof window.loadStatLabels === 'function') window.loadStatLabels();

    if (targetStatus && targetStatus.trim() !== '') {
      if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem('statuses_registered');
      if (wasNewStatus) {
        showToast('Кастомная карточка добавлена. Новый статус "' + targetStatus + '" зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.', 'success', 5000);
      } else {
        showToast('Кастомная карточка добавлена. Статус "' + targetStatus + '" проверен.', 'success', 4000);
      }
    } else {
      showToast('Кастомная карточка добавлена', 'success');
    }
  }

  async function registerMissingStatuses() {
    try {
      var cards = await loadCustomCardsFromStorage();
      var statusesToRegister = cards.map(function (c) { return c.targetStatus; }).filter(function (s) { return s && s.trim() !== ''; }).map(function (s) { return s.trim(); });
      if (statusesToRegister.length === 0) return;
      var uniqueStatuses = [];
      var seen = {};
      statusesToRegister.forEach(function (s) { if (!seen[s]) { seen[s] = true; uniqueStatuses.push(s); } });
      var registeredCount = 0;
      for (var i = 0; i < uniqueStatuses.length; i++) {
        var status = uniqueStatuses[i];
        try {
          var response = await fetch('/api/status/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ status: status, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
          });
          if (response.ok) {
            var data = await response.json();
            if (data.success && !data.exists) { registeredCount++; }
          }
        } catch (error) {
          logger.warn('Не удалось зарегистрировать статус "' + status + '":', error);
        }
      }
      if (registeredCount > 0) {
        showToast('Зарегистрировано ' + registeredCount + ' новых статусов. Обновите страницу, чтобы увидеть их в фильтрах.', 'success', 5000);
      }
    } catch (error) {
      logger.error('Error registering missing statuses:', error);
    }
  }

  async function initializeCustomCards() {
    await renderCustomCardsSettings();
    await renderCustomCardsOnDashboard();

    if (typeof sessionStorage !== 'undefined' && !sessionStorage.getItem('statuses_registered')) {
      await registerMissingStatuses();
      sessionStorage.setItem('statuses_registered', 'true');
    }

    var addBtn = getElementById('addCustomCardBtn');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        var form = getElementById('customCardForm');
        if (form) form.reset();
        var colorEl = getElementById('customCardColor');
        if (colorEl) colorEl.value = '#3b82f6';
        var newStatusInputGroup = getElementById('newStatusInputGroup');
        if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
      });
    }

    var targetStatusSelect = getElementById('customCardTargetStatus');
    var newStatusInputGroup = getElementById('newStatusInputGroup');
    var newStatusInput = getElementById('customCardNewStatus');

    if (targetStatusSelect) {
      targetStatusSelect.addEventListener('change', function () {
        if (this.value === '__new__') {
          if (newStatusInputGroup) newStatusInputGroup.style.display = 'block';
          if (newStatusInput) { newStatusInput.focus(); newStatusInput.required = true; }
        } else {
          if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
          if (newStatusInput) { newStatusInput.value = ''; newStatusInput.required = false; }
        }
      });
    }

    var saveBtn = getElementById('saveCustomCardBtn');
    if (saveBtn) {
      saveBtn.addEventListener('click', async function () {
        await createCustomCard();
      });
    }

    var modal = getElementById('customCardModal');
    if (modal) {
      modal.addEventListener('hidden.bs.modal', function () {
        var form = getElementById('customCardForm');
        if (form) form.reset();
        if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
        if (newStatusInput) { newStatusInput.value = ''; newStatusInput.required = false; }
      });
    }

    document.addEventListener('change', async function (e) {
      var t = e.target;
      if (!(t instanceof HTMLElement)) return;
      if (t.classList.contains('card-toggle') && t.getAttribute('data-card') && t.getAttribute('data-card').indexOf('custom:') === 0) {
        var key = t.getAttribute('data-card').slice(7);
        var cards = await loadCustomCardsFromStorage();
        var card = cards.filter(function (x) { return x.key === key; })[0];
        if (card) {
          card.visible = !!t.checked;
          await saveCustomCardsToStorage(cards);
          await renderCustomCardsOnDashboard();
        }
      }
    });

    document.addEventListener('click', async function (e) {
      var removeBtn = (e.target instanceof HTMLElement) ? e.target.closest('[data-remove-custom-card]') : null;
      if (removeBtn) {
        var key = removeBtn.getAttribute('data-remove-custom-card');
        var cards = (await loadCustomCardsFromStorage()).filter(function (x) { return x.key !== key; });
        await saveCustomCardsToStorage(cards);
        await renderCustomCardsSettings();
        await renderCustomCardsOnDashboard();
        showToast('Кастомная карточка удалена', 'success');
        return;
      }

      var registerBtn = (e.target instanceof HTMLElement) ? e.target.closest('[data-register-status]') : null;
      if (registerBtn) {
        var status = registerBtn.getAttribute('data-register-status');
        if (!status) return;
        registerBtn.disabled = true;
        var originalHtml = registerBtn.innerHTML;
        registerBtn.innerHTML = '<span class="loader loader-sm loader-white" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;margin-right:8px;"></span> Регистрация...';
        try {
          var response = await fetch('/api/status/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ status: status, csrf: (window.DashboardConfig && window.DashboardConfig.csrfToken) || '' })
          });
          if (!response.ok) {
            var errorData = await response.json();
            throw new Error(errorData.error || 'Ошибка регистрации статуса');
          }
          var data = await response.json();
          if (data.success) {
            showToast('Статус "' + status + '" успешно зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.', 'success', 5000);
          } else {
            throw new Error('Не удалось зарегистрировать статус');
          }
        } catch (error) {
          logger.error('Error registering status:', error);
          showToast('Ошибка регистрации статуса: ' + error.message, 'error');
          registerBtn.disabled = false;
          registerBtn.innerHTML = originalHtml;
        }
      }
    });
  }

  window.LS_KEY_CUSTOM_CARDS = LS_KEY_CUSTOM_CARDS;
  window.hexToRgb = hexToRgb;
  window.loadCustomCardsFromLocalStorage = loadCustomCardsFromLocalStorage;
  window.loadCustomCardsFromStorage = loadCustomCardsFromStorage;
  window.saveCustomCardsToStorage = saveCustomCardsToStorage;
  window.renderCustomCardsSettings = renderCustomCardsSettings;
  window.renderCustomCardsOnDashboard = renderCustomCardsOnDashboard;
  window.refreshCustomCardCounts = refreshCustomCardCounts;
  window.createCustomCard = createCustomCard;
  window.registerMissingStatuses = registerMissingStatuses;
  window.initializeCustomCards = initializeCustomCards;
})();

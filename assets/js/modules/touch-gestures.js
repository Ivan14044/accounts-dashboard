/**
 * Touch-жесты на мобильных: свайпы по карточкам статистики и по строкам таблицы.
 *
 * Вынесено из dashboard-init.js 2026-08-09 — шестой шаг разбора.
 *
 * Модуль полностью самодостаточен: снаружи ему нужны только logger, showToast,
 * refreshDashboardData и DashboardSelection, и все они берутся из глобальной
 * области в момент жеста, а не при загрузке файла.
 *
 * Осторожно при правках: обработчики touchstart/touchmove вешаются на document,
 * и любая тяжёлая работа в них выражается прямо в подтормаживании прокрутки на
 * телефоне. Слушатели здесь passive там, где не нужен preventDefault.
 */
(function () {
  'use strict';

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
      // Удаляем все старые статусы (включая индексированные status[N] от http_build_query)
      if (typeof window.deleteAllStatusKeys === 'function') {
        window.deleteAllStatusKeys(url);
      } else {
        const keysToDelete = [];
        for (const key of url.searchParams.keys()) {
          if (key === 'status' || key === 'status[]' || /^status\[\d+\]$/.test(key)) {
            keysToDelete.push(key);
          }
        }
        keysToDelete.forEach(key => url.searchParams.delete(key));
      }
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


  // Наружу — то, что зовёт dashboard-init.js при инициализации и из обработчика
  // свайпа по карточке.
  window.initializeTouchGestures = initializeTouchGestures;
  window.handleCardSwipe         = handleCardSwipe;
})();

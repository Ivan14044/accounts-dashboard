/**
 * Touch-жесты на мобильных: свайп влево по карточке статистики.
 *
 * Вынесено из dashboard-init.js 2026-08-09 — шестой шаг разбора.
 *
 * Модуль полностью самодостаточен: снаружи ему нужны только logger, showToast,
 * refreshDashboardData и DashboardSelection, и все они берутся из глобальной
 * области в момент жеста, а не при загрузке файла.
 *
 * Осторожно при правках: обработчики touchstart/touchmove висят на document,
 * и любая тяжёлая работа в них выражается прямо в подтормаживании прокрутки на
 * телефоне. Слушатели passive — preventDefault здесь не нужен нигде.
 *
 * === Почему делегирование, а не слушатели на каждой карточке (фикс 2026-08-09) ===
 * Раньше жесты вешались на `document.querySelectorAll('.touch-card')`, но класса
 * `touch-card` нет ни в одном шаблоне и ни в одном CSS — он существовал только
 * здесь, начиная с первого коммита. Цикл крутился по пустому списку, слушателей
 * не создавалось, и свайп молча не работал вообще. Карточки помечены классом
 * `stat-card`.
 * Заменить один селектор было мало: кастомные карточки создаёт custom-cards.js
 * уже ПОСЛЕ инициализации (renderCustomCardsOnDashboard асинхронна и пересоздаёт
 * узлы при каждом рендере), и разовый querySelectorAll их бы не увидел. Поэтому
 * четыре слушателя на document вместо N×3 на карточках — новые карточки
 * начинают работать без переинициализации.
 *
 * Чего здесь осознанно НЕТ: эмуляции свайпа мышью и hover-эффектов для десктопа.
 * Тот код висел на том же несуществующем `.touch-card`, то есть не выполнялся
 * никогда; «заодно» включать его вместе с фиксом мобильного свайпа — значит
 * менять поведение десктопа, где уже есть и CSS-hover (`.stat-card:hover`), и
 * клик по кастомной карточке (делегированный обработчик в dashboard-init.js).
 * Инлайновый transform из тех обработчиков конфликтовал бы с обоими.
 */
(function () {
  'use strict';

  // Пороги жеста в пикселях.
  const SWIPE_MIN_X    = 100; // после какого сдвига влево считаем это свайпом
  const SWIPE_FOLLOW_X = 50;  // с какого сдвига карточка едет за пальцем
  const SWIPE_MAX_Y    = 50;  // больше — это вертикальная прокрутка, не свайп
  const TAP_TOLERANCE  = 10;  // палец почти не двигался — это тап

  // Состояние текущего жеста. Один палец = одна карточка, поэтому одного
  // состояния на модуль достаточно.
  let activeCard = null;
  let startX = 0;
  let startY = 0;
  let currentX = 0;
  let currentY = 0;

  /** Снимает следы жеста с карточки и обнуляет состояние. */
  function resetGesture() {
    if (activeCard) {
      activeCard.classList.remove('touching');
      activeCard.style.transform = '';
    }
    activeCard = null;
  }

  function onTouchStart(e) {
    const touch = e.touches && e.touches[0];
    if (!touch) return;

    const target = e.target;
    const card = target && target.closest ? target.closest('.stat-card') : null;
    if (!card) {
      activeCard = null;
      return;
    }

    activeCard = card;
    startX = currentX = touch.clientX;
    startY = currentY = touch.clientY;
    card.classList.add('touching');
  }

  function onTouchMove(e) {
    if (!activeCard) return;
    const touch = e.touches && e.touches[0];
    if (!touch) return;

    currentX = touch.clientX;
    currentY = touch.clientY;

    const deltaX = currentX - startX;
    const deltaY = currentY - startY;

    // Карточка едет за пальцем только при явном горизонтальном намерении:
    // иначе она дёргалась бы при обычной вертикальной прокрутке страницы.
    if (deltaX < -SWIPE_FOLLOW_X && Math.abs(deltaY) < SWIPE_MAX_Y) {
      activeCard.style.transform = `translateX(${deltaX}px)`;
    } else {
      activeCard.style.transform = '';
    }
  }

  function onTouchEnd() {
    if (!activeCard) return;

    const card = activeCard;
    const deltaX = currentX - startX;
    const deltaY = currentY - startY;

    resetGesture();

    // Свайп влево — применить сценарий карточки.
    if (deltaX < -SWIPE_MIN_X && Math.abs(deltaY) < SWIPE_MAX_Y) {
      handleCardSwipe(card);
      return;
    }

    // Тап — редактирование названия. На дашборде таких меток сейчас нет
    // (заголовок карточки — `.stat-title`), ветка оставлена для карточек с
    // редактируемым `.stat-label.editable`. Проверка typeof — потому что
    // startEditing живёт в dashboard-init.js и грузится отдельным файлом.
    if (Math.abs(deltaX) < TAP_TOLERANCE && Math.abs(deltaY) < TAP_TOLERANCE) {
      const label = card.querySelector('.stat-label.editable');
      if (label && typeof startEditing === 'function') {
        startEditing(label);
      }
    }
  }

  // ===== Touch-жесты и адаптивные карточки =====
  function initializeTouchGestures() {
    document.addEventListener('touchstart', onTouchStart, { passive: true });
    document.addEventListener('touchmove', onTouchMove, { passive: true });
    document.addEventListener('touchend', onTouchEnd, { passive: true });
    document.addEventListener('touchcancel', resetGesture, { passive: true });
  }

  /**
   * Определяет, что делать со свайпнутой карточкой.
   *
   * Разные карточки помечаются РАЗНЫМИ атрибутами, и это не прихоть:
   *  - серверные (templates/partials/dashboard/stats-cards.php):
   *    data-card="total" либо data-card="status:<ключ>" + data-status="<статус>";
   *  - кастомные (assets/js/modules/custom-cards.js):
   *    data-card-type="custom" + data-card-key="<ключ>".
   * До 2026-08-09 функция ветвилась по data-card-type === 'total'|'status', а PHP
   * такой атрибут не рендерит вовсе — условие не выполнялось никогда, и свайп по
   * обычной карточке молча ничего не делал.
   *
   * @param {Element} card карточка `.stat-card`
   * @return {string} 'total' | 'status' | 'custom' | '' — если сценария нет
   */
  function resolveCardKind(card) {
    // data-card-type ставит только JS (кастомные карточки) — он и главнее.
    const explicitType = card.getAttribute('data-card-type');
    if (explicitType) {
      return explicitType;
    }

    const cardId = card.getAttribute('data-card') || '';
    if (cardId === 'total') {
      return 'total';
    }
    // Фильтровать надо по data-status: в data-card лежит ключ, безопасный для
    // селектора (пробелы и кириллица заменены на «_»), а не имя статуса.
    if (cardId.indexOf('status:') === 0 && card.getAttribute('data-status')) {
      return 'status';
    }

    return '';
  }

  async function handleCardSwipe(card) {
    const cardKind = resolveCardKind(card);
    const status = card.getAttribute('data-status');

    if (cardKind === '') {
      // Карточки без сценария фильтрации — например «Пустые статусы» (у неё своя
      // кнопка «Управление»). Тост не показываем, чтобы случайный свайп по ленте
      // не сыпал сообщениями, но в отладке видно, что жест дошёл.
      logger.debug('Card swipe: у карточки нет сценария', card.getAttribute('data-card'));
      return;
    }

    if (cardKind === 'total') {
      // Показать общую статистику
      showToast('Показать детальную статистику по всем аккаунтам', 'info');
    } else if (cardKind === 'status') {
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
    } else if (cardKind === 'custom') {
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
      if (filters.has_passkey) url.searchParams.set('has_passkey', '1');
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
      if (filters.phone_removed) url.searchParams.set('phone_removed', filters.phone_removed);
    
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

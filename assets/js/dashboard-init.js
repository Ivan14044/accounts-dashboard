// Флаг для обратной совместимости. Утилиты и константы — в utils/* и dashboard-init-constants.js; скрытие карточек и настройки — в features/*
window.__INLINE_DASHBOARD_ACTIVE__ = true;
var getElementById = window.getElementById || function (id) { return document.getElementById(id); };
var getSel = window.getSel || function (sel) { return document.querySelector(sel); };
var LS_KEY_COLUMNS = window.LS_KEY_COLUMNS || 'dashboard_visible_columns';
var LS_KEY_CARDS = window.LS_KEY_CARDS || 'dashboard_visible_cards';
var LS_KEY_KNOWN_COLS = window.LS_KEY_KNOWN_COLS || 'dashboard_known_columns';
var LS_KEY_HIDDEN_CARDS = window.LS_KEY_HIDDEN_CARDS || 'dashboard_hidden_cards';
var ACTIVE_FILTERS_COUNT = window.ACTIVE_FILTERS_COUNT || 0;
var loadHiddenCards = window.loadHiddenCards;
var loadHiddenCardsFromLocalStorage = window.loadHiddenCardsFromLocalStorage;
var saveHiddenCards = window.saveHiddenCards;
var hideCard = window.hideCard;
var showCard = window.showCard;
var loadSettings = window.loadSettings;
var saveSettings = window.saveSettings;
var toggleColumnVisibility = window.toggleColumnVisibility;
var applySavedColumnVisibility = window.applySavedColumnVisibility;
var toggleCardVisibility = window.toggleCardVisibility;

// ===== Управление скрытием карточек (реализация в features/hidden-cards.js; алиасы выше) =====
// ===== Обработчики событий =====
// Обработчик скрытия карточек (делегирование событий)
document.addEventListener('click', function (e) {
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

document.addEventListener('DOMContentLoaded', function () {
  // NB: selectedIds загрузка (loadSelectedIds) удалена — выбор всё равно сбрасывается ниже (clearSelection)
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

  // Скрываем прелоадеры (данные уже отрендерены сервером)
  const statsLoading = getElementById('statsLoading');
  const tableLoading = getElementById('tableLoading');

  if (statsLoading) {
    statsLoading.classList.remove('show');
    statsLoading.style.display = 'none';
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
  window.DashboardConfig.csrfToken = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.csrfToken) || '';

  // НЕ сохраняем выбранные строки при перезагрузке - очищаем выбор
  if (window.DashboardSelection) {
    // Инициализируем filteredTotalLive из серверного значения
    window.DashboardSelection.setFilteredTotalLive((window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.filteredTotal) || 0);

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
  // Загружаем сохраненные названия блоков и инициализируем доп. модули
  loadStatLabels();
  initStatValues();
  initializeAutoRefresh();
  initializeTouchGestures();
  initScrollToTop();
  // Слайдеры инициализируются через DashboardFilters.init() в dashboard-main.js
  // Гарантируем синхронизацию значений ползунков перед отправкой формы
  document.addEventListener('submit', function (e) {
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
    settingsModalEl.addEventListener('show.bs.modal', function () {
      // Синхронизируем чекбоксы при открытии модального окна
      syncCardCheckboxesWithHidden();
    });
  }

  // Реакция на переключение чекбоксов настроек (колонки/карточки)
  document.addEventListener('change', function (e) {
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
  document.addEventListener('click', function (e) {
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
  document.addEventListener('click', function (e) {
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
            csrf: (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.csrfToken) || ''
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
  document.addEventListener('click', function (e) {
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
    cellCopyBtn.addEventListener('click', function () {
      const body = getElementById('cellModalBody');
      copyToClipboard(body.textContent || '');
    });
  }

  // Обработчик для всех кнопок копирования (совместимость с Firefox)
  // Используем делегирование событий для динамически созданных элементов
  document.addEventListener('click', function (e) {
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

  // Пагинация без прокрутки вверх (AJAX)
  document.addEventListener('click', function (e) {
    const a = e.target.closest('ul.pagination a.page-link');
    if (!a) return;
    const li = a.closest('li');
    if (li && li.classList.contains('disabled')) { e.preventDefault(); return; }
    e.preventDefault();
    const href = a.getAttribute('href') || '';
    if (!href) return;
    const url = new URL(href, window.location.origin);
    const pageParam = parseInt(url.searchParams.get('page') || '1');
    const current = new URL(window.location);
    current.searchParams.set('page', String(pageParam));
    history.replaceState(null, '', current.toString());
    // Обновляем номер страницы в футере немедленно
    const pageNumEl = getElementById('pageNum');
    if (pageNumEl) pageNumEl.textContent = String(pageParam);
    const pageJumpInputEl = getElementById('pageJumpInput');
    if (pageJumpInputEl) pageJumpInputEl.value = String(pageParam);
    // НЕ очищаем selectedIds при пагинации - выбранные строки должны сохраняться между страницами
    // selectedAllFiltered сбрасываем, так как это относится к текущему фильтру
    if (window.DashboardSelection) {
      window.DashboardSelection.setSelectedAllFiltered(false);
      window.DashboardSelection.updateSelectedCount();
    }
    if (typeof window.refreshDashboardData === 'function') {
      window.refreshDashboardData({ light: true });
    } else {
      window.location.reload();
    }
  });

  // Export selected CSV
  const exportSelectedCsv = getElementById('exportSelectedCsv');
  if (exportSelectedCsv) {
    exportSelectedCsv.addEventListener('click', function () {
      const DS = window.DashboardSelection;
      if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;

      // Создаем скрытую форму для корректной обработки заголовков скачивания
      const form = document.createElement('form');
      form.method = 'GET';
      form.action = 'export.php';
      // Не указываем target, чтобы браузер правильно обработал Content-Disposition: attachment

      const currentSort = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.sort) || '';
      const currentDir = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.dir) || '';

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
    exportSelectedTxt.addEventListener('click', function () {
      const DS = window.DashboardSelection;
      if (!DS || (!DS.getSelectedAllFiltered() && DS.getSelectedIds().size === 0)) return;
      const currentSort = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.sort) || '';
      const currentDir = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.dir) || '';
      let visibleCols = [];
      try { const saved = localStorage.getItem('dashboard_visible_columns'); if (saved) visibleCols = JSON.parse(saved); } catch (_) { }
      if (!Array.isArray(visibleCols) || visibleCols.length === 0) {
        visibleCols = Array.from(document.querySelectorAll('#accountsTable thead th[data-col]')).map(th => th.getAttribute('data-col'));
      }
      const ALL_COL_KEYS = (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.allColumnKeys) || [];
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
    deleteSelectedBtn.addEventListener('click', function () {
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
    confirmDeleteBtn.addEventListener('click', async function () {
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
            body: JSON.stringify({ ids: [], csrf: (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.csrfToken) || '' })
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
          const requestBody = { ids: ids, csrf: (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.csrfToken) || '' };

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
          if (typeof window.refreshDashboardData === 'function') {
            await window.refreshDashboardData();
          } else {
            window.location.reload();
          }
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

  // Переход на страницу по вводу номера (поле + кнопка «Перейти»)
  const pageJumpInput = getElementById('pageJumpInput');
  const pageJumpBtn = getElementById('pageJumpBtn');
  if (pageJumpBtn && pageJumpInput) {
    function applyPageJump() {
      const pagesEl = getElementById('pagesCount');
      const totalPages = pagesEl ? parseInt(pagesEl.textContent, 10) : 1;
      let num = parseInt(pageJumpInput.value, 10);
      if (!Number.isFinite(num) || num < 1) num = 1;
      if (num > totalPages) num = totalPages;
      pageJumpInput.value = String(num);
      goToPage(num);
    }
    pageJumpBtn.addEventListener('click', applyPageJump);
    pageJumpInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        applyPageJump();
      }
    });
  }
});

function goToPage(selectedPage) {
  if (!selectedPage || selectedPage < 1) return;
  const url = new URL(window.location);
  url.searchParams.set('page', String(selectedPage));
  history.replaceState(null, '', url.toString());
  const pageNumEl = getElementById('pageNum');
  if (pageNumEl) pageNumEl.textContent = String(selectedPage);
  window.DashboardSelection && window.DashboardSelection.clearSelection();
  if (typeof window.refreshDashboardData === 'function') {
    window.refreshDashboardData();
  } else {
    window.location.reload();
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
    label.addEventListener('click', function (e) {
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
  input.addEventListener('keydown', function (e) {
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

// Второй DOMContentLoaded обработчик удалён — код перенесён в основной обработчик выше (строка 67)

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
  if (el.__animFrameId) { try { cancelAnimationFrame(el.__animFrameId); } catch (_) { } }
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

  toggleBtn.addEventListener('click', function () {
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
    if (typeof window.refreshDashboardData === 'function') {
      window.refreshDashboardData();
    }
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
  try { if (window.refreshController) window.refreshController.abort(); } catch (_) { }

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
    card.addEventListener('touchstart', function (e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      currentX = startX;
      currentY = startY;

      this.classList.add('touching');
    });

    card.addEventListener('touchmove', function (e) {
      currentX = e.touches[0].clientX;
      currentY = e.touches[0].clientY;

      const deltaX = currentX - startX;
      const deltaY = currentY - startY;

      // Swipe влево - показать детали
      if (deltaX < -50 && Math.abs(deltaY) < 50) {
        this.style.transform = `translateX(${deltaX}px)`;
      }
    });

    card.addEventListener('touchend', function (e) {
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
    card.addEventListener('mousedown', function (e) {
      startX = e.clientX;
      startY = e.clientY;
      this.classList.add('touching');
    });

    card.addEventListener('mousemove', function (e) {
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

    card.addEventListener('mouseup', function (e) {
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
    card.addEventListener('mouseenter', function () {
      if (!this.classList.contains('touching')) {
        this.style.transform = 'translateY(-5px) scale(1.02)';
      }
    });

    card.addEventListener('mouseleave', function () {
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
    if (typeof window.refreshDashboardData === 'function') {
      window.refreshDashboardData();
    } else {
      window.location.reload();
    }
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
    if (typeof window.refreshDashboardData === 'function') {
      window.refreshDashboardData();
    } else {
      window.location.reload();
    }
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
window.addEventListener('load', function () {
  adjustForMobile();
  loadHiddenCards().catch(err => logger.error('Error loading hidden cards:', err)); // Загружаем скрытые карточки при загрузке страницы
});

// ===== Кастомные карточки: реализация в features/custom-cards.js =====

// Логика массовой смены статуса вынесена в модуль `dashboard-modals.js` (initStatusModal).

document.addEventListener('click', function (e) {
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
  let t; return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delay); };
}

// Дебаунсированная версия refreshDashboardData для использования в фильтрах.
// Явно вызываем window.refreshDashboardData, чтобы работало и с бандлом (dashboard.min.js), и с отдельными скриптами.
const debouncedRefreshDashboardData = debounce(() => {
  if (typeof window.refreshDashboardData === 'function') {
    window.refreshDashboardData();
  } else {
    window.location.reload();
  }
}, 300); // 300ms дебаунс для фильтров

document.addEventListener('DOMContentLoaded', function () {
  const searchInput = getElementById('modernSearchInput');
  if (searchInput) {
    const applyLiveSearch = debounce(() => {
      const url = new URL(window.location);
      url.searchParams.set('q', searchInput.value || '');
      url.searchParams.set('page', '1');
      history.replaceState(null, '', url.toString());
      window.DashboardSelection && window.DashboardSelection.clearSelection();
      if (typeof window.refreshDashboardData === 'function') {
        window.refreshDashboardData();
      } else {
        window.location.reload();
      }
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

    // Добавляем выбранные статусы (пустой статус — name="empty_status", value="1")
    if (selectedCount > 0) {
      checkedBoxes.forEach(cb => {
        if (cb.name === 'empty_status' || cb.value === '__empty__') {
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

  // Обработчик изменения чекбоксов: только подпись dropdown; применение — через форму в filters-modern.js (один refresh)
  statusCheckboxes.forEach(cb => {
    cb.addEventListener('change', () => {
      updateStatusUI();
      var form = document.getElementById('filtersForm');
      if (form && typeof window.applyFormFiltersWithoutReload === 'function') {
        window.applyFormFiltersWithoutReload(form);
      } else {
        debouncedApplyStatusFilter();
      }
    });
  });

  // Предотвращаем закрытие dropdown при клике внутри
  if (statusDropdownMenu) {
    statusDropdownMenu.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }

  // Кнопка "Выбрать все" / "Очистить все" — применяем через форму (один путь, один refresh)
  const selectAllStatusesBtn = getElementById('selectAllStatusesBtn');
  if (selectAllStatusesBtn) {
    selectAllStatusesBtn.addEventListener('click', () => {
      statusCheckboxes.forEach(cb => cb.checked = true);
      updateStatusUI();
      var form = document.getElementById('filtersForm');
      if (form && typeof window.applyFormFiltersWithoutReload === 'function') {
        window.applyFormFiltersWithoutReload(form);
      } else {
        debouncedApplyStatusFilter();
      }
    });
  }
  const clearAllStatusesBtn = getElementById('clearAllStatusesBtn');
  if (clearAllStatusesBtn) {
    clearAllStatusesBtn.addEventListener('click', () => {
      statusCheckboxes.forEach(cb => cb.checked = false);
      updateStatusUI();
      var form = document.getElementById('filtersForm');
      if (form && typeof window.applyFormFiltersWithoutReload === 'function') {
        window.applyFormFiltersWithoutReload(form);
      } else {
        debouncedApplyStatusFilter();
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
  // Логика быстрых чекбоксов (has_email/has_two_fa/...) вынесена в filters-modern.js,
  // где форма является единым источником правды. Здесь дополнительная обработка не нужна,
  // чтобы не дублировать обновление URL и данных.
  // Диапазоны (pharma, friends, year_created, limit_rk): применение при вводе/blur/Enter — в filters-modern.js (единый источник правды — форма).
});

document.addEventListener('click', function (e) {
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
  // Обновляем без перезагрузки (явно window — работа с бандлом и отдельными скриптами)
  if (typeof window.refreshDashboardData === 'function') {
    window.refreshDashboardData();
  } else {
    window.location.href = cur.toString();
  }
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
document.addEventListener('click', function (e) {
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
  const longFields = ['token', 'cookies', 'user_agent', 'extra_info_1', 'extra_info_2', 'extra_info_3', 'extra_info_4'];
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
        body: JSON.stringify({ id: rowId, field: field, value: newVal, csrf: (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.csrfToken) || '' })
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
document.addEventListener('click', function (e) {
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
  bulkEditBtn.addEventListener('click', function () {
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

// Универсальная кнопка "Сбросить все" - очищает выбранные строки и/или фильтры
const clearAllSelectedBtn = getElementById('clearAllSelectedBtn');
if (clearAllSelectedBtn) {
  clearAllSelectedBtn.addEventListener('click', function () {
    const DS = window.DashboardSelection;
    const hasSelection = DS && (DS.getSelectedAllFiltered() || DS.getSelectedIds().size > 0);
    const hasActiveFilters = document.querySelectorAll('.filter-chip').length > 0;

    if (hasActiveFilters) {
      if (hasSelection && DS) {
        DS.clearSelection();
      }
      // Перенаправляем на страницу без параметров фильтров
      const baseUrl = window.location.pathname;
      window.location.href = baseUrl;
      return; // Прерываем выполнение, так как происходит перезагрузка страницы
    } else if (hasSelection && DS) {
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
  applyTransferBtn.addEventListener('click', async function () {
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
      showToast(`⚠️ Слишком большой текст (${(sizeInBytes / 1024 / 1024).toFixed(1)}MB). Максимум 20MB`, 'error');
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
        csrf: (window.__DASHBOARD_CONFIG__ && window.__DASHBOARD_CONFIG__.csrfToken) || '',
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

// Пустой DOMContentLoaded обработчик удалён в ходе рефакторинга

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
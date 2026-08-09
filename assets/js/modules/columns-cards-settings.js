/**
 * Настройки видимости колонок и карточек статистики.
 *
 * Вынесено из dashboard-init.js 2026-08-09 — четвёртый шаг разбора. Здесь:
 * загрузка и сохранение настроек (localStorage + серверные user_settings),
 * скрытие/показ колонки таблицы и карточки статистики, применение сохранённой
 * видимости к свежеотрисованным строкам.
 *
 * ВАЖНО про удалённую копию. В репозитории лежал assets/js/features/settings-columns-cards.js
 * с теми же пятью функциями — неподключённая сирота от неоконченного разбора.
 * Подключать её было нельзя: это была БОЛЕЕ СТАРАЯ версия (toggleCardVisibility
 * 37 строк против 62 живых), то есть без логики, дописанной после того выноса.
 * Молчаливый откат поведения = потерянные настройки пользователя. Поэтому в
 * модуль переехала живая реализация, а сирота удалена.
 *
 * Ключи localStorage — из modules/constants.js, своих копий здесь нет.
 * Остальные зависимости берутся из глобальной области в момент вызова:
 * getSel, logger, showToast, saveHiddenCards (из dashboard-init.js).
 */
(function () {
  'use strict';

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
    
      // Синхронизируем чекбоксы с сохранённым состоянием скрытых карточек.
      // НЕ вызываем toggleCardVisibility — скрытием управляют cards-hide-sync.js
      // и loadHiddenCards(). loadSettings только обновляет чекбоксы.
      document.querySelectorAll('.card-toggle').forEach(cb => {
        const cardName = cb.getAttribute('data-card');
        if (!cardName || cardName.trim() === '') {
          return;
        }
        cb.checked = !hiddenCards.includes(cardName);
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

  // Наружу — всё, что зовут снаружи: dashboard-init.js, table-module.js
  // (applySavedColumnVisibility после перерисовки) и dashboard-stats.js.
  window.loadSettings               = loadSettings;
  window.saveSettings               = saveSettings;
  window.toggleColumnVisibility     = toggleColumnVisibility;
  window.applySavedColumnVisibility = applySavedColumnVisibility;
  window.toggleCardVisibility       = toggleCardVisibility;
})();

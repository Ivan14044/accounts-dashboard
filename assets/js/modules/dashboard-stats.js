/**
 * Модуль статистики дашборда — обновление значений карточек и сброс/превью labels.
 *
 * ВАЖНО: видимость карточек (hide/show) и persistence — НЕ ЗДЕСЬ.
 * Эта логика живёт только в dashboard-init.js (правильный POST с CSRF в /api/settings)
 * и cards-hide-sync.js (синхронное скрытие из localStorage до отрисовки + observer).
 *
 * Раньше здесь были дубли hideCard/showCard/saveHiddenCards/toggleCardVisibility,
 * которые перезаписывали глобальные функции из dashboard-init.js. Дубли слали
 * неправильный POST `{action: 'save_hidden_cards', hidden_cards: [...]}` без CSRF —
 * бэкенд возвращал 500, БД не обновлялась, и при refresh страницы скрытие
 * слетало (БД отдавала пустой массив, fallback на localStorage случался не всегда).
 */

// Вспомогательная функция для безопасного получения элемента через dom-cache
function getElementById(id) {
  if (typeof domCache !== 'undefined' && domCache.getById) {
    return domCache.getById(id);
  }
  return document.getElementById(id);
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

// Сброс названий блоков к исходным значениям
function resetStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');

  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const originalText = label.getAttribute('data-original');
    const labelText = label.querySelector('.label-text');

    if (!labelText || !cardType) return;

    labelText.textContent = originalText;

    const key = `stat_label_${cardType}`;
    localStorage.removeItem(key);
  });
}

// Предварительный просмотр названий блоков
function previewStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  let previewText = 'Текущие названия блоков:\\n\\n';

  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const labelText = label.querySelector('.label-text');
    if (!labelText || !cardType) return;

    const currentText = labelText.textContent;
    const originalText = label.getAttribute('data-original');

    previewText += `• ${cardType}: \"${currentText}\"`;
    if (currentText !== originalText) {
      previewText += ` (было: \"${originalText}\")`;
    }
    previewText += '\\n';
  });

  alert(previewText);
}

// Инициализация модуля статистики (только labels-кнопки).
// Hide/show карточек инициализируется в dashboard-init.js — не дублируем!
function initStatsModule() {
  const resetStatLabelsBtn = getElementById('resetStatLabels');
  if (resetStatLabelsBtn) {
    resetStatLabelsBtn.addEventListener('click', function() {
      if (confirm('Вы действительно хотите сбросить все названия блоков к исходным значениям?')) {
        resetStatLabels();
        if (typeof showToast === 'function') {
          showToast('Названия блоков сброшены к исходным значениям', 'success');
        }
      }
    });
  }

  const previewStatLabelsBtn = getElementById('previewStatLabels');
  if (previewStatLabelsBtn) {
    previewStatLabelsBtn.addEventListener('click', function() {
      previewStatLabels();
    });
  }

  if (typeof logger !== 'undefined') {
    logger.debug('✅ Модуль статистики инициализирован (labels only)');
  }
}

// Экспорт. Все функции скрытия живут в global scope из dashboard-init.js
// (hideCard/showCard/saveHiddenCards/loadHiddenCards/toggleCardVisibility) —
// здесь их НЕ дублируем.
window.DashboardStats = {
  init: initStatsModule,
  updateStatValue: updateStatValue,
  updateStatusCards: updateStatusCards
};

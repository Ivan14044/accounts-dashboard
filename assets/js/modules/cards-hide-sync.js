/**
 * Синхронное скрытие карточек статистики из localStorage.
 *
 * Загружается с defer в конце body. Цели:
 *  1. До рендера (через MutationObserver на .stats-grid) — успеть скрыть карточки,
 *     которые добавляет PHP-шаблон или клиентский код (custom-cards.js).
 *  2. После initial load — продолжать наблюдать. Любая логика, которая
 *     перерисовывает карточки в runtime (refreshDashboardData, custom-cards
 *     async добавление, кастомные виджеты), не должна показывать спрятанные.
 *
 * РАНЬШЕ observer disconnect-ился на window.load. Это создавало баг: если
 * пользователь скрыл карточку и localStorage обновился, то при следующем
 * rerender карточки (после load) скрытие не применялось.
 *
 * Источник правды — localStorage. Перечитываем его на каждый mutation —
 * это копеечно (читается строка ~200 байт), зато наблюдатель всегда
 * синхронизирован с реальным состоянием.
 */
(function() {
  var LS_KEY = 'dashboard_hidden_cards';

  function readHidden() {
    try {
      var saved = localStorage.getItem(LS_KEY);
      if (!saved) return null;
      var arr = JSON.parse(saved);
      return Array.isArray(arr) && arr.length > 0 ? new Set(arr) : null;
    } catch (_) {
      return null;
    }
  }

  function hideCardImmediately(card, hiddenSet) {
    var cardId = card.getAttribute('data-card');
    if (!cardId || !hiddenSet || !hiddenSet.has(cardId)) return;

    card.classList.add('hidden');
    card.style.setProperty('display', 'none', 'important');
    card.style.setProperty('visibility', 'hidden', 'important');
    card.style.setProperty('opacity', '0', 'important');
    card.setAttribute('hidden', '');
  }

  function applyHidingToAllCards() {
    var hiddenSet = readHidden();
    if (!hiddenSet) return;
    document.querySelectorAll('.stat-card').forEach(function(card) {
      hideCardImmediately(card, hiddenSet);
    });
  }

  // _hiddenCardsToHide остаётся для обратной совместимости с dashboard-init.js,
  // но теперь это просто проекция localStorage, а не отдельный лайфтайм.
  var initialHidden = readHidden();
  if (initialHidden) {
    window._hiddenCardsToHide = initialHidden;
  }

  var observer = new MutationObserver(function(mutations) {
    // Перечитываем localStorage на каждый mutation — это дёшево, зато
    // всегда видим актуальный set (например, после showCard/hideCard).
    var hiddenSet = readHidden();
    if (!hiddenSet) return;

    for (var i = 0; i < mutations.length; i++) {
      var added = mutations[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var node = added[j];
        if (node.nodeType !== 1) continue;
        if (node.classList && node.classList.contains('stat-card')) {
          hideCardImmediately(node, hiddenSet);
        }
        if (node.querySelectorAll) {
          node.querySelectorAll('.stat-card').forEach(function(c) {
            hideCardImmediately(c, hiddenSet);
          });
        }
      }
    }
  });

  function startObserving() {
    var statsContainer = document.querySelector('.stats-grid');
    var target = statsContainer || document.body;
    if (!target) return;
    observer.observe(target, { childList: true, subtree: true });
  }

  if (document.body) {
    startObserving();
    applyHidingToAllCards();
  } else {
    document.addEventListener('DOMContentLoaded', function() {
      startObserving();
      applyHidingToAllCards();
    });
  }

  // Финальное применение после полной загрузки (на случай если что-то добавилось
  // между DOMContentLoaded и load). Observer ОСТАЁТСЯ активным — это намеренно.
  window.addEventListener('load', applyHidingToAllCards);
})();

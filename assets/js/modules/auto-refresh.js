/**
 * Автообновление таблицы и кнопка «Наверх».
 *
 * Вынесено из dashboard-init.js 2026-08-09 — пятый шаг разбора.
 *
 * Два сюжета в одном модуле не случайно: оба про фоновое поведение страницы,
 * оба работают с таймерами и скроллом и оба ни от чего в дашборде не зависят,
 * кроме refreshDashboardData. Разносить их по разным файлам ради чистоты
 * классификации смысла нет.
 *
 * Состояние (таймер автообновления и флаг включённости) живёт внутри модуля —
 * раньше это были две глобальные let-переменные в dashboard-init.js.
 *
 * Зависимости берутся из глобальной области в момент вызова:
 * getElementById и refreshDashboardData.
 */
(function () {
  'use strict';

  // ===== Автообновление данных =====
  let autoRefreshInterval = null;
  let isAutoRefreshEnabled = false;
  let autoRefreshSkipped = false;
  let initializedToggle = null;

  function toggleAutoRefresh() {
    if (isAutoRefreshEnabled) stopAutoRefresh();
    else startAutoRefresh();
  }

  function onAutoRefreshVisible() {
    if (isAutoRefreshEnabled && document.visibilityState === 'visible' && autoRefreshSkipped) {
      if (document.querySelector('tr[data-id][data-editing="true"]')) return;
      autoRefreshSkipped = false;
      refreshDashboardData();
    }
  }

  function initializeAutoRefresh() {
    const toggleBtn = getElementById('autoRefreshToggle');
    if (!toggleBtn || initializedToggle === toggleBtn) return;
    if (initializedToggle) initializedToggle.removeEventListener('click', toggleAutoRefresh);
    initializedToggle = toggleBtn;
    toggleBtn.addEventListener('click', toggleAutoRefresh);
    let savedState = null;
    try { savedState = localStorage.getItem('dashboard_auto_refresh'); } catch (_) {}
    if (isAutoRefreshEnabled || savedState === 'enabled') startAutoRefresh();
  }

  function startAutoRefresh() {
    const toggleBtn = getElementById('autoRefreshToggle');
    if (toggleBtn) {
      toggleBtn.classList.add('active');
      toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
      toggleBtn.title = 'Остановить автообновление';
      toggleBtn.setAttribute('aria-pressed', 'true');
      toggleBtn.setAttribute('aria-label', toggleBtn.title);
    }
    if (isAutoRefreshEnabled) return;
    isAutoRefreshEnabled = true;
    autoRefreshSkipped = false;
    autoRefreshInterval = setInterval(() => {
      if (document.visibilityState === 'hidden' || document.querySelector('tr[data-id][data-editing="true"]')) {
        autoRefreshSkipped = true;
        return;
      }
      autoRefreshSkipped = false;
      refreshDashboardData();
    }, 30000);
    document.addEventListener('visibilitychange', onAutoRefreshVisible);
    try { localStorage.setItem('dashboard_auto_refresh', 'enabled'); } catch (_) {}
  }

  function stopAutoRefresh() {
    isAutoRefreshEnabled = false;
    autoRefreshSkipped = false;
    if (autoRefreshInterval !== null) {
      clearInterval(autoRefreshInterval);
      autoRefreshInterval = null;
    }
    document.removeEventListener('visibilitychange', onAutoRefreshVisible);
    const toggleBtn = getElementById('autoRefreshToggle');
    if (toggleBtn) {
      toggleBtn.classList.remove('active');
      toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
      toggleBtn.title = 'Включить автообновление';
      toggleBtn.setAttribute('aria-pressed', 'false');
      toggleBtn.setAttribute('aria-label', toggleBtn.title);
    }
    // Общий refreshController может принадлежать ручному обновлению.
    try { localStorage.setItem('dashboard_auto_refresh', 'disabled'); } catch (_) {}
    // Состояние видно по кнопке и aria-pressed; toast при каждом клике
    // образует стопку, перекрывающую шапку и следующие действия.
  }

  // ===== refreshDashboardData перенесена в dashboard-refresh.js =====

  // ===== Кнопка "Наверх" =====
  function initScrollToTop() {
    const scrollToTopBtn = getElementById('scrollToTop');
    if (!scrollToTopBtn) return;

    // Показываем/скрываем кнопку по позиции скролла. Без layout-чтений в scroll (только pageYOffset и classList).
    var scrollToTopTicking = false;
    function toggleScrollToTop() {
      if (scrollToTopTicking) return;
      scrollToTopTicking = true;
      requestAnimationFrame(function() {
        scrollToTopTicking = false;
        if (window.pageYOffset > 300) {
          scrollToTopBtn.classList.add('show');
        } else {
          scrollToTopBtn.classList.remove('show');
        }
      });
    }

    // Плавный скролл наверх
    function scrollToTop() {
      window.scrollTo({
        top: 0,
        behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'
      });
    }

    window.addEventListener('scroll', toggleScrollToTop, { passive: true });
    scrollToTopBtn.addEventListener('click', scrollToTop);

    toggleScrollToTop();
  }


  // Наружу — только то, что зовёт dashboard-init.js при инициализации.
  // startAutoRefresh/stopAutoRefresh экспортированы для ручного управления
  // из консоли и на случай будущей кнопки «пауза».
  window.initializeAutoRefresh = initializeAutoRefresh;
  window.startAutoRefresh      = startAutoRefresh;
  window.stopAutoRefresh       = stopAutoRefresh;
  window.initScrollToTop       = initScrollToTop;
})();
